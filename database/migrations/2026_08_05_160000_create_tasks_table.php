<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Zrób coś", "podjedź gdzieś" — the things asked for over the day that are
 * otherwise remembered in a Messenger thread nobody scrolls back through.
 *
 * Scoped to the household like everything else. There is no `created_by`: two
 * phones share one account, so the app genuinely cannot tell who wrote a task
 * down, and a column claiming otherwise would be a guess on every row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);

            // Who it is for. See App\Enums\Assignee for why this is not a user.
            $table->string('assignee', 8);

            // A date, never a time: "kupić chleb" is for a day, and asking for
            // an hour would make writing one down a form rather than a line.
            $table->date('due_on')->nullable();

            /*
             * Kept rather than deleted when ticked, so "co dziś zrobiliśmy" is
             * answerable and a mistaken tap is one tap back — the same reason
             * a shopping line keeps `bought_at`.
             */
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            // Every screen asks the same question: what is still open, per
            // household.
            $table->index(['user_id', 'done_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
