<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which chains this household actually drives to.
     *
     * Scoped to the account like everything else somebody owns, even though there
     * is one household here: the plan is a trip *they* make, and a global list
     * would be a setting rather than a choice.
     *
     * An empty selection is not "no shops" — it is "not chosen yet", and the
     * planner reads it as every chain. There is deliberately no `enabled` column
     * saying the same thing twice: a row here means "I go there", its absence
     * means "I do not", and the difference between an empty table and a full one
     * is answered by counting rather than by a flag that could disagree with it.
     */
    public function up(): void
    {
        Schema::create('shop_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Ticking a chain twice is the same statement, not a second one.
            $table->unique(['user_id', 'shop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_user');
    }
};
