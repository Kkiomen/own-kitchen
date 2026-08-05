<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An inflected or colloquial spelling that maps onto a canonical ingredient.
 * Polish declension means "makaron", "makaronu" and "makaronem" all arrive from
 * recipe text and must resolve to one row.
 *
 * @property int $id
 * @property int $ingredient_id
 * @property string $alias
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Ingredient $ingredient
 */
#[Fillable(['ingredient_id', 'alias'])]
class IngredientAlias extends Model
{
    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
