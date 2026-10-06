<?php

declare(strict_types=1);

/**
 * What goes on the plate beside an obiad's main course — read by
 * `App\Planning\SideDishes`.
 *
 * A Polish obiad is "drugie danie": a cutlet, potatoes and a surówka. A
 * generator that could only choose one dish per meal served "Dorsz z porami"
 * as three helpings each to reach the calories, and both reviewers of every
 * generated week called that the least believable thing on it. The meal slot
 * already holds several dishes; this file says which recipes can be the other
 * two.
 *
 * Titles alone cannot: "Ziemniaki zapiekane z boczkiem" opens like a side and
 * is a main course. So a title needle only nominates — `SideDishes` then
 * checks what the recipe is made of (no meat or fish, a handful of
 * ingredients, a side's calories) before it may stand beside anything.
 *
 * Whole-word needles on the normalised title, trailing `*` for a prefix.
 */

return [
    // The part of the plate that fills: potatoes, groats, rice, dumplings.
    'starch' => [
        'ziemniaki', 'ziemniaczki', 'ziemniakow', 'puree', 'hasselback', 'frytki',
        'kasza', 'kasze', 'kaszy', 'ryz', 'ryzu', 'kopytka', 'kluski', 'pyzy', 'bataty',
    ],

    // The vegetable beside it.
    'salad' => [
        'surowka', 'surowki', 'mizeria', 'buraczki', 'cwikla', 'kapusta zasmazana',
        'fasolka szparagowa', 'marchewka z groszkiem', 'salata z ogorkow',
    ],

    /*
     * Never a side, whatever the needle said: jars for the winter, sweet rice
     * and dumplings, and anything that names a different meal.
     */
    'exclude' => [
        'na zime', 'do sloik*', 'w sloiku', 'przetwor*', 'na slodko', 'slodk*', 'z jablkami',
        'z jablkiem', 'cynamon*', 'na mleku', 'pudding', 'czekolad*', 'truskaw*', 'owoc*',
        'mango', 'z serem', 'zapiekan*', 'faszerowan*', 'nadziewan*', 'z wczoraj*',
        'wczorajsze', 'smazony', 'smazone', 'smazona', 'sos do',
        // Things that name another meal, or are baked goods rather than a side.
        'sniadani*', 'kolacj*', 'szybki obiad', 'zdrowy obiad', 'danie', 'chleb*', 'muffin*',
        'babka', 'kaszy manny', 'kasza manna',
        // Dishes of their own, not what goes beside a fish: "kluski niby leniwe,
        // a jednak ruskie" were served with dorsz.
        'leniw*', 'ruski*', 'z twarog*',
        // Kasza is cooked in a pot: "Jak ugotować kaszę gryczaną w Air Fryer"
        // came up three times in a week.
        'ugotowac kasz*',
        // A milk soup is breakfast or supper, never the first course before
        // potrawka — two days running in a generated week.
        'mleczn*',
    ],
];
