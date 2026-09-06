<?php

declare(strict_types=1);

/**
 * The quick picker at the top of the list — the row you tap to say "chicken" or
 * "soup", the way a food delivery app works.
 *
 * These are computed, not imported. The sources' own categories were never
 * recorded, and their tags cover barely half the catalogue while mixing cuisine
 * with diet with single ingredients. Deriving from what we do trust — canonical
 * ingredients, plus titles and tags as corroboration — covers every recipe from
 * every source with one rule set.
 *
 * A recipe may land in several categories; that is correct, a chicken soup is
 * both. Order here is the order shown.
 *
 * Keys:
 *   `ingredients`  canonical product names, matched exactly — the strongest signal
 *   `titles`       whole words in the normalised title; a trailing `*` means prefix
 *   `tags`         matched against the normalised tag name
 *   `excludeTitles` same matching, but disqualifies; checked first
 *   `withoutIngredientCategories` matches when every ingredient is curated and
 *                  none falls in these `IngredientCategory` values
 *   `maxMinutes`   matches when the recipe declares a time at or under this
 *
 * **Title needles are whole words on purpose.** A plain substring search put
 * "Coś zupełnie osobliwego" in Soups ("zupe") and would have put a celery salad
 * in Desserts ("łodyga" contains "lody"). Use `*` only for stems that need it.
 *
 * Every rule below survived an audit against the real catalogue; the ones that
 * did not are recorded as warnings, because they look reasonable and would
 * otherwise be proposed again.
 */

/*
 * Polish titles are full of meat-free imitations — `"Boczek" z bakłażana`,
 * "Żeberka z kukurydzy", "Pasta z tempehu a la ryba". A title rule cannot tell
 * them from the real thing, so the meat and fish categories refuse these.
 */
$imitations = [
    'wege', 'wegan*', 'a la', 'z baklazana', 'z kukurydzy', 'z tempehu',
    'z tofu', 'z ciecierzycy', 'z soczewicy', 'z nerkowcow', 'roslinn*',
];

