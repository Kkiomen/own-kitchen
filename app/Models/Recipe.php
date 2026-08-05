<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Appliance;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string|null $description
 * @property int|null $servings
 * @property string|null $servings_label
 * @property int|null $total_time_minutes
 * @property Appliance|null $appliance
 * @property bool $is_meal_prep
 * @property string|null $image_url
 * @property string $source_name
 * @property string|null $source_url
 * @property Carbon|null $imported_at
 * @property bool $needs_review
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, RecipeIngredient> $ingredients
 * @property-read Collection<int, RecipeStep> $steps
 * @property-read Collection<int, Tag> $tags
 */
#[Fillable([
    'slug', 'title', 'description', 'servings', 'servings_label', 'total_time_minutes', 'appliance',
    'is_meal_prep', 'image_url', 'source_name', 'source_url', 'imported_at', 'needs_review',
])]
class Recipe extends Model
{
    /**
     * @return HasMany<RecipeIngredient, $this>
     */
    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class)->orderBy('position');
    }

    /**
     * @return HasMany<RecipeStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(RecipeStep::class)->orderBy('position');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * The quick picks at the top of the list. Derived by `CategoriseRecipes`,
     * not imported — see database/data/categories.php.
     *
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /**
     * Dishes cooked ahead in a batch, for the "co biorę do pracy" view.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeMealPrep(Builder $query): void
    {
        $query->where('is_meal_prep', true);
    }

    /**
     * Recipes the parser was not fully confident about, for the review queue.
     *
     * The alternatives are wrapped in their own group: without it the `orWhere`
     * would escape and cancel out any constraint the caller already applied.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeNeedingReview(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->where('needs_review', true)
                ->orWhereHas('ingredients', fn (Builder $lines) => $lines->where('needs_review', true))
                ->orWhereHas('steps', fn (Builder $steps) => $steps->where('needs_review', true));
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'appliance' => Appliance::class,
            'is_meal_prep' => 'boolean',
            'imported_at' => 'datetime',
            'needs_review' => 'boolean',
        ];
    }
}
