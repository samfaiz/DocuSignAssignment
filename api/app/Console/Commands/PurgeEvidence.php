<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\Recipient;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deletes stored photographs and precise coordinates once their retention
 * period has passed.
 *
 * A retention policy that exists only in a privacy notice is not a retention
 * policy. This is the thing that actually enforces it, and it runs on a
 * schedule so nobody has to remember.
 *
 * What it removes is narrow on purpose. The photograph and the coordinates go;
 * the record that a photograph was requested and that the signer agreed or
 * refused stays forever. That decision is the part with evidential value, and
 * it contains no personal data — deleting it would weaken the audit trail
 * while protecting nobody.
 */
class PurgeEvidence extends Command
{
    protected $signature = 'signdesk:purge-evidence
                            {--dry-run : List what would be removed without touching anything}';

    protected $description = 'Delete signing photographs and coordinates past their retention period';

    public function handle(AuditLogger $audit): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $photoDays = (int) config('signing.retention.photo_days');
        $locationDays = (int) config('signing.retention.location_days');

        if ($photoDays <= 0 && $locationDays <= 0) {
            $this->warn('Retention is disabled for both photographs and locations; nothing to do.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('Dry run — nothing will be modified.');
        }

        $photos = $photoDays > 0
            ? $this->purgePhotos($photoDays, $dryRun, $audit)
            : 0;

        $locations = $locationDays > 0
            ? $this->purgeLocations($locationDays, $dryRun, $audit)
            : 0;

        $this->newLine();
        $this->info(sprintf(
            '%s %d photograph(s) and %d location(s).',
            $dryRun ? 'Would remove' : 'Removed',
            $photos,
            $locations,
        ));

        if (! $dryRun && ($photos > 0 || $locations > 0)) {
            $this->line(
                'Documents already sealed and delivered keep their own copy — '
                . 'they are tamper-evident, so nothing can be removed from them.'
            );
        }

        return self::SUCCESS;
    }

    private function purgePhotos(int $days, bool $dryRun, AuditLogger $audit): int
    {
        $cutoff = Carbon::now('UTC')->subDays($days);

        $recipients = Recipient::query()
            ->whereNotNull('photo_storage_key')
            ->where('photo_captured_at', '<', $cutoff)
            ->with('envelope')
            ->get();

        foreach ($recipients as $recipient) {
            $this->line("  photo  {$recipient->email}  captured {$recipient->photo_captured_at->toDateString()}");

            if ($dryRun) {
                continue;
            }

            try {
                Storage::disk(config('signing.storage_disk'))->delete($recipient->photo_storage_key);
            } catch (Throwable $e) {
                // A missing file is still a purged file. Clearing the row
                // regardless keeps the database from advertising an image that
                // is no longer there.
                $this->warn("    could not delete object: {$e->getMessage()}");
            }

            $recipient->forceFill([
                'photo_storage_key' => null,
                'photo_sha256' => null,
            ])->save();

            $this->recordPurge($audit, $recipient, 'photograph', $days);
        }

        return $recipients->count();
    }

    private function purgeLocations(int $days, bool $dryRun, AuditLogger $audit): int
    {
        $cutoff = Carbon::now('UTC')->subDays($days);

        $recipients = Recipient::query()
            ->whereNotNull('latitude')
            ->where('location_captured_at', '<', $cutoff)
            ->with('envelope')
            ->get();

        foreach ($recipients as $recipient) {
            $this->line("  location  {$recipient->email}  captured {$recipient->location_captured_at->toDateString()}");

            if ($dryRun) {
                continue;
            }

            $recipient->forceFill([
                'latitude' => null,
                'longitude' => null,
                'location_accuracy_m' => null,
            ])->save();

            $this->recordPurge($audit, $recipient, 'location', $days);
        }

        return $recipients->count();
    }

    /**
     * Append the deletion to the envelope's chain.
     *
     * Removing data quietly would leave a certificate describing a photograph
     * that no longer exists, with nothing explaining the gap. An entry saying
     * when and why it went keeps the record coherent, and demonstrates the
     * policy was applied rather than merely written down.
     */
    private function recordPurge(
        AuditLogger $audit,
        Recipient $recipient,
        string $what,
        int $days
    ): void {
        if (! $recipient->envelope) {
            return;
        }

        $audit->record($recipient->envelope, AuditEvent::EVIDENCE_PURGED, [
            'what' => $what,
            'retention_days' => $days,
            'reason' => 'retention period elapsed',
        ], recipient: $recipient, actor: 'system');
    }
}
