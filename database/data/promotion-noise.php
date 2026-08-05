<?php

declare(strict_types=1);

/**
 * Leaflet entries that must never be matched to a food product.
 *
 * Most of a chain's leaflet is not groceries, and most of that is harmless: the
 * matcher only knows curated aliases, so "Kosiarka akumulatorowa NAC" resolves to
 * nothing on its own. This list exists for the entries that are not harmless —
 * the non-food that contains a food word and would otherwise match it:
 *
 *   "Karma dla psa wołowina Dolina Noteci"  → would become beef
 *   "Żel pod prysznic mleko i miód"         → would become milk, and honey
 *   "Świeca zapachowa wanilia"              → would become vanilla
 *
 * A dog food recommended as the cheapest beef in town is worse than no
 * recommendation at all, so this is checked before anything else and rejects the
 * whole entry rather than trying to clean it.
 *
 * Grow this from `php artisan promotions:quality`, which lists what matched by
 * frequency. Matching is on whole words of the normalised title; a trailing `*`
 * matches by prefix, because Polish declines these ("karma", "karmy", "karmę").
 */
return [
    // Pet food and pet care — the biggest source of false meat and fish.
    'karm*',
    'przysmak dla',
    'przysmaki dla',
    'dla psa',
    'dla psow',
    'dla kota',
    'dla kotow',
    'zwirek',
    'obroza*',
    'smycz*',
    'drapak*',

    // Cosmetics and hygiene — full of milk, honey, almonds, oats and fruit.
    'szampon*',
    'odzywka do wlosow',
    'zel pod prysznic',
    'plyn do kapieli',
    'mydlo',
    'mydla',
    'dezodorant*',
    'antyperspirant*',
    'pasta do zebow',
    'szczoteczk*',
    'plyn do plukania ust',
    'krem do rak',
    'krem do twarzy',
    'balsam do ciala',
    'golarka*',
    'maszynk* do golenia',
    'pian* do golenia',
    'podpask*',
    'tampon*',
    'wkladk* higieniczn*',
    'pielusz*',
    'pieluch*',
    'chusteczki nawilzane',

    // Household chemicals — "cytrynowy", "lawendowy", "mleczko" all live here.
    'proszek do prania',
    'kapsulki do prania',
    'plyn do prania',
    'plyn do plukania tkanin',
    'plyn do naczyn',
    'tabletki do zmywarki',
    'mleczko do czyszczenia',
    'odswiezacz*',
    'worki na smieci',
    'papier toaletowy',
    'recznik papierowy',
    'reczniki papierowe',
    'reczniki kuchenne',
    'serwetki',

    // Kitchen equipment given away with a bottle. "Aperitivo Aperol + foremka do
    // lodu" matched **Lód** on the first live run — the freebie won, not the
    // drink.
    'foremk*',
    'szklank* w zestawie',

    // Non-food goods whose names borrow food words.
    'swieca*',
    'swiec zapachow*',
    'znicz*',
    'podpalka',
    'brykiet*',
    'wegiel drzewny',
    'karmnik*',
    'nasiona kwiat*',
    'nawoz*',

    // The leaflet's own housekeeping: coupons and cross-promotions have a price
    // on them and no product behind them.
    'kod rabatowy',
    'z kodem',
    'kupon*',
    'karta podarunkowa',
    'doladowanie',
    'wszystkie produkty',
];
