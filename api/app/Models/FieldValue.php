<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldValue extends Model
{
    use HasFactory;

    protected $fillable = ['signature_field_id', 'text_value', 'signature_asset_id'];

    public function field(): BelongsTo
    {
        return $this->belongsTo(SignatureField::class, 'signature_field_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(SignatureAsset::class, 'signature_asset_id');
    }
}
