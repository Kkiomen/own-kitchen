<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_nutrition', function (Blueprint $table): void {
            $table->id();

            /*
             * What 100 g of this product is worth, nutritionally.
             *
             * Per 100 g and never per portion, for the reason `ingredient_measures`
             * exists at all: a recipe line states an amount in whatever unit it
             * likes — 2 cebule, pół szklanki, 300 g — and only grams are common
             * ground between them. Every figure here is multiplied by the grams
             * `IngredientMeasures::toGrams()` works out, so a single number covers
             * every way the catalogue can ask for the product.
             *
             * One row per product: unlike a weight, a calorie does not depend on
             * the unit it was measured in.
             */
            $table->foreignId('ingredient_id')->unique()->constrained()->cascadeOnDelete();

            $table->decimal('kcal_per_100g', 6, 1);

            /*
             * The macros are nullable and the calories are not, deliberately.
             * Calories are what a plan is built to hit, so a row that cannot state
             * them has no business existing; protein, fat and carbohydrate are a
             * second question the same reading usually but not always answers.
             */
            $table->decimal('protein_g_per_100g', 5, 1)->nullable();
            $table->decimal('fat_g_per_100g', 5, 1)->nullable();
            $table->decimal('carbs_g_per_100g', 5, 1)->nullable();

            /*
             * Where the figure came from, so a later automated source can be told
             * apart from what was curated by hand — and so importing one can never
             * quietly overwrite the other without it being visible.
             */
            $table->string('source')->default('curated');

            /*
             * The English food term this product was looked up under.
             *
             * This is the join key we do not otherwise have. Every external
             * nutrition database (USDA FoodData Central, Open Food Facts) is
             * searched in English, and "Ser żółty" reaches none of them. Storing
             * the term used means a future importer has somewhere to start that is
             * not a fresh translation of 1 600 Polish names — and, more to the
             * point, that a human already decided which food was meant.
             *
             * It is a search term, never a fabricated identifier: an invented
             * external id would look authoritative and resolve to the wrong food.
             */
            $table->string('external_key')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_nutrition');
    }
};
