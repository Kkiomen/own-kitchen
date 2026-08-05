<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IngredientCategory;
use App\Enums\IngredientSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The canonical product. Every recipe line, pantry entry and shopping list item
 * points here, which is what keeps the same thing from existing twice.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property IngredientCategory $category
 * @property IngredientSource $source
 * @property int|null $default_unit_id
 * @property float|null $density_g_per_ml
 * @property bool $is_staple
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Unit|null $defaultUnit
 * @property-read Collection<int, IngredientAlias> $aliases
 * @property-read Collection<int, IngredientMeasure> $measures
 */
#[Fillable(['slug', 'name', 'category', 'source', 'default_unit_id', 'density_g_per_ml', 'is_staple'])]
class Ingredient extends Model
{
    /**
     * Products nobody has vouched for: invented by the importer from a line it
     * did not recognise. This is the review queue, and it does not shrink just
     * because a later import found the alias the importer itself wrote.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAwaitingCuration(Builder $query): void
    {
        $query->where('source', IngredientSource::Import);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function defaultUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'default_unit_id');
    }

    /**
     * @return HasMany<IngredientAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(IngredientAlias::class);
    }

    /**
     * What a piece, a clove or a spoonful of this weighs.
     *
     * @return HasMany<IngredientMeasure, $this>
     */
    public function measures(): HasMany
    {
        return $this->hasMany(IngredientMeasure::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => IngredientCategory::class,
            'source' => IngredientSource::class,
            'density_g_per_ml' => 'float',
            'is_staple' => 'boolean',
        ];
    }
}
