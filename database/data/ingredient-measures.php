<?php

declare(strict_types=1);

/**
 * What a piece, a clove, a bunch or a spoonful of each product weighs.
 *
 * This is what lets the app answer questions it otherwise cannot. A recipe wants
 * "2 cebule", the fridge holds "300 g cebuli", the leaflet sells a 1 kg bag:
 * without a weight for one onion those are three unrelated facts. `Quantity` will
 * never cross a dimension on its own, and that is correct — grams and millilitres
 * are different things until you say *of what*. This file is the *of what*.
 *
 * Keys are **exact canonical product names** from `database/data/ingredients.php`.
 * Not needles, not stems: the emoji file may guess and show a slightly wrong
 * picture, but a wrong weight is a wrong number on a shopping list. `IngredientMeasureSeeder`
 * throws on a name no product has, so a rename here cannot rot silently.
 *
 * Two kinds of entry, and the layering matters:
 *
 *   'density' — grams per millilitre. Covers **every** volume measure at once:
 *               ml, l, łyżka, łyżeczka, szklanka. Use it for anything that
 *               actually pours.
 *   'grams'   — what one of a named measure weighs. Wins over density.
 *
 * The override exists because a spoon is not a physics experiment. A tablespoon
 * of flour is not 15 ml × 0.53 = 8 g; a Polish recipe means a heaped spoon,
 * nearer 15 g, and every recipe in the catalogue was written by someone who meant
 * that. So flour carries a density for glasses and an explicit weight for spoons.
 *
 * Numbers are averages of an ordinary supermarket item and they are honest about
 * being averages. Where a product varies so much that an average would mislead —
 * a "kawałek" of anything, one "sztuka" of minced meat — it is better to leave
 * it out: an absent weight makes the app stay quiet, an invented one makes it
 * confidently wrong about whether you have enough.
 *
 * Grow this from `php artisan ingredients:measures`, which lists what is missing
 * in the order the catalogue actually uses it.
 *
 * Deliberately absent, and it must stay that way: "1 szt. soli", "1 szt. wody",
 * "1 szt. mąki". Those are parser artefacts from lines like "sól do smaku", not
 * things anybody counts, and giving them a weight would invent an amount for
 * every seasoning line in the catalogue.
 */
