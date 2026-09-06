<?php

declare(strict_types=1);

/**
 * What 100 g of each product is worth: calories, and the three macros.
 *
 * Nothing in the catalogue carried this. None of the four recipe sources
 * publishes nutrition in its JSON-LD, so no amount of re-importing would produce
 * it — this file is the whole of what the app knows about calories, and a plan
 * built to hit 2 500 kcal a head is built on it.
 *
 * Keys are **exact canonical product names** from `database/data/ingredients.php`,
 * exactly as in `ingredient-measures.php`, and `IngredientNutritionSeeder` throws
 * on a name no product has. A wrong weight makes a shopping list wrong; a wrong
 * calorie makes a week's eating wrong, and it does it silently.
 *
 * Each entry is `[kcal, protein, fat, carbohydrate, external key]` per 100 g.
 * The macros may be null where a reading does not state them; the calories may
 * not — a product that cannot state them has no business being in this file, and
 * `NutritionBook` is built to stay quiet about it instead.
 *
 * The **external key** is an English food term, and it is the one thing here that
 * is not about today. Every external nutrition database — USDA FoodData Central,
 * Open Food Facts — is searched in English, and "Ser żółty" reaches none of
 * them. Recording the term each figure was looked up under means a future
 * importer starts from a decision a person already made about *which food was
 * meant*, rather than re-translating 1 600 Polish names and hoping. It is
 * deliberately a search term and never a numeric identifier: an invented FDC id
 * would look authoritative and resolve to the wrong food.
 *
 * ## The rule that decides most of these numbers
 *
 * **A recipe states the weight of the thing as bought, not as served.** "100 g
 * ryżu" is dry rice, and dry rice is 360 kcal against 130 for cooked — a factor
 * of nearly three, applied to a staple, on every recipe that uses one. So pasta,
 * rice, groats and flour are all dry here, and that is not an oversight to be
 * "corrected" later.
 *
 * The same rule read the other way is why the tinned things are tinned: Polish
 * recipes asking for "fasola" or "ciecierzyca" overwhelmingly mean the drained
 * contents of a tin, not something soaked overnight, so those carry tinned
 * figures. Lentils go the other way — "100 g soczewicy" is dry in practically
 * every recipe in this catalogue — and the inconsistency is the catalogue's, not
 * a mistake here. Both are marked where they appear.
 *
 * ## Deliberately absent, and it must stay that way
 *
 * The generic and the invented: "Przyprawy", "Zioła", "Mięso", "Owoce",
 * "suszonych", "puree", "serka", "smalec do smażenia", "sos winegret",
 * "Przyprawa gyros", "Przyprawa do mięsa". Some are review-queue artefacts that
 * should not be products at all; the rest are a category wearing a product's
 * name, and there is no honest calorie for "meat". A blend that is mostly salt
 * would also be given a spice's calories and be wrong by an order of magnitude
 * on every line that used it.
 *
 * Equipment too, obviously: "Patyczki do szaszłyków" is not eaten.
 *
 * Grow this from `php artisan ingredients:nutrition`, which lists what is missing
 * in the order the catalogue actually leans on it — the same "attack by
 * frequency" rule the ingredient review queue and the weights already follow.
 */
