<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecipeVerdict;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One household's verdict on one recipe.
 *
 * @property int $id
 * @property int $user_id
 * @property int $recipe_id
 * @property RecipeVerdict $verdict
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Recipe $recipe
 */
#[Fillable(['user_id', 'recipe_id', 'verdict'])]
class RecipePreference extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOf(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verdict' => RecipeVerdict::class,
        ];
    }
}
