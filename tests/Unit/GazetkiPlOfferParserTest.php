<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Offers\Drafts\OfferDraft;
use App\Offers\Sources\GazetkiPl\GazetkiPlOfferParser;
use PHPUnit\Framework\TestCase;

class GazetkiPlOfferParserTest extends TestCase
{
    private const string BASE_URL = 'https://www.gazetki.pl';

    public function test_it_reads_a_name_and_a_price_off_every_card(): void
    {
        $offers = $this->parse();

        $this->assertSame('Cukier biały', $this->find($offers, 'Cukier biały')->title);
        $this->assertSame(99, $this->find($offers, 'Cukier biały')->price->grosze);
    }

    public function test_it_reads_the_price_the_offer_replaces(): void
    {
        $this->assertSame(198, $this->find($this->parse(), 'Cukier biały')->regularPrice?->grosze);
    }

    public function test_an_offer_with_no_earlier_price_says_so(): void
    {
        $this->assertNull($this->find($this->parse(), 'Piwo Garage 400 ml')->regularPrice);
    }

    public function test_it_reads_how_long_the_offer_still_runs(): void
    {
        $this->assertSame(1, $this->find($this->parse(), 'Cukier biały')->endsInDays);
        $this->assertSame(3, $this->find($this->parse(), 'Papryka słodka czerwona na wagę')->endsInDays);
    }

    /**
     * A coupon has a headline and no price. Nothing downstream can rank it, so it
     * is not an offer — and importing it would put "40zł na zamówienia min. 160zł"
     * on a shopping plan.
     */
    public function test_an_entry_with_no_price_is_not_an_offer(): void
    {
        foreach ($this->parse() as $offer) {
            $this->assertStringNotContainsString('LUZIK40', $offer->title);
        }
    }

    /**
     * The first image inside a card is the shop's logo. Reading it would
     * illustrate every promotion with the same Biedronka badge.
     */
    public function test_the_picture_is_the_product_rather_than_the_shop_logo(): void
    {
        $image = $this->find($this->parse(), 'Cukier biały')->imageUrl;

        $this->assertNotNull($image);
        $this->assertStringNotContainsString('/stores/', $image);
    }

    public function test_a_relative_link_becomes_an_absolute_one(): void
    {
        $this->assertStringStartsWith(
            self::BASE_URL.'/',
            $this->find($this->parse(), 'Piwo Garage 400 ml')->url,
        );
    }

    /**
     * The site answers an out-of-range page with the first page rather than a
     * 404, so a walk that stops "when a page comes back empty" never stops.
     */
    public function test_it_reads_how_many_pages_the_listing_has(): void
    {
        $this->assertSame(120, (new GazetkiPlOfferParser)->parseLastPage($this->fixture()));
    }

    /**
     * @return list<OfferDraft>
     */
    private function parse(): array
    {
        return (new GazetkiPlOfferParser)->parseOffers($this->fixture(), 'biedronka', self::BASE_URL);
    }

    /**
     * @param  list<OfferDraft>  $offers
     */
    private function find(array $offers, string $title): OfferDraft
    {
        foreach ($offers as $offer) {
            if ($offer->title === $title) {
                return $offer;
            }
        }

        $this->fail("No offer titled '{$title}' was parsed.");
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/gazetki/biedronka-oferty.html');
    }
}