return [
    // -------------------------------------------------------------- vegetables
    'Groszek zielony' => [81, 5.4, 0.4, 14.5, 'peas, green, raw'],
    'Mieszanka warzyw' => [42, 2.2, 0.3, 8.0, 'vegetables, mixed, frozen'],
    'Czosnek' => [149, 6.4, 0.5, 33.1, 'garlic, raw'],
    'Cebula' => [40, 1.1, 0.1, 9.3, 'onions, raw'],
    'Papryka' => [31, 1.0, 0.3, 6.0, 'peppers, sweet, red, raw'],
    'Marchew' => [41, 0.9, 0.2, 9.6, 'carrots, raw'],
    'Ziemniak' => [77, 2.0, 0.1, 17.5, 'potatoes, flesh and skin, raw'],
    'Pomidor' => [18, 0.9, 0.2, 3.9, 'tomatoes, red, ripe, raw'],
    'Ogórek' => [15, 0.7, 0.1, 3.6, 'cucumber, with peel, raw'],
    'Cukinia' => [17, 1.2, 0.3, 3.1, 'squash, summer, zucchini, raw'],
    'Pieczarki' => [22, 3.1, 0.3, 3.3, 'mushrooms, white, raw'],
    'Szpinak' => [23, 2.9, 0.4, 3.6, 'spinach, raw'],
    'Seler naciowy' => [16, 0.7, 0.2, 3.0, 'celery, raw'],
    'Pomidorki koktajlowe' => [18, 0.9, 0.2, 3.9, 'tomatoes, cherry, raw'],
    'Por' => [61, 1.5, 0.3, 14.2, 'leeks, raw'],
    'Kukurydza' => [86, 3.2, 1.2, 19.0, 'corn, sweet, yellow, kernels'],
    'Kapusta' => [25, 1.3, 0.1, 5.8, 'cabbage, raw'],
    'Grzyby leśne' => [22, 3.1, 0.3, 3.3, 'mushrooms, wild, raw'],
    'Dymka' => [32, 1.8, 0.2, 7.3, 'onions, spring, raw'],
    // Dry, not the sort packed in oil — those are nearer 210 and are a different
    // product a recipe names differently.
    'Suszone pomidory' => [258, 14.1, 3.0, 55.8, 'tomatoes, sun-dried'],
    'Pomidory z puszki' => [20, 1.0, 0.2, 4.0, 'tomatoes, red, canned'],
    'Sałata' => [15, 1.4, 0.2, 2.9, 'lettuce, butterhead, raw'],
    'Brokuł' => [34, 2.8, 0.4, 6.6, 'broccoli, raw'],
    'Rukola' => [25, 2.6, 0.7, 3.7, 'arugula, raw'],
    'Oliwki zielone' => [145, 1.0, 15.3, 3.8, 'olives, green, pickled'],
    'Pietruszka korzeń' => [36, 2.0, 0.4, 8.0, 'parsley root, raw'],
    'Mieszanka sałat' => [15, 1.4, 0.2, 2.9, 'lettuce, mixed salad greens, raw'],
    'Dynia' => [26, 1.0, 0.1, 6.5, 'pumpkin, raw'],
    'Ogórek kiszony' => [12, 0.6, 0.2, 2.2, 'cucumber, pickled, fermented'],
    'Kalafior' => [25, 1.9, 0.3, 5.0, 'cauliflower, raw'],
    'Bataty' => [86, 1.6, 0.1, 20.1, 'sweet potato, raw'],
    'Szparagi' => [20, 2.2, 0.1, 3.9, 'asparagus, raw'],
    'Burak' => [43, 1.6, 0.2, 9.6, 'beets, raw'],
    'Rzodkiewka' => [16, 0.7, 0.1, 3.4, 'radishes, raw'],
    'Bakłażan' => [25, 1.0, 0.2, 5.9, 'eggplant, raw'],
    'Fasolka szparagowa' => [31, 1.8, 0.1, 7.0, 'beans, snap, green, raw'],
    'Kapusta kiszona' => [19, 0.9, 0.1, 4.3, 'sauerkraut, canned'],
    'Ogórek konserwowy' => [12, 0.6, 0.2, 2.2, 'cucumber, pickled, sweet'],
    'Szalotka' => [72, 2.5, 0.1, 16.8, 'shallots, raw'],
    'Kapary' => [23, 2.4, 0.9, 4.9, 'capers, canned'],
    'Jarmuż' => [49, 4.3, 0.9, 8.8, 'kale, raw'],
    'Kapusta pekińska' => [16, 1.2, 0.2, 3.2, 'cabbage, chinese, raw'],
    'Kapusta czerwona' => [31, 1.4, 0.2, 7.4, 'cabbage, red, raw'],
    'Kurki' => [32, 1.5, 0.5, 6.9, 'mushrooms, chanterelle, raw'],
    'Kiełki' => [30, 3.0, 0.5, 6.0, 'sprouts, mixed, raw'],
    'Botwinka' => [22, 2.2, 0.1, 4.3, 'beet greens, raw'],
    'Papryczka jalapeño' => [29, 0.9, 0.4, 6.5, 'peppers, jalapeno, raw'],
    'Kalarepa' => [27, 1.7, 0.1, 6.2, 'kohlrabi, raw'],
    'Koper włoski' => [31, 1.2, 0.2, 7.3, 'fennel, bulb, raw'],
    'Seler korzeniowy' => [42, 1.5, 0.3, 9.2, 'celeriac, raw'],
    'Boczniaki' => [33, 3.3, 0.4, 6.1, 'mushrooms, oyster, raw'],
    'Brukselka' => [43, 3.4, 0.3, 9.0, 'brussels sprouts, raw'],
    'Pak choi' => [13, 1.5, 0.2, 2.2, 'cabbage, chinese, pak-choi, raw'],
    // A bag of soup vegetables: carrot, parsley root, celeriac and leek. An
    // average of four roots rather than a reading of one thing, and it is here
    // only because 73 lines ask for it by that name.
    'Włoszczyzna' => [40, 1.2, 0.2, 9.0, 'soup vegetables, mixed root, raw'],

    // ------------------------------------------------------------------- fruit
    'Grejpfrut' => [42, 0.8, 0.1, 10.7, 'grapefruit, raw'],
    'Figi' => [74, 0.8, 0.3, 19.2, 'figs, raw'],
    'Porzeczki' => [56, 1.4, 0.2, 13.8, 'currants, red or white, raw'],
    'Melon' => [34, 0.8, 0.2, 8.2, 'melons, cantaloupe, raw'],
    'Cytryna' => [29, 1.1, 0.3, 9.3, 'lemons, raw, without peel'],
    'Jabłko' => [52, 0.3, 0.2, 13.8, 'apples, raw, with skin'],
    'Limonka' => [30, 0.7, 0.2, 10.5, 'limes, raw'],
    'Awokado' => [160, 2.0, 14.7, 8.5, 'avocados, raw'],
    'Banan' => [89, 1.1, 0.3, 22.8, 'bananas, raw'],
    'Pomarańcza' => [47, 0.9, 0.1, 11.8, 'oranges, raw'],
    'Rodzynki' => [299, 3.1, 0.5, 79.2, 'raisins, seedless'],
    'Ananas' => [50, 0.5, 0.1, 13.1, 'pineapple, raw'],
    'Truskawki' => [32, 0.7, 0.3, 7.7, 'strawberries, raw'],
    'Borówki' => [57, 0.7, 0.3, 14.5, 'blueberries, raw'],
    'Maliny' => [52, 1.2, 0.7, 11.9, 'raspberries, raw'],
    'Żurawina' => [46, 0.4, 0.1, 12.2, 'cranberries, raw'],
    'Gruszka' => [57, 0.4, 0.1, 15.2, 'pears, raw'],
    'Mango' => [60, 0.8, 0.4, 15.0, 'mangos, raw'],
    'Śliwki suszone' => [240, 2.2, 0.4, 63.9, 'plums, dried (prunes)'],
    'Granat' => [83, 1.7, 1.2, 18.7, 'pomegranates, raw'],
    'Morele suszone' => [241, 3.4, 0.5, 62.6, 'apricots, dried'],
    'Śliwki' => [46, 0.7, 0.3, 11.4, 'plums, raw'],
    'Daktyle' => [282, 2.5, 0.4, 75.0, 'dates, deglet noor'],
    'Brzoskwinia' => [39, 0.9, 0.3, 9.5, 'peaches, raw'],
    'Winogrona' => [69, 0.7, 0.2, 18.1, 'grapes, red or green, raw'],
    'Wiśnie' => [50, 1.0, 0.3, 12.2, 'cherries, sour, red, raw'],
    'Kiwi' => [61, 1.1, 0.5, 14.7, 'kiwifruit, green, raw'],
    'Rabarbar' => [21, 0.9, 0.2, 4.5, 'rhubarb, raw'],

    // ------------------------------------------------------------------- dairy
    'Serek homogenizowany' => [145, 8.0, 5.0, 16.0, 'cheese, quark, sweetened, homogenised'],
    'Napój sojowy' => [45, 3.3, 1.8, 3.3, 'beverage, soy milk, unsweetened'],
    'Pudding proteinowy' => [90, 10.0, 2.0, 8.0, 'pudding, protein, ready-to-eat'],
    'Masło' => [717, 0.9, 81.1, 0.1, 'butter, salted'],
    'Mleko' => [61, 3.2, 3.3, 4.8, 'milk, whole, 3.25% fat'],
    'Ser żółty' => [356, 25.0, 27.4, 2.2, 'cheese, gouda'],
    'Śmietana 18%' => [190, 2.6, 18.0, 3.5, 'cream, sour, 18% fat'],
    'Parmezan' => [392, 35.8, 25.8, 3.2, 'cheese, parmesan, hard'],
    'Śmietanka 30%' => [293, 2.2, 30.0, 3.2, 'cream, heavy whipping, 30% fat'],
    'Jogurt naturalny' => [61, 3.5, 3.3, 4.7, 'yogurt, plain, whole milk'],
    'Mozzarella' => [280, 22.2, 22.4, 2.2, 'cheese, mozzarella, whole milk'],
    'Feta' => [264, 14.2, 21.3, 4.1, 'cheese, feta'],
    'Twaróg' => [133, 18.7, 4.7, 3.5, 'cheese, quark, semi-fat'],
    'Jogurt grecki' => [97, 9.0, 5.0, 4.0, 'yogurt, greek, plain, whole milk'],
    'Mleko kokosowe' => [197, 2.0, 21.3, 2.8, 'coconut milk, canned'],
    'Serek kremowy' => [342, 6.2, 34.0, 4.1, 'cheese, cream'],
    'Kefir' => [51, 3.3, 2.0, 4.5, 'kefir, plain, whole milk'],
    'Skyr' => [63, 11.0, 0.2, 4.0, 'skyr, plain, nonfat'],
    'Gorgonzola' => [350, 19.0, 30.0, 2.0, 'cheese, blue, gorgonzola'],
    'Ricotta' => [174, 11.3, 13.0, 3.0, 'cheese, ricotta, whole milk'],
    'Mascarpone' => [429, 4.6, 44.0, 4.8, 'cheese, mascarpone'],
    'Serek wiejski' => [98, 11.1, 4.3, 3.4, 'cheese, cottage, creamed'],
    'Ser kozi' => [364, 21.6, 29.8, 2.5, 'cheese, goat, semisoft'],
    'Serek topiony' => [290, 12.0, 24.0, 6.0, 'cheese, processed, spread'],
    'Halloumi' => [321, 22.0, 25.0, 2.0, 'cheese, halloumi'],
    'Camembert' => [300, 19.8, 24.3, 0.5, 'cheese, camembert'],
    'Burrata' => [330, 17.0, 29.0, 2.0, 'cheese, burrata'],
    'Twarożek' => [133, 18.7, 4.7, 3.5, 'cheese, quark, semi-fat'],
    'Napój owsiany' => [45, 0.8, 1.5, 6.6, 'beverage, oat milk, unsweetened'],

    // --------------------------------------------------------------------- egg
    'Jajko' => [143, 12.6, 9.5, 0.7, 'egg, whole, raw, fresh'],

    // -------------------------------------------------------------------- meat
    'Kurczak cały' => [215, 18.6, 15.1, 0.0, 'chicken, whole, meat and skin, raw'],
    'Łopatka wieprzowa' => [221, 18.0, 16.5, 0.0, 'pork, shoulder (blade), raw'],
    'Kaszanka' => [320, 12.0, 27.0, 8.0, 'sausage, blood (black pudding)'],
    'Salceson' => [280, 14.0, 24.0, 1.0, 'headcheese, pork'],
    'Polędwica wołowa' => [150, 21.0, 7.0, 0.0, 'beef, tenderloin, raw'],
    'Kaczka' => [337, 19.0, 28.0, 0.0, 'duck, meat and skin, raw'],
    'Pierś z kurczaka' => [120, 22.5, 2.6, 0.0, 'chicken, breast, skinless, raw'],
    'Boczek' => [518, 9.3, 53.0, 0.0, 'pork, belly, raw'],
    'Mięso mielone wieprzowe' => [263, 17.0, 21.0, 0.0, 'pork, ground, raw'],
    'Szynka' => [145, 20.0, 7.0, 1.0, 'ham, sliced, regular'],
    'Schab' => [143, 21.0, 6.0, 0.0, 'pork, loin, raw'],
    'Kiełbasa' => [300, 14.0, 27.0, 1.5, 'sausage, pork, smoked'],
    'Mięso mielone drobiowe' => [143, 20.0, 7.0, 0.0, 'chicken, ground, raw'],
    'Udka z kurczaka' => [177, 18.0, 11.0, 0.0, 'chicken, thigh, with skin, raw'],
    'Mięso mielone wołowe' => [250, 19.0, 19.0, 0.0, 'beef, ground, 80% lean, raw'],
    'Wołowina' => [187, 21.0, 11.0, 0.0, 'beef, chuck, raw'],
    'Indyk' => [111, 22.0, 2.5, 0.0, 'turkey, breast, skinless, raw'],
    'Wątróbka drobiowa' => [119, 17.0, 4.8, 0.7, 'chicken, liver, raw'],
    'Karkówka' => [240, 17.0, 19.0, 0.0, 'pork, shoulder (neck), raw'],
    'Polędwica wieprzowa' => [120, 21.0, 3.5, 0.0, 'pork, tenderloin, raw'],
    'Żeberka wieprzowe' => [277, 18.0, 23.0, 0.0, 'pork, spareribs, raw'],
    'Chorizo' => [455, 24.0, 38.0, 2.0, 'sausage, chorizo, pork and beef'],
    'Słonina' => [700, 3.0, 75.0, 0.0, 'pork, fatback, raw'],
    'Parówki' => [250, 11.0, 22.0, 2.0, 'frankfurter, pork'],
    'Skrzydełka z kurczaka' => [203, 18.0, 14.0, 0.0, 'chicken, wing, with skin, raw'],
    'Wieprzowina' => [220, 20.0, 15.0, 0.0, 'pork, fresh, raw'],
    'Salami' => [380, 21.0, 32.0, 1.5, 'salami, dry or hard, pork'],
    'Filet drobiowy' => [120, 22.5, 2.6, 0.0, 'chicken, breast, skinless, raw'],
    'Wędlina' => [180, 18.0, 11.0, 1.5, 'luncheon meat, sliced'],
    /*
     * A stock is a litre of liquid, not a stock cube, and that is what a recipe
     * pours in. Reading these as the cube would put a kilocalorie on a soup for
     * every millilitre of water in it.
     */
    'Bulion drobiowy' => [4, 0.5, 0.2, 0.3, 'soup, chicken broth, prepared'],
    'Bulion wołowy' => [4, 0.6, 0.2, 0.2, 'soup, beef broth, prepared'],

    // -------------------------------------------------------------------- fish
    'Morszczuk' => [86, 17.0, 1.3, 0.0, 'fish, hake, raw'],
    'Makrela wędzona' => [305, 18.6, 25.1, 0.0, 'fish, mackerel, smoked'],
    'Paluszki krabowe' => [95, 7.6, 0.9, 15.0, 'fish, surimi, crab sticks'],
    'Anchois' => [210, 29.0, 10.0, 0.0, 'fish, anchovy, canned in oil, drained'],
    'Łosoś' => [208, 20.4, 13.4, 0.0, 'fish, salmon, atlantic, raw'],
    'Krewetki' => [85, 20.1, 0.5, 0.0, 'crustaceans, shrimp, raw'],
    'Śledź' => [158, 18.0, 9.0, 0.0, 'fish, herring, atlantic, raw'],
    'Tuńczyk' => [116, 26.0, 1.0, 0.0, 'fish, tuna, light, canned in water, drained'],
    'Dorsz' => [82, 18.0, 0.7, 0.0, 'fish, cod, atlantic, raw'],
    'Pstrąg wędzony' => [160, 21.0, 8.0, 0.0, 'fish, trout, smoked'],
    'Biała ryba filet' => [90, 18.0, 1.5, 0.0, 'fish, white, fillet, raw'],

    // ------------------------------------------------------------------- grain
    'Mąka migdałowa' => [580, 21.0, 50.0, 21.0, 'flour, almond, blanched'],
    'Kopytka' => [160, 4.0, 1.0, 34.0, 'dumplings, potato, fresh'],
    'Papier ryżowy' => [330, 5.0, 0.5, 80.0, 'rice paper, dry'],
    'Chipsy kukurydziane' => [490, 7.0, 24.0, 62.0, 'snacks, tortilla chips, plain'],
    'Chałka' => [300, 9.0, 6.0, 52.0, 'bread, challah, egg bread'],
    'Bajgle' => [275, 11.0, 1.5, 53.0, 'bread, bagel, plain'],
    'Płatki jaglane' => [362, 10.0, 3.5, 71.0, 'millet flakes, dry'],
    'Otręby owsiane' => [246, 17.3, 7.0, 66.2, 'oat bran, raw'],
    // All dry, all of them. See the header: a recipe weighs what it bought.
    'Mąka pszenna' => [364, 10.3, 1.0, 76.3, 'wheat flour, white, all-purpose'],
    'Bułka tarta' => [380, 12.5, 4.0, 72.0, 'bread crumbs, dry, grated, plain'],
    'Makaron' => [371, 13.0, 1.5, 74.7, 'pasta, dry, unenriched'],
    'Chleb' => [265, 8.5, 3.2, 49.0, 'bread, wheat'],
    'Płatki owsiane' => [379, 13.2, 6.5, 67.7, 'oats, rolled, dry'],
    'Mąka ziemniaczana' => [340, 0.6, 0.1, 83.0, 'potato starch'],
    'Ryż' => [360, 7.1, 0.7, 79.0, 'rice, white, long-grain, dry'],
    'Kasza gryczana' => [343, 13.3, 3.4, 71.5, 'buckwheat groats, dry'],
    'Bułka' => [280, 9.0, 3.0, 54.0, 'rolls, dinner, plain'],
    'Tortilla' => [310, 8.0, 7.0, 52.0, 'tortillas, ready-to-bake, flour'],
    'Mąka kukurydziana' => [361, 6.9, 3.9, 76.9, 'corn flour, whole-grain'],
    'Ciasto francuskie' => [375, 5.5, 24.0, 34.0, 'puff pastry, frozen, ready-to-bake'],
    'Bagietka' => [270, 9.0, 1.5, 55.0, 'bread, french or vienna'],
    'Kasza jaglana' => [378, 11.0, 4.2, 72.8, 'millet, raw'],
    'Makaron spaghetti' => [371, 13.0, 1.5, 74.7, 'pasta, spaghetti, dry'],
    'Mąka orkiszowa' => [352, 14.6, 2.4, 70.0, 'spelt flour, whole-grain'],
    'Quinoa' => [368, 14.1, 6.1, 64.2, 'quinoa, uncooked'],
    'Makaron ryżowy' => [364, 6.0, 0.6, 83.0, 'noodles, rice, dry'],
    'Grahamka' => [250, 9.0, 2.0, 47.0, 'bread, whole-wheat roll'],
    'Kuskus' => [376, 12.8, 0.6, 77.4, 'couscous, dry'],
    'Naleśniki' => [220, 6.0, 8.0, 30.0, 'crepes, prepared'],
    'Ryż arborio' => [360, 7.0, 0.6, 79.0, 'rice, white, short-grain, dry'],
    'Kasza pęczak' => [352, 9.9, 1.2, 77.7, 'barley, pearled, raw'],
    'Panko' => [380, 12.0, 3.0, 75.0, 'bread crumbs, panko, dry'],
    'Gnocchi' => [165, 4.0, 1.0, 34.0, 'gnocchi, potato, fresh'],
    'Makaron orzo' => [371, 13.0, 1.5, 74.7, 'pasta, orzo, dry'],
    'Płatki kukurydziane' => [378, 7.0, 0.9, 84.0, 'cereals, corn flakes'],
    'Mąka pełnoziarnista' => [340, 13.2, 2.5, 64.5, 'wheat flour, whole-grain'],
    'Mąka ryżowa' => [366, 6.0, 1.4, 80.1, 'rice flour, white'],
    'Mąka żytnia' => [349, 10.4, 1.6, 71.9, 'rye flour, medium'],
    'Herbatniki' => [450, 6.5, 15.0, 72.0, 'cookies, plain, tea biscuit'],
    'Mąka owsiana' => [379, 13.2, 6.5, 67.7, 'oat flour, whole-grain'],
    'Pinsa' => [270, 9.0, 3.0, 50.0, 'pizza crust, ready-to-bake'],
    'Makaron lasagne' => [371, 13.0, 1.5, 74.7, 'pasta, lasagne, dry'],

    // --------------------------------------------------------------------- fat
    'Olej lniany' => [884, 0.0, 100.0, 0.0, 'oil, flaxseed'],
    'Oliwa z oliwek' => [884, 0.0, 100.0, 0.0, 'oil, olive, extra virgin'],
    'Olej rzepakowy' => [884, 0.0, 100.0, 0.0, 'oil, canola (rapeseed)'],
    'Olej sezamowy' => [884, 0.0, 100.0, 0.0, 'oil, sesame'],
    'Olej kokosowy' => [892, 0.0, 99.1, 0.0, 'oil, coconut'],
    'Smalec' => [898, 0.0, 99.5, 0.0, 'lard, pork'],
    'Margaryna' => [717, 0.2, 80.0, 0.7, 'margarine, regular, 80% fat'],

    // --------------------------------------------------------------- sweetener
    'Syrop z agawy' => [310, 0.1, 0.5, 76.0, 'syrups, agave'],
    'Cukier' => [400, 0.0, 0.0, 100.0, 'sugars, granulated'],
    'Miód' => [304, 0.3, 0.0, 82.4, 'honey'],
    'Cukier wanilinowy' => [400, 0.0, 0.0, 100.0, 'sugars, granulated, vanilla'],
    'Cukier puder' => [400, 0.0, 0.0, 100.0, 'sugars, powdered'],
    'Syrop klonowy' => [260, 0.0, 0.0, 67.0, 'syrups, maple'],
    // A polyol the body does not metabolise: zero is the reading, not a gap.
    'Erytrol' => [0, 0.0, 0.0, 0.0, 'sweetener, erythritol'],
    'Ksylitol' => [240, 0.0, 0.0, 100.0, 'sweetener, xylitol'],
    'Słodzik' => [0, 0.0, 0.0, 0.0, 'sweetener, tabletop, sucralose'],
    'Dżem' => [250, 0.4, 0.1, 62.0, 'jams and preserves'],
    'Budyń waniliowy' => [350, 0.5, 0.2, 86.0, 'pudding, vanilla, dry mix'],
    'Powidła śliwkowe' => [210, 0.8, 0.2, 52.0, 'plum butter, spread'],

    // -------------------------------------------------------------------- sauce
    'Pasta gochujang' => [220, 5.0, 1.5, 47.0, 'sauce, gochujang, chili paste'],
    'Sos teriyaki' => [89, 5.9, 0.0, 15.6, 'sauce, teriyaki, ready-to-serve'],
    'Kostka rosołowa' => [250, 10.0, 15.0, 20.0, 'soup, bouillon cube, dry'],
    'Musztarda' => [66, 4.4, 3.7, 5.8, 'mustard, prepared, yellow'],
    'Sos sojowy' => [53, 8.1, 0.6, 4.9, 'soy sauce, made from soy and wheat'],
    'Majonez' => [680, 1.0, 75.0, 1.3, 'mayonnaise, regular'],
    'Koncentrat pomidorowy' => [82, 4.3, 0.5, 18.9, 'tomato paste, canned'],
    'Ocet balsamiczny' => [88, 0.5, 0.0, 17.0, 'vinegar, balsamic'],
    'Ketchup' => [102, 1.3, 0.1, 25.8, 'catsup'],
    'Przecier pomidorowy' => [35, 1.6, 0.2, 7.0, 'tomato puree, canned'],
    'Ocet winny' => [19, 0.0, 0.0, 0.3, 'vinegar, red wine'],
    'Ocet ryżowy' => [18, 0.0, 0.0, 0.5, 'vinegar, rice'],
    'Chrzan' => [48, 1.2, 0.7, 11.3, 'horseradish, prepared'],
    'Ostry sos chili' => [30, 1.0, 0.5, 5.5, 'sauce, hot chile, ready-to-serve'],
    'Ocet jabłkowy' => [21, 0.0, 0.0, 0.9, 'vinegar, cider'],
    'Sos rybny' => [35, 5.0, 0.0, 3.6, 'sauce, fish, ready-to-serve'],
    'Pesto' => [450, 5.0, 45.0, 5.0, 'sauce, pesto, basil'],
    'Sos worcester' => [78, 0.0, 0.0, 19.0, 'sauce, worcestershire'],
    'Tahini' => [595, 17.0, 53.8, 21.2, 'seeds, sesame butter, tahini'],
    'Ocet spirytusowy' => [18, 0.0, 0.0, 0.6, 'vinegar, distilled'],
    'Pasta miso' => [199, 12.8, 6.0, 25.4, 'miso'],
    'Sos pomidorowy' => [45, 1.5, 1.0, 7.0, 'sauce, tomato, canned'],
    'Sos ostrygowy' => [51, 1.4, 0.3, 11.0, 'sauce, oyster, ready-to-serve'],
    'Żurek zakwas' => [25, 0.8, 0.1, 5.0, 'sour rye starter, liquid'],
    'Sos BBQ' => [172, 0.8, 0.6, 41.0, 'sauce, barbecue'],
    'Bulion warzywny' => [3, 0.2, 0.1, 0.4, 'soup, vegetable broth, prepared'],

    // ------------------------------------------------------------------- spice
    'Anyż gwiazdkowy' => [337, 17.6, 15.9, 50.0, 'spices, anise, star'],
    'Trawa cytrynowa' => [99, 1.8, 0.5, 25.3, 'lemongrass (citronella), raw'],
    'Sól' => [0, 0.0, 0.0, 0.0, 'salt, table'],
    'Pieprz czarny' => [251, 10.4, 3.3, 64.0, 'spices, pepper, black'],
    'Papryka słodka' => [282, 14.1, 12.9, 54.0, 'spices, paprika'],
    'Chili' => [282, 12.0, 14.0, 50.0, 'spices, chili powder'],
    'Liść laurowy' => [313, 7.6, 8.4, 75.0, 'spices, bay leaf'],
    'Imbir' => [80, 1.8, 0.8, 17.8, 'ginger root, raw'],
    'Ziele angielskie' => [263, 6.1, 8.7, 72.1, 'spices, allspice, ground'],
    'Cynamon' => [247, 4.0, 1.2, 80.6, 'spices, cinnamon, ground'],
    'Majeranek' => [271, 12.7, 7.0, 60.6, 'spices, marjoram, dried'],
    'Gałka muszkatołowa' => [525, 5.8, 36.3, 49.3, 'spices, nutmeg, ground'],
    'Papryka ostra' => [282, 14.1, 12.9, 54.0, 'spices, paprika, hot'],
    'Papryka wędzona' => [282, 14.1, 12.9, 54.0, 'spices, paprika, smoked'],
    'Kmin rzymski' => [375, 17.8, 22.3, 44.2, 'spices, cumin seed'],
    'Kurkuma' => [354, 7.8, 9.9, 64.9, 'spices, turmeric, ground'],
    'Curry' => [325, 14.3, 14.0, 55.8, 'spices, curry powder'],
    'Zioła prowansalskie' => [265, 9.0, 5.0, 50.0, 'spices, herbes de provence, dried'],
    'Kminek' => [333, 19.8, 14.6, 49.9, 'spices, caraway seed'],
    'Goździki' => [274, 6.0, 13.0, 65.5, 'spices, cloves, ground'],
    'Gorczyca' => [508, 26.1, 36.2, 28.1, 'spices, mustard seed, yellow'],
    'Kardamon' => [311, 10.8, 6.7, 68.5, 'spices, cardamom'],
    'Zioła suszone' => [265, 9.0, 5.0, 50.0, 'spices, herbs, dried, mixed'],
    'Garam masala' => [325, 14.0, 14.0, 55.0, 'spices, garam masala'],
    'Przyprawa do piernika' => [300, 6.0, 6.0, 60.0, 'spices, gingerbread spice mix'],
    'Szafran' => [310, 11.4, 5.9, 65.4, 'spices, saffron'],

    // -------------------------------------------------------------------- herb
    'Natka pietruszki' => [36, 3.0, 0.8, 6.3, 'parsley, fresh'],
    'Koperek' => [43, 3.5, 1.1, 7.0, 'dill weed, fresh'],
    'Szczypiorek' => [30, 3.3, 0.7, 4.4, 'chives, raw'],
    // Dried, because that is the jar a Polish kitchen reaches for; fresh basil
    // is the one that gets bought as a pot and is nearer 23.
    'Oregano' => [265, 9.0, 4.3, 68.9, 'spices, oregano, dried'],
    'Bazylia' => [23, 3.2, 0.6, 2.7, 'basil, fresh'],
    'Tymianek' => [276, 9.1, 7.4, 63.9, 'spices, thyme, dried'],
    'Kolendra' => [23, 2.1, 0.5, 3.7, 'coriander (cilantro) leaves, raw'],
    'Rozmaryn' => [131, 3.3, 5.9, 20.7, 'rosemary, fresh'],
    'Mięta' => [44, 3.3, 0.7, 8.4, 'peppermint, fresh'],
    'Szałwia' => [315, 10.6, 12.8, 60.7, 'spices, sage, ground'],
    'Lubczyk' => [30, 3.0, 0.5, 5.0, 'lovage, fresh'],
    'Cząber' => [272, 6.7, 5.9, 68.7, 'spices, savory, ground'],

    // ---------------------------------------------------------------- nut_seed
    'Orzechy włoskie' => [654, 15.2, 65.2, 13.7, 'nuts, walnuts, english'],
    'Sezam' => [573, 17.7, 49.7, 23.4, 'seeds, sesame seeds, whole'],
    'Migdały' => [579, 21.2, 49.9, 21.6, 'nuts, almonds'],
    'Słonecznik' => [584, 20.8, 51.5, 20.0, 'seeds, sunflower seed kernels'],
    'Pestki dyni' => [559, 30.2, 49.1, 10.7, 'seeds, pumpkin seed kernels'],
    'Wiórki kokosowe' => [660, 6.9, 64.5, 23.7, 'nuts, coconut meat, dried, desiccated'],
    'Siemię lniane' => [534, 18.3, 42.2, 28.9, 'seeds, flaxseed'],
    'Nasiona chia' => [486, 16.5, 30.7, 42.1, 'seeds, chia seeds, dried'],
    'Nerkowce' => [553, 18.2, 43.9, 30.2, 'nuts, cashew nuts, raw'],
    'Pistacje' => [560, 20.2, 45.3, 27.2, 'nuts, pistachio nuts, raw'],
    'Orzeszki ziemne' => [567, 25.8, 49.2, 16.1, 'peanuts, all types, raw'],
    'Orzeszki pinii' => [673, 13.7, 68.4, 13.1, 'nuts, pine nuts, dried'],
    'Orzechy laskowe' => [628, 15.0, 60.8, 16.7, 'nuts, hazelnuts or filberts'],
    'Mak' => [525, 18.0, 41.6, 28.1, 'seeds, poppy seed'],
    'Orzechy pekan' => [691, 9.2, 72.0, 13.9, 'nuts, pecans'],

    // ------------------------------------------------------------------ legume
    'Tempeh' => [193, 19.0, 11.0, 9.4, 'tempeh, cooked'],
    'Seitan' => [150, 25.0, 2.0, 6.0, 'seitan, wheat gluten, prepared'],
    /*
     * Tinned, and this is the entry most likely to be wrong for any given recipe.
     * A Polish recipe asking for "fasola" nearly always means the drained
     * contents of a tin, which is a third of the calories of the same weight
     * soaked from dry — so tinned is the reading that is right more often. A
     * recipe that did mean dry beans will be under-counted, and there is no way
     * to tell the two apart from the line alone.
     */
    'Fasola' => [110, 7.5, 0.5, 19.0, 'beans, kidney, canned, drained'],
    'Fasola biała' => [110, 7.5, 0.5, 19.0, 'beans, white, canned, drained'],
    'Fasola czerwona' => [110, 7.5, 0.5, 19.0, 'beans, kidney, red, canned, drained'],
    'Fasola czarna' => [110, 7.5, 0.5, 19.0, 'beans, black, canned, drained'],
    'Ciecierzyca' => [120, 6.0, 2.0, 18.0, 'chickpeas, canned, drained'],
    // Dry, unlike the beans above, because "100 g soczewicy" is dry in
    // practically every recipe here. The inconsistency belongs to the catalogue.
    'Soczewica' => [353, 24.6, 1.1, 60.1, 'lentils, raw'],
    'Tofu' => [76, 8.1, 4.8, 1.9, 'tofu, firm, prepared'],
    'Bób' => [88, 7.9, 0.7, 12.0, 'broad beans (fava), immature seeds, raw'],
    'Hummus' => [166, 7.9, 9.6, 14.3, 'hummus, commercial'],

    // ------------------------------------------------------------------ baking
    'Proszek do pieczenia' => [53, 0.0, 0.0, 27.7, 'leavening agents, baking powder'],
    'Drożdże' => [105, 8.4, 1.9, 12.0, 'leavening agents, yeast, baker\'s, compressed'],
    'Czekolada gorzka' => [546, 4.9, 31.3, 61.2, 'chocolate, dark, 70% cacao'],
    'Kakao' => [228, 19.6, 13.7, 57.9, 'cocoa, dry powder, unsweetened'],
    'Soda oczyszczona' => [0, 0.0, 0.0, 0.0, 'leavening agents, baking soda'],
    'Żelatyna' => [335, 86.0, 0.1, 0.0, 'gelatin, dry powder, unsweetened'],

    // ---------------------------------------------------------------- beverage
    'Sok jabłkowy' => [46, 0.1, 0.1, 11.3, 'apple juice, unsweetened'],
    'Wódka' => [231, 0.0, 0.0, 0.0, 'alcoholic beverage, vodka, 80 proof'],
    'Herbata' => [1, 0.0, 0.0, 0.3, 'beverages, tea, brewed'],
    'Sok ananasowy' => [53, 0.4, 0.1, 12.9, 'pineapple juice, canned'],
    'Woda' => [0, 0.0, 0.0, 0.0, 'water, tap'],
    'Wino białe' => [82, 0.1, 0.0, 2.6, 'alcoholic beverage, wine, table, white'],
    'Wino czerwone' => [85, 0.1, 0.0, 2.6, 'alcoholic beverage, wine, table, red'],
    'Piwo' => [43, 0.5, 0.0, 3.6, 'alcoholic beverage, beer, regular'],
    'Alkohol mocny' => [231, 0.0, 0.0, 0.0, 'alcoholic beverage, distilled, 80 proof'],
    'Sok pomarańczowy' => [45, 0.7, 0.2, 10.4, 'orange juice, raw'],
    'Kawa' => [2, 0.1, 0.0, 0.0, 'beverages, coffee, brewed'],
    'Lód' => [0, 0.0, 0.0, 0.0, 'water, ice'],
    'Sake' => [134, 0.5, 0.0, 5.0, 'alcoholic beverage, sake'],

    // ------------------------------------------------------------------- other
    'Odżywka białkowa' => [380, 75.0, 5.0, 8.0, 'whey protein powder, isolate'],
    'Nori' => [35, 5.8, 0.3, 5.1, 'seaweed, laver, raw'],
    'Płatki drożdżowe' => [350, 50.0, 5.0, 20.0, 'yeast, nutritional, flakes'],
];
