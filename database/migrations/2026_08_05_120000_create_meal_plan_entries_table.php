<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plan_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->date('date');
            $table->string('slot');

            /*
             * A recipe or a note, never both and never neither. "Kanapki" and
             * "obiad u rodziców" are real entries in a week; they simply have no
             * ingredients, so the shopping list passes over them.
             */
            $table->foreignId('recipe_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('note')->nullable();

            /*
             * How many portions this meal is for. It is what turns a plan into a
             * shopping list: a recipe written for 4 that two people eat twice is
             * the same recipe cooked once, and a recipe written for 2 planned for
             * six portions has to buy three times over.
             */
            $table->unsignedSmallInteger('servings')->nullable();

            // Several dishes can share one slot — a soup and a salad at dinner.
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            // Every read is "this account's week", so the index matches it.
            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_plan_entries');
    }
};
