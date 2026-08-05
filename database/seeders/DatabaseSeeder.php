<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seeds reference data only.
     *
     * Deliberately creates no user. Sign-up closes as soon as an account exists,
     * so a seeded test account would lock the real owner out of the form — and a
     * factory password on a deployed app is a way in for anyone who reads this
     * file.
     */
    public function run(): void
    {
        $this->call([
            UnitSeeder::class,
            IngredientSeeder::class,
            // After the dictionary: it can only attach weights to products the
            // dictionary has already created.
            IngredientMeasureSeeder::class,
            ShopSeeder::class,
        ]);
    }
}
