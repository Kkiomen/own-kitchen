<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('servings')->nullable();
            $table->string('servings_label')->nullable();
            $table->unsignedSmallInteger('total_time_minutes')->nullable();
            $table->string('image_url')->nullable();
            $table->string('source_name');
            $table->string('source_url')->nullable()->unique();
            $table->timestamp('imported_at')->nullable();
            // Set when the parser was unsure; lets us re-run an improved parser over the raw text.
            $table->boolean('needs_review')->default(false);
            $table->timestamps();
        });

        Schema::create('recipe_ingredients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 10, 3)->nullable();
            // Upper bound of ranges such as "1 - 2 tablespoons".
            $table->decimal('quantity_max', 10, 3)->nullable();
            // Preparation hint that belongs to the line, e.g. "finely chopped".
            $table->string('note')->nullable();
            // Multi-part recipes: "Base", "Cheese filling", "Topping".
            $table->string('section')->nullable();
            // Verbatim source line. Never discarded, so parsing stays re-runnable.
            $table->string('raw_text');
            $table->unsignedSmallInteger('position');
            $table->boolean('is_optional')->default(false);
            $table->boolean('needs_review')->default(false);
            $table->timestamps();

            $table->index(['recipe_id', 'position']);
        });

        Schema::create('recipe_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('section')->nullable();
            $table->text('instruction');
            $table->text('raw_text');
            // Drives the guided-cooking screen: the verb and the icon to show.
            $table->string('action')->nullable();
            $table->string('appliance')->nullable();
            $table->unsignedSmallInteger('temperature_celsius')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->timestamps();

            $table->index(['recipe_id', 'position']);
        });

        // "ADD 150 g of apples" on a step screen: which lines this step consumes.
        Schema::create('recipe_step_ingredients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_step_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_ingredient_id')->constrained()->cascadeOnDelete();
            // Null means "whatever is left of that line"; set for partial uses like "2/3 of the cheese".
            $table->decimal('quantity', 10, 3)->nullable();
            $table->string('portion_note')->nullable();
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['recipe_step_id', 'recipe_ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_step_ingredients');
        Schema::dropIfExists('recipe_steps');
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('recipes');
    }
};
