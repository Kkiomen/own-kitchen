<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MealSlot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One meal on one day: a recipe (usually) or a note, and how many portions of it.
 *
 * Scoped to the account like the kitchen and the shopping list, so a household's
 * week is its own.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $date
 * @property MealSlot $slot
 * @property int|null $recipe_id
 * @property string|null $note
 * @property int|null $servings
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Recipe|null $recipe
 * @property-read User $user
 */
#[Fillable(['user_id', 'date', 'slot', 'recipe_id', 'note', 'servings', 'position'])]
class MealPlanEntry extends Model
{
    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The days named, as `Y-m-d`.
     *
     * The dates go through `fromDateTime()` rather than straight into the query
     * because a `date` cast is still stored as a full timestamp — "2026-08-10"
     * on its own matches nothing, and matches it *silently*: an empty week reads
     * exactly like a week nobody planned.
     *
     * @param  Builder<$this>  $query
     * @param  list<string>  $dates
     */
    public function scopeOnDates(Builder $query, array $dates): void
    {
        $query->whereIn('date', array_map($this->fromDateTime(...), $dates));
    }

    /**
     * A note is a plan too — it just cannot be shopped for.
     */
    public function isNote(): bool
    {
        return $this->recipe_id === null;
    }

    public function label(): string
    {
        return $this->recipe === null ? (string) $this->note : $this->recipe->title;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'slot' => MealSlot::class,
        ];
    }
}
