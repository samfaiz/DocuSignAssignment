<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consent extends Model
{
    use HasFactory;

    protected $fillable = [
        'recipient_id', 'disclosure_version', 'disclosure_sha256',
        'accepted_at', 'ip', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }
}
