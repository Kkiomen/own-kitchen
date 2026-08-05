<?php

declare(strict_types=1);

/**
 * Which meal of the day each recipe suits — what makes "wygeneruj tydzień"
 * possible, and what narrows the picker when planning one meal.
 *
 * Derived, like `categories.php`, and for the same reason: the sources never
 * said. The tags that do mention a meal cover a fraction of the catalogue
 * ("Śniadania i kolacje" 198 recipes, "Obiady" 153, "Kolacje" 34, out of
 * ~10 200), so they can only corroborate. The quick-pick categories already
 * computed from ingredients and titles are the strongest signal available, and
 * this file leans on them first.
 *
 * A recipe may suit several meals — an omelette is breakfast and supper, a
 * salad is lunch-box and supper — and forcing one home would make the other
 * suggestion impossible. Nothing is exclusive here except through `exclude*`.
 *
 * Keys:
 *   `categories`         quick-pick slugs from `categories.php` — the main signal
 *   `titles`             whole words in the normalised title; trailing `*` = prefix
 *   `tags`               normalised source tag names
 *   `mealPrep`           true matches recipes the source itself calls carried food
 *   `excludeCategories`  disqualifies, checked first
 *   `excludeTitles`      same, on the title, anywhere in it
 *   `sideWords`          words that name a thing served *beside* a meal; they
 *                        disqualify unless `mainCategories` corroborates that
 *                        the recipe is a main course after all
 *   `mainCategories`     the categories that override `sideWords`
 *   `sideAlways`         phrases that say outright what the dish accompanies;
 *                        nothing overrides these
 *
 * **Title needles are whole words** (see `TitleNeedle`): a substring search puts
 * "zupełnie" in Soups. Two stems are deliberately absent — `ciast*` (dough as
 * much as cake, which is why Wypieki is its own category) and `past*` ("pasta"
 * is a spread in Polish, but `past*` also reaches "pasternak").
 *
 * Order is the order of the day, and the slugs are `MealSlot` values; an unknown
 * one is a bug rather than data, so `TagMealSlots` throws on it.
 */

/*
 * Sweet baking is a podwieczorek, never a dinner, and a cake matching "z jabłkami"
 * or a title word shared with a main course is the failure mode this prevents.
 */
$sweet = ['desery', 'wypieki'];

/*
 * A side dish is not a meal, and this is the difference between a plan somebody
 * cooks from and a joke: "Surówka z młodej kapusty" for supper feeds nobody.
 *
 * The word alone cannot decide it — "Kotlety rybne z łososia z surówką z
 * kapusty" is dinner that comes *with* one — and position cannot either, because
 * beszamel.se.pl writes headlines and puts the dish in the second sentence
 * ("Pieczone buraki mieszam z ogórkiem małosolnym. Ta surówka znika…"). So it is
 * settled by corroboration: `$mainCourses` below. Know independently that this
 * is fish or pasta, and it is a meal; otherwise a title advertising a surówka is
 * a surówka.
 *
 * These stay out of **every** meal. The picker's "szukaj w całym katalogu"
 * still reaches them, which is the right place for a sauce: you go looking for
 * one on purpose, nobody is served it for supper.
 */
$sides = [
    'surowka', 'surowki', 'surowke', 'surowka', 'sos', 'sosy', 'sosik',
    'dip', 'dipy', 'dressing', 'marynata', 'marynaty', 'pesto', 'zasmazka',
    'farsz', 'panierka', 'konfitura', 'dzem', 'syrop', 'kiszonka', 'ocet',
];

/*
 * What the catalogue knows from ingredients rather than from a title, and
 * therefore what can overrule the list above. Deliberately only the meat, fish,
 * pasta and soup categories: `salatki` cannot be here, because that is the very
 * category a surówka lands in.
 */
$mainCourses = ['kurczak', 'wolowina', 'wieprzowina', 'ryby', 'makarony', 'zupy'];

/*
 * Corroboration has one blind spot and this closes it: "Surówka do karkówki"
 * and "Surówka z białej kapusty – klasyczny dodatek do kotletów schabowych"
 * land in Wieprzowina, because the *title* names pork even though the dish is
 * shredded cabbage. A recipe that says out loud what it is served *with* is the
 * side, not the meal, and no category may vouch for it.
 */
$accompanies = [
    'dodatek do', 'dodatki do', 'surowka do', 'surowke do', 'sos do', 'sosy do',
    'dip do', 'marynata do', 'dressing do', 'do kotletow', 'do miesa', 'do obiadu',
];

