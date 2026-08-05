<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UnitDimension;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        /** @var list<array{code: string, name: string, symbol: string, dimension: UnitDimension, factor: float|int, approximate?: bool}> $units */
        $units = require database_path('data/units.php');

        foreach ($units as $unit) {
            Unit::query()->updateOrCreate(
                ['code' => $unit['code']],
                [
                    'name' => $unit['name'],
                    'symbol' => $unit['symbol'],
                    'dimension' => $unit['dimension'],
                    'factor_to_base' => $unit['factor'],
                    'is_approximate' => $unit['approximate'] ?? false,
                ],
            );
        }
    }
}
