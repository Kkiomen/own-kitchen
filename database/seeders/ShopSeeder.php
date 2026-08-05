<?php

namespace Database\Seeders;

use App\Offers\ShopDirectory;
use Illuminate\Database\Seeder;

/**
 * The chains we read leaflets from, so a fresh database can render the plan
 * screen before any crawling has happened.
 *
 * Reference data, exactly like units and ingredients: no promotions, no prices,
 * nothing that goes stale.
 */
class ShopSeeder extends Seeder
{
    public function __construct(private readonly ShopDirectory $shops) {}

    public function run(): void
    {
        /** @var array<string, string> $configured */
        $configured = config('offers.shops', []);

        foreach (array_keys($configured) as $slug) {
            $this->shops->for($slug);
        }
    }
}
