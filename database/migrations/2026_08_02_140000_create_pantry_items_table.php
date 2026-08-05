<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pantry_items', function (Blueprint $table): void {
            $table->id();
            /*
             * The kitchen belongs to an account, not a person: the couple share
             * one account and therefore one fridge, which is the whole point.
             */
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('location');

            /*
             * Both nullable, and that is deliberate. "I have salt" is a useful
             * statement on its own; forcing an amount would make people either
             * lie or give up on keeping the list current.
             */
            $table->decimal('quantity', 10, 3)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();

            $table->date('expires_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            /*
             * One row per product per place: the same cheese in the fridge and in
             * the freezer is two entries, but two "cheese" rows in the fridge is
             * a duplicate that would break every amount comparison.
             */
            $table->unique(['user_id', 'ingredient_id', 'location']);
            $table->index(['user_id', 'location']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pantry_items');
    }
};
