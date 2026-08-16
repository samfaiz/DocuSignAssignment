<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SealedDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'envelope_id', 'storage_key', 'sha256_stamped', 'sha256_sealed',
        'pades_level', 'tsa_url', 'certificate_subject', 'certificate_serial',
        'page_count', 'warnings', 'sealed_at',
    ];

    protected function casts(): array
    {
        return [
            'warnings' => 'array',
            'sealed_at' => 'datetime',
        ];
    }

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /** True when the seal reached the level we asked for, with a timestamp. */
    public function isFullyQualified(): bool
    {
        return $this->pades_level === 'PAdES-B-LTA' && empty($this->warnings);
    }
}
