<?php

declare(strict_types=1);

namespace App\Planning;

use App\Enums\UnitDimension;
use App\Models\Promotion;
use App\Pricing\IngredientCost;
use App\Support\Measurement\IngredientMeasures;
use App\Support\Measurement\Quantity;
use App\Support\Money\Money;

/**
 * What one shop's leaflet offers this week, one best offer per product.
 *
 * The thing a week is planned *around* when somebody says "jadę do Biedronki".
 * One shop, never several: the household drives to one, and a plan built from
 * the cheapest butter in one chain and the cheapest chicken in another is a plan
 * for two trips that nobody asked for. Choosing *which* shop is
 * `CheapestShopPlan`'s job.
 *
 * **An offer is an option, never an obligation.** The first live comparison
 * priced every offered product at the offer, and a shop with more products in
 * its leaflet came out *dearer* — Netto at 332 zł against 133 in Kaufland for
 * the same week — because a leaflet also discounts the premium salmon and the
 * six-pack. Somebody standing at the shelf picks up whichever is cheaper, so
 * that is what `quote()` does, and only an offer that actually wins counts as
 * "z promocji".
 *
 * Everything is priced by the amount, the way `RecipeFacts` and `WeekCost`
 * price the rest of a week. Charging whole packs for the offered products only
 * would make them look dear next to everything else, which is pro-rated.
 */
final readonly class ShopOffers
{
    /**
     * @param  array<int, Promotion>  $best  ingredient id => the offer the ranking puts first
     */
    public function __construct(
        public int $shopId,
        public string $shopName,
        private array $best,
    ) {}

    public function has(int $ingredientId): bool
    {
        return isset($this->best[$ingredientId]);
    }

    public function offerFor(int $ingredientId): ?Promotion
    {
        return $this->best[$ingredientId] ?? null;
    }

    /** @return list<int> */
    public function ingredientIds(): array
    {
        return array_keys($this->best);
    }

    public function isEmpty(): bool
    {
        return $this->best === [];
    }

    /**
     * What this much of the product costs in this shop this week, and whether
     * the offer is why. Null when nothing prices it at all.
     *
     * The offer's price for the amount, most precise layer first:
     *
     * 1. It states a price per kilo/litre/piece — priced by the amount.
     * 2. The leaflet printed a "before" — the usual price of the amount, cut by
     *    the offer's own discount.
     * 3. Neither, and nothing knows the usual price — one pack.
     *
     * With no "before" and a usual price known, the offer is not quoted at all:
     * how good it is nobody said, and inventing a discount would make the week
     * look cheaper than it is.
     *
     * @return array{cost: Money, onOffer: bool, saves: ?Money}|null
     */
    public function quote(int $ingredientId, ?Quantity $wanted, IngredientCost $costs, IngredientMeasures $measures): ?array
    {
        $usual = $costs->of($ingredientId, $wanted);
        $offered = $this->offeredCost($ingredientId, $wanted, $costs, $measures);

        if ($offered === null || ($usual !== null && ! $offered->isLessThan($usual))) {
            return $usual === null ? null : ['cost' => $usual, 'onOffer' => false, 'saves' => null];
        }

        return ['cost' => $offered, 'onOffer' => true, 'saves' => $usual?->minus($offered)];
    }

    private function offeredCost(int $ingredientId, ?Quantity $wanted, IngredientCost $costs, IngredientMeasures $measures): ?Money
    {
        $offer = $this->offerFor($ingredientId);

        if ($offer === null) {
            return null;
        }

        $byOfferUnit = $this->byOfferUnit($offer, $wanted, $measures);

        if ($byOfferUnit !== null) {
            return $byOfferUnit;
        }

        $usual = $costs->of($ingredientId, $wanted);
        $regular = $offer->regularPrice();

        if ($usual !== null && $regular !== null && $regular->grosze > 0) {
            return $offer->price()->isLessThan($regular)
                ? $usual->scaledBy($offer->price()->grosze / $regular->grosze)
                : null;
        }

        return $usual === null ? $offer->price() : null;
    }

    private function byOfferUnit(Promotion $offer, ?Quantity $wanted, IngredientMeasures $measures): ?Money
    {
        $unitPrice = $offer->unitPrice();

        if ($unitPrice === null || $wanted === null) {
            return null;
        }

        $amount = $wanted->unit->dimension === $unitPrice->per ? $wanted : $measures->toGrams($wanted);

        if ($amount === null || $amount->unit->dimension !== $unitPrice->per) {
            return null;
        }

        $per = $unitPrice->per === UnitDimension::Count ? 1.0 : 1000.0;

        return $unitPrice->price->scaledBy($amount->toBase() / $per);
    }
}
