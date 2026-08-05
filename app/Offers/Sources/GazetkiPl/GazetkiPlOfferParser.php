<?php

declare(strict_types=1);

namespace App\Offers\Sources\GazetkiPl;

use App\Offers\Drafts\OfferDraft;
use App\Support\Money\Money;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * The only class that knows gazetki.pl's markup.
 *
 * The site is worth reading precisely because it does not publish leaflets as
 * page scans: every entry is a real element with a name and a price on it, which
 * is the difference between a shopping plan and a folder of pictures.
 */
final class GazetkiPlOfferParser
{
    private const string OFFER_CARDS = '//a[contains(@class, "js-offer-link-item")][@data-offer-id]';

    private const string PAGINATOR_LINKS = '//ul[contains(@class, "paginator__list")]//a[contains(@href, "page=")]';

    /**
     * @return list<OfferDraft>
     */
    public function parseOffers(string $html, string $shopSlug, string $baseUrl): array
    {
        $xpath = $this->xpath($html);
        $cards = $xpath->query(self::OFFER_CARDS);

        if ($cards === false) {
            return [];
        }

        $offers = [];

        foreach ($cards as $card) {
            if (! $card instanceof DOMElement) {
                continue;
            }

            $offer = $this->toDraft($card, $xpath, $shopSlug, $baseUrl);

            if ($offer !== null) {
                $offers[$offer->externalId] = $offer;
            }
        }

        // The same offer can appear twice on one page — once in the grid and once
        // in a "popular right now" strip above it. Keyed by the site's own id so
        // the second copy replaces the first instead of being imported as a
        // separate promotion for the same thing.
        return array_values($offers);
    }

    /**
     * The last page number the paginator offers.
     *
     * Read rather than guessed: walking until a page comes back empty costs one
     * wasted throttled request per shop, and the site answers 200 with the first
     * page's content for an out-of-range number rather than a 404 — so "empty"
     * never arrives and the walk would only stop at the page cap.
     */
    public function parseLastPage(string $html): int
    {
        $links = $this->xpath($html)->query(self::PAGINATOR_LINKS);

        if ($links === false) {
            return 1;
        }

        $highest = 1;

        foreach ($links as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }

            if (preg_match('/[?&]page=(\d+)/', $link->getAttribute('href'), $matches) === 1) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        return $highest;
    }

    private function toDraft(DOMElement $card, DOMXPath $xpath, string $shopSlug, string $baseUrl): ?OfferDraft
    {
        $price = Money::parse($this->textOf($xpath, $card, './/*[contains(@class, "product__price-offer")]'));
        $title = trim($this->textOf($xpath, $card, './/*[contains(@class, "product__name")]'));

        // A card with no price is an advert dressed as an offer. Nothing below
        // can rank it, so it is not a promotion.
        if ($price === null || $title === '') {
            return null;
        }

        return new OfferDraft(
            externalId: $card->getAttribute('data-offer-id'),
            shopSlug: $shopSlug,
            title: $title,
            price: $price,
            regularPrice: Money::parse(
                $this->textOf($xpath, $card, './/*[contains(@class, "product__price-normal")]')
            ),
            endsInDays: $this->daysLeft(
                $this->textOf($xpath, $card, './/*[contains(@class, "product-date")]')
            ),
            url: $this->absolute($card->getAttribute('href'), $baseUrl),
            // Deliberately the product image, not any image in the card: the first
            // <img> inside one is the shop's logo, and every promotion would end
            // up illustrated with the same Biedronka badge.
            imageUrl: $this->attributeOf($xpath, $card, './/*[contains(@class, "product__image")]//img', 'src'),
        );
    }

    /**
     * "4 dni", "1 dzień", "1 miesiąc" — a remaining duration, never a date.
     *
     * Only the number and the unit word matter, and Polish declines the unit by
     * the number ("2 dni", "5 dni", "1 dzień"), so the stem is matched rather
     * than the whole word.
     */
    private function daysLeft(string $text): ?int
    {
        if (preg_match('/(\d+)\s*(dzie|dni|tyg|mies)/ui', trim($text), $matches) !== 1) {
            return null;
        }

        return (int) $matches[1] * match (mb_strtolower($matches[2])) {
            'tyg' => 7,
            'mies' => 30,
            default => 1,
        };
    }

    private function textOf(DOMXPath $xpath, DOMNode $context, string $query): string
    {
        $found = $xpath->query($query, $context);
        $element = $found === false ? null : $found->item(0);

        return $element instanceof DOMElement ? $element->textContent : '';
    }

    private function attributeOf(DOMXPath $xpath, DOMNode $context, string $query, string $attribute): ?string
    {
        $found = $xpath->query($query, $context);
        $element = $found === false ? null : $found->item(0);

        if (! $element instanceof DOMElement) {
            return null;
        }

        $value = $element->getAttribute($attribute);

        return $value === '' ? null : $value;
    }

    private function absolute(string $href, string $baseUrl): string
    {
        return str_starts_with($href, 'http') ? $href : $baseUrl.$href;
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // Real-world markup is never valid; parse it anyway and discard the warnings.
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
