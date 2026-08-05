<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Measurement\Quantity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a recipe's ingredient list, already split into a quantity, a unit and
 * a canonical ingredient. `raw_text` keeps the original wording so an improved
 * parser can be re-run without fetching the source page again.
 *
 * @property int $id
 * @property int $recipe_id
 * @property int|null $ingredient_id
 * @property int|null $unit_id
 * @property float|null $quantity
 * @property float|null $quantity_max
 * @property string|null $note
 * @property string|null $section
 * @property string $raw_text
 * @property int $position
 * @property bool $is_optional
 * @property bool $needs_review
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Recipe $recipe
 * @property-read Ingredient|null $ingredient
 * @property-read Unit|null $unit
 */
#[Fillable([
    'recipe_id', 'ingredient_id', 'unit_id', 'quantity', 'quantity_max', 'note',
    'section', 'raw_text', 'position', 'is_optional', 'needs_review',
])]
class RecipeIngredient extends Model
{
    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

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
     * Null when the line carries no measurable amount, e.g. "freshly ground pepper".
     */
    public function toQuantity(): ?Quantity
    {
        if ($this->quantity === null || $this->unit === null) {
            return null;
        }

        return $this->unit->quantity($this->quantity);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'quantity_max' => 'float',
            'is_optional' => 'boolean',
            'needs_review' => 'boolean',
        ];
    }
}
