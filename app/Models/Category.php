<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A quick pick at the top of the list: chicken, soup, pasta. Derived from the
 * recipes themselves rather than imported — see database/data/categories.php.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $icon
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Recipe> $recipes
 */
#[Fillable(['slug', 'name', 'icon', 'position'])]
class Category extends Model
{
    /**
     * @return BelongsToMany<Recipe, $this>
     */
    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class);
    }
}
