<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one of a measure of one product weighs: a ząbek of garlic, a pęczek of
 * parsley, a łyżka of flour.
 *
 * @property int $id
 * @property int $ingredient_id
 * @property int $unit_id
 * @property float $grams
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Ingredient $ingredient
 * @property-read Unit $unit
 */
#[Fillable(['ingredient_id', 'unit_id', 'grams'])]
class IngredientMeasure extends Model
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['grams' => 'float'];
    }
}
