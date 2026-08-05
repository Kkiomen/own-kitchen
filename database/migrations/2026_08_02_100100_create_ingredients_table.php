<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table): void {
            $table->id();
            // Normalised, diacritics-free key. The deduplication anchor.
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('category');
            // Curated vs invented by the importer. Drives the review queue, and
            // must survive re-imports so quality cannot silently "improve".
            $table->string('source')->default('import');
            $table->foreignId('default_unit_id')->nullable()->constrained('units')->nullOnDelete();
            // Enables mass <-> volume conversion (e.g. "1 glass of flour" -> grams).
            $table->decimal('density_g_per_ml', 8, 4)->nullable();
            // Salt, pepper, oil: assumed always at hand, so they never block a recipe suggestion.
            $table->boolean('is_staple')->default(false);
            $table->timestamps();

            $table->index('category');
            $table->index('source');
        });

        Schema::create('ingredient_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            // Normalised inflected form as found in recipe text, e.g. "makaronu spaghetti".
            $table->string('alias')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_aliases');
        Schema::dropIfExists('ingredients');
    }
};
