<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            // The appliance the whole recipe is written for, as opposed to the one a
            // single step happens in. Only set when the recipe really is device
            // specific — an air fryer recipe is not an oven recipe with a shorter time.
            $table->string('appliance')->nullable()->after('total_time_minutes');

            $table->index('appliance');
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropIndex(['appliance']);
            $table->dropColumn('appliance');
        });
    }
};
