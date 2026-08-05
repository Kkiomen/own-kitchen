<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table): void {
            $table->id();

            // The shop's own name, and the key the leaflet source knows it by.
            // They match today because there is one source; they are separate
            // columns so a second source can map its own slug onto the same shop
            // rather than creating a duplicate Biedronka.
            $table->string('slug')->unique();
            $table->string('name');

            // Drives the order stops appear in a plan. Not alphabetical: the
            // shops you actually pass are the ones worth listing first.
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shops');
    }
};