return [
    'breakfast' => [
        'categories' => ['sniadania'],
        'tags' => ['sniadania', 'sniadania i kolacje'],
        'titles' => [
            'owsianka', 'owsianki', 'jaglanka', 'jajecznica', 'omlet*', 'nalesnik*',
            'racuch*', 'placuszk*', 'tost*', 'kanapk*', 'granola', 'musli', 'muesli',
            'jogurt*', 'twarozek', 'szakszuka', 'shakshuka', 'gofr*', 'smoothie',
            'koktajl', 'jaja', 'jajka', 'jajko', 'jajecznice', 'sniadanie', 'sniadaniowe',
            'chia', 'pudding', 'bulka', 'bulki',
        ],
        // A cheesecake is not breakfast because it contains twaróg.
        'excludeCategories' => $sweet,
        'sideWords' => $sides,
        'mainCategories' => $mainCourses,
        'sideAlways' => $accompanies,
    ],

    'second_breakfast' => [
        /*
         * The lunch box. `is_meal_prep` is the source itself saying "cooked
         * ahead and carried", which is exactly this meal — the one signal here
         * that is stated rather than inferred.
         */
        'mealPrep' => true,
        'categories' => ['przekaski', 'salatki'],
        'tags' => ['przekaski', 'na wynos'],
        'titles' => [
            'kanapk*', 'salatka', 'salatki', 'wrap*', 'tortilla', 'tortille',
            'koktajl', 'smoothie', 'jogurt*', 'batonik*', 'muffin*', 'hummus',
            'lunchbox', 'przekask*',
        ],
        'excludeCategories' => $sweet,
        'sideWords' => $sides,
        'mainCategories' => $mainCourses,
        'sideAlways' => $accompanies,
    ],

    'lunch' => [
        // The warm main meal: everything the catalogue knows as a main course.
        'categories' => ['zupy', 'makarony', 'kurczak', 'wolowina', 'wieprzowina', 'ryby'],
        'tags' => ['obiady', 'dania glowne'],
        'titles' => [
            'zupa', 'zupy', 'krem', 'rosol', 'gulasz', 'pierogi', 'kotlet*',
            'pieczen', 'risotto', 'curry', 'zapiekank*', 'leczo', 'bigos',
            'golabk*', 'obiad', 'obiadowe', 'duszon*', 'pilaw', 'lasagne',
            'lazanki', 'kluski', 'kopytka', 'sztuka miesa', 'potrawka', 'ragout',
            'chili con carne', 'stek', 'schabowy', 'schabowe', 'zeberka',
        ],
        'excludeCategories' => $sweet,
        // "Zupa krem z truskawek" is a pudding; sweet soups are not dinner.
        'excludeTitles' => ['na slodko'],
        'sideWords' => $sides,
        'mainCategories' => $mainCourses,
        'sideAlways' => $accompanies,
    ],

    'snack' => [
        'categories' => ['desery', 'wypieki'],
        'tags' => ['desery', 'przekaski', 'inne slodkosci', 'ciasta'],
        'titles' => [
            'deser*', 'sernik', 'szarlotka', 'muffin*', 'babeczk*', 'ciasteczk*',
            'brownie', 'tiramisu', 'budyn', 'kisiel', 'galaretka', 'lody',
            'sorbet', 'koktajl', 'smoothie', 'batonik*', 'rogalik*', 'drozdzowk*',
            'podwieczorek', 'na slodko',
        ],
        // A jar of jam is not an afternoon meal either.
        'sideWords' => $sides,
        'mainCategories' => $mainCourses,
        'sideAlways' => $accompanies,
    ],

    'dinner' => [
        /*
         * Kolacja in Poland is the lighter evening meal — salads, sandwiches,
         * eggs, a warm bake — much closer to breakfast than to obiad, which is
         * why the two share so much vocabulary here.
         */
        'categories' => ['salatki', 'sniadania', 'przekaski'],
        'tags' => ['kolacje', 'sniadania i kolacje'],
        'titles' => [
            'salatka', 'salatki', 'kanapk*', 'tost*', 'omlet*', 'jajecznica',
            'zapiekank*', 'tortilla', 'tortille', 'wrap*', 'hummus', 'kolacja',
            'kolacje', 'pasta z', 'placki', 'frittata', 'grzanki', 'quesadilla',
            'burrito',
        ],
        'excludeCategories' => $sweet,
        /*
         * `surowka` used to be a needle here, and it is the reason `$sides`
         * exists: a surówka is what comes *beside* the obiad. The `salatki`
         * category above still reaches every real salad, which is a supper.
         */
        'sideWords' => $sides,
        'mainCategories' => $mainCourses,
        'sideAlways' => $accompanies,
    ],
];
