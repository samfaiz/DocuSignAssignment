<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignatureAsset extends Model
{
    use HasFactory;

    /** Drawn on a canvas, uploaded as an image, or typed in a script font. */
    public const KIND_DRAWN = 'drawn';
    public const KIND_UPLOADED = 'uploaded';
    public const KIND_TYPED = 'typed';

    protected $fillable = [
        'recipient_id', 'kind', 'storage_key', 'sha256',
        'font_family', 'width', 'height',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }
}
