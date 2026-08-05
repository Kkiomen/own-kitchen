<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_meal_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();

            /*
             * A `MealSlot` value rather than a foreign key: the meals of a day
             * are a closed vocabulary in code, not rows anybody edits.
             */
            $table->string('slot');
            $table->timestamps();

            $table->unique(['recipe_id', 'slot']);
            // Every read is "give me the dinner dishes", so the index matches it.
            $table->index('slot');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_meal_slots');
    }
};