return [
    [
        'slug' => 'szybkie',
        'name' => 'Szybkie',
        'icon' => 'clock',
        // Under half an hour, when the source declared a time at all.
        'maxMinutes' => 30,
        'tags' => ['szybkie przygotowanie'],
    ],
    [
        'slug' => 'kurczak',
        'name' => 'Kurczak',
        'ingredients' => ['Pierś z kurczaka', 'Udka z kurczaka', 'Filet drobiowy', 'Kości drobiowe', 'Mięso mielone drobiowe'],
        'titles' => ['kurczak*', 'drobiow*', 'kurczaki'],
        'excludeTitles' => $imitations,
    ],
    [
        'slug' => 'wolowina',
        'name' => 'Wołowina',
        'ingredients' => ['Wołowina', 'Polędwica wołowa', 'Mięso gulaszowe', 'Mięso mielone wołowe'],
        // A bare "stek" was dropped: it matched "Steki z karkówki", which is pork.
        'titles' => ['wolowin*', 'wolowy', 'wolowa', 'wolowe', 'wolowej'],
        'excludeTitles' => $imitations,
    ],
    [
        'slug' => 'wieprzowina',
        'name' => 'Wieprzowina',
        'ingredients' => [
            'Schab', 'Karkówka', 'Boczek', 'Żeberka wieprzowe',
            'Mięso mielone wieprzowe', 'Polędwica wieprzowa', 'Kości wieprzowe',
        ],
        'titles' => ['wieprzow*', 'schab*', 'karkowk*', 'zeberk*', 'boczek', 'boczku'],
        'excludeTitles' => $imitations,
    ],
    [
        'slug' => 'ryby',
        'name' => 'Ryby i owoce morza',
        'ingredients' => [
            'Łosoś', 'Dorsz', 'Tuńczyk', 'Krewetki', 'Halibut', 'Biała ryba filet',
            'Paluszki krabowe', 'Owoce morza mieszanka', 'Anchois',
        ],
        'titles' => [
            'losos*', 'dorsz*', 'ryba', 'ryby', 'rybne', 'rybny', 'krewetk*',
            'tunczyk*', 'makrel*', 'pstrag*', 'sledz*', 'halibut*',
        ],
        'excludeTitles' => $imitations,
    ],
    [
        'slug' => 'makarony',
        'name' => 'Makarony',
        'ingredients' => [
            'Makaron', 'Makaron spaghetti', 'Makaron orzo', 'Makaron lasagne',
            'Makaron ryżowy', 'Gnocchi', 'Kopytka',
        ],
        /*
         * "pasta" was dropped after it claimed 80 recipes: in Polish it means a
         * spread, not pasta — "Pasta z makreli", "Pasta warzywna". Do not add it
         * back.
         */
        'titles' => ['makaron*', 'spaghetti', 'lasagne', 'penne', 'gnocchi', 'tagliatelle'],
        // Courgette and sweet-potato "spaghetti" are vegetables cut into ribbons.
        'excludeTitles' => ['spaghetti z cukinii', 'spaghetti z batata', 'makaronem z cukinii'],
    ],
    [
        'slug' => 'zupy',
        'name' => 'Zupy',
        // No "zupe": it lives inside "zupełnie".
        'titles' => ['zupa', 'zupy', 'zupka', 'krem z', 'chlodnik*', 'rosol*', 'barszcz*', 'zurek', 'bulion*'],
        'tags' => ['zupy'],
        // A cream *of* something is soup; a cream *with* something is dessert.
        'excludeTitles' => ['krem z mascarpone', 'krem z serka', 'krem z bialej czekolady'],
    ],
    [
        'slug' => 'salatki',
        'name' => 'Sałatki',
        'titles' => ['salatka', 'salatki', 'salatke', 'surowka', 'surowki', 'coleslaw'],
        'tags' => ['salatki', 'salatka'],
    ],
    [
        'slug' => 'sniadania',
        'name' => 'Śniadania',
        'titles' => [
            'jajecznica', 'omlet*', 'owsianka', 'owsianki', 'nalesnik*',
            'tost', 'tosty', 'kanapk*', 'bajgiel', 'bajgle', 'granola', 'sniadani*',
        ],
        'tags' => ['sniadania', 'sniadania i kolacje'],
    ],
    [
        'slug' => 'wege',
        'name' => 'Wege',
        /*
         * Derived rather than tagged: barely any source tags this, but every
         * recipe has resolved ingredients. The categoriser additionally refuses
         * to judge a recipe holding an ingredient nobody has curated — see the
         * comment there; guessing here would mislead the one person who most
         * needs the answer to be right.
         */
        'withoutIngredientCategories' => ['meat', 'fish'],
        // Animal products that do not sit in an animal category. Lard is a Fat
        // and gelatine is a Baking ingredient; both were passing this rule.
        'withoutIngredients' => ['Smalec', 'Żelatyna', 'Sos rybny'],
        'tags' => ['vege', 'wegetarianskie', 'weganskie'],
    ],
    [
        'slug' => 'wypieki',
        'name' => 'Wypieki',
        /*
         * Baking, sweet or savoury, kept apart from desserts because "ciasto"
         * means dough as much as cake — together they dragged tomato tarts and
         * puff pastry with bacon into the dessert row.
         */
        'titles' => [
            'ciasto', 'ciasta', 'tarta', 'tarte', 'tarty', 'muffin*', 'babka',
            'bulki', 'bulecz*', 'chleb', 'drozdzow*', 'rogalik*', 'pizza',
        ],
    ],
    [
        'slug' => 'desery',
        'name' => 'Desery',
        // "lody" stays a whole word: "łodyga" would otherwise be ice cream.
        'titles' => [
            'deser', 'desery', 'sernik*', 'brownie', 'lody', 'budyn*',
            'tiramisu', 'panna cotta', 'szarlotka', 'mus czekoladowy',
        ],
        'tags' => ['desery'],
    ],
    [
        'slug' => 'przekaski',
        'name' => 'Przekąski',
        /*
         * "hummus" and a bare "dip" were dropped: they matched dishes that merely
         * contain them ("Jajka w hummusie", "Placuszki z cukinii z dipem").
         */
        'titles' => ['przekask*', 'bruschett*', 'dip z', 'nachos*', 'paluszki'],
    ],
];
