<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            // Which aggregator this came from, and its own id for the offer.
            // Together they are the identity: re-crawling must update the row it
            // wrote last week, not stack a second copy of the same offer.
            $table->string('source');
            $table->string('external_id');

            // Exactly as the leaflet wrote it, brand and all. Kept for the same
            // reason recipe lines keep raw_text: it is what a wrong match is
            // diagnosed from, and improving the matcher is then a re-run rather
            // than a re-crawl.
            $table->string('title');

            /*
             * Null means "we could not honestly say which product this is" — a
             * lawnmower, a loyalty coupon, or a food we have no name for. It is
             * never filled by inventing a product: an invented ingredient owns an
             * alias for good and would poison recipe import for every line that
             * happens to contain the same word.
             */
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('needs_review')->default(true);

            // Grosze, never a float. See App\Support\Money\Money.
            $table->unsignedInteger('price_minor');
            $table->unsignedInteger('regular_price_minor')->nullable();
            $table->unsignedTinyInteger('discount_percent')->nullable();

            // Parsed out of the title when it is there ("Masło Łaciate 200 g").
            // Both null together — a size without a unit cannot be compared.
            $table->decimal('pack_quantity', 10, 3)->nullable();
            $table->foreignId('pack_unit_id')->nullable()->constrained('units')->nullOnDelete();

            /*
             * Price per kilo, litre or piece: the only figure two offers for the
             * same product can be ranked on. Derived from the two columns above,
             * stored rather than computed on read because the plan sorts on it.
             */
            $table->unsignedInteger('unit_price_minor')->nullable();
            $table->string('unit_price_per')->nullable();

            /*
             * The listing states how long an offer still runs ("4 dni"), not the
             * date it ends, so this is that duration added to the day we read the
             * page. Approximate by construction: it is used to drop stale offers,
             * never to promise the till price on a given day.
             */
            $table->date('valid_to')->nullable();

            $table->string('url');
            $table->string('image_url')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_id']);

            // The plan's one hot query: active offers for the products on a list.
            $table->index(['ingredient_id', 'valid_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
