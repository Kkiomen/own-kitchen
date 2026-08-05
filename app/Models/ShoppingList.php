<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One shopping trip's worth of list: the weekly shop, the Asian grocer, the
 * barbecue. A household has as many as it finds useful and always at least one.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ShoppingListItem> $items
 * @property-read User $user
 */
#[Fillable(['user_id', 'name', 'is_default'])]
class ShoppingList extends Model
{
    /**
     * The one every screen can write to without asking which.
     *
     * Created on demand rather than by the seeder: a seeded list would belong to
     * a seeded account, and `DatabaseSeeder` deliberately creates no user.
     */
    public static function defaultFor(User $user): self
    {
        return static::query()->firstOrCreate(
            ['user_id' => $user->id, 'is_default' => true],
            ['name' => 'Lista główna'],
        );
    }

    /**
     * @return HasMany<ShoppingListItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ShoppingListItem::class);
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
    public function scopeOf(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }

    /**
     * The main list is never deleted: everything that writes a line down needs
     * somewhere to put it, and an account with no list at all would break the
     * screen that was meant to create one.
     */
    public function isDeletable(): bool
    {
        return ! $this->is_default;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }
}
