<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('symbol');
            $table->string('dimension');
            // Amount of the dimension's base unit (gram / millilitre / item) in one of this unit.
            $table->decimal('factor_to_base', 12, 4);
            // Kitchen approximations such as "a pinch" must not be trusted for budgeting.
            $table->boolean('is_approximate')->default(false);
            $table->timestamps();

            $table->index('dimension');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
