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
 * suggestion impossible. Nothing is exclusive here except through `exclude*`
 * and `named`.
 *
 * Keys:
 *   `named`              words by which a title names this meal outright. When a
 *                        title names *any* meal it gets only the meals it names
 *                        ("…to obiad idealny" is a lunch and nothing else) —
 *                        the author said so, and nothing here knows better.
 *                        Exclusions and the side-dish rule still apply first
 *   `categories`         quick-pick slugs from `categories.php` — the main signal
 *   `titles`             whole words in the normalised title; trailing `*` = prefix
 *   `tags`               normalised source tag names
 *   `mealPrep`           true matches recipes the source itself calls carried food
 *   `excludeCategories`  disqualifies, checked first
 *   `excludeTitles`      same, on the title, anywhere in it
 *   `dishRequiredIn`     categories in which a category or tag alone is not
 *                        enough: one of this meal's own `titles` has to agree
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
 * **A prefix must not reach an adjective, and a meal is a dish, not an
 * ingredient.** `jogurt*` made "Kurczak w sosie jogurtowo-czosnkowym" a
 * breakfast and `nalesnik*` did the same for "Rosół z naleśnikowymi roladkami";
 * a bare `jajka` did it for every beszamel headline listing its ingredients
 * ("Biorę ziemniaki i jajka. Tymi kotlecikami…"). Spell out the forms a title
 * uses for the dish instead.
 *
 * Order is the order of the day, and the slugs are `MealSlot` values; an unknown
 * one is a bug rather than data, so `TagMealSlots` throws on it.
 */

/*
 * `pieczywo` is bread you bake yourself — "Chałka z Air Fryera", "Bajgle" — and
 * it is no meal at all, any more than a sauce is: it is what the kanapka is made
 * *from*. Excluded everywhere, the snack included. A bagel bought ready and
 * filled is not in that category (see `categories.php`), so "Bajgiel z wędzonym
 * łososiem" stays a breakfast.
 */
$bread = ['pieczywo'];

/*
 * Sweet baking is a podwieczorek, never a dinner, and a cake matching "z jabłkami"
 * or a title word shared with a main course is the failure mode this prevents.
 */
$sweet = ['desery', 'wypieki', ...$bread];

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
    // A sauce for something else: "Ragu do makaronu" was planned with no pasta.
    'do makaronu', 'do ryzu', 'do klusek', 'do ziemniakow',
    'dip do', 'marynata do', 'dressing do', 'do kotletow', 'do miesa', 'do obiadu',
    /*
     * Bases rather than dishes: the dough or the groats a dish is later made
     * from. "Naleśniki do krokietów" was planned as a breakfast — half of a
     * different recipe, served on its own.
     */
    'do krokietow', 'na krokiety', 'ciasto na pierogi', 'ciasto na uszka',
    'ciasto na gofry', 'ciasto na zupe', 'jak ugotowac kasze',
    // Preserves are a jar for the cupboard, not a supper: "Sałatka z cukinii na
    // zimę [domowe przetwory]" was a generated Wednesday's kolacja.
    'na zime', 'przetwor*', 'do sloik*', 'w sloikach', 'w sloiku z', 'zamkniety w sloiku', 'konserwa',
    'domowa kielbasa', 'domowej kielbasy', 'kielbasianka',
    // A side that names the main course it goes with — "Czosnek z pieczarkami
    // po prowansalsku, idealny do wieprzowiny" was a generated obiad.
    'do wieprzowiny', 'do kurczaka', 'do ryby', 'do ryb', 'do grzanek', 'do steku',
    // Party food is not a plan: "…na kinderbal" was a Sunday supper.
    'imprez*', 'kinderbal*', 'na przyjecie', 'gosci', 'dla gosci', 'koreczki', 'kanapkowe szaszlyki', 'szaszlyki kanapkowe',
    // Recipes for using up yesterday's leftovers need yesterday first.
    'czerstw*', 'z wczorajszego', 'z resztek', 'resztki', 'z obiadu', 'nie wyrzucam',
    'zostaly', 'zostana', 'zostanie',
    // A stock is what a soup is made from: "Jak zrobić wywar mięsny na barszcz
    // czerwony?" was a generated Sunday obiad.
    // Narrow on purpose: "bulion" and "zakwas" alone reach real soups ("Bulion z
    // lanymi kluskami", "Żurek bez zakwasu").
    'wywar na', 'wywar miesny', 'zrobic wywar', 'zakwas na', 'zakwas buraczany', 'domowy zakwas',
    'robi zakwas',
    // Children's cereal is not a breakfast anybody plans.
    'cini minis', 'nesquik', 'czekoladowe platki',
];

