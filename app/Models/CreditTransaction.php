<?php

namespace App\Models;

use App\Enums\CreditTransactionType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only ledger row. Rows are never updated or deleted.
 */
class CreditTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'generation_id',
        'type',
        'amount_nanos',
        'balance_after_nanos',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'type' => CreditTransactionType::class,
            'amount_nanos' => 'integer',
            'balance_after_nanos' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    public function toFrontend(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'amount' => Money::format($this->amount_nanos),
            'amount_nanos' => $this->amount_nanos,
            'balance_after' => Money::format($this->balance_after_nanos),
            'description' => $this->description,
            'generation_id' => $this->generation_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
