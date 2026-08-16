<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single row of runtime mail configuration.
 */
class MailSetting extends Model
{
    protected $fillable = [
        'mailer', 'host', 'port', 'username', 'password', 'encryption',
        'from_address', 'from_name', 'updated_by',
    ];

    /**
     * Never serialised. An admin editing these settings has no reason to receive
     * the existing password back, and a stray `->toJson()` anywhere else must not
     * be able to leak it either — the UI is told whether one is set, not what it is.
     */
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            // Encrypted at rest with the application key. Laravel handles the
            // round trip, so the column holds ciphertext and nothing else in the
            // codebase has to remember to encrypt it.
            'password' => 'encrypted',
            'port' => 'integer',
            'last_test_ok' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    /** The settings row, creating an empty one on first use. */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'mailer' => 'smtp',
            'encryption' => 'tls',
        ]);
    }

    /** True once there is enough here to actually send mail. */
    public function isConfigured(): bool
    {
        return filled($this->host)
            && filled($this->port)
            && filled($this->from_address);
    }

    /** Shape returned to the admin UI — deliberately without the password. */
    public function toAdminArray(): array
    {
        return [
            'mailer' => $this->mailer,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'encryption' => $this->encryption,
            'from_address' => $this->from_address,
            'from_name' => $this->from_name,
            'has_password' => filled($this->password),
            'is_configured' => $this->isConfigured(),
            'last_tested_at' => $this->last_tested_at,
            'last_test_ok' => $this->last_test_ok,
            'last_test_error' => $this->last_test_error,
        ];
    }
}
