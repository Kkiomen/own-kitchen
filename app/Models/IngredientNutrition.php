<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What 100 g of one product is worth in calories, and in the three macros.
 *
 * @property int $id
 * @property int $ingredient_id
 * @property float $kcal_per_100g
 * @property float|null $protein_g_per_100g
 * @property float|null $fat_g_per_100g
 * @property float|null $carbs_g_per_100g
 * @property string $source
 * @property string|null $external_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Ingredient $ingredient
 */
#[Fillable([
    'ingredient_id',
    'kcal_per_100g',
    'protein_g_per_100g',
    'fat_g_per_100g',
    'carbs_g_per_100g',
    'source',
    'external_key',
])]
class IngredientNutrition extends Model
{
    protected $table = 'ingredient_nutrition';

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kcal_per_100g' => 'float',
            'protein_g_per_100g' => 'float',
            'fat_g_per_100g' => 'float',
            'carbs_g_per_100g' => 'float',
        ];
    }
}
