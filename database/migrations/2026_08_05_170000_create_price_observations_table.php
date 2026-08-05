<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What one source said one product cost on one day.
     *
     * Append-only, and that is the whole reason this table exists rather than a
     * `current_price` column on `ingredients`. A single column would be
     * overwritten by whichever source ran last, so a leaflet's cut-price butter
     * would erase the national average and the estimate would swing by half
     * every time the crawler ran. A median over the rows here does not care what
     * order the sources arrived in.
     *
     * `promotions` cannot serve this purpose even though it holds prices: it is
     * a picture of *this week*, pruned when an offer stops running, so it has no
     * memory at all. An observation written from a promotion outlives it.
     */
    public function up(): void
    {
        Schema::create('price_observations', function (Blueprint $table): void {
            $table->id();

            // Which reader this came from ('gus', 'leaflets'), and its own id for
            // the reading. Together with the day they are the identity: re-running
            // a month later adds a row, re-running the same day updates one.
            $table->string('source');
            $table->string('external_id');

            /*
             * Exactly as the source wrote it — "mąka pszenna - za 1kg", "Masło
             * Łaciate 200 g". Kept for the same reason recipe lines keep
             * `raw_text` and promotions keep `title`: it is what a wrong match is
             * diagnosed from, and improving the matcher is then a re-run rather
             * than a re-fetch.
             */
            $table->string('title');

            /*
             * Null means "we could not honestly say which product this is". Never
             * filled by inventing one: an invented ingredient owns an alias for
             * good and would poison recipe import for every line containing the
             * same word. See App\Offers — this is the same rule, same reason.
             */
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('needs_review')->default(true);

            // Which chain, when the reading is one chain's price. Null for a
            // national average, which belongs to no shop.
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();

            // Grosze, never a float. See App\Support\Money\Money.
            $table->unsignedInteger('price_minor');

            // What that price buys. Both null together — a size without a unit
            // cannot be compared with anything.
            $table->decimal('pack_quantity', 10, 3)->nullable();
            $table->foreignId('pack_unit_id')->nullable()->constrained('units')->nullOnDelete();

            /*
             * Price per kilo, litre or piece: the only figure two readings of the
             * same product can be averaged over. An observation without one is
             * still stored — it is evidence the source mentioned the product —
             * but it can never enter an estimate, and the coverage report counts
             * it as a gap rather than as coverage.
             */
            $table->unsignedInteger('unit_price_minor')->nullable();
            $table->string('unit_price_per')->nullable();

            /*
             * The day the price held. A date rather than a timestamp because no
             * source is more precise than that, and because "the newest reading
             * per product per source" is a question about days.
             */
            $table->date('observed_on');

            $table->timestamps();

            $table->unique(['source', 'external_id', 'observed_on']);

            // The estimate's one hot query: recent unit prices for the products
            // on a list.
            $table->index(['ingredient_id', 'observed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_observations');
    }
};
