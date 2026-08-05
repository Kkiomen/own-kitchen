<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_measures', function (Blueprint $table): void {
            $table->id();

            /*
             * What one of this measure of this product weighs.
             *
             * The pair is the point: a weight cannot hang off the unit, because a
             * ząbek of garlic is 5 g and a ząbek is nothing on its own; and it
             * cannot hang off the product, because one garlic bulb is 45 g and one
             * of its cloves is 5 g. Only (product, unit) together name a weight.
             */
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->decimal('grams', 10, 3);
            $table->timestamps();

            // One weight per pair. Two would make every comparison depend on which
            // row happened to be read first.
            $table->unique(['ingredient_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_measures');
    }
};
