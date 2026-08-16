<?php

namespace App\Console\Commands;

use App\Models\Envelope;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

/**
 * Recomputes every envelope's audit chain and reports breaks.
 *
 * Intended to run on a schedule. A hash chain only helps if something actually
 * recomputes it — otherwise a tampered row sits there looking exactly like a
 * genuine one until somebody happens to look.
 */
class VerifyAuditChains extends Command
{
    protected $signature = 'signdesk:verify-audit
                            {--envelope= : Verify a single envelope by UUID}';

    protected $description = 'Recompute audit hash chains and report any breaks';

    public function handle(AuditLogger $audit): int
    {
        $query = Envelope::query()->has('auditEvents');

        if ($uuid = $this->option('envelope')) {
            $query->where('uuid', $uuid);
        }

        $envelopes = $query->get();

        if ($envelopes->isEmpty()) {
            $this->warn('No envelopes with audit events found.');

            return self::SUCCESS;
        }

        $broken = 0;
        $rows = [];

        foreach ($envelopes as $envelope) {
            $result = $audit->verifyChain($envelope);

            if (! $result['valid']) {
                $broken++;
            }

            $rows[] = [
                $envelope->uuid,
                $result['count'],
                $result['valid'] ? 'OK' : 'BROKEN',
                $result['valid'] ? '' : "seq {$result['broken_at']}: {$result['reason']}",
            ];
        }

        $this->table(['Envelope', 'Events', 'Chain', 'Detail'], $rows);

        if ($broken > 0) {
            $this->error("{$broken} of {$envelopes->count()} audit chains failed verification.");

            // Non-zero so a scheduler or CI job treats this as the incident it is.
            return self::FAILURE;
        }

        $this->info("All {$envelopes->count()} audit chains verified.");

        return self::SUCCESS;
    }
}
