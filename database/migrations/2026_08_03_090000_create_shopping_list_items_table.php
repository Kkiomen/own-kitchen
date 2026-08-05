<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_list_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();

            // Both nullable together: "kup masło" without a number is a valid line.
            $table->decimal('quantity', 10, 3)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();

            // Kept rather than deleted on ticking, so the trolley can be untangled
            // at the till and the whole list cleared in one go afterwards.
            $table->timestamp('bought_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            // One product is one line, however many recipes asked for it.
            $table->unique(['user_id', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_list_items');
    }
};
