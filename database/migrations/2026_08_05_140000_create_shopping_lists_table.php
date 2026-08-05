<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Several lists per household — the weekly shop, the trip to the Asian grocer,
 * the barbecue — instead of one that mixes all three.
 *
 * The list becomes the owner of a line, so `shopping_list_items.user_id` goes:
 * a list already knows whose it is, and two columns saying so is the sort of
 * duplication this database exists to avoid. The unique key moves with it —
 * one product is still one line, but *per list*, because the same butter can
 * honestly belong to two different trips.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            /*
             * Exactly one per household, and it cannot be deleted: every screen
             * that writes a line down needs somewhere to put it without asking.
             */
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            // Two lists called "Biedronka" would be indistinguishable on screen.
            $table->unique(['user_id', 'name']);
        });

        // Everybody gets the main list, including accounts with nothing on it
        // yet: the app assumes there is always one to write to.
        $now = now();

        foreach (DB::table('users')->pluck('id') as $userId) {
            DB::table('shopping_lists')->insert([
                'user_id' => $userId,
                'name' => 'Lista główna',
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('shopping_list_items', function (Blueprint $table): void {
            $table->foreignId('shopping_list_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();
        });

        // What was on the one list is what is now on the main one.
        DB::table('shopping_list_items')->update([
            'shopping_list_id' => DB::raw(
                '(select id from shopping_lists where shopping_lists.user_id = shopping_list_items.user_id)'
            ),
        ]);

        Schema::table('shopping_list_items', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'ingredient_id']);
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
            $table->unique(['shopping_list_id', 'ingredient_id']);

            // Only nullable long enough to be filled in: a line on no list at
            // all would disappear from every screen while still being there.
            $table->foreignId('shopping_list_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('shopping_list_items', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();
        });

        DB::table('shopping_list_items')->update([
            'user_id' => DB::raw(
                '(select user_id from shopping_lists where shopping_lists.id = shopping_list_items.shopping_list_id)'
            ),
        ]);

        Schema::table('shopping_list_items', function (Blueprint $table): void {
            $table->dropUnique(['shopping_list_id', 'ingredient_id']);
            $table->dropForeign(['shopping_list_id']);
            $table->dropColumn('shopping_list_id');
            $table->unique(['user_id', 'ingredient_id']);
        });

        Schema::dropIfExists('shopping_lists');
    }
};
