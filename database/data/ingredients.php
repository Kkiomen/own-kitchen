<?php

declare(strict_types=1);

/**
 * Seed dictionary of common Polish cooking products.
 *
 * `aliases` lists the declined and colloquial spellings that show up in recipe
 * text. They are normalised by the seeder, so write them naturally, with
 * diacritics. Growing this list is the cheapest way to improve import quality —
 * prefer adding entries here over adding cleverness to the parser.
 *
 * `unit` is the measure the product is normally bought or used in, and becomes
 * the default when a recipe line gives an amount without one.
 */
return [
    // Vegetables
    ['name' => 'Cebula', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['cebuli', 'cebulę', 'cebule', 'cebulą', 'cebulki', 'cebulka', 'cebul', 'cebulek']],
    ['name' => 'Czosnek', 'category' => 'vegetable', 'unit' => 'clove', 'aliases' => ['czosnku', 'czosnkiem', 'czosku', 'ząbki czosnku', 'ząbek czosnku', 'czosnki', 'czosnków', 'główka czosnku', 'główki czosnku']],
    ['name' => 'Marchew', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['marchewka', 'marchewki', 'marchwi', 'marchewkę', 'marchewek', 'marchewki mini']],
    ['name' => 'Ziemniak', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['ziemniaki', 'ziemniaków', 'ziemniaka', 'kartofle', 'ziemniaczki', 'ziemniaczków']],
    ['name' => 'Pomidor', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['pomidory', 'pomidorów', 'pomidora', 'pomidorki']],
    ['name' => 'Pomidory z puszki', 'category' => 'vegetable', 'unit' => 'can', 'aliases' => ['pomidorów z puszki', 'krojone pomidory', 'passata']],
    ['name' => 'Papryka', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['papryki', 'paprykę', 'papryka czerwona', 'papryki czerwonej']],
    ['name' => 'Ogórek', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['ogórka', 'ogórki', 'ogórków', 'ogórek zielony', 'ogórek szklarniowy', 'ogórek gruntowy']],
    // A jar of pickles is not a fresh cucumber, and "ogórki konserwowe" was
    // reducing to "ogórki". Kiszony (brine) already had its own entry; this is
    // the vinegar one, and Polish recipes mean different things by them.
    ['name' => 'Ogórek konserwowy', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['ogórków konserwowych', 'ogórki konserwowe', 'ogórka konserwowego', 'korniszony', 'korniszonów', 'korniszona']],
    ['name' => 'Cukinia', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['cukinii', 'cukinię', 'cukinie', 'cukini']],
    ['name' => 'Bakłażan', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['bakłażana', 'bakłażany']],
    ['name' => 'Pieczarki', 'category' => 'vegetable', 'unit' => 'g', 'aliases' => ['pieczarek', 'pieczarki białe', 'pieczarkami']],
    ['name' => 'Kurki', 'category' => 'vegetable', 'unit' => 'g', 'aliases' => ['kurek', 'kurkami', 'kurki świeże']],
    ['name' => 'Por', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['pora', 'pory']],
    ['name' => 'Seler naciowy', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['selera naciowego', 'seler']],
    ['name' => 'Kapusta', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['kapusty', 'kapustę', 'kapusta biała', 'kapusty białej', 'kapusta włoska', 'kapusty włoskiej', 'kapusta młoda', 'kapusty młodej', 'główka kapusty', 'główki kapusty']],
    // Sauerkraut is not a cabbage you shred and Chinese leaf is a salad. Both
    // were reducing to plain "kapusty" — 182 lines of it.
    ['name' => 'Kapusta kiszona', 'category' => 'vegetable', 'unit' => 'g', 'aliases' => ['kapusty kiszonej', 'kapusta kiszona', 'kiszonej kapusty', 'kapusta kwaszona', 'kapusty kwaszonej']],
    ['name' => 'Kapusta pekińska', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['kapusty pekińskiej', 'kapusta pekińska', 'pekińska']],
    ['name' => 'Kapusta czerwona', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['kapusty czerwonej', 'kapusta czerwona', 'czerwonej kapusty']],
    ['name' => 'Brokuł', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['brokuła', 'brokuły', 'brokułów']],
    ['name' => 'Kalafior', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['kalafiora', 'kalafiory']],
    // Was five invented products at once — kalarepa, kalarepy, kalarepki,
    // kalarepka, "mniejszych kalarep" — over 38 recipe lines, and filed to the
    // pantry rather than the fridge because an invention has no category.
    ['name' => 'Brukselka', 'category' => 'vegetable', 'unit' => 'g', 'aliases' => ['brukselki', 'brukselek', 'brukselkę', 'kapusta brukselka']],
    ['name' => 'Kalarepa', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['kalarepy', 'kalarepę', 'kalarepka', 'kalarepki', 'kalarepek', 'kalarep']],
    ['name' => 'Szpinak', 'category' => 'vegetable', 'unit' => 'g', 'aliases' => ['szpinaku', 'szpinakiem', 'szpinak baby']],
    ['name' => 'Sałata', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['sałaty', 'sałatę', 'sałata lodowa']],
    ['name' => 'Dynia', 'category' => 'vegetable', 'unit' => 'g', 'aliases' => ['dyni', 'dynię']],
    ['name' => 'Burak', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['buraki', 'buraka', 'buraków', 'buraczki', 'buraczków']],
    ['name' => 'Groszek zielony', 'category' => 'vegetable', 'unit' => 'g', 'aliases' => ['groszku', 'groszek', 'groszku zielonego']],
    ['name' => 'Kukurydza', 'category' => 'vegetable', 'unit' => 'can', 'aliases' => ['kukurydzy', 'kukurydzę']],

    // Fruit
    ['name' => 'Jabłko', 'category' => 'fruit', 'unit' => 'piece', 'aliases' => ['jabłka', 'jabłek', 'jabłkami', 'jabłko']],
    ['name' => 'Banan', 'category' => 'fruit', 'unit' => 'piece', 'aliases' => ['banany', 'banana', 'bananów']],
    ['name' => 'Cytryna', 'category' => 'fruit', 'unit' => 'piece', 'aliases' => ['cytryny', 'cytrynę', 'sok z cytryny', 'skórka z cytryny']],
    ['name' => 'Limonka', 'category' => 'fruit', 'unit' => 'piece', 'aliases' => ['limonki', 'limonkę']],
    ['name' => 'Pomarańcza', 'category' => 'fruit', 'unit' => 'piece', 'aliases' => ['pomarańczy', 'pomarańcze']],
    ['name' => 'Owoce', 'category' => 'fruit', 'unit' => 'g', 'aliases' => ['owoców', 'owocami', 'owoce sezonowe', 'owoców sezonowych', 'owoce mieszane']],
    ['name' => 'Truskawki', 'category' => 'fruit', 'unit' => 'g', 'aliases' => ['truskawek', 'truskawki świeże']],
    ['name' => 'Maliny', 'category' => 'fruit', 'unit' => 'g', 'aliases' => ['malin', 'malinami']],
    ['name' => 'Borówki', 'category' => 'fruit', 'unit' => 'g', 'aliases' => ['borówek', 'jagody', 'jagód']],
    ['name' => 'Śliwki', 'category' => 'fruit', 'unit' => 'g', 'aliases' => ['śliwek', 'śliwki węgierki']],
    // Prunes, like the dried apricots that already have their own entry.
    ['name' => 'Śliwki suszone', 'category' => 'fruit', 'unit' => 'g', 'aliases' => ['suszonych śliwek', 'śliwki suszone', 'śliwek suszonych', 'suszone śliwki', 'suszonej śliwki']],
    ['name' => 'Rodzynki', 'category' => 'fruit', 'unit' => 'g', 'aliases' => ['rodzynek', 'rodzynki sułtańskie']],

    // Meat & fish
    ['name' => 'Pierś z kurczaka', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['piersi z kurczaka', 'filet z kurczaka', 'kurczaka', 'kurczak', 'piersi kurczaka', 'pierś kurczaka', 'filety z kurczaka', 'filetów z kurczaka']],
    ['name' => 'Udka z kurczaka', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['udek z kurczaka', 'udka kurczaka', 'udek kurczaka', 'udziec z kurczaka', 'podudzia z kurczaka', 'podudzi z kurczaka', 'podudzia', 'podudzi', 'pałki kurczaka', 'pałek kurczaka', 'pałka kurczaka', 'pałki z kurczaka', 'pałek z kurczaka']],
    // "600 g mięsa z rosołu", "około 1 kg mięsa" — 210 lines saying meat and
    // nothing more precise. Curated as `meat` so Wege excludes them rather than
    // declining to judge; the specific cuts still win, being longer phrases.
    ['name' => 'Mięso', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['mięsa', 'mięsem', 'mięso mieszane', 'mięsa mieszanego']],
    ['name' => 'Mięso mielone wieprzowe', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['mięsa mielonego', 'mięso mielone', 'mięsa wieprzowego', 'siekanego mięsa wieprzowego', 'mielonego mięsa', 'mielone mięso', 'mielonej wieprzowiny', 'mielonego schabu', 'łopatki']],
    // Bare "mielonego" belongs to the pork above, so each of these has to say so
    // itself: 200-odd lines of minced beef, turkey and chicken were pork.
    ['name' => 'Mięso mielone wołowe', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['mięsa mielonego wołowego', 'mielonej wołowiny', 'mięso mielone z wołowiny', 'wołowiny mielonej', 'mielonego mięsa wołowego']],
    ['name' => 'Mięso mielone drobiowe', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['mięsa mielonego drobiowego', 'mielonego drobiowego', 'mięso mielone z indyka', 'mięsa mielonego z indyka', 'mielonego indyka', 'mielonego mięsa z indyka', 'mięso mielone z kurczaka', 'mięsa mielonego z kurczaka', 'mielonego kurczaka', 'mielonego mięsa drobiowego', 'mielonego mięsa indyka', 'mielone mięso drobiowe', 'mielonego mięsa z kurczaka']],
    ['name' => 'Wołowina', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['wołowiny', 'mięso wołowe', 'wołowe', 'mięsa wołowego', 'szponder', 'mostek wołowy', 'łata', 'antrykotu', 'antrykot', 'rozbratla', 'mięsa stekowego', 'ogon wołowy', 'ogona wołowego', 'rostbef', 'rostbefu', 'rostbefu wołowego']],
    ['name' => 'Boczek', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['boczku', 'boczkiem', 'boczek wędzony', 'boczku w słupkach']],
    ['name' => 'Słonina', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['słoniny', 'słoniną']],
    // The genitive plural drops the stem vowel — skrzydełka → skrzydełek — and
    // that is the form a recipe writes after an amount. The same trap the emoji
    // needles document.
    ['name' => 'Skrzydełka z kurczaka', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['skrzydełek', 'skrzydełka', 'skrzydełek z kurczaka', 'skrzydełka kurczaka', 'skrzydełek kurczaka']],
    ['name' => 'Wieprzowina', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['wieprzowiny', 'wieprzowiną', 'mięso wieprzowe']],
    ['name' => 'Szynka', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['szynki', 'szynkę']],
    ['name' => 'Kiełbasa', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['kiełbasy', 'kiełbasę', 'kiełbaski', 'kiełbaski wiejskiej']],
    ['name' => 'Łosoś', 'category' => 'fish', 'unit' => 'g', 'aliases' => ['łososia', 'filet z łososia', 'łosoś wędzony']],
    ['name' => 'Śledź', 'category' => 'fish', 'unit' => 'g', 'aliases' => ['śledzia', 'śledzie', 'śledzi', 'filety śledziowe', 'filetów śledziowych', 'filet śledziowy', 'śledzie matjas', 'płaty śledziowe']],
    ['name' => 'Tuńczyk', 'category' => 'fish', 'unit' => 'can', 'aliases' => ['tuńczyka', 'tuńczyk w puszce']],
    ['name' => 'Krewetki', 'category' => 'fish', 'unit' => 'g', 'aliases' => ['krewetek', 'krewetkami']],

    // Dairy & eggs
    ['name' => 'Jajko', 'category' => 'egg', 'unit' => 'piece', 'aliases' => ['jajka', 'jajek', 'jaja', 'jaj', 'jajko kurze', 'żółtka', 'żółtko', 'żółtek', 'białek', 'białka', 'białko']],
    ['name' => 'Mleko', 'category' => 'dairy', 'unit' => 'ml', 'aliases' => ['mleka', 'mlekiem', 'mleko 3,2%']],
    ['name' => 'Masło', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['masła', 'masłem', 'masło extra']],
    ['name' => 'Śmietana 18%', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['śmietany', 'śmietaną', 'śmietana kwaśna', 'śmietany 18%']],
    ['name' => 'Śmietanka 30%', 'category' => 'dairy', 'unit' => 'ml', 'aliases' => ['śmietanki', 'śmietanka kremówka', 'śmietanki 30%', 'kremówka', 'śmietany kremówki', 'śmietanki kremówki', 'śmietana kremówka', 'śmietanki 36%', 'śmietanka 36%']],
    /*
     * "ser", "sera" and "serem" belong here because a Polish recipe saying only
     * "100 g sera" means the yellow block. The cost of holding those is that
     * **every named cheese has to claim its own two-word spelling**, or it is
     * swallowed: "sera mozzarella" reduces to "sera" — the resolver tries longer
     * groups first, but only groups that are aliases, and a bare "sera" was the
     * only one on offer. A fridge photograph found it; the catalogue then showed
     * ~500 lines of mozzarella, feta, parmesan, gorgonzola, ricotta and white
     * curd filed as a block of gouda.
     *
     * So each entry below carries the "ser/sera + variety" forms. Gouda, cheddar,
     * edam and gruyère deliberately do **not**: those genuinely are yellow cheese
     * and Ser żółty is the right answer for them.
     */
    ['name' => 'Ser żółty', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['sera żółtego', 'ser', 'sera', 'serem', 'ser gouda', 'sera gouda', 'ser cheddar', 'sera cheddar', 'ser edamski', 'sera edamskiego', 'ser gruyere', 'sera gruyere']],
    ['name' => 'Parmezan', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['parmezanu', 'parmezanem', 'grana padano', 'pecorino', 'sera pecorino', 'ser parmezan', 'sera parmezan', 'sera parmezanu', 'ser parmezański', 'sera parmezańskiego']],
    ['name' => 'Mozzarella', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['mozzarelli', 'mozzarellę', 'ser mozzarella', 'sera mozzarella', 'serem mozzarella', 'sera mozzarelli', 'ser mozarella', 'sera mozarella']],
    ['name' => 'Twaróg', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['twarogu', 'twarogiem', 'ser biały', 'sera białego', 'zmielonego twarogu', 'biały ser', 'białego sera', 'ser twarogowy', 'sera twarogowego', 'ser twarogowy półtłusty', 'sera twarogowego półtłustego', 'ser chudy', 'sera chudego']],
    ['name' => 'Serek kremowy', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['serka kremowego', 'cream cheese', 'philadelphia', 'almette', 'bieluch', 'serek almette', 'serek śmietankowy', 'serek kanapkowy', 'serka kanapkowego', 'serka śmietankowego', 'serka śmietankowego w plastrach', 'śmietankowy serek', 'śmietankowego serka']],
    ['name' => 'Jogurt naturalny', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['jogurtu', 'jogurtu naturalnego', 'jogurt']],
    ['name' => 'Mascarpone', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['mascarpone serek', 'serek mascarpone', 'serka mascarpone', 'serkiem mascarpone']],

    // Grains, pasta, bread
    ['name' => 'Makaron spaghetti', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['makaronu spaghetti', 'spaghetti']],
    // Shapes, not products: a household buys "makaron" and the shape is which bag
    // it came in. Each of these was a separate invented product before now.
    ['name' => 'Makaron', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['makaronu', 'makaronem', 'makaron penne', 'makaron świderki', 'penne', 'świderki', 'świderków', 'tagliatelle', 'farfalle', 'fusilli', 'kokardki', 'muszelki', 'makaron pełnoziarnisty', 'makaronu pełnoziarnistego', 'ramen', 'udon', 'makaron chow mein']],
    ['name' => 'Ryż', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['ryżu', 'ryżem', 'ryż biały', 'ryż basmati']],
    ['name' => 'Kasza gryczana', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['kaszy gryczanej', 'kasza']],
    ['name' => 'Kasza jaglana', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['kaszy jaglanej']],
    ['name' => 'Mąka pszenna', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['mąki pszennej', 'mąki', 'mąka', 'mąką', 'mąki tortowej', 'mąka tortowa', 'mąki krupczatki']],
    // Same shape as the oils: bare "mąki" is wheat, so every other flour claims
    // its own spelling. A gluten-free recipe made with "mąka pszenna" is worse
    // than a wrong amount — it is the wrong answer to the only question asked.
    ['name' => 'Mąka pełnoziarnista', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['mąki pełnoziarnistej', 'mąka razowa', 'mąki razowej', 'mąka graham', 'mąki graham']],
    ['name' => 'Mąka orkiszowa', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['mąki orkiszowej']],
    ['name' => 'Mąka kukurydziana', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['mąki kukurydzianej', 'skrobia kukurydziana', 'skrobi kukurydzianej']],
    ['name' => 'Mąka ryżowa', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['mąki ryżowej']],
    ['name' => 'Mąka żytnia', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['mąki żytniej']],
    ['name' => 'Mąka owsiana', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['mąki owsianej']],
    ['name' => 'Mąka migdałowa', 'category' => 'nut_seed', 'unit' => 'g', 'aliases' => ['mąki migdałowej']],
    ['name' => 'Mąka ziemniaczana', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['mąki ziemniaczanej', 'skrobia ziemniaczana', 'skrobi ziemniaczanej', 'skrobi', 'skrobii ziemniaczanej']],
    ['name' => 'Bułka tarta', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['bułki tartej', 'tartej bułki', 'tarta bułka', 'bułką tartą']],
    ['name' => 'Chleb', 'category' => 'grain', 'unit' => 'slice', 'aliases' => ['chleba', 'pieczywo', 'pieczywa', 'kromka chleba', 'kromek chleba', 'kromki chleba', 'kromka pieczywa', 'kromek pieczywa', 'kromki pieczywa']],
    ['name' => 'Płatki owsiane', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['płatków owsianych', 'płatki']],
    ['name' => 'Herbatniki', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['herbatników', 'ciastka', 'ciasteczka', 'herbatniki lotus biscoff']],
    ['name' => 'Kuskus', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['kuskusu', 'kuskusem', 'kaszy kuskus', 'kasza kuskus']],

    // Legumes, nuts, seeds
    ['name' => 'Ciecierzyca', 'category' => 'legume', 'unit' => 'can', 'aliases' => ['ciecierzycy', 'cieciorka', 'ciecierzyca z puszki']],
    ['name' => 'Fasola', 'category' => 'legume', 'unit' => 'can', 'aliases' => ['fasoli', 'fasolę', 'fasolka', 'fasolki']],
    // The colours are different beans in a Polish kitchen — red for chilli,
    // white for soups — and "fasoli czerwonej" reduced to plain "fasoli".
    ['name' => 'Fasola czerwona', 'category' => 'legume', 'unit' => 'can', 'aliases' => ['fasoli czerwonej', 'fasola czerwona', 'czerwonej fasolki', 'czerwona fasolka', 'fasola kidney', 'fasoli kidney']],
    ['name' => 'Fasola biała', 'category' => 'legume', 'unit' => 'can', 'aliases' => ['fasoli białej', 'fasola biała', 'białej fasolki', 'biała fasolka', 'fasola jaś', 'fasoli jaś']],
    ['name' => 'Fasola czarna', 'category' => 'legume', 'unit' => 'can', 'aliases' => ['fasoli czarnej', 'fasola czarna', 'czarnej fasoli']],
    // A green vegetable, not a dried pulse. Without its own entry "Fasolka
    // szparagowa" resolved to Fasola on the alias "fasolka", which is a different
    // thing to cook and a different thing to buy. The two-word alias wins over
    // the one-word one because the resolver tries longer groups first.
    ['name' => 'Fasolka szparagowa', 'category' => 'vegetable', 'unit' => 'g', 'aliases' => ['fasolki szparagowej', 'fasolkę szparagową', 'szparagowa', 'szparagowej']],
    ['name' => 'Soczewica', 'category' => 'legume', 'unit' => 'g', 'aliases' => ['soczewicy', 'soczewica czerwona']],
    ['name' => 'Orzechy włoskie', 'category' => 'nut_seed', 'unit' => 'g', 'aliases' => ['orzechów włoskich', 'orzechy', 'orzechów']],
    ['name' => 'Orzechy laskowe', 'category' => 'nut_seed', 'unit' => 'g', 'aliases' => ['orzechów laskowych', 'orzechy laskowe', 'orzeszki laskowe', 'orzeszków laskowych']],
    ['name' => 'Orzechy pekan', 'category' => 'nut_seed', 'unit' => 'g', 'aliases' => ['orzechów pekan', 'orzechy pekan', 'pekan']],
    ['name' => 'Migdały', 'category' => 'nut_seed', 'unit' => 'g', 'aliases' => ['migdałów', 'płatki migdałowe', 'migdaów', 'płatków migdałów', 'płatków migdałowych', 'migdałami']],
    ['name' => 'Pistacje', 'category' => 'nut_seed', 'unit' => 'g', 'aliases' => ['pistacji', 'pistacjami']],
    ['name' => 'Słonecznik', 'category' => 'nut_seed', 'unit' => 'g', 'aliases' => ['słonecznika', 'pestki słonecznika', 'pestek słonecznika', 'nasion słonecznika', 'nasiona słonecznika', 'ziarna słonecznika']],
    // Pumpkin seeds are not pumpkin, and "pestki dyni" was resolving to Dynia.
    ['name' => 'Pestki dyni', 'category' => 'nut_seed', 'unit' => 'g', 'aliases' => ['pestek dyni', 'pestkami dyni', 'nasion dyni', 'nasiona dyni']],
    ['name' => 'Sezam', 'category' => 'nut_seed', 'unit' => 'g', 'aliases' => ['sezamu', 'ziarna sezamu', 'ziaren sezamu', 'nasion sezamu', 'nasiona sezamu', 'sezam biały', 'sezamu białego']],

    // Herbs & spices
    ['name' => 'Sól', 'category' => 'spice', 'unit' => 'g', 'staple' => true, 'aliases' => ['soli', 'solą', 'sól morska', 'soli morskiej']],
    ['name' => 'Pieprz czarny', 'category' => 'spice', 'unit' => 'g', 'staple' => true, 'aliases' => ['pieprzu', 'pieprz', 'pieprzem', 'czarny pieprz', 'zmielony czarny pieprz', 'pieprzu czarnego', 'pierzu', 'mielonego pieprzu', 'mielony pieprz', 'mielonego czarnego pieprzu', 'mielonego białego pieprzu', 'białego pieprzu', 'biały pieprz']],
    ['name' => 'Papryka słodka', 'category' => 'spice', 'unit' => 'g', 'staple' => true, 'aliases' => ['papryki słodkiej', 'słodka papryka', 'papryka w proszku', 'słodkiej papryki', 'mielonej papryki', 'mielona papryka', 'mielonej słodkiej papryki', 'papryki mielonej', 'papryka mielona', 'papryki w proszku']],
    ['name' => 'Oregano', 'category' => 'herb', 'unit' => 'g', 'staple' => true, 'aliases' => ['oregano suszone', 'oregan', 'suszonego oregano']],
    ['name' => 'Bazylia', 'category' => 'herb', 'unit' => 'g', 'aliases' => ['bazylii', 'bazylię', 'bazylia świeża', 'bazyli', 'świeżej bazyli', 'świeża bazylia']],
    ['name' => 'Natka pietruszki', 'category' => 'herb', 'unit' => 'bunch', 'aliases' => ['natki pietruszki', 'pietruszki', 'natka', 'natkę pietruszki']],
    ['name' => 'Koperek', 'category' => 'herb', 'unit' => 'bunch', 'aliases' => ['koperku', 'koperkiem', 'koper', 'kopru', 'świeżego kopru']],
    ['name' => 'Tymianek', 'category' => 'herb', 'unit' => 'g', 'aliases' => ['tymianku', 'tymiankiem']],
    ['name' => 'Lubczyk', 'category' => 'herb', 'unit' => 'g', 'aliases' => ['lubczyku', 'lubczyk ogrodowy', 'lubczyku ogrodowego']],
    ['name' => 'Cząber', 'category' => 'herb', 'unit' => 'g', 'aliases' => ['cząbru', 'cząber ogrodowy', 'cząbru ogrodowego']],
    ['name' => 'Szałwia', 'category' => 'herb', 'unit' => 'g', 'aliases' => ['szałwii', 'szałwi', 'liści szałwii', 'liście szałwii']],
    ['name' => 'Kiełki', 'category' => 'vegetable', 'unit' => 'g', 'aliases' => ['kiełków', 'kiełki rzodkiewki', 'kiełków rzodkiewki', 'kiełki lucerny', 'kiełki brokuła']],
    ['name' => 'Rozmaryn', 'category' => 'herb', 'unit' => 'g', 'aliases' => ['rozmarynu', 'rozmarynem']],
    ['name' => 'Mięta', 'category' => 'herb', 'unit' => 'g', 'aliases' => ['mięty', 'miętę']],
    ['name' => 'Liść laurowy', 'category' => 'spice', 'unit' => 'leaf', 'staple' => true, 'aliases' => ['liścia laurowego', 'liście laurowe', 'listek laurowy', 'liść laurowy', 'listki laurowe', 'liści laurowych']],
    ['name' => 'Ziele angielskie', 'category' => 'spice', 'unit' => 'piece', 'staple' => true, 'aliases' => ['ziela angielskiego', 'ziela angielskie', 'ziarna ziela angielskiego']],
    ['name' => 'Cynamon', 'category' => 'spice', 'unit' => 'g', 'staple' => true, 'aliases' => ['cynamonu', 'cynamonem', 'mielonego cynamonu', 'cynamon mielony']],
    ['name' => 'Curry', 'category' => 'spice', 'unit' => 'g', 'staple' => true, 'aliases' => ['przyprawa curry']],
    ['name' => 'Kmin rzymski', 'category' => 'spice', 'unit' => 'g', 'staple' => true, 'aliases' => ['kminu rzymskiego', 'kumin', 'kminu', 'nasion kuminu', 'nasiona kuminu', 'kuminu', 'mielonego kuminu', 'mielonego kminu', 'kumin mielony']],
    ['name' => 'Gałka muszkatołowa', 'category' => 'spice', 'unit' => 'g', 'staple' => true, 'aliases' => ['gałki muszkatołowej']],
    ['name' => 'Imbir', 'category' => 'spice', 'unit' => 'g', 'aliases' => ['imbiru', 'imbirem', 'imbir świeży', 'mielonego imbiru', 'imbir mielony', 'imbiru mielonego']],
    ['name' => 'Papryka ostra', 'category' => 'spice', 'unit' => 'g', 'staple' => true, 'aliases' => ['papryki ostrej', 'ostra papryka', 'ostrej papryki', 'papryka ostra mielona']],
    ['name' => 'Papryka wędzona', 'category' => 'spice', 'unit' => 'g', 'staple' => true, 'aliases' => ['papryki wędzonej', 'wędzona papryka', 'wędzonej papryki', 'papryka wędzona mielona']],
    ['name' => 'Chili', 'category' => 'spice', 'unit' => 'g', 'staple' => true, 'aliases' => ['chilli', 'papryczka chili', 'płatki chili', 'papryczki chili', 'ostra papryczka', 'ostre papryczki', 'ostrej papryczki', 'ostrych papryczek', 'papryczek chili', 'mielonego chili', 'mielonego chilli', 'chili mielone']],

    // Fats, sauces, liquids
    ['name' => 'Oliwa z oliwek', 'category' => 'fat', 'unit' => 'ml', 'staple' => true, 'aliases' => ['oliwy z oliwek', 'oliwy', 'oliwa']],
    /*
     * `olej` and `ocet` are bare aliases for the same reason `ser` is: a line
     * saying only "2 łyżki oleju" means the neutral one in the cupboard. And for
     * the same reason, **every other oil and vinegar has to claim its own
     * two-word spelling or it is swallowed** — sesame oil is a flavouring and
     * rice vinegar is not balsamic, but "oleju sezamowego" reduced to "oleju"
     * and ~200 lines of it were rapeseed. See the cheese note above: this is one
     * failure, not three.
     */
    ['name' => 'Olej rzepakowy', 'category' => 'fat', 'unit' => 'ml', 'staple' => true, 'aliases' => ['oleju', 'olej', 'olej roślinny', 'oleju roślinnego', 'olej słonecznikowy', 'oleju słonecznikowego', 'tłuszcz do smażenia', 'tłuszczu do smażenia', 'tłuszcz do wysmarowania']],
    // "tłuszcz do smażenia" was imported as a product 61 times. It is a real
    // instruction and the household's answer to it is the neutral oil; it lands
    // in the Fat category either way, which the availability check exempts, so
    // this cannot make a recipe look uncookable.
    ['name' => 'Smalec', 'category' => 'fat', 'unit' => 'g', 'aliases' => ['smalcu', 'smalcem', 'smalec gęsi']],
    ['name' => 'Olej sezamowy', 'category' => 'fat', 'unit' => 'ml', 'aliases' => ['oleju sezamowego', 'olej sezamowy prażony', 'oleju sezamowego prażonego']],
    ['name' => 'Olej kokosowy', 'category' => 'fat', 'unit' => 'ml', 'aliases' => ['oleju kokosowego']],
    // An oil pressed from them is not the seeds: "oleju z pestek dyni" started
    // resolving to Pestki dyni the moment those got an entry of their own.
    ['name' => 'Olej z pestek dyni', 'category' => 'fat', 'unit' => 'ml', 'aliases' => ['oleju z pestek dyni', 'olej dyniowy', 'oleju dyniowego']],
    ['name' => 'Olej lniany', 'category' => 'fat', 'unit' => 'ml', 'aliases' => ['oleju lnianego']],
    ['name' => 'Ocet balsamiczny', 'category' => 'sauce', 'unit' => 'ml', 'aliases' => ['octu balsamicznego', 'ocet']],
    ['name' => 'Ocet winny', 'category' => 'sauce', 'unit' => 'ml', 'aliases' => ['octu winnego', 'ocet winny biały', 'octu winnego białego', 'ocet z czerwonego wina', 'octu z czerwonego wina']],
    ['name' => 'Ocet ryżowy', 'category' => 'sauce', 'unit' => 'ml', 'aliases' => ['octu ryżowego']],
    ['name' => 'Ocet jabłkowy', 'category' => 'sauce', 'unit' => 'ml', 'aliases' => ['octu jabłkowego']],
    ['name' => 'Ocet spirytusowy', 'category' => 'sauce', 'unit' => 'ml', 'aliases' => ['octu spirytusowego', 'ocet 10%']],
    ['name' => 'Sos sojowy', 'category' => 'sauce', 'unit' => 'ml', 'aliases' => ['sosu sojowego']],
    ['name' => 'Musztarda', 'category' => 'sauce', 'unit' => 'g', 'aliases' => ['musztardy', 'musztardę', 'musztarda dijon', 'musztardy dijon', 'musztarda sarepska', 'musztardy sarepskiej', 'musztarda francuska', 'musztardy francuskiej']],
    ['name' => 'Majonez', 'category' => 'sauce', 'unit' => 'g', 'aliases' => ['majonezu', 'majonezem']],
    // Deliberately does NOT claim "przecier pomidorowy": that is a thinner product
    // with its own entry, and letting concentrate own the alias made recipe steps
    // resolve to an ingredient the recipe never listed.
    ['name' => 'Koncentrat pomidorowy', 'category' => 'sauce', 'unit' => 'g', 'aliases' => ['koncentratu pomidorowego', 'koncentrat']],
    /*
     * A chicken stock filed as vegetable stock is not a small error: the Wege
     * category is computed from ingredients, and this one lied on 359 lines.
     *
     * The two meat stocks are categorised as `meat` rather than `sauce`, which
     * is what makes Wege exclude a recipe built on them — that category keys on
     * ingredient categories, and "someone avoiding meat must not be handed a
     * guess" outranks a carton of stock sitting in the meat aisle.
     */
    ['name' => 'Bulion warzywny', 'category' => 'sauce', 'unit' => 'ml', 'aliases' => ['bulionu', 'bulionu warzywnego', 'rosół', 'wywar', 'bulion jarzynowy', 'bulionu jarzynowego']],
    ['name' => 'Bulion drobiowy', 'category' => 'meat', 'unit' => 'ml', 'aliases' => ['bulionu drobiowego', 'rosół drobiowy', 'rosołu drobiowego', 'bulion z kurczaka', 'bulionu z kurczaka', 'wywar drobiowy', 'wywaru drobiowego']],
    ['name' => 'Bulion wołowy', 'category' => 'meat', 'unit' => 'ml', 'aliases' => ['bulionu wołowego', 'wywar wołowy', 'wywaru wołowego']],
    ['name' => 'Woda', 'category' => 'beverage', 'unit' => 'ml', 'staple' => true, 'aliases' => ['wody', 'wodą', 'wrzątek', 'wrzątku']],
    ['name' => 'Wino białe', 'category' => 'beverage', 'unit' => 'ml', 'aliases' => ['wina białego', 'białe wino', 'wina', 'wino', 'wina wytrawnego', 'białego wytrawnego wina']],
    // "wina czerwonego" was resolving to the white one on 71 lines, purely
    // because the bare word belongs there.
    ['name' => 'Wino czerwone', 'category' => 'beverage', 'unit' => 'ml', 'aliases' => ['wina czerwonego', 'czerwone wino', 'czerwonego wina', 'wino czerwone wytrawne']],
    ['name' => 'Mleko kokosowe', 'category' => 'dairy', 'unit' => 'ml', 'aliases' => ['mleka kokosowego', 'mleczko kokosowe', 'mleczka kokosowego']],

    // Sweet & baking
    ['name' => 'Cukier', 'category' => 'sweetener', 'unit' => 'g', 'aliases' => ['cukru', 'cukrem', 'cukier biały', 'cukru trzcinowego', 'cukier trzcinowy']],
    ['name' => 'Cukier puder', 'category' => 'sweetener', 'unit' => 'g', 'aliases' => ['cukru pudru', 'cukier pudrowy']],
    ['name' => 'Cukier wanilinowy', 'category' => 'sweetener', 'unit' => 'g', 'aliases' => ['cukru wanilinowego', 'wanilia', 'ekstrakt waniliowy', 'ekstraktu waniliowego']],
    ['name' => 'Miód', 'category' => 'sweetener', 'unit' => 'g', 'aliases' => ['miodu', 'miodem']],
    ['name' => 'Czekolada gorzka', 'category' => 'baking', 'unit' => 'g', 'aliases' => ['czekolady gorzkiej', 'czekolada', 'czekolady']],
    ['name' => 'Kakao', 'category' => 'baking', 'unit' => 'g', 'aliases' => ['kakao gorzkie']],
    ['name' => 'Proszek do pieczenia', 'category' => 'baking', 'unit' => 'g', 'aliases' => ['proszku do pieczenia']],
    ['name' => 'Soda oczyszczona', 'category' => 'baking', 'unit' => 'g', 'aliases' => ['sody oczyszczonej', 'sody', 'soda']],
    ['name' => 'Drożdże', 'category' => 'baking', 'unit' => 'g', 'aliases' => ['drożdży', 'drożdże świeże', 'drożdże suche']],
    ['name' => 'Żelatyna', 'category' => 'baking', 'unit' => 'g', 'aliases' => ['żelatyny', 'żelatynę']],

    // Added after the first import run flagged these as unrecognised.
    ['name' => 'Filet drobiowy', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['filetów drobiowych', 'filety drobiowe', 'fileta drobiowego', 'filetu drobiowego', 'mięso drobiowe', 'mięsa drobiowego']],
    ['name' => 'Szparagi', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['szparagów', 'szparagi zielone', 'szparaga']],
    ['name' => 'Szczypiorek', 'category' => 'herb', 'unit' => 'bunch', 'aliases' => ['szczypiorku', 'szczypiorkiem', 'szczypiorek świeży']],
    // A line that genuinely says "świeże zioła (np. pietruszka, bazylia)" names a
    // real thing loosely, and the Herb category is exempt from the availability
    // check — so this is a product, not a guess dressed as one.
    ['name' => 'Zioła', 'category' => 'herb', 'unit' => 'bunch', 'aliases' => ['ziół', 'ziołami', 'zioła świeże', 'świeże zioła', 'zioła prowansalskie świeże']],
    ['name' => 'Kolendra', 'category' => 'herb', 'unit' => 'bunch', 'aliases' => ['kolendry', 'kolendrę', 'kolendra świeża', 'nasion kolendry', 'nasiona kolendry', 'ziarna kolendry']],
    ['name' => 'Awokado', 'category' => 'fruit', 'unit' => 'piece', 'aliases' => ['awokado dojrzałe']],
    ['name' => 'Tortilla', 'category' => 'grain', 'unit' => 'piece', 'aliases' => ['tortille', 'tortilli', 'placki tortilli', 'tortilla pszenna', 'tortilii', 'tortilii pełnoziarnistej', 'tortilla pełnoziarnista']],
    ['name' => 'Makaron orzo', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['orzo', 'makaronu orzo']],
    ['name' => 'Kasza pęczak', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['kaszy pęczak', 'pęczak']],
    ['name' => 'Feta', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['fety', 'fetę', 'ser feta', 'sera feta', 'ser typu feta', 'sera typu feta', 'typu feta', 'ser sałatkowy', 'sera sałatkowego']],
    ['name' => 'Burrata', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['burraty', 'burratę']],
    ['name' => 'Gorgonzola', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['gorgonzoli', 'gorgonzolę', 'ser pleśniowy', 'sera pleśniowego', 'ser gorgonzola', 'sera gorgonzola', 'ser lazur', 'sera lazur', 'ser z niebieską pleśnią', 'sera z niebieską pleśnią', 'niebieską pleśnią']],
    ['name' => 'Ser kozi', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['sera koziego', 'serem kozim', 'koziego sera', 'kozi ser']],
    // Not yellow cheese and not a substitute for it: it squeaks, it grills and it
    // does not melt. It was resolving to Ser żółty on 32 lines through "sera".
    ['name' => 'Halloumi', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['halloumi', 'ser halloumi', 'sera halloumi', 'ser grillowany', 'sera grillowanego']],
    ['name' => 'Przecier pomidorowy', 'category' => 'sauce', 'unit' => 'g', 'aliases' => ['przecieru pomidorowego', 'passata pomidorowa']],
    ['name' => 'Kurkuma', 'category' => 'spice', 'unit' => 'g', 'staple' => true, 'aliases' => ['kurkumy', 'kurkumę', 'mielonej kurkumy']],
    ['name' => 'Harissa', 'category' => 'sauce', 'unit' => 'g', 'aliases' => ['harissy', 'pasta harissa', 'pasty harissa']],
    ['name' => 'Tahini', 'category' => 'sauce', 'unit' => 'g', 'aliases' => ['tahini pasta', 'pasta sezamowa']],
    ['name' => 'Przyprawa taco', 'category' => 'spice', 'unit' => 'g', 'aliases' => ['przyprawy taco', 'przyprawa meksykańska', 'przyprawy kuchni meksykańskiej', 'przyprawy meksykańskiej']],
    ['name' => 'Papryczka jalapeño', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['jalapeno', 'papryczki jalapeño', 'papryczka ostra', 'papryczki ostrej', 'marynowane jalapeño', 'papryczki marynowanej jalapeño']],
    ['name' => 'Bób', 'category' => 'legume', 'unit' => 'g', 'aliases' => ['bobu', 'bobem']],
    ['name' => 'Bataty', 'category' => 'vegetable', 'unit' => 'g', 'aliases' => ['batatów', 'batata', 'batat', 'słodkie ziemniaki']],
    ['name' => 'Suszone pomidory', 'category' => 'vegetable', 'unit' => 'g', 'aliases' => ['suszonych pomidorów', 'pomidory suszone', 'pomidorów suszonych']],
    ['name' => 'Bagietka', 'category' => 'grain', 'unit' => 'piece', 'aliases' => ['bagietki', 'bagietkę', 'grzanki', 'półbagietki', 'półbagietka']],
    ['name' => 'Mieszanka warzyw', 'category' => 'vegetable', 'unit' => 'g', 'aliases' => ['mieszanki warzyw', 'chińskiej mieszanki warzyw', 'warzywa mrożone', 'mrożonych warzyw']],
    ['name' => 'Makaron ryżowy', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['makaronu ryżowego']],
    ['name' => 'Ryż brązowy', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['ryżu brązowego']],
    ['name' => 'Karkówka', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['karkówki', 'karkówkę', 'karkówka wieprzowa']],
    ['name' => 'Trufla', 'category' => 'other', 'unit' => 'g', 'aliases' => ['trufli', 'sos truflowy', 'sosie truflowym', 'oliwa truflowa']],
    ['name' => 'Ogórek kiszony', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['ogórków kiszonych', 'ogórki kiszone', 'ogórka kiszonego', 'ogórki małosolne', 'ogórków małosolnych', 'ogórek małosolny']],
    ['name' => 'Jogurt grecki', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['jogurtu greckiego', 'jogurt typu greckiego', 'jogurtu typu greckiego', 'jogurt grecki gęsty']],

    // Second pass over the review queue.
    ['name' => 'Kapary', 'category' => 'vegetable', 'unit' => 'g', 'aliases' => ['kaparów', 'kaparami']],
    ['name' => 'Pomidorki koktajlowe', 'category' => 'vegetable', 'unit' => 'g', 'aliases' => ['pomidorków koktajlowych', 'pomidorków', 'pomidorki cherry', 'pomidorków cherry', 'pomidorkami koktajlowymi', 'pomidorkami']],
    ['name' => 'Granat', 'category' => 'fruit', 'unit' => 'piece', 'aliases' => ['granata', 'granatu', 'pestki granatu']],
    ['name' => 'Kefir', 'category' => 'dairy', 'unit' => 'ml', 'aliases' => ['kefiru', 'ajran', 'ajranu', 'maślanka', 'maślanki']],
    ['name' => 'Sumak', 'category' => 'spice', 'unit' => 'g', 'staple' => true, 'aliases' => ['sumaku', 'przyprawa sumak']],
    ['name' => 'Guanciale', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['pancetta', 'pancetty']],
    ['name' => 'Szafran', 'category' => 'spice', 'unit' => 'g', 'aliases' => ['szafranu', 'nitki szafranu']],
    ['name' => 'Morele suszone', 'category' => 'fruit', 'unit' => 'g', 'aliases' => ['suszonych moreli', 'moreli', 'morele']],
    ['name' => 'Twarożek kozi', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['twarożku koziego', 'twarożek owczy', 'twarożku owczego']],
    ['name' => 'Wódka', 'category' => 'beverage', 'unit' => 'ml', 'aliases' => ['wódki']],
    ['name' => 'Ricotta', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['ricotty', 'ricottę', 'ser ricotta', 'sera ricotta', 'serek ricotta', 'serka ricotta']],
    ['name' => 'Botwinka', 'category' => 'vegetable', 'unit' => 'bunch', 'aliases' => ['botwinki', 'botwinę', 'botwina']],
    ['name' => 'Ryż arborio', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['ryżu arborio', 'ryż do risotto', 'ryżu do risotto', 'arborio']],

    // Third pass: the tail of the review queue.
    ['name' => 'Pasta truflowa', 'category' => 'sauce', 'unit' => 'g', 'aliases' => ['pasty truflowej']],
    ['name' => 'Oliwki zielone', 'category' => 'vegetable', 'unit' => 'g', 'aliases' => ['zielonych oliwek', 'oliwek', 'oliwki', 'oliwki czarne', 'czarnych oliwek']],
    ['name' => 'Orzeszki pinii', 'category' => 'nut_seed', 'unit' => 'g', 'aliases' => ['orzeszków pinii', 'pinie', 'orzeszki piniowe', 'orzeszków piniowych']],
    ['name' => 'Sos rybny', 'category' => 'sauce', 'unit' => 'ml', 'aliases' => ['sosu rybnego']],
    ['name' => 'Tofu', 'category' => 'legume', 'unit' => 'g', 'aliases' => ['tofu naturalne', 'tofu wędzone']],
    ['name' => 'Pasta miso', 'category' => 'sauce', 'unit' => 'g', 'aliases' => ['pasty miso', 'miso']],
    ['name' => 'Nori', 'category' => 'other', 'unit' => 'g', 'aliases' => ['chipsów z nori', 'glony nori', 'płatki nori']],

    // Fourth pass, after widening the imported categories (fish, grill, Mexican).
    ['name' => 'Bułka', 'category' => 'grain', 'unit' => 'piece', 'aliases' => ['bułki', 'bułkę', 'kajzerka', 'kajzerki', 'bułka pszenna']],
    ['name' => 'Rabarbar', 'category' => 'fruit', 'unit' => 'g', 'aliases' => ['rabarbaru', 'łodyg rabarbaru', 'łodygi rabarbaru']],
    ['name' => 'Dorsz', 'category' => 'fish', 'unit' => 'g', 'aliases' => ['dorsza', 'filet z dorsza', 'filetów z dorsza', 'biała ryba', 'filetów białej ryby', 'polędwica z dorsza', 'polędwicy z dorsza']],
    ['name' => 'Anchois', 'category' => 'fish', 'unit' => 'piece', 'aliases' => ['fileciki anchois', 'filety anchois', 'sardele']],
    ['name' => 'Anyż gwiazdkowy', 'category' => 'spice', 'unit' => 'piece', 'staple' => true, 'aliases' => ['anyżu', 'gwiazdki anyżu', 'anyż']],
    ['name' => 'Goździki', 'category' => 'spice', 'unit' => 'piece', 'staple' => true, 'aliases' => ['goździków', 'goździki całe']],
    ['name' => 'Ciasto francuskie', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['ciasta francuskiego', 'ciasto półfrancuskie']],
    ['name' => 'Grzyby leśne', 'category' => 'vegetable', 'unit' => 'g', 'aliases' => ['grzybów leśnych', 'grzyby', 'grzybów', 'grzybków', 'grzybki', 'borowiki', 'borowików', 'suszonych borowików', 'podgrzybki', 'suszonych grzybów', 'suszone grzyby', 'grzyby suszone', 'grzybów suszonych', 'shitake', 'shiitake']],
    ['name' => 'Włoszczyzna', 'category' => 'vegetable', 'unit' => 'package', 'aliases' => ['włoszczyzny', 'włoszczyznę']],
    ['name' => 'Rzodkiewka', 'category' => 'vegetable', 'unit' => 'bunch', 'aliases' => ['rzodkiewki', 'pęczek rzodkiewki', 'rzodkiewek']],
    ['name' => 'Polędwica wieprzowa', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['polędwicy wieprzowej', 'polędwiczki wieprzowe', 'polędwiczka wieprzowa', 'polędwiczki wieprzowej', 'polędwiczek wieprzowych']],
    ['name' => 'Pietruszka korzeń', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['korzeń pietruszki', 'korzenia pietruszki', 'pietruszka']],
    ['name' => 'Seler korzeniowy', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['selera korzeniowego', 'seler korzeń']],

    // Fifth pass, from the review queue after the first 90 recipes.
    ['name' => 'Indyk', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['mięso indyka', 'mięsa indyka', 'uda z indyka', 'udo z indyka', 'pierś z indyka', 'piersi z indyka', 'indyka']],
    ['name' => 'Halibut', 'category' => 'fish', 'unit' => 'g', 'aliases' => ['halibuta', 'filetów halibuta', 'filet z halibuta']],
    ['name' => 'Żeberka wieprzowe', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['żeberek wieprzowych', 'żeberka', 'żeberek']],
    ['name' => 'Kości wieprzowe', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['kości wieprzowych', 'kość wieprzowa']],
    ['name' => 'Kości drobiowe', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['kości drobiowych', 'korpus kurczaka', 'korpusu kurczaka']],
    // Italian potato dumplings; the Polish "kopytka" are a separate product below.
    ['name' => 'Gnocchi', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['gotowych gnocchi', 'gnocchi ziemniaczane', 'gnochi']],
    ['name' => 'Quinoa', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['komosa ryżowa', 'komosy ryżowej']],
    ['name' => 'Ciasto kataifi', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['ciasta kataifi', 'kataifi', 'kadayif']],
    ['name' => 'Purée ziemniaczane', 'category' => 'vegetable', 'unit' => 'g', 'aliases' => ['puree ziemniaczane', 'ziemniaki puree']],
    ['name' => 'Żurawina', 'category' => 'fruit', 'unit' => 'g', 'aliases' => ['żurawiny', 'suszonej żurawiny', 'żurawina suszona']],
    ['name' => 'Jarmuż', 'category' => 'vegetable', 'unit' => 'g', 'aliases' => ['jarmużu', 'liście jarmużu']],
    ['name' => 'Ananas', 'category' => 'fruit', 'unit' => 'piece', 'aliases' => ['ananasa', 'ananasy']],
    ['name' => 'Kaki', 'category' => 'fruit', 'unit' => 'piece', 'aliases' => ['owoce kaki', 'persymona']],
    ['name' => 'Gorczyca', 'category' => 'spice', 'unit' => 'g', 'staple' => true, 'aliases' => ['nasion gorczycy', 'nasiona gorczycy', 'gorczycy']],
    ['name' => 'Zioła prowansalskie', 'category' => 'spice', 'unit' => 'g', 'staple' => true, 'aliases' => ['ziół prowansalskich', 'przyprawy prowansalskiej', 'przyprawa prowansalska']],
    ['name' => 'Orzeszki ziemne', 'category' => 'nut_seed', 'unit' => 'g', 'aliases' => ['orzeszków ziemnych', 'fistaszki', 'orzeszki arachidowe']],
    ['name' => 'Sos worcester', 'category' => 'sauce', 'unit' => 'ml', 'aliases' => ['sosu worcester', 'worcestershire', 'sosu worcesterhire', 'sos worcesterhire']],
    ['name' => 'Ostry sos chili', 'category' => 'sauce', 'unit' => 'ml', 'aliases' => ['cholula', 'sos cholula', 'meksykański sos cholula', 'tabasco', 'sriracha', 'srirachy', 'sos sriracha', 'sosu sriracha', 'słodki sos chili', 'słodkim sosie chili', 'ostry sos', 'ostrego sosu', 'sos ostry']],
    ['name' => 'Krem pistacjowy', 'category' => 'sweetener', 'unit' => 'g', 'aliases' => ['kremu pistacjowego']],
    ['name' => 'Sake', 'category' => 'beverage', 'unit' => 'ml', 'aliases' => ['wino ryżowe', 'wina ryżowego', 'mirin']],
    ['name' => 'Kombu', 'category' => 'other', 'unit' => 'g', 'aliases' => ['algi konbu', 'wodorosty konbu', 'konbu', 'kelp']],
    ['name' => 'Dymka', 'category' => 'vegetable', 'unit' => 'bunch', 'aliases' => ['szczypior', 'cebula dymka', 'cebulka dymka']],

    // Not food. Recipe lists mix these in, so they need a home that the pantry and
    // budget features can exclude.
    ['name' => 'Papier do pieczenia', 'category' => 'equipment', 'unit' => 'piece', 'aliases' => ['arkusze papieru do pieczenia', 'arkusz papieru do pieczenia', 'papieru do pieczenia']],
    ['name' => 'Folia spożywcza', 'category' => 'equipment', 'unit' => 'piece', 'aliases' => ['przezroczysta folia spożywcza', 'folii spożywczej']],
    ['name' => 'Patyczki do szaszłyków', 'category' => 'equipment', 'unit' => 'piece', 'aliases' => ['patyczki na szaszłyki', 'krótkie patyczki na szaszłyki', 'patyczki szaszłykowe', 'wykałaczki']],
    ['name' => 'Forma do pieczenia', 'category' => 'equipment', 'unit' => 'piece', 'aliases' => ['podłużna forma', 'duża podłużna forma', 'keksówka', 'tortownica']],

    // Sixth pass, from the review queue after 200 recipes.
    ['name' => 'Hummus', 'category' => 'legume', 'unit' => 'g', 'aliases' => ['domowy hummus', 'hummusu']],
    ['name' => 'Dżem', 'category' => 'sweetener', 'unit' => 'g', 'aliases' => ['konfitur', 'konfitura', 'dżemu', 'dżemu brzoskwiniowego', 'dżemu morelowego']],
    ['name' => 'Sok pomarańczowy', 'category' => 'beverage', 'unit' => 'ml', 'aliases' => ['soku pomarańczowego']],
    ['name' => 'Brzoskwinia', 'category' => 'fruit', 'unit' => 'piece', 'aliases' => ['brzoskwinie', 'brzoskwiń', 'brzoskwini']],
    ['name' => 'Alkohol mocny', 'category' => 'beverage', 'unit' => 'ml', 'aliases' => ['mocnego alkoholu', 'mocniejszego alkoholu', 'likieru pomarańczowego', 'angostury', 'żubrówka', 'żubrówki', 'whisky', 'brandy', 'koniak', 'rum']],
    ['name' => 'Katsuobushi', 'category' => 'fish', 'unit' => 'g', 'aliases' => ['pasków suszonej ryby bonito', 'bonito', 'płatki bonito']],
    ['name' => 'Biała ryba filet', 'category' => 'fish', 'unit' => 'g', 'aliases' => ['białych filetów rybnych', 'filety rybne', 'filetów rybnych', 'filetów ryby', 'filet ryby', 'filetów surowej ryby', 'miruna', 'okoń morski', 'żabnica']],
    ['name' => 'Sos tatarski', 'category' => 'sauce', 'unit' => 'g', 'aliases' => ['sosu tatarskiego']],
    ['name' => 'Mięso gulaszowe', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['mięsa gulaszowego', 'gulaszowe']],
    ['name' => 'Ketchup', 'category' => 'sauce', 'unit' => 'g', 'aliases' => ['ketchupu', 'ketchupu pikantnego', 'keczup']],
    ['name' => 'Wędlina', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['wędliny', 'wędzony filet kurczaka', 'wędlina pokrojona w kostkę']],
    ['name' => 'Rukola', 'category' => 'vegetable', 'unit' => 'g', 'aliases' => ['rukoli', 'rukolę', 'rokietta']],
    ['name' => 'Mango', 'category' => 'fruit', 'unit' => 'piece', 'aliases' => ['mango dojrzałe']],
    ['name' => 'Bułka hamburgerowa', 'category' => 'grain', 'unit' => 'piece', 'aliases' => ['bułeczki hamburgerowe', 'bułki hamburgerowe', 'bułka do burgera', 'bułki burgerowe', 'bułek burgerowych']],
    ['name' => 'Makaron lasagne', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['płatów lasagne', 'płaty lasagne', 'lasagne']],
    ['name' => 'Polędwica wołowa', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['polędwicy wołowej', 'polędwica z wołowiny']],
    ['name' => 'Ajvar', 'category' => 'sauce', 'unit' => 'g', 'aliases' => ['ajvaru']],
    ['name' => 'Pinsa', 'category' => 'grain', 'unit' => 'piece', 'aliases' => ['pinsy', 'spód do pizzy', 'ciasto na pizzę', 'ciasta na pizzę', 'gotowego ciasta na pizzę']],
    ['name' => 'Sos pomidorowy', 'category' => 'sauce', 'unit' => 'ml', 'aliases' => ['sosu pomidorowego']],
    ['name' => 'Salami', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['salami pikantnego', 'plasterków salami']],
    ['name' => 'Kaczka', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['kaczki', 'szyi kaczki', 'skrzydeł kaczki', 'porcja rosołowa kaczki']],
    ['name' => 'Gęś', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['gęsi', 'porcja rosołowa gęsi']],
    ['name' => 'Kminek', 'category' => 'spice', 'unit' => 'g', 'staple' => true, 'aliases' => ['kminku', 'nasion kminku', 'nasiona kminku', 'ziaren kminku', 'mielonego kminku', 'kminek mielony']],
    ['name' => 'Majeranek', 'category' => 'spice', 'unit' => 'g', 'staple' => true, 'aliases' => ['majeranku', 'suszonego majeranku']],
    ['name' => 'Chałka', 'category' => 'grain', 'unit' => 'piece', 'aliases' => ['chałki', 'chałkę', 'brioche']],
    ['name' => 'Sos ostrygowy', 'category' => 'sauce', 'unit' => 'ml', 'aliases' => ['sosu ostrygowego']],
    ['name' => 'Pita', 'category' => 'grain', 'unit' => 'piece', 'aliases' => ['pity', 'chlebek pita', 'pitki']],
    ['name' => 'Syrop klonowy', 'category' => 'sweetener', 'unit' => 'ml', 'aliases' => ['syropu klonowego']],
    ['name' => 'Pierożki gyoza', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['pierożków gyoza', 'gyoza', 'pierogi gyoza']],

    // Seventh pass, from the review queue after 350 recipes.
    ['name' => 'Garam masala', 'category' => 'spice', 'unit' => 'g', 'staple' => true, 'aliases' => ['przyprawy garam masala', 'przyprawa garam masala']],
    ['name' => 'Matcha', 'category' => 'other', 'unit' => 'g', 'aliases' => ['matchy', 'herbata matcha']],
    ['name' => 'Chorizo', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['kiełbasa chorizo']],
    ['name' => 'Mieszanka sałat', 'category' => 'vegetable', 'unit' => 'g', 'aliases' => ['mix sałat', 'miksu sałat', 'miks sałat', 'sałata mieszana', 'radicchio', 'roszponka']],
    ['name' => 'Owoce morza mieszanka', 'category' => 'fish', 'unit' => 'g', 'aliases' => ['mieszanki morskiej', 'mrożonej mieszanki morskiej', 'owoce morza', 'owoców morza', 'owocami morza', 'kalmary', 'ośmiorniczki']],
    ['name' => 'Kopytka', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['kopytek', 'kluski śląskie', 'klusek śląskich', 'leniwe', 'leniwych']],
    ['name' => 'Wiórki kokosowe', 'category' => 'nut_seed', 'unit' => 'g', 'aliases' => ['wiórków kokosowych', 'wiórki kokosa']],
    ['name' => 'Pesto', 'category' => 'sauce', 'unit' => 'g', 'aliases' => ['czerwonego pesto', 'pesto zielone', 'pesto z bazylii']],
    ['name' => 'Sos teriyaki', 'category' => 'sauce', 'unit' => 'ml', 'aliases' => ['sosu teriyaki', 'sosu marynaty teriyaki', 'marynata teriyaki']],
    ['name' => 'Skyr', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['skyru', 'jogurt skyr', 'jogurtu skyr', 'jogurt islandzki', 'jogurtu islandzkiego']],
    ['name' => 'Chipsy kukurydziane', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['chipsów kukurydzianych', 'nachos', 'nachosy', 'tortilla chips']],
    ['name' => 'Oranżada', 'category' => 'beverage', 'unit' => 'ml', 'aliases' => ['oranżady']],
    ['name' => 'Lód', 'category' => 'beverage', 'unit' => 'piece', 'aliases' => ['kostki lodu', 'kostek lodu', 'lodu']],
    ['name' => 'Perliczka', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['perliczki', 'perliczkę']],
    ['name' => 'Paluszki krabowe', 'category' => 'fish', 'unit' => 'g', 'aliases' => ['paluszków krabowych', 'surimi']],
    ['name' => 'Kardamon', 'category' => 'spice', 'unit' => 'g', 'staple' => true, 'aliases' => ['nasion kardamonu', 'nasiona kardamonu', 'kardamonu', 'mielonego kardamonu', 'kardamon mielony']],
    ['name' => 'Nasiona chia', 'category' => 'nut_seed', 'unit' => 'g', 'aliases' => ['nasion chia', 'chia']],

    // Eighth pass. Some aliases below are the source site's own typos ("oregan",
    // "migdaów", "czosku") — real data beats correct spelling here.
    ['name' => 'Szalotka', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['szalotek', 'szalotki', 'szalotkę']],
    ['name' => 'Shichimi togarashi', 'category' => 'spice', 'unit' => 'g', 'aliases' => ['japońskiej przyprawy shichimi togarashi', 'togarashi']],
    ['name' => 'Syrop z agawy', 'category' => 'sweetener', 'unit' => 'ml', 'aliases' => ['syropu z agawy', 'agawa']],
    ['name' => 'Schab', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['schabu', 'schab wieprzowy', 'kotlet schabowy', 'kotlety schabowe', 'kotletów schabowych', 'kotletach schabowych']],
    // A whole bird, which is what the statistical office prices and what a
    // recipe means by "kurczak" when it does not name a cut. Without it the
    // cheapest meat in the catalogue had no price at all.
    ['name' => 'Pędy bambusa', 'category' => 'vegetable', 'unit' => 'g', 'aliases' => ['pędów bambusa', 'bambus']],

    // Ninth pass: what the first air fryer import asked for. The device favours
    // coatings, offal and ready-made sauces, which the oven-and-pot dictionary
    // built from kwestiasmaku.com had no reason to cover.
    ['name' => 'Otręby owsiane', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['otrębów owsianych', 'otręby', 'otrębów']],
    ['name' => 'Płatki kukurydziane', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['płatków kukurydzianych', 'cornflakes']],
    ['name' => 'Płatki żytnie', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['płatków żytnich']],
    ['name' => 'Wątróbka drobiowa', 'category' => 'meat', 'unit' => 'g', 'aliases' => ['wątróbki drobiowej', 'wątróbek drobiowych', 'wątróbka', 'wątróbki', 'wątróbek', 'watrobki']],
    ['name' => 'Sos adżyka', 'category' => 'sauce', 'unit' => 'g', 'aliases' => ['sosu adżyka', 'adżyka', 'adżyki']],
    ['name' => 'Sos BBQ', 'category' => 'sauce', 'unit' => 'ml', 'aliases' => ['sosu bbq', 'sos barbecue', 'sosu barbecue']],
    ['name' => 'Czubrica', 'category' => 'spice', 'unit' => 'g', 'aliases' => ['czubricy', 'czubrica czerwona', 'czubrica zielona']],
    ['name' => 'Przyprawa gyros', 'category' => 'spice', 'unit' => 'g', 'aliases' => ['przyprawy gyros', 'przyprawa do gyrosa', 'przyprawy gryros']],
    ['name' => 'Herbata', 'category' => 'beverage', 'unit' => 'ml', 'aliases' => ['herbaty', 'mocnej herbaty', 'herbatę']],
    ['name' => 'Kawa', 'category' => 'beverage', 'unit' => 'ml', 'aliases' => ['kawy', 'mocnej kawy', 'kawa rozpuszczalna']],

    // From the review queue after 550 kwestiasmaku recipes.
    ['name' => 'Sok ananasowy', 'category' => 'beverage', 'unit' => 'ml', 'aliases' => ['soku ananasowego']],
    ['name' => 'Krówki', 'category' => 'sweetener', 'unit' => 'piece', 'aliases' => ['krówek', 'cukierki krówki']],
    ['name' => 'Wakame', 'category' => 'other', 'unit' => 'g', 'aliases' => ['wodorostów wakame', 'wodorosty wakame']],
    ['name' => 'Groch łuskany', 'category' => 'legume', 'unit' => 'g', 'aliases' => ['grochu żółtego', 'groch żółty', 'grochu łuskanego', 'groch połówki']],
    ['name' => 'Kostka rosołowa', 'category' => 'sauce', 'unit' => 'cube', 'aliases' => ['kostki rosołowej', 'kostka rosołowa bio', 'bulion w kostce']],
    ['name' => 'Koper włoski', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['bulwy kopru włoskiego', 'kopru włoskiego', 'fenkuł', 'nasion kopru', 'nasiona kopru', 'nasion kopru włoskiego']],
    ['name' => 'Pak choi', 'category' => 'vegetable', 'unit' => 'piece', 'aliases' => ['kapustka pak choi', 'kapusta pak choi', 'bok choy']],
    ['name' => 'Boczniaki', 'category' => 'vegetable', 'unit' => 'g', 'aliases' => ['boczniaków', 'grzyby boczniaki']],
    ['name' => 'Serek topiony', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['serka topionego', 'ser topiony']],
    ['name' => 'Naleśniki', 'category' => 'grain', 'unit' => 'piece', 'aliases' => ['naleśników', 'naleśnik', 'gotowe naleśniki']],
    ['name' => 'Trawa cytrynowa', 'category' => 'herb', 'unit' => 'piece', 'aliases' => ['trawy cytrynowej', 'lemongrass']],
    ['name' => 'Liście kafiru', 'category' => 'herb', 'unit' => 'leaf', 'aliases' => ['liść kafiru', 'liści kafiru']],
    ['name' => 'Panko', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['panierka panko', 'bułka panko']],
    ['name' => 'Sos do pizzy', 'category' => 'sauce', 'unit' => 'g', 'aliases' => ['sosu do pizzy']],
    ['name' => 'Żurek zakwas', 'category' => 'sauce', 'unit' => 'ml', 'aliases' => ['żuru', 'żurku', 'zakwasu', 'zakwas na żurek']],
    ['name' => 'Bajgle', 'category' => 'grain', 'unit' => 'piece', 'aliases' => ['bajgiel', 'bajgla', 'bajgli']],
    ['name' => 'Gruszka', 'category' => 'fruit', 'unit' => 'piece', 'aliases' => ['gruszki', 'gruszkę', 'gruszek']],
    ['name' => 'Sznurek piekarniczy', 'category' => 'equipment', 'unit' => 'piece', 'aliases' => ['sznurka piekarniczego', 'sznurek kuchenny']],
    ['name' => 'Chrzan', 'category' => 'sauce', 'unit' => 'g', 'aliases' => ['tartego chrzanu', 'chrzanu', 'chrzan tarty']],
    ['name' => 'Pasta do laksy', 'category' => 'sauce', 'unit' => 'g', 'aliases' => ['pasty do laksy', 'laksa', 'pasta laksa']],
    ['name' => 'Zioła suszone', 'category' => 'spice', 'unit' => 'g', 'staple' => true, 'aliases' => ['suszonych ziół', 'suszone zioła', 'mieszanka ziół']],
    // "przyprawa do grilla", "mieszanka przypraw kuchni meksykańskiej" — a real
    // jar with a name nobody standardises. Spice, so it is exempt from the
    // availability check and cannot make a dish look uncookable.
    ['name' => 'Przyprawy', 'category' => 'spice', 'unit' => 'g', 'staple' => true, 'aliases' => ['przypraw', 'przyprawa', 'przyprawy', 'mieszanka przypraw', 'mieszanki przypraw', 'przyprawa do potraw', 'ulubione przyprawy', 'ulubionych przypraw']],

    // Lunchbox and meal prep staples, from the centrumrespo.pl import.
    ['name' => 'Serek wiejski', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['serka wiejskiego', 'serek ziarnisty', 'serka ziarnistego', 'cottage cheese']],
    ['name' => 'Twarożek', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['twarożku', 'twarożkiem', 'twarożek grani', 'twarożku grani', 'twarożek naturalny']],
    ['name' => 'Serek homogenizowany', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['serka homogenizowanego', 'serek homogenizowany waniliowy', 'homogenizowany', 'serek waniliowy', 'serka waniliowego']],
    ['name' => 'Pudding proteinowy', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['puddingu proteinowego', 'puddingu proteinowego caffe latte', 'pudding białkowy', 'deser proteinowy']],
    ['name' => 'Winogrona', 'category' => 'fruit', 'unit' => 'g', 'aliases' => ['winogron', 'winogronami', 'winogrona jasne', 'winogrona ciemne']],
    ['name' => 'Kiwi', 'category' => 'fruit', 'unit' => 'piece', 'aliases' => ['kiwi zielone']],
    ['name' => 'Nektarynka', 'category' => 'fruit', 'unit' => 'piece', 'aliases' => ['nektarynki', 'nektarynek', 'nektarynkę']],
    ['name' => 'Mandarynka', 'category' => 'fruit', 'unit' => 'piece', 'aliases' => ['mandarynki', 'mandarynek', 'mandarynkę']],
    ['name' => 'Daktyle', 'category' => 'fruit', 'unit' => 'g', 'aliases' => ['daktyli', 'daktylami', 'daktyli suszonych', 'daktyle suszone']],
    ['name' => 'Grahamka', 'category' => 'grain', 'unit' => 'piece', 'aliases' => ['grahamki', 'grahamek', 'bułka grahamka', 'bułki grahamki', 'bułek grahamek']],
    ['name' => 'Pieczywo chrupkie', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['pieczywa chrupkiego', 'wafle ryżowe', 'wafli ryżowych', 'pieczywo chrupkie żytnie']],
    ['name' => 'Papier ryżowy', 'category' => 'grain', 'unit' => 'piece', 'aliases' => ['papieru ryżowego', 'papierki ryżowe']],
    ['name' => 'Gofry', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['gofrów', 'gofrów wytrawnych', 'gofry wytrawne', 'gofr']],
    ['name' => 'Kluski na parze', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['klusek na parze', 'kluski parowe', 'bułeczki na parze']],
    ['name' => 'Kimchi', 'category' => 'vegetable', 'unit' => 'g', 'aliases' => ['kimchi kapusta']],
    ['name' => 'Pasta gochujang', 'category' => 'sauce', 'unit' => 'g', 'aliases' => ['pasty gochujang', 'gochujang', 'pasta gochudżang']],
    ['name' => 'Erytrol', 'category' => 'sweetener', 'unit' => 'g', 'aliases' => ['erytrolu', 'erytrytol', 'erytrytolu']],

    // Tenth pass: what the full airfryerprzepisy.pl archive asked for once all 503
    // recipes were in. Deliberately NOT added: "przyprawy", "do podania", "świeże
    // zioła do dekoracji", "płatków". Those name no product at all, and inventing
    // an entry for them would put a phantom item on a shopping list — they belong
    // in the review queue, which is exactly what it is for.
    ['name' => 'Budyń waniliowy', 'category' => 'sweetener', 'unit' => 'g', 'aliases' => ['budyniu waniliowego', 'budyń', 'budyniu']],
    ['name' => 'Parówki', 'category' => 'meat', 'unit' => 'piece', 'aliases' => ['parówek', 'parówka', 'parówki drobiowe']],
    ['name' => 'Piwo', 'category' => 'beverage', 'unit' => 'ml', 'aliases' => ['piwa', 'piwo jasne']],
    // "ser pleśniowy camembert" is spelled out because shops label it that way,
    // and the two-word "ser pleśniowy" belongs to Gorgonzola. The resolver tries
    // the longer group first, so the full phrase reaches the right cheese.
    ['name' => 'Camembert', 'category' => 'dairy', 'unit' => 'g', 'aliases' => ['camemberta', 'ser camembert', 'serek camembert', 'ser pleśniowy camembert', 'brie']],
    ['name' => 'Mak', 'category' => 'nut_seed', 'unit' => 'g', 'aliases' => ['maku', 'mak niebieski']],
    ['name' => 'Przyprawa do mięsa', 'category' => 'spice', 'unit' => 'g', 'aliases' => ['przypraw do mięsa', 'przyprawy do mięsa', 'przyprawa do kurczaka', 'przyprawy do kurczaka']],
    ['name' => 'Przyprawa do grilla', 'category' => 'spice', 'unit' => 'g', 'aliases' => ['przyprawy do grilla', 'przypraw do grilla']],

    // The centrumrespo.pl meal prep import. A dietician's site leans on sweeteners,
    // protein powders and meat substitutes far more than a general recipe site does,
    // so these barely appeared until it was added.
    ['name' => 'Słodzik', 'category' => 'sweetener', 'unit' => 'g', 'aliases' => ['słodzika', 'słodzikiem', 'słodzik w proszku']],
    ['name' => 'Ksylitol', 'category' => 'sweetener', 'unit' => 'g', 'aliases' => ['ksylitolu', 'ksylitolem', 'puder z ksylitolu', 'pudru z ksylitolu']],
    ['name' => 'Odżywka białkowa', 'category' => 'other', 'unit' => 'g', 'aliases' => ['odżywki białkowej', 'odżywki', 'odżywka', 'odżywki białkowej waniliowej', 'wegańskiej odżywki białkowej', 'białko w proszku']],
    ['name' => 'Tempeh', 'category' => 'legume', 'unit' => 'g', 'aliases' => ['tempehu', 'tempehem']],
    ['name' => 'Seitan', 'category' => 'legume', 'unit' => 'g', 'aliases' => ['seitanu', 'seitana', 'seitanu gotowego', 'seitanu instant', 'seitan gotowy']],
    ['name' => 'Granulat sojowy', 'category' => 'legume', 'unit' => 'g', 'aliases' => ['granulatu sojowego', 'kostka sojowa']],
    ['name' => 'Kotlety sojowe', 'category' => 'legume', 'unit' => 'g', 'aliases' => ['kotletów sojowych', 'kotleciki sojowe', 'kotlecików sojowych']],
    ['name' => 'Edamame', 'category' => 'legume', 'unit' => 'g', 'aliases' => ['edamame w strąkach']],
    ['name' => 'Płatki drożdżowe', 'category' => 'other', 'unit' => 'g', 'aliases' => ['płatków drożdżowych', 'drożdże nieaktywne']],
    ['name' => 'Siemię lniane', 'category' => 'nut_seed', 'unit' => 'g', 'aliases' => ['siemienia lnianego', 'zmielonego siemienia lnianego', 'siemię', 'siemienia']],
    ['name' => 'Nerkowce', 'category' => 'nut_seed', 'unit' => 'g', 'aliases' => ['nerkowców', 'orzechy nerkowca', 'orzechów nerkowca', 'orzechy nerkowce']],
    ['name' => 'Płatki jaglane', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['płatków jaglanych']],
    ['name' => 'Płatki ryżowe', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['płatków ryżowych']],
    ['name' => 'Płatki orkiszowe', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['płatków orkiszowych']],
    ['name' => 'Proszek budyniowy', 'category' => 'baking', 'unit' => 'g', 'aliases' => ['proszku budyniowego']],
    // "ekstrakt waniliowy" is deliberately absent here: it already belongs to Cukier wanilinowy.
    ['name' => 'Aromat waniliowy', 'category' => 'baking', 'unit' => 'ml', 'aliases' => ['aromatu waniliowego', 'aromat wanilinowy']],
    ['name' => 'Biszkopty', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['biszkoptów', 'biszkopt']],
    ['name' => 'Precle', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['precelków', 'precelki', 'precla', 'precel']],
    ['name' => 'Focaccia', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['focacci', 'focaccię', 'schiacciata', 'schiacciaty']],
    ['name' => 'Piadina', 'category' => 'grain', 'unit' => 'piece', 'aliases' => ['piadiny', 'placek pszenny piadina', 'placka pszennego piadin']],
    ['name' => 'Ciasto naleśnikowe', 'category' => 'grain', 'unit' => 'g', 'aliases' => ['ciasta naleśnikowego']],
    ['name' => 'Napój sojowy', 'category' => 'dairy', 'unit' => 'ml', 'aliases' => ['napoju sojowego', 'napoju sojowego wysokobiałkowego', 'mleko sojowe']],
    ['name' => 'Napój owsiany', 'category' => 'dairy', 'unit' => 'ml', 'aliases' => ['napoju owsianego', 'mleko owsiane', 'napój roślinny', 'napoju roślinnego']],
    ['name' => 'Napój migdałowy', 'category' => 'dairy', 'unit' => 'ml', 'aliases' => ['napoju migdałowego', 'mleko migdałowe']],
    ['name' => 'Napój kokosowy', 'category' => 'dairy', 'unit' => 'ml', 'aliases' => ['napoju kokosowego', 'mleko kokosowe do picia']],
    ['name' => 'Margaryna', 'category' => 'fat', 'unit' => 'g', 'aliases' => ['margaryny', 'margarynę']],
    ['name' => 'Powidła śliwkowe', 'category' => 'sweetener', 'unit' => 'g', 'aliases' => ['powideł śliwkowych', 'powideł', 'powidła']],
    ['name' => 'Przyprawa do piernika', 'category' => 'spice', 'unit' => 'g', 'aliases' => ['przyprawy do piernika', 'przyprawy do pierników', 'przyprawa do pierników']],
    ['name' => 'Pasta tamaryndowa', 'category' => 'sauce', 'unit' => 'g', 'aliases' => ['pasty z tamaryndowca', 'pasta z tamaryndowca', 'tamaryndowiec']],
    ['name' => 'Grejpfrut', 'category' => 'fruit', 'unit' => 'piece', 'aliases' => ['grejpfruta', 'grejpfruty', 'grejpfrutów']],
    ['name' => 'Melon', 'category' => 'fruit', 'unit' => 'piece', 'aliases' => ['melona', 'melony']],
    ['name' => 'Marakuja', 'category' => 'fruit', 'unit' => 'piece', 'aliases' => ['marakui', 'marakuję']],
    ['name' => 'Figi', 'category' => 'fruit', 'unit' => 'g', 'aliases' => ['fig', 'świeżych fig', 'figi świeże']],
    ['name' => 'Jeżyny', 'category' => 'fruit', 'unit' => 'g', 'aliases' => ['jeżyn']],
    ['name' => 'Wiśnie', 'category' => 'fruit', 'unit' => 'g', 'aliases' => ['wiśni', 'wiśnie mrożone', 'wiśni mrożonych', 'wiśnii']],
    ['name' => 'Porzeczki', 'category' => 'fruit', 'unit' => 'g', 'aliases' => ['porzeczek', 'porzeczki czarne', 'porzeczek czarnych', 'porzeczki czerwone', 'porzeczek czerwonych']],
    ['name' => 'Makrela wędzona', 'category' => 'fish', 'unit' => 'g', 'aliases' => ['makreli wędzonej', 'wędzona makrela', 'makrela', 'makreli', 'makreli wedzonej']],
    ['name' => 'Pstrąg wędzony', 'category' => 'fish', 'unit' => 'g', 'aliases' => ['pstrąga wędzonego', 'wędzonego pstrąga', 'pstrąga', 'pstrąg']],
];
