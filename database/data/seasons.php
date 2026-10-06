<?php

declare(strict_types=1);

/**
 * When things are in season — what lets a generated week taste of its month.
 *
 * Read by `App\Planning\Season`. Two halves, and they are used differently:
 *
 * **`produce`** — the months a Polish shop has a product at its best and
 * cheapest, keyed on **exact canonical product names** (the dictionary test
 * fails on a name that has drifted). Only products whose season is the point
 * are listed: an onion is always in season and says nothing about a week.
 * `strict` marks the ones that are close to absent out of season — asparagus in
 * November is an import at three times the price, not a dish anybody plans for
 * — and only those are pushed *down* out of season. The rest are only lifted in
 * it.
 *
 * **`occasions`** — dishes that belong to a date. "Biała kiełbasa na
 * wielkanocne śniadanie" is a fine recipe and a strange Tuesday in October.
 * Matched as whole words on the normalised title, like every other title rule.
 * A recipe naming an occasion is planned only inside its window; outside it,
 * it is still one search away.
 *
 * Months are 1–12. A window is `[month, day]` to `[month, day]` and may wrap the
 * year; `easter` is a span of days around Easter Sunday instead, because it
 * moves.
 */

return [
    'produce' => [
        'Szparagi' => ['months' => [4, 5, 6], 'strict' => true, 'titles' => ['szparag*']],
        'Bób' => ['months' => [6, 7, 8], 'strict' => true, 'titles' => ['bob', 'bobu', 'bobem']],
        'Botwinka' => ['months' => [5, 6, 7], 'strict' => true, 'titles' => ['botwin*']],
        'Rabarbar' => ['months' => [4, 5, 6], 'strict' => true, 'titles' => ['rabarbar*']],
        'Truskawki' => ['months' => [5, 6, 7], 'strict' => true, 'titles' => ['truskaw*']],
        'Wiśnie' => ['months' => [6, 7, 8], 'strict' => true, 'titles' => ['wisni*']],
        'Porzeczki' => ['months' => [6, 7, 8], 'strict' => true, 'titles' => ['porzecz*']],
        'Maliny' => ['months' => [6, 7, 8, 9], 'strict' => false],
        'Borówki' => ['months' => [7, 8, 9], 'strict' => false],
        'Jeżyny' => ['months' => [8, 9], 'strict' => true, 'titles' => ['jezyn*']],
        'Kurki' => ['months' => [6, 7, 8, 9], 'strict' => true, 'titles' => ['kurki', 'kurkami', 'kurek', 'kurkowy', 'kurkowa', 'kurkowe']],
        'Grzyby leśne' => ['months' => [8, 9, 10], 'strict' => false],
        'Kukurydza' => ['months' => [8, 9], 'strict' => false],
        'Fasolka szparagowa' => ['months' => [6, 7, 8, 9], 'strict' => false],
        'Cukinia' => ['months' => [6, 7, 8, 9], 'strict' => false],
        'Pomidor' => ['months' => [7, 8, 9], 'strict' => false],
        'Ogórek' => ['months' => [6, 7, 8, 9], 'strict' => false],
        'Rzodkiewka' => ['months' => [4, 5, 6], 'strict' => false],
        'Szczypiorek' => ['months' => [4, 5, 6], 'strict' => false],
        'Koperek' => ['months' => [5, 6, 7, 8], 'strict' => false],
        'Groszek zielony' => ['months' => [6, 7], 'strict' => false],
        'Brzoskwinia' => ['months' => [7, 8, 9], 'strict' => false],
        'Nektarynka' => ['months' => [7, 8, 9], 'strict' => false],
        'Śliwki' => ['months' => [8, 9, 10], 'strict' => false, 'titles' => ['sliwk*', 'sliwkowy', 'sliwkowe']],
        'Dynia' => ['months' => [9, 10, 11], 'strict' => false, 'titles' => ['dyni*']],
        'Jabłko' => ['months' => [9, 10, 11, 12], 'strict' => false],
        'Gruszka' => ['months' => [9, 10, 11], 'strict' => false],
        'Kalafior' => ['months' => [7, 8, 9, 10], 'strict' => false],
        'Brokuł' => ['months' => [7, 8, 9, 10], 'strict' => false],
        'Brukselka' => ['months' => [10, 11, 12, 1, 2], 'strict' => false],
        'Jarmuż' => ['months' => [10, 11, 12, 1, 2], 'strict' => false],
        'Kapusta' => ['months' => [9, 10, 11, 12, 1, 2, 3], 'strict' => false],
        'Mandarynka' => ['months' => [11, 12, 1, 2], 'strict' => false],
        'Pomarańcza' => ['months' => [12, 1, 2, 3], 'strict' => false],
    ],

    'occasions' => [
        'christmas' => [
            'titles' => [
                // "na wigilię" normalises to `wigilie`: "barszcz czerwony na wigilię"
                // was a generated October starter.
                'wigilia', 'wigilii', 'wigilie', 'wigilijn*', 'swiateczn*', 'na swieta', 'bozonarodzeni*',
                'sylwest*', 'karp', 'karpia', 'barszcz z uszkami', 'piernik*', 'pierniczk*',
                'makowiec', 'kutia', 'kutie',
            ],
            'from' => [12, 1],
            'to' => [1, 6],
        ],
        'easter' => [
            'titles' => ['wielkanoc*', 'wielkanocn*', 'mazurek', 'mazurka', 'babka wielkanocna', 'pascha'],
            'easter' => [-21, 2],
        ],
        /*
         * Not a holiday but a season the title states outright. A chłodnik in
         * October was a generated Monday's obiad, and "młoda kapusta" is a
         * product that simply does not exist in a November shop.
         */
        'early_summer' => [
            'titles' => [
                'chlodnik*', 'gazpacho', 'mloda kapusta', 'mlodej kapusty', 'mloda kapuste', 'mlodej kapuscie',
                // Fresh corn and fresh cucumbers are summer: both were generated
                // for the end of October.
                'swiezej kukurydzy', 'swieza kukurydza', 'swieza kukurydze', 'swiezych ogorkow',
                'ze swiezych ogorkow', 'swiezego ogorka',
                'mlode ziemniaki', 'mlodych ziemniakow', 'mlodymi ziemniakami', 'mlodych ziemniakach',
                'mlode ziemniaczki', 'mlodych ziemniaczkow', 'mlodymi ziemniaczkami',
                'na upal*', 'w upal*', 'upalne', 'na lato', 'letni', 'letnia', 'letnie', 'wiosenna',
                'wiosenny', 'wiosenne', 'na wiosne', 'wiosna', 'wiosny',
            ],
            'from' => [4, 15],
            'to' => [9, 10],
        ],
        /*
         * Spring greens, read off the title because every product carrying
         * these names was invented by the importer ("spora garść młodych
         * pokrzyw") and the produce list takes only curated ones. A nettle and
         * sorrel barszcz was a generated starter at the end of October.
         */
        'spring_greens' => [
            'titles' => [
                'pokrzyw*', 'szczaw*', 'czosnek niedzwiedzi', 'czosnku niedzwiedziego',
                'czosnkiem niedzwiedzim', 'zielony barszcz', 'zupa szczawiowa',
            ],
            'from' => [3, 20],
            'to' => [6, 30],
        ],
        'autumn_winter' => [
            'titles' => ['jesienn*', 'zimow*', 'rozgrzewaj*'],
            'from' => [9, 15],
            'to' => [3, 15],
        ],
        'andrzejki' => [
            'titles' => ['andrzejk*'],
            'from' => [11, 24],
            'to' => [11, 30],
        ],
        'halloween' => [
            'titles' => ['halloween*', 'straszne', 'strasznych', 'straszny', 'upiorn*', 'dyniowe potwor*'],
            'from' => [10, 28],
            'to' => [11, 1],
        ],
        'fat_thursday' => [
            'titles' => ['tlusty czwartek', 'paczki', 'paczek', 'faworki', 'chrust*'],
            'easter' => [-60, -45],
        ],
        'valentines' => [
            'titles' => ['walentynk*', 'walentynkow*'],
            'from' => [2, 1],
            'to' => [2, 14],
        ],
        'summer_grill' => [
            // Not `grill*`: "grillowany kurczak" comes off a grill pan all year.
            'titles' => ['z grilla', 'na grilla', 'na grillu', 'grillowa impreza'],
            'from' => [5, 1],
            'to' => [9, 15],
        ],
    ],
];
