<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UnitDimension;
use App\Support\Measurement\Quantity;
use App\Support\Measurement\UnitDefinition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $symbol
 * @property UnitDimension $dimension
 * @property float $factor_to_base
 * @property bool $is_approximate
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Ingredient> $ingredients
 */
#[Fillable(['code', 'name', 'symbol', 'dimension', 'factor_to_base', 'is_approximate'])]
class Unit extends Model
{
    /**
     * @return HasMany<Ingredient, $this>
     */
    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class, 'default_unit_id');
    }

    /**
     * Hand the measurement logic a persistence-free view of this unit.
     */
    public function definition(): UnitDefinition
    {
        return new UnitDefinition(
            code: $this->code,
            dimension: $this->dimension,
            factorToBase: $this->factor_to_base,
            isApproximate: $this->is_approximate,
        );
    }

    public function quantity(float $amount): Quantity
    {
        return new Quantity($amount, $this->definition());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dimension' => UnitDimension::class,
            'factor_to_base' => 'float',
            'is_approximate' => 'boolean',
        ];
    }
}
