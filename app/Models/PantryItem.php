<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StorageLocation;
use App\Support\Measurement\Quantity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One product in one place in one kitchen.
 *
 * @property int $id
 * @property int $user_id
 * @property int $ingredient_id
 * @property StorageLocation $location
 * @property float|null $quantity
 * @property int|null $unit_id
 * @property Carbon|null $expires_at
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Ingredient $ingredient
 * @property-read Unit|null $unit
 * @property-read User $user
 */
#[Fillable(['user_id', 'ingredient_id', 'location', 'quantity', 'unit_id', 'expires_at', 'note'])]
class PantryItem extends Model
{
    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeExpiringWithin(Builder $query, int $days): void
    {
        $query->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', now()->addDays($days));
    }

    /**
     * Null when the amount was never given — "I have salt" without a number.
     * Callers must treat that as "enough", not as "none".
     */
    public function toQuantity(): ?Quantity
    {
        if ($this->quantity === null || $this->unit === null) {
            return null;
        }

        return $this->unit->quantity($this->quantity);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'location' => StorageLocation::class,
            'quantity' => 'float',
            'expires_at' => 'date',
        ];
    }
}
