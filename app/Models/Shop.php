<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A shop chain we read leaflets from. One row per chain, not per branch: a
 * promotion in a national leaflet holds in every branch of it, and a per-branch
 * table would multiply the catalogue by a thousand to say the same thing.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Promotion> $promotions
 * @property-read Collection<int, User> $shoppers
 */
#[Fillable(['slug', 'name', 'position'])]
class Shop extends Model
{
    /**
     * @return HasMany<Promotion, $this>
     */
    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }

    /**
     * The accounts that say they drive here.
     *
     * @return BelongsToMany<User, $this>
     */
    public function shoppers(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeInWalkingOrder(Builder $query): void
    {
        $query->orderBy('position')->orderBy('name');
    }
}
