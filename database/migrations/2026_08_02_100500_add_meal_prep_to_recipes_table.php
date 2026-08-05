<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            // Cooked ahead in a batch and eaten over the following days — a lunchbox
            // dish rather than something served straight off the pan. Like `appliance`
            // this is a fact the source adapter states, not a guess from the text, so
            // the filter can be trusted; a free-text tag from the site could not be.
            $table->boolean('is_meal_prep')->default(false)->after('appliance');

            $table->index('is_meal_prep');
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropIndex(['is_meal_prep']);
            $table->dropColumn('is_meal_prep');
        });
    }
};
