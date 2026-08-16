<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SignatureField extends Model
{
    use HasFactory;

    public const TYPE_SIGNATURE = 'signature';
    public const TYPE_INITIAL = 'initial';
    public const TYPE_DATE = 'date';
    public const TYPE_TEXT = 'text';
    public const TYPE_CHECKBOX = 'checkbox';

    protected $fillable = [
        'envelope_id', 'recipient_id', 'type', 'page', 'x', 'y', 'w', 'h', 'required',
    ];

    protected function casts(): array
    {
        return [
            // Cast to float so the JSON the SPA receives carries numbers, not
            // the decimal strings Postgres returns.
            'x' => 'float',
            'y' => 'float',
            'w' => 'float',
            'h' => 'float',
            'required' => 'boolean',
        ];
    }

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }

    public function value(): HasOne
    {
        return $this->hasOne(FieldValue::class);
    }

    public function expectsImage(): bool
    {
        return in_array($this->type, [self::TYPE_SIGNATURE, self::TYPE_INITIAL], true);
    }
}
