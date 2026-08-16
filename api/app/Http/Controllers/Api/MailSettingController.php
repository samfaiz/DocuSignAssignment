<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MailSetting;
use App\Services\MailConfigurator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Admin-managed SMTP configuration.
 *
 * Mail is load-bearing here rather than incidental: signing links and one-time
 * passcodes travel over it, so an operator who cannot fix mail delivery cannot
 * fix the product. Hence editable from the interface rather than only from .env.
 */
class MailSettingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'settings' => MailSetting::current()->toAdminArray(),
            'presets' => [
                'gmail' => [
                    'label' => 'Gmail / Google Workspace',
                    'host' => 'smtp.gmail.com',
                    'port' => 587,
                    'encryption' => 'tls',
                    // Gmail rejects account passwords over SMTP. The username is
                    // the full address and the password must be a 16-character
                    // app password, which requires 2-step verification first.
                    'note' => 'Use an App Password, not your Google account password. '
                        . 'Enable 2-Step Verification, then create one at '
                        . 'myaccount.google.com/apppasswords.',
                ],
                'brevo' => [
                    'label' => 'Brevo',
                    'host' => 'smtp-relay.brevo.com',
                    'port' => 587,
                    'encryption' => 'tls',
                    'note' => 'Username is your Brevo login; password is an SMTP key.',
                ],
                'mailpit' => [
                    'label' => 'Mailpit (local development)',
                    'host' => '127.0.0.1',
                    'port' => 1025,
                    'encryption' => null,
                    'note' => 'No credentials. Captured mail appears at localhost:8025.',
                ],
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mailer' => ['nullable', 'string', 'in:smtp'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            // Absent means "leave the stored one alone", so an admin can change
            // the host without having to retype a password they cannot read back.
            'password' => ['nullable', 'string', 'max:255'],
            'encryption' => ['nullable', 'in:tls,ssl'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:255'],
        ]);

        $settings = MailSetting::current();

        $settings->fill([
            'mailer' => $data['mailer'] ?? 'smtp',
            'host' => $data['host'],
            'port' => $data['port'],
            'username' => $data['username'] ?? null,
            'encryption' => $data['encryption'] ?? null,
            'from_address' => $data['from_address'],
            'from_name' => $data['from_name'],
            'updated_by' => $request->user()->id,
        ]);

        if (filled($data['password'] ?? null)) {
            $settings->password = $data['password'];
        }

        $settings->save();

        MailConfigurator::forget();
        MailConfigurator::apply();

        return response()->json(['settings' => $settings->fresh()->toAdminArray()]);
    }

    /**
     * Sends a real message through the saved settings.
     *
     * Saving credentials proves nothing — SMTP fails for host, port, TLS and
     * authentication reasons that all look identical from a form. The failure is
     * stored so it is still visible later, when whoever debugs it is not the
     * person who pressed the button.
     */
    public function test(Request $request): JsonResponse
    {
        $to = $request->validate([
            'to' => ['required', 'email'],
        ])['to'];

        $settings = MailSetting::current();

        if (! $settings->isConfigured()) {
            return response()->json([
                'ok' => false,
                'message' => 'Save the mail settings before sending a test.',
            ], 422);
        }

        MailConfigurator::forget();
        MailConfigurator::apply();

        try {
            Mail::raw(
                "This is a test message from SignDesk.\n\n"
                . "If it reached you, signing invitations and one-time passcodes "
                . "will reach your signers too.\n\n"
                . 'Sent at ' . Carbon::now('UTC')->toDayDateTimeString() . " UTC.",
                fn ($message) => $message->to($to)->subject('SignDesk test message')
            );

            $settings->forceFill([
                'last_tested_at' => Carbon::now('UTC'),
                'last_test_ok' => true,
                'last_test_error' => null,
            ])->save();

            return response()->json([
                'ok' => true,
                'message' => "Test message sent to {$to}.",
            ]);
        } catch (Throwable $e) {
            $settings->forceFill([
                'last_tested_at' => Carbon::now('UTC'),
                'last_test_ok' => false,
                'last_test_error' => mb_substr($e->getMessage(), 0, 500),
            ])->save();

            return response()->json([
                'ok' => false,
                'message' => 'Could not send: ' . $e->getMessage(),
                'hint' => $this->hintFor($e->getMessage()),
            ], 422);
        }
    }

    /** Turns the usual SMTP failures into something actionable. */
    private function hintFor(string $error): ?string
    {
        return match (true) {
            str_contains($error, '535') || stripos($error, 'authentication') !== false =>
                'Authentication was rejected. For Gmail this almost always means an '
                . 'account password was used instead of a 16-character App Password.',
            stripos($error, 'could not connect') !== false || str_contains($error, '110') =>
                'The host and port were unreachable. Many providers block outbound '
                . 'port 25 — use 587 with TLS.',
            stripos($error, 'certificate') !== false || stripos($error, 'ssl') !== false =>
                'TLS negotiation failed. Try port 587 with TLS, or 465 with SSL.',
            default => null,
        };
    }
}