return [
    'breakfast' => [
        'named' => [
            'sniadanie', 'sniadania', 'sniadaniu', 'sniadan', 'sniadaniowe',
            'sniadaniowy', 'sniadaniowa', 'sniadaniowej', 'sniadaniowych',
        ],
        'categories' => ['sniadania'],
        'tags' => ['sniadania', 'sniadania i kolacje'],
        'titles' => [
            'owsianka', 'owsianki', 'owsianke', 'jaglanka', 'jajecznica', 'jajecznice',
            'omlet', 'omlety', 'omletu', 'nalesnik', 'nalesniki', 'nalesnikow',
            'racuchy', 'racuszki', 'placuszki', 'placuszkow', 'tost', 'tosty', 'tostow',
            'kanapka', 'kanapki', 'kanapek', 'kanapke', 'granola', 'granole', 'musli',
            'muesli', 'jogurt z', 'twarozek', 'szakszuka', 'shakshuka', 'gofry', 'gofr',
            'smoothie', 'koktajl', 'pudding chia', 'chia pudding', 'bajgiel', 'bajgle',
            'jajka po*', 'jajka sadzone', 'jajka na miekko', 'jajko na miekko',
            'jajko w koszulce', 'jajka w koszulkach', 'jajka zapiekane',
            'frittata', 'grzanki', 'pasta jajeczna', 'tortilla z jajkiem',
        ],
        // A cheesecake is not breakfast because it contains twaróg, and a soup
        // is not breakfast however its title reads ("Rosół z naleśnikowymi…").
        'excludeCategories' => [...$sweet, 'zupy'],
        // Pancakes baked with minced meat are an obiad that happens to be made
        // of pancakes — the first generated Saturday served them at 8 a.m.
        'excludeTitles' => [
            'mielon*', 'z miesem', 'po bolonsku', 'po meksykansku', 'zapiekane z',
            'ziemniak*', 'kotlet*', 'kotleciki', 'pierogi',
            // Home-made cold cuts are what goes *on* the kanapka.
            'wedlin*', 'piers z', 'seitan*', 'tempeh*',
            // "Naleśniki z ricottą w sosie pomidorowym" is a lunch made of pancakes.
            'w sosie', 'bolonsk*',
        ],
        /*
         * Schab, a chicken bake, a plate of pasta: main courses that reached
         * breakfast through a tag or a category alone. In these categories the
         * title has to name a breakfast dish — "Bajgiel z boczkiem" does,
         * "Schab pieczony w air fryerze" does not.
         */
        'dishRequiredIn' => ['kurczak', 'wolowina', 'wieprzowina', 'ryby', 'makarony'],
        'sideWords' => $sides,
        'mainCategories' => $mainCourses,
        'sideAlways' => $accompanies,
    ],

    'second_breakfast' => [
        'named' => [
            'lunchbox', 'lunchboxa', 'lunchboxie', 'lunchboxow', 'drugie sniadanie', 'do pracy',
            'przekaska', 'przekaske', 'przekaski',
        ],
        /*
         * The lunch box. `is_meal_prep` is the source itself saying "cooked
         * ahead and carried", which is exactly this meal — the one signal here
         * that is stated rather than inferred.
         */
        'mealPrep' => true,
        'categories' => ['przekaski', 'salatki'],
        'tags' => ['przekaski', 'na wynos'],
        'titles' => [
            'kanapk*', 'salatka', 'salatki', 'wrap', 'wrapy', 'tortilla', 'tortille',
            'koktajl', 'smoothie', 'jogurt z', 'batonik*', 'muffin*', 'hummus',
            'lunchbox', 'przekask*',
        ],
        'excludeCategories' => [...$sweet, 'zupy'],
        'sideWords' => $sides,
        'mainCategories' => $mainCourses,
        'sideAlways' => $accompanies,
    ],

    'lunch' => [
        'named' => [
            'obiad', 'obiadu', 'obiady', 'obiadow', 'obiadem', 'obiedzie', 'obiadowe',
            'obiadowy', 'obiadowa', 'obiadowej', 'obiadowych',
        ],
        // The warm main meal: everything the catalogue knows as a main course.
        'categories' => ['zupy', 'makarony', 'kurczak', 'wolowina', 'wieprzowina', 'ryby'],
        'tags' => ['obiady', 'dania glowne'],
        'titles' => [
            'zupa', 'zupy', 'krem', 'rosol', 'gulasz', 'pierogi', 'kotlet*',
            'pieczen', 'risotto', 'curry', 'zapiekank*', 'leczo', 'bigos',
            'golabk*', 'duszon*', 'pilaw', 'lasagne',
            'lazanki', 'kluski', 'kopytka', 'sztuka miesa', 'potrawka', 'ragout',
            'chili con carne', 'stek', 'schabowy', 'schabowe', 'zeberka',
        ],
        'excludeCategories' => $sweet,
        /*
         * "Zupa krem z truskawek" is a pudding; sweet soups are not dinner. And
         * the obiad is the warm meal: a salmon tortilla, a sandwich or a bowl of
         * salad reached it through the fish or chicken in them, and a generated
         * week served them as Thursday's dinner. They are kolacja.
         */
        'excludeTitles' => [
            'na slodko', 'zupa mleczna', 'kanapk*', 'tost', 'tosty', 'wrap', 'wrapy',
            'tortilla z', 'tortille z', 'salatka', 'salatki', 'salatke', 'bajgiel',
            'owsianka', 'jajecznica', 'omlet', 'pasta z', 'pasta jajeczna',
            'babeczk*', 'muffin*',
            // Herring in a marinade is a cold starter, and it was a generated Sunday obiad.
            'sledz', 'sledzie', 'sledzi', 'sledzik*', 'w zalewie',
            // Sushi is cold and its rice goes hard overnight: two days of it was a
            // generated obiad, and the reviewer's worst pick of three weeks.
            'sushi', 'onigiri', 'kulki sushi',
            // Bread is not the obiad either, whatever its flour scored: "Focaccia z
            // Air Fryer" was six portions of a Monday lunch.
            'focaccia', 'focaccie', 'ciabatta', 'chalka', 'chleb', 'chlebek', 'bagietka',
            'bagietki', 'bulki', 'schiacciata', 'pizza', 'pizze',
        ],
        'sideWords' => $sides,
        'mainCategories' => $mainCourses,
        'sideAlways' => $accompanies,
    ],

    'snack' => [
        'named' => ['podwieczorek', 'podwieczorku'],
        'categories' => ['desery', 'wypieki'],
        'tags' => ['desery', 'przekaski', 'inne slodkosci', 'ciasta'],
        'titles' => [
            'deser*', 'sernik', 'szarlotka', 'muffin*', 'babeczk*', 'ciasteczk*',
            'brownie', 'tiramisu', 'budyn', 'kisiel', 'galaretka', 'lody',
            'sorbet', 'koktajl', 'smoothie', 'batonik*', 'rogalik*', 'drozdzowk*',
            'na slodko',
        ],
        // A loaf is not an afternoon meal, and nor is a jar of jam.
        'excludeCategories' => $bread,
        'sideWords' => $sides,
        'mainCategories' => $mainCourses,
        'sideAlways' => $accompanies,
    ],

    'dinner' => [
        'named' => ['kolacja', 'kolacje', 'kolacji', 'kolacyjne', 'kolacyjny', 'kolacyjna'],
        /*
         * Kolacja in Poland is the lighter evening meal — salads, sandwiches,
         * eggs, a warm bake — much closer to breakfast than to obiad, which is
         * why the two share so much vocabulary here.
         */
        'categories' => ['salatki', 'sniadania', 'przekaski'],
        'tags' => ['kolacje', 'sniadania i kolacje'],
        'titles' => [
            'salatka', 'salatki', 'kanapk*', 'tost', 'tosty', 'omlet', 'omlety',
            'jajecznica', 'zapiekank*', 'tortilla', 'tortille', 'wrap', 'wrapy',
            'hummus', 'pasta z', 'placki', 'frittata', 'grzanki', 'quesadilla',
            'burrito',
        ],
        'excludeCategories' => $sweet,
        // Porridge and granola are a morning, whichever category agrees with
        // supper — `sniadania` is shared, and it brought owsianka to the table at
        // seven in the evening twice in one generated week.
        'excludeTitles' => [
            'owsianka', 'owsianki', 'owsianke', 'jaglanka', 'granola', 'granole', 'musli',
            'muesli', 'platki', 'smoothie', 'koktajl', 'pudding chia', 'chia pudding',
            // And home-made cold cuts are what goes on the kanapka, not the kanapka.
            'wedlin*', 'na kanapki',
            // Kolacja is savoury. Chocolate pancakes and yoghurt with honey were
            // generated suppers; they are a podwieczorek.
            'czekolad*', 'z miodem', 'z nutella', 'nutella', 'z dzemem', 'z owocami',
            'z bananem', 'bananow*', 'jogurt grecki', 'karmel*', 'cynamon*', 'z jablkami',
            'z truskawkami', 'z borowkami', 'z malinami', 'z jagodami', 'waniliow*', 'na slodko',
            // "Zapiekany ryż z orzechami, jabłkiem i gruszką" and "naleśniki ze
            // słodkim nadzieniem" were both read as dessert by both reviewers.
            'z jablkiem', 'jablkiem i', 'slodkim nadzieniem', 'slodkie nadzienie', 'zapiekany ryz',
            'miodem', 'borowkami', 'na mleku',
            'focaccia', 'focaccie', 'chalka', 'chleb', 'chlebek', 'bagietka', 'schiacciata',
            'pieczen', 'pieczony schab', 'schab pieczony',
            // Street food and afternoon sweets, both generated as suppers.
            'parowki w boczku', 'parowk*', 'syrniki', 'racuchy', 'racuszki', 'gofry', 'gofr',
        ],
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
