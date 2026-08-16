<?php

namespace App\Services;

use App\Models\MailSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Layers the database mail settings over the configured defaults.
 *
 * Runs on every boot, including inside the queue worker — which is where mail is
 * actually sent, so it has to apply there too rather than only on web requests.
 *
 * Settings are cached because this would otherwise be a database read on every
 * single request just to send the occasional email.
 */
class MailConfigurator
{
    private const CACHE_KEY = 'mail-settings';

    public static function apply(): void
    {
        $settings = self::cached();

        if ($settings === null) {
            return;
        }

        $mailer = $settings['mailer'] ?: 'smtp';

        config([
            "mail.mailers.{$mailer}.transport" => 'smtp',
            "mail.mailers.{$mailer}.host" => $settings['host'],
            "mail.mailers.{$mailer}.port" => $settings['port'],
            "mail.mailers.{$mailer}.username" => $settings['username'],
            "mail.mailers.{$mailer}.password" => $settings['password'],
            // Symfony's transport wants null rather than an empty string when
            // there is no encryption, or it tries to negotiate one anyway.
            "mail.mailers.{$mailer}.encryption" => $settings['encryption'] ?: null,
            'mail.default' => $mailer,
            'mail.from.address' => $settings['from_address'],
            'mail.from.name' => $settings['from_name'],
        ]);

        // The mail manager memoises resolved mailers, so a mailer built before
        // this ran would keep the old credentials for the rest of the process.
        Mail::purge($mailer);
    }

    /** Clears the cache so the next boot picks up an edit immediately. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function cached(): ?array
    {
        try {
            return Cache::remember(self::CACHE_KEY, now()->addMinutes(10), function () {
                // Guarded because this runs during boot, including on a database
                // that has not been migrated yet — `artisan migrate` on a fresh
                // install would otherwise fail before it could create the table.
                if (! Schema::hasTable('mail_settings')) {
                    return null;
                }

                $settings = MailSetting::query()->first();

                if (! $settings || ! $settings->isConfigured()) {
                    return null;
                }

                return [
                    'mailer' => $settings->mailer,
                    'host' => $settings->host,
                    'port' => $settings->port,
                    'username' => $settings->username,
                    'password' => $settings->password,
                    'encryption' => $settings->encryption,
                    'from_address' => $settings->from_address,
                    'from_name' => $settings->from_name,
                ];
            });
        } catch (Throwable) {
            // A missing database or unreachable cache must not take the whole
            // application down — fall back to whatever .env provides.
            return null;
        }
    }
}