return [
    // ---------------------------------------------------------------- vegetables
    'Cebula' => ['density' => 0.55, 'grams' => ['piece' => 150]],
    'Szalotka' => ['grams' => ['piece' => 30]],
    'Dymka' => ['grams' => ['piece' => 15, 'bunch' => 60]],
    // A clove and the whole bulb are both "czosnek" and differ by a factor of
    // nine. This pair is the clearest argument for keying weights on (product,
    // unit) rather than on either one alone.
    'Czosnek' => ['grams' => ['clove' => 5, 'piece' => 45, 'tsp' => 3, 'tbsp' => 9]],
    'Marchew' => ['grams' => ['piece' => 80]],
    'Pietruszka korzeń' => ['grams' => ['piece' => 80]],
    'Seler korzeniowy' => ['grams' => ['piece' => 600]],
    'Seler naciowy' => ['grams' => ['piece' => 60, 'bunch' => 400]],
    'Por' => ['grams' => ['piece' => 200]],
    'Ziemniak' => ['grams' => ['piece' => 120]],
    'Bataty' => ['grams' => ['piece' => 200]],
    'Burak' => ['grams' => ['piece' => 150]],
    'Pomidor' => ['grams' => ['piece' => 120, 'can' => 400]],
    'Pomidorki koktajlowe' => ['grams' => ['piece' => 15]],
    'Papryka' => ['grams' => ['piece' => 150]],
    'Chili' => ['grams' => ['piece' => 10, 'tsp' => 2, 'tbsp' => 6]],
    'Papryczka jalapeño' => ['grams' => ['piece' => 15]],
    'Cukinia' => ['grams' => ['piece' => 300]],
    'Bakłażan' => ['grams' => ['piece' => 300]],
    'Ogórek' => ['grams' => ['piece' => 200]],
    'Ogórek kiszony' => ['grams' => ['piece' => 60]],
    'Ogórek konserwowy' => ['grams' => ['piece' => 50, 'jar' => 400]],
    'Rzodkiewka' => ['grams' => ['piece' => 10, 'bunch' => 150]],
    'Brokuł' => ['grams' => ['piece' => 500]],
    'Kalafior' => ['grams' => ['piece' => 900]],
    'Kapusta' => ['density' => 0.35, 'grams' => ['piece' => 1200]],
    'Kapusta kiszona' => ['density' => 0.6, 'grams' => ['cup' => 150, 'tbsp' => 10]],
    'Kapusta pekińska' => ['grams' => ['piece' => 800]],
    'Kapusta czerwona' => ['density' => 0.35, 'grams' => ['piece' => 1000]],
    'Brukselka' => ['grams' => ['piece' => 20]],
    'Kalarepa' => ['grams' => ['piece' => 250]],
    'Sałata' => ['grams' => ['piece' => 300]],
    'Rukola' => ['grams' => ['package' => 100]],
    'Szpinak' => ['grams' => ['package' => 450]],
    'Jarmuż' => ['grams' => ['piece' => 200]],
    'Botwinka' => ['grams' => ['bunch' => 400]],
    'Szparagi' => ['grams' => ['piece' => 20, 'bunch' => 500]],
    'Pieczarki' => ['grams' => ['piece' => 20]],
    'Dynia' => ['grams' => ['piece' => 2000]],
    'Kukurydza' => ['grams' => ['piece' => 250, 'can' => 300]],
    'Groszek zielony' => ['grams' => ['can' => 400]],
    'Imbir' => ['grams' => ['piece' => 30, 'tsp' => 2, 'tbsp' => 6]],
    'Suszone pomidory' => ['grams' => ['piece' => 5]],
    'Oliwki zielone' => ['grams' => ['piece' => 4, 'jar' => 300]],
    'Kapary' => ['grams' => ['jar' => 100, 'tbsp' => 9, 'tsp' => 3]],

    // ------------------------------------------------------------------- fruit
    'Jabłko' => ['grams' => ['piece' => 180]],
    'Gruszka' => ['grams' => ['piece' => 170]],
    'Banan' => ['grams' => ['piece' => 120]],
    // A spoon of a citrus fruit means its juice; there is no other way to spoon
    // one. The density is the juice's, which is what "łyżka soku z cytryny" —
    // 514 lines of the catalogue — actually needs.
    'Cytryna' => ['density' => 1.03, 'grams' => ['piece' => 100]],
    'Limonka' => ['density' => 1.03, 'grams' => ['piece' => 60]],
    'Pomarańcza' => ['density' => 1.04, 'grams' => ['piece' => 200]],
    'Mango' => ['grams' => ['piece' => 300]],
    'Awokado' => ['grams' => ['piece' => 200]],
    'Granat' => ['grams' => ['piece' => 250]],
    'Brzoskwinia' => ['grams' => ['piece' => 150]],
    'Śliwki' => ['grams' => ['piece' => 30]],
    'Morele suszone' => ['grams' => ['piece' => 8]],
    'Ananas' => ['grams' => ['piece' => 1200, 'slice' => 80, 'can' => 400]],
    'Rodzynki' => ['density' => 0.6, 'grams' => ['tbsp' => 12]],
    'Żurawina' => ['density' => 0.55, 'grams' => ['tbsp' => 10]],

    // ------------------------------------------------------------ herbs, greens
    // A bunch is what a shop sells; a sprig is what a recipe picks off it.
    'Natka pietruszki' => ['grams' => ['bunch' => 30, 'sprig' => 3, 'piece' => 30, 'tbsp' => 4]],
    'Koperek' => ['grams' => ['bunch' => 25, 'sprig' => 2, 'piece' => 25, 'tbsp' => 4]],
    'Szczypiorek' => ['grams' => ['bunch' => 30, 'tbsp' => 4]],
    'Kolendra' => ['grams' => ['bunch' => 25, 'tsp' => 1.8, 'tbsp' => 4]],
    'Bazylia' => ['grams' => ['bunch' => 25, 'piece' => 25, 'leaf' => 0.5, 'tsp' => 1, 'tbsp' => 3]],
    'Mięta' => ['grams' => ['bunch' => 25, 'leaf' => 0.4, 'sprig' => 2]],
    'Rozmaryn' => ['grams' => ['sprig' => 3, 'tsp' => 1.5, 'tbsp' => 4]],
    'Tymianek' => ['grams' => ['sprig' => 2, 'tsp' => 1.5, 'tbsp' => 4]],

    // ------------------------------------------------------- dried spices, leaf
    // Whole spices weigh almost nothing and are counted, not weighed. Without
    // these, "3 liście laurowe" has no weight at all and every soup looks like it
    // is short of something.
    'Liść laurowy' => ['grams' => ['piece' => 0.2, 'leaf' => 0.2]],
    'Ziele angielskie' => ['grams' => ['piece' => 0.1]],
    'Goździki' => ['grams' => ['piece' => 0.05, 'tsp' => 2]],
    'Sól' => ['density' => 1.2, 'grams' => ['tsp' => 6, 'tbsp' => 18, 'pinch' => 0.4]],
    'Pieprz czarny' => ['density' => 0.5, 'grams' => ['tsp' => 2.5, 'tbsp' => 7, 'pinch' => 0.2]],
    'Papryka słodka' => ['grams' => ['tsp' => 2.3, 'tbsp' => 7]],
    'Papryka ostra' => ['grams' => ['tsp' => 2.3, 'tbsp' => 7]],
    'Papryka wędzona' => ['grams' => ['tsp' => 2.3, 'tbsp' => 7]],
    'Oregano' => ['grams' => ['tsp' => 1, 'tbsp' => 3]],
    'Lubczyk' => ['grams' => ['tsp' => 1, 'tbsp' => 3, 'sprig' => 2]],
    'Cząber' => ['grams' => ['tsp' => 1, 'tbsp' => 3]],
    'Szałwia' => ['grams' => ['tsp' => 1, 'tbsp' => 3, 'leaf' => 0.3, 'sprig' => 2]],
    'Majeranek' => ['grams' => ['tsp' => 1, 'tbsp' => 3]],
    'Zioła prowansalskie' => ['grams' => ['tsp' => 1, 'tbsp' => 3]],
    'Zioła suszone' => ['grams' => ['tsp' => 1, 'tbsp' => 3]],
    'Cynamon' => ['grams' => ['tsp' => 2.6, 'tbsp' => 8]],
    'Kurkuma' => ['grams' => ['tsp' => 3, 'tbsp' => 9]],
    'Curry' => ['grams' => ['tsp' => 2.5, 'tbsp' => 7]],
    'Garam masala' => ['grams' => ['tsp' => 2.5, 'tbsp' => 7]],
    'Kmin rzymski' => ['grams' => ['tsp' => 2.1, 'tbsp' => 6]],
    'Kminek' => ['grams' => ['tsp' => 2.1, 'tbsp' => 6]],
    'Gałka muszkatołowa' => ['grams' => ['tsp' => 2.2, 'tbsp' => 7]],
    'Kardamon' => ['grams' => ['tsp' => 2, 'tbsp' => 6, 'piece' => 0.2]],
    'Gorczyca' => ['grams' => ['tsp' => 3.3, 'tbsp' => 10]],
    'Szafran' => ['grams' => ['tsp' => 0.7]],
    'Przyprawa do mięsa' => ['grams' => ['tsp' => 2.5, 'tbsp' => 7]],
    'Przyprawa gyros' => ['grams' => ['tsp' => 2.5, 'tbsp' => 7]],

    // ---------------------------------------------------------- fats and liquids
    // These genuinely pour, so one density covers spoons, glasses and millilitres
    // alike and there is nothing to override.
    'Oliwa z oliwek' => ['density' => 0.91],
    'Olej rzepakowy' => ['density' => 0.92],
    'Olej sezamowy' => ['density' => 0.92],
    'Olej kokosowy' => ['density' => 0.92],
    'Olej z pestek dyni' => ['density' => 0.92],
    'Olej lniany' => ['density' => 0.93],
    'Smalec' => ['density' => 0.92, 'grams' => ['tbsp' => 13, 'tsp' => 4]],
    'Pestki dyni' => ['density' => 0.55, 'grams' => ['tbsp' => 9, 'tsp' => 3, 'handful' => 30]],
    'Śliwki suszone' => ['grams' => ['piece' => 8]],
    'Woda' => ['density' => 1.0],
    'Bulion warzywny' => ['density' => 1.0, 'grams' => ['cube' => 10, 'piece' => 10]],
    'Bulion drobiowy' => ['density' => 1.0, 'grams' => ['cube' => 10, 'piece' => 10]],
    'Bulion wołowy' => ['density' => 1.0, 'grams' => ['cube' => 10, 'piece' => 10]],
    'Mleko' => ['density' => 1.03],
    'Napój owsiany' => ['density' => 1.02],
    'Mleko kokosowe' => ['density' => 0.98, 'grams' => ['can' => 400]],
    'Śmietana 18%' => ['density' => 1.01],
    'Śmietanka 30%' => ['density' => 0.99],
    'Jogurt naturalny' => ['density' => 1.03],
    'Jogurt grecki' => ['density' => 1.05],
    'Kefir' => ['density' => 1.03],
    'Wino białe' => ['density' => 0.99],
    'Wino czerwone' => ['density' => 0.99],
    'Piwo' => ['density' => 1.01],
    'Sake' => ['density' => 0.99],
    'Alkohol mocny' => ['density' => 0.94],
    'Sok pomarańczowy' => ['density' => 1.05],
    'Żurek zakwas' => ['density' => 1.02],

    // --------------------------------------------------------- sauces and pastes
    'Sos sojowy' => ['density' => 1.15],
    'Sos rybny' => ['density' => 1.2],
    'Sos ostrygowy' => ['density' => 1.2],
    'Sos worcester' => ['density' => 1.1],
    'Ostry sos chili' => ['density' => 1.1],
    'Sos pomidorowy' => ['density' => 1.03],
    'Ocet balsamiczny' => ['density' => 1.06],
    'Ocet winny' => ['density' => 1.01],
    'Ocet ryżowy' => ['density' => 1.01],
    'Ocet jabłkowy' => ['density' => 1.01],
    'Ocet spirytusowy' => ['density' => 1.01],
    'Musztarda' => ['density' => 1.05],
    'Ketchup' => ['density' => 1.14],
    'Majonez' => ['density' => 0.91],
    'Chrzan' => ['density' => 1.0],
    'Pesto' => ['density' => 0.95],
    'Tahini' => ['density' => 1.05],
    'Pasta miso' => ['density' => 1.25],
    'Koncentrat pomidorowy' => ['density' => 1.07],
    'Przecier pomidorowy' => ['density' => 1.03],
    'Pomidory z puszki' => ['density' => 1.03, 'grams' => ['can' => 400]],
    'Miód' => ['density' => 1.42],
    'Syrop klonowy' => ['density' => 1.32],
    'Dżem' => ['density' => 1.33],

    // ---------------------------------------------------- dry goods and baking
    // Everything here needs the spoon override: a level scientific spoonful of
    // flour is half what a Polish recipe means by "łyżka mąki".
    'Mąka pszenna' => ['density' => 0.53, 'grams' => ['tsp' => 5, 'tbsp' => 15, 'cup' => 130]],
    'Mąka ziemniaczana' => ['density' => 0.68, 'grams' => ['tsp' => 6, 'tbsp' => 18, 'cup' => 160]],
    'Mąka pełnoziarnista' => ['density' => 0.55, 'grams' => ['tsp' => 5, 'tbsp' => 15, 'cup' => 135]],
    'Mąka orkiszowa' => ['density' => 0.53, 'grams' => ['tsp' => 5, 'tbsp' => 15, 'cup' => 130]],
    'Mąka żytnia' => ['density' => 0.5, 'grams' => ['tsp' => 5, 'tbsp' => 14, 'cup' => 125]],
    'Mąka kukurydziana' => ['density' => 0.6, 'grams' => ['tsp' => 6, 'tbsp' => 17, 'cup' => 150]],
    'Mąka ryżowa' => ['density' => 0.6, 'grams' => ['tsp' => 6, 'tbsp' => 17, 'cup' => 150]],
    'Mąka owsiana' => ['density' => 0.4, 'grams' => ['tsp' => 4, 'tbsp' => 11, 'cup' => 100]],
    'Mąka migdałowa' => ['density' => 0.4, 'grams' => ['tsp' => 4, 'tbsp' => 11, 'cup' => 100]],
    'Bułka tarta' => ['density' => 0.45, 'grams' => ['tsp' => 4, 'tbsp' => 12, 'cup' => 110]],
    'Panko' => ['density' => 0.25, 'grams' => ['tbsp' => 6, 'cup' => 60]],
    'Cukier' => ['density' => 0.85, 'grams' => ['tsp' => 5, 'tbsp' => 15, 'cup' => 200]],
    'Cukier puder' => ['density' => 0.56, 'grams' => ['tsp' => 3, 'tbsp' => 10, 'cup' => 130]],
    'Cukier wanilinowy' => ['density' => 0.8, 'grams' => ['tsp' => 4, 'tbsp' => 12, 'package' => 16]],
    'Proszek do pieczenia' => ['grams' => ['tsp' => 4, 'tbsp' => 12, 'package' => 15]],
    'Soda oczyszczona' => ['grams' => ['tsp' => 4.6, 'tbsp' => 14]],
    'Drożdże' => ['grams' => ['tsp' => 3, 'tbsp' => 9, 'cube' => 100, 'package' => 7]],
    'Żelatyna' => ['grams' => ['tsp' => 3, 'tbsp' => 9, 'package' => 20]],
    'Kakao' => ['density' => 0.4, 'grams' => ['tsp' => 2.5, 'tbsp' => 7]],
    'Budyń waniliowy' => ['grams' => ['package' => 40]],

    // ------------------------------------------------------------ grains, pulses
    'Ryż' => ['density' => 0.85, 'grams' => ['tbsp' => 15, 'cup' => 200]],
    'Kasza gryczana' => ['density' => 0.75, 'grams' => ['tbsp' => 13, 'cup' => 180]],
    'Kasza jaglana' => ['density' => 0.8, 'grams' => ['tbsp' => 14, 'cup' => 190]],
    'Kasza pęczak' => ['density' => 0.8, 'grams' => ['tbsp' => 14, 'cup' => 190]],
    'Kasza bulgur' => ['density' => 0.75, 'grams' => ['tbsp' => 13, 'cup' => 180]],
    'Ryż brązowy' => ['density' => 0.85, 'grams' => ['tbsp' => 15, 'cup' => 200]],
    'Quinoa' => ['density' => 0.75, 'grams' => ['tbsp' => 13, 'cup' => 180]],
    'Płatki owsiane' => ['density' => 0.36, 'grams' => ['tbsp' => 7, 'cup' => 90]],
    'Makaron' => ['grams' => ['package' => 500]],
    'Soczewica' => ['density' => 0.85, 'grams' => ['tbsp' => 13, 'cup' => 200]],
    'Ciecierzyca' => ['density' => 0.8, 'grams' => ['tbsp' => 12, 'cup' => 190, 'can' => 400]],
    'Fasola' => ['density' => 0.8, 'grams' => ['tbsp' => 12, 'cup' => 190, 'can' => 400]],
    'Fasola czerwona' => ['density' => 0.8, 'grams' => ['tbsp' => 12, 'cup' => 190, 'can' => 400]],
    'Fasola biała' => ['density' => 0.8, 'grams' => ['tbsp' => 12, 'cup' => 190, 'can' => 400]],
    'Fasola czarna' => ['density' => 0.8, 'grams' => ['tbsp' => 12, 'cup' => 190, 'can' => 400]],

    // ------------------------------------------------------------- nuts, seeds
    'Orzechy włoskie' => ['density' => 0.45, 'grams' => ['tbsp' => 8, 'cup' => 110]],
    'Orzechy laskowe' => ['density' => 0.6, 'grams' => ['tbsp' => 10, 'cup' => 140]],
    'Orzechy pekan' => ['density' => 0.45, 'grams' => ['tbsp' => 8, 'cup' => 110]],
    // A herring fillet, which is how they are sold and how recipes count them.
    'Śledź' => ['grams' => ['piece' => 90]],
    'Orzeszki ziemne' => ['density' => 0.6, 'grams' => ['tbsp' => 10, 'cup' => 145]],
    'Migdały' => ['density' => 0.6, 'grams' => ['tbsp' => 10, 'cup' => 140]],
    'Sezam' => ['density' => 0.6, 'grams' => ['tsp' => 3, 'tbsp' => 9]],
    'Słonecznik' => ['density' => 0.55, 'grams' => ['tbsp' => 9]],
    'Siemię lniane' => ['density' => 0.65, 'grams' => ['tsp' => 3.5, 'tbsp' => 10]],
    'Nasiona chia' => ['density' => 0.7, 'grams' => ['tsp' => 4, 'tbsp' => 12]],
    'Wiórki kokosowe' => ['density' => 0.3, 'grams' => ['tbsp' => 5]],

    // ------------------------------------------------------------ dairy and eggs
    'Jajko' => ['grams' => ['piece' => 55]],
    'Masło' => ['density' => 0.91, 'grams' => ['tsp' => 8, 'tbsp' => 25, 'cube' => 200, 'piece' => 200]],
    'Margaryna' => ['density' => 0.9, 'grams' => ['tbsp' => 25, 'cube' => 250]],
    // Grated, which is how a recipe spoons or measures cheese by the glass.
    'Ser żółty' => ['density' => 0.4, 'grams' => ['slice' => 20, 'piece' => 20, 'tbsp' => 6, 'cup' => 100]],
    'Parmezan' => ['density' => 0.4, 'grams' => ['tbsp' => 6]],
    'Mozzarella' => ['grams' => ['piece' => 125, 'package' => 125]],
    'Feta' => ['grams' => ['package' => 200]],
    'Serek kremowy' => ['density' => 1.0, 'grams' => ['package' => 150]],
    'Twaróg' => ['density' => 1.05],

    // --------------------------------------------------------------- meat, fish
    'Pierś z kurczaka' => ['grams' => ['piece' => 180]],
    'Udka z kurczaka' => ['grams' => ['piece' => 130]],
    'Polędwica wieprzowa' => ['grams' => ['piece' => 400]],
    'Boczek' => ['grams' => ['slice' => 15]],
    'Szynka' => ['grams' => ['slice' => 15]],
    // A kotlet is what the shop sells and what "4 kotlety schabowe" counts.
    'Schab' => ['grams' => ['slice' => 100, 'piece' => 150]],
    'Kiełbasa' => ['grams' => ['piece' => 100]],
    'Parówki' => ['grams' => ['piece' => 40]],
    'Łosoś' => ['grams' => ['piece' => 150]],
    'Tuńczyk' => ['grams' => ['can' => 150]],

    // ------------------------------------------------------------------- bakery
    'Chleb' => ['grams' => ['piece' => 500, 'slice' => 35]],
    'Bułka' => ['grams' => ['piece' => 60]],
    'Bagietka' => ['grams' => ['piece' => 250]],
    'Tortilla' => ['grams' => ['piece' => 45, 'package' => 320]],
    'Naleśniki' => ['grams' => ['piece' => 40]],
    'Ciasto francuskie' => ['grams' => ['package' => 275]],
];
