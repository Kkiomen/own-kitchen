<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Appliance;
use App\Enums\StepAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * One screen of the guided-cooking flow: an instruction, the tool it happens in,
 * its temperature and duration, and the exact ingredient amounts it consumes.
 *
 * @property int $id
 * @property int $recipe_id
 * @property int $position
 * @property string|null $section
 * @property string $instruction
 * @property string $raw_text
 * @property StepAction|null $action
 * @property Appliance|null $appliance
 * @property int|null $temperature_celsius
 * @property int|null $duration_seconds
 * @property bool $needs_review
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Recipe $recipe
 * @property-read Collection<int, RecipeIngredient> $ingredients
 */
#[Fillable([
    'recipe_id', 'position', 'section', 'instruction', 'raw_text', 'action',
    'appliance', 'temperature_celsius', 'duration_seconds', 'needs_review',
])]
class RecipeStep extends Model
{
    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * The lines this step uses, carrying the amount to show on its screen.
     *
     * @return BelongsToMany<RecipeIngredient, $this>
     */
    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(RecipeIngredient::class, 'recipe_step_ingredients')
            ->withPivot(['quantity', 'portion_note', 'position'])
            ->orderBy('recipe_step_ingredients.position');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => StepAction::class,
            'appliance' => Appliance::class,
            'needs_review' => 'boolean',
        ];
    }
}
