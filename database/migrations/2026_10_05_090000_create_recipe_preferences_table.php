<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Lubimy to" and "nie proponuj więcej" — the household's own taste, which no
 * source states and no rule can derive.
 *
 * Whether a dish looks good enough to cook is decided by looking at it, and the
 * planner had no way to hear that: a week it generated was judged on its photos
 * and found dull, with nothing to learn from. One row per (account, recipe),
 * because a recipe is liked or not — two rows saying both would leave the
 * planner to guess which one is current.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();

            // See App\Enums\RecipeVerdict.
            $table->string('verdict', 8);
            $table->timestamps();

            $table->unique(['user_id', 'recipe_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_preferences');
    }
};
