<?php

declare(strict_types=1);

/**
 * What *kind* of dish a recipe is, read off its title — the thing a week is
 * actually remembered by.
 *
 * Read by `App\Planning\RecipeFacts` and varied by `PlanGenerator`. Neither the
 * protein rotation nor the dominant-ingredient rule can see this: a generated
 * week had three different sandwich spreads for breakfast and pancakes in the
 * morning and again for supper — different ingredients, different proteins,
 * and to anybody at the table the same thing three times.
 *
 * Whole-word needles on the normalised title, like every title rule here; a
 * trailing `*` is a prefix. **First family to match wins**, so order matters:
 * "Kanapka z jajecznicą" is a sandwich, which is why `kanapki` comes before
 * `jajka`. A title matching nothing has no family and is left out of this
 * rotation rather than guessed at.
 */

return [
    'kanapki' => [
        'kanapka', 'kanapki', 'kanapek', 'kanapke', 'tost', 'tosty', 'tostow', 'bajgiel',
        'bajgle', 'kajzerk*', 'bulka', 'bruschett*', 'grzanki', 'pasta', 'pasty', 'paste',
        'pasta jajeczna', 'hummus', 'twarozek', 'smarowidl*', 'panini', 'croque',
    ],
    'tortille' => ['tortilla', 'tortille', 'tortilli', 'wrap', 'wrapy', 'burrito', 'quesadilla', 'tacos', 'tortillas'],
    'nalesniki' => [
        'nalesnik', 'nalesniki', 'nalesnikow', 'placuszki', 'placuszkow', 'placki', 'placek',
        'racuchy', 'racuszki', 'syrniki', 'gofry', 'gofr', 'pancakes', 'dutch baby', 'nalesnikowe',
    ],
    'owsianka' => [
        'owsianka', 'owsianki', 'owsianke', 'jaglanka', 'granola', 'granole', 'musli', 'muesli',
        'pudding', 'jogurt z', 'jogurt grecki', 'bowl', 'smoothie', 'koktajl', 'ryzanka',
    ],
    'jajka' => [
        'jajecznica', 'jajecznice', 'omlet', 'omlety', 'omletu', 'szakszuka', 'shakshuka',
        'frittata', 'suflet', 'jajka', 'jajko', 'jaja',
    ],
    'salatka' => ['salatka', 'salatki', 'salatke', 'bowl z'],
    'zapiekanka' => ['zapiekanka', 'zapiekanki', 'zapiekanke', 'zapiekane', 'lasagne', 'lazania', 'quiche', 'tarta'],
    'zupa' => [
        'zupa', 'zupy', 'zupka', 'krem z', 'rosol', 'barszcz', 'zurek', 'kapusniak', 'chlodnik', 'ramen',
        // The soups Poland names without the word: "Pomidorowa z czerwoną fasolą"
        // was served with kluski and a surówka.
        'pomidorowa', 'pomidorowka', 'ogorkowa', 'grochowka', 'krupnik', 'zalewajka',
        'jarzynowa', 'pieczarkowa', 'grzybowa', 'kalafiorowa', 'brokulowa', 'dyniowa', 'gulaszowa',
        'solanka', 'minestrone', 'bulion', 'flaki',
    ],
    'makaron' => ['makaron', 'makaronem', 'spaghetti', 'penne', 'tagliatelle', 'gnocchi', 'noodle', 'noodles', 'kluski', 'kopytka', 'pierogi', 'lazanki'],
    'kotlety' => ['kotlet', 'kotlety', 'kotleciki', 'klopsiki', 'pulpety', 'pulpeciki', 'nuggetsy', 'burger', 'burgery'],
    'curry' => ['curry', 'gulasz', 'leczo', 'potrawka', 'ragout', 'ragu', 'chili', 'stir fry', 'duszon*'],
];
