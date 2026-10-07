<?php

declare(strict_types=1);

/**
 * The plain sides no website publishes — read by `App\Importing\Sources\House`.
 *
 * Every source in the catalogue writes recipes worth a headline: roast potatoes
 * with a secret ingredient, kasza "cooked" in an air fryer. Nobody writes a page
 * about boiled potatoes with dill, and that is exactly what a Polish obiad puts
 * beside a cutlet — so the week planner had nothing ordinary to offer, and both
 * reviewers of generated weeks asked for it in every round.
 *
 * These go through the importer like any site: the lines are parsed, resolved
 * against the dictionary and counted for calories, so they are held to the same
 * rules as everything else and improve with it. Write lines the way a recipe
 * does — an amount, a unit, the product — and steps one action at a time, which
 * is what the cooking screen walks through.
 *
 * Changing an entry is a re-import: `php artisan recipes:import house --replace`.
 */

return [
    // — Starch —
    [
        'slug' => 'ziemniaki-gotowane-z-koperkiem',
        'title' => 'Ziemniaki gotowane z koperkiem',
        'servings' => 4,
        'minutes' => 30,
        'ingredients' => ['1 kg ziemniaków', '1 łyżka masła', '2 łyżki posiekanego koperku', 'sól'],
        'steps' => [
            'Ziemniaki obierz, opłucz i pokrój na podobnej wielkości kawałki.',
            'Zalej zimną wodą, posól i doprowadź do wrzenia.',
            'Gotuj pod przykryciem 20 minut, aż będą miękkie.',
            'Odcedź, dodaj masło i posyp koperkiem.',
        ],
    ],
    [
        'slug' => 'puree-ziemniaczane',
        'title' => 'Puree ziemniaczane',
        'servings' => 4,
        'minutes' => 30,
        'ingredients' => ['1 kg ziemniaków', '2 łyżki masła', '100 ml mleka', 'sól', 'gałka muszkatołowa'],
        'steps' => [
            'Ziemniaki obierz, pokrój i ugotuj w osolonej wodzie do miękkości, około 20 minut.',
            'Mleko podgrzej z masłem.',
            'Ziemniaki odcedź i rozgnieć tłuczkiem, stopniowo wlewając ciepłe mleko z masłem.',
            'Dopraw solą i szczyptą gałki muszkatołowej.',
        ],
    ],
    [
        'slug' => 'ziemniaki-w-mundurkach',
        'title' => 'Ziemniaki gotowane w mundurkach',
        'servings' => 4,
        'minutes' => 35,
        'ingredients' => ['1 kg drobnych ziemniaków', '1 łyżka masła', 'sól'],
        'steps' => [
            'Ziemniaki dokładnie wyszoruj szczoteczką, nie obieraj.',
            'Zalej zimną osoloną wodą i gotuj 25 minut od zawrzenia.',
            'Odcedź i podawaj z masłem.',
        ],
    ],
    [
        'slug' => 'mlode-ziemniaki-z-koperkiem',
        'title' => 'Młode ziemniaki z koperkiem',
        'servings' => 4,
        'minutes' => 25,
        'ingredients' => ['1 kg młodych ziemniaków', '2 łyżki masła', '3 łyżki posiekanego koperku', 'sól'],
        'steps' => [
            'Młode ziemniaki oskrob lub dokładnie umyj.',
            'Gotuj w osolonej wodzie 15 minut.',
            'Odcedź, dodaj masło i koperek, wymieszaj.',
        ],
    ],
    [
        'slug' => 'kasza-gryczana-na-sypko',
        'title' => 'Kasza gryczana na sypko',
        'servings' => 4,
        'minutes' => 20,
        'ingredients' => ['200 g kaszy gryczanej', '400 ml wody', '1 łyżeczka masła', 'sól'],
        'steps' => [
            'Wodę zagotuj z solą i masłem.',
            'Wsyp kaszę, zmniejsz ogień i gotuj pod przykryciem 15 minut, nie mieszając.',
            'Zdejmij z ognia i odstaw na 5 minut pod przykryciem, potem rozluźnij widelcem.',
        ],
    ],
    [
        'slug' => 'kasza-peczak-gotowana',
        'title' => 'Kasza pęczak gotowana',
        'servings' => 4,
        'minutes' => 40,
        'ingredients' => ['200 g kaszy pęczak', '600 ml wody', '1 łyżeczka masła', 'sól'],
        'steps' => [
            'Kaszę przepłucz na sitku.',
            'Wsyp do wrzącej osolonej wody i gotuj na małym ogniu 30 minut.',
            'Odcedź nadmiar wody i wymieszaj z masłem.',
        ],
    ],
    [
        'slug' => 'kasza-jeczmienna-z-cebulka',
        'title' => 'Kasza jęczmienna z podsmażoną cebulką',
        'servings' => 4,
        'minutes' => 30,
        'ingredients' => ['200 g kaszy pęczak', '600 ml wody', '1 cebula', '1 łyżka oleju', 'sól'],
        'steps' => [
            'Kaszę ugotuj w osolonej wodzie na małym ogniu przez 25 minut.',
            'Cebulę pokrój w kostkę i zeszklij na oleju.',
            'Wymieszaj kaszę z cebulką.',
        ],
    ],
    [
        'slug' => 'ryz-bialy-na-sypko',
        'title' => 'Ryż biały na sypko',
        'servings' => 4,
        'minutes' => 20,
        'ingredients' => ['200 g ryżu', '400 ml wody', 'sól'],
        'steps' => [
            'Ryż przepłucz na sitku, aż woda będzie czysta.',
            'Wsyp do wrzącej osolonej wody, przykryj i gotuj na małym ogniu 12 minut.',
            'Odstaw pod przykryciem na 5 minut i rozluźnij widelcem.',
        ],
    ],
    [
        'slug' => 'ryz-brazowy-gotowany',
        'title' => 'Ryż brązowy gotowany',
        'servings' => 4,
        'minutes' => 35,
        'ingredients' => ['200 g ryżu brązowego', '500 ml wody', 'sól'],
        'steps' => [
            'Ryż przepłucz na sitku.',
            'Wsyp do wrzącej osolonej wody i gotuj pod przykryciem 30 minut.',
            'Odstaw na 5 minut i rozluźnij widelcem.',
        ],
    ],
    [
        'slug' => 'kasza-bulgur-gotowana',
        'title' => 'Kasza bulgur gotowana',
        'servings' => 4,
        'minutes' => 15,
        'ingredients' => ['200 g kaszy bulgur', '400 ml wody', '1 łyżeczka oliwy', 'sól'],
        'steps' => [
            'Wodę zagotuj z solą i oliwą.',
            'Wsyp kaszę, gotuj na małym ogniu 10 minut pod przykryciem.',
            'Odstaw na 5 minut i rozluźnij widelcem.',
        ],
    ],

    // — Vegetables —
    [
        'slug' => 'mizeria-ze-smietana',
        'title' => 'Mizeria ze śmietaną',
        'servings' => 4,
        'minutes' => 10,
        'ingredients' => ['2 ogórki', '4 łyżki śmietany 18%', '1 łyżka posiekanego koperku', 'sól', 'pieprz'],
        'steps' => [
            'Ogórki obierz i pokrój w cienkie plasterki.',
            'Lekko posól i odstaw na 5 minut, potem odlej sok.',
            'Wymieszaj ze śmietaną i koperkiem, dopraw pieprzem.',
        ],
    ],
    [
        'slug' => 'surowka-z-marchewki-i-jablka',
        'title' => 'Surówka z marchewki i jabłka',
        'servings' => 4,
        'minutes' => 10,
        'ingredients' => ['4 marchewki', '1 jabłko', '1 łyżka soku z cytryny', '1 łyżka oleju', 'sól'],
        'steps' => [
            'Marchewki obierz i zetrzyj na tarce o drobnych oczkach.',
            'Jabłko zetrzyj razem ze skórką.',
            'Wymieszaj z sokiem z cytryny i olejem, dopraw solą.',
        ],
    ],
    [
        'slug' => 'surowka-z-kiszonej-kapusty-z-marchewka',
        'title' => 'Surówka z kiszonej kapusty z marchewką',
        'servings' => 4,
        'minutes' => 10,
        'ingredients' => ['500 g kapusty kiszonej', '1 marchewka', '1 jabłko', '1 łyżka oleju', '1 łyżeczka cukru'],
        'steps' => [
            'Kapustę odciśnij i posiekaj.',
            'Marchewkę i jabłko zetrzyj na tarce.',
            'Wymieszaj wszystko z olejem i cukrem.',
        ],
    ],
    [
        'slug' => 'surowka-z-bialej-kapusty',
        'title' => 'Surówka z białej kapusty',
        'servings' => 4,
        'minutes' => 15,
        'ingredients' => ['500 g białej kapusty', '1 marchewka', '1 łyżka octu', '1 łyżka oleju', '1 łyżeczka cukru', 'sól'],
        'steps' => [
            'Kapustę cienko poszatkuj, posól i ugnieć dłonią.',
            'Dodaj startą marchewkę.',
            'Wymieszaj z octem, olejem i cukrem.',
        ],
    ],
    [
        'slug' => 'buraczki-zasmazane-domowe',
        'title' => 'Buraczki zasmażane',
        'servings' => 4,
        'minutes' => 60,
        'ingredients' => ['4 buraki', '1 łyżka masła', '1 łyżka mąki', '1 łyżka soku z cytryny', '1 łyżeczka cukru', 'sól'],
        'steps' => [
            'Buraki ugotuj w skórce do miękkości, około 45 minut.',
            'Ostudź, obierz i zetrzyj na tarce.',
            'Na maśle zrumień mąkę, dodaj buraki i podgrzewaj 5 minut.',
            'Dopraw sokiem z cytryny, cukrem i solą.',
        ],
    ],
    [
        'slug' => 'marchewka-z-groszkiem',
        'title' => 'Marchewka z groszkiem',
        'servings' => 4,
        'minutes' => 20,
        'ingredients' => ['4 marchewki', '200 g groszku zielonego', '1 łyżka masła', '1 łyżeczka mąki', 'sól'],
        'steps' => [
            'Marchewki obierz i pokrój w kostkę.',
            'Gotuj w małej ilości osolonej wody 10 minut, dodaj groszek i gotuj 5 minut.',
            'Dodaj masło wymieszane z mąką i zagotuj, aż sos zgęstnieje.',
        ],
    ],
    [
        'slug' => 'cwikla-z-chrzanem',
        'title' => 'Ćwikła z chrzanem',
        'servings' => 4,
        'minutes' => 60,
        'ingredients' => ['4 buraki', '2 łyżki tartego chrzanu', '1 łyżka soku z cytryny', '1 łyżeczka cukru', 'sól'],
        'steps' => [
            'Buraki ugotuj w skórce do miękkości, około 45 minut.',
            'Ostudź, obierz i zetrzyj na tarce o drobnych oczkach.',
            'Wymieszaj z chrzanem, sokiem z cytryny, cukrem i solą.',
        ],
    ],
    [
        'slug' => 'surowka-z-pora-z-jogurtem',
        'title' => 'Surówka z pora z jogurtem',
        'servings' => 4,
        'minutes' => 10,
        'ingredients' => ['1 por', '1 marchewka', '1 jabłko', '3 łyżki jogurtu naturalnego', 'sól', 'pieprz'],
        'steps' => [
            'Pora pokrój w cienkie półplasterki.',
            'Marchewkę i jabłko zetrzyj na tarce.',
            'Wymieszaj z jogurtem, dopraw solą i pieprzem.',
        ],
    ],
];
