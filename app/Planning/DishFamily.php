<?php

declare(strict_types=1);

namespace App\Planning;

use App\Catalogue\TitleNeedle;
use App\Importing\Parsing\PolishTextNormalizer;

/**
 * What kind of dish a title names — `database/data/dish-families.php` asked
 * about one recipe.
 */
final class DishFamily
{
    /** @var array<string, list<string>> */
    private array $families;

    public function __construct(private readonly PolishTextNormalizer $normalizer)
    {
        /** @var array<string, list<string>> $families */
        $families = require database_path('data/dish-families.php');

        $this->families = $families;

        /** @var list<string> $classics */
        $classics = require database_path('data/home-classics.php');

        $this->classics = $classics;
    }

    /** @var list<string> */
    private array $classics;

    /** Whether the title names one of the dishes a Polish home is built on. */
    public function isHomeClassic(string $title): bool
    {
        return TitleNeedle::matchesAny($this->normalizer->normalize($title), $this->classics);
    }

    /**
     * Tofu, tempeh and seitan — a protein of their own for the week's rotation.
     *
     * The quick-pick categories file them all under `wege`, which the rotation
     * treats as "no meat today" and barely limits; a reviewed week had tofu on
     * three days running and seitan for a Tuesday breakfast.
     */
    public function isPlantProtein(string $title): bool
    {
        return TitleNeedle::matchesAny($this->normalizer->normalize($title), ['tofu', 'tempeh*', 'seitan*']);
    }

    /**
     * Whether the title says the dish comes with its own potatoes, groats or
     * rice. "Kurczak pieczony z dynią, ziemniakami i szałwią" draws a fifth of
     * its calories from the potatoes — under the line by a hair — and was
     * served with a second plate of them.
     */
    public function namesStarch(string $title): bool
    {
        return TitleNeedle::matchesAny($this->normalizer->normalize($title), [
            'ziemniak*', 'ziemniacz*', 'kasz*', 'kaszotto', 'ryz', 'ryzem', 'ryzu', 'risotto', 'kuskus*',
            'bulgur*', 'komos*', 'quinoa', 'frytk*', 'kopytk*', 'klusk*', 'pyz*', 'gnocchi', 'makaron*',
            'batat*', 'puree', 'pieczywem', 'chlebem', 'bulka', 'bagietk*', 'tortill*', 'pierog*',
            // Rice inside: gołąbki with pyzy beside them was two starches on one plate.
            'golabk*', 'golabki', 'nalesnik*', 'krokiet*', 'pierozk*', 'empanad*', 'pizz*',
            // And pasta: "wegański mac and cheese" with kopytka, two days running.
            'mac and cheese', 'lasagn*', 'spaghetti', 'penne', 'tagliatelle', 'orzo', 'noodl*', 'lazank*',
            'zapiekank*',
        ]);
    }

    /**
     * Which soup this is, for the soups a week can have only one of: żurek as
     * Monday's starter and a different żurek as Tuesday's obiad was a generated
     * week, two recipes whose titles share nothing but the word that matters.
     */
    public function soupKindOf(string $title): ?string
    {
        $normalised = $this->normalizer->normalize($title);

        foreach (self::SOUP_KINDS as $kind => $needles) {
            if (TitleNeedle::matchesAny($normalised, $needles)) {
                return $kind;
            }
        }

        return null;
    }

    /** @var array<string, list<string>> */
    private const array SOUP_KINDS = [
        'zurek' => ['zurek', 'zurku', 'zurkiem'],
        'rosol' => ['rosol', 'rosolu', 'rosole', 'bulion'],
        'pomidorowa' => ['pomidorowa', 'pomidorowej', 'pomidorowke', 'pomidorowka'],
        'barszcz' => ['barszcz', 'barszczu', 'barszczem'],
        'krupnik' => ['krupnik', 'krupniku'],
        'grochowka' => ['grochowka', 'grochowki', 'grochowke'],
        'ogorkowa' => ['ogorkowa', 'ogorkowej', 'ogorkowke'],
        'kapusniak' => ['kapusniak', 'kapusniaku'],
        'zalewajka' => ['zalewajka', 'zalewajki', 'zalewajke'],
        'krem' => ['krem z', 'krem', 'kremowa zupa'],
        'gulaszowa' => ['gulaszowa', 'gulaszowej'],
        'curry' => ['curry', 'laksa', 'ramen', 'tom yum', 'pho', 'miso'],
    ];

    /**
     * Meat a soup's title names that its categories may not: "zupa z
     * pulpecikami" and "zupa parówkowa" were filed under nothing at all.
     */
    public function namesMeat(string $title): bool
    {
        return TitleNeedle::matchesAny($this->normalizer->normalize($title), [
            'pulpet*', 'klops*', 'kielbas*', 'parowk*', 'parowkow*', 'miesn*', 'mies*', 'boczk*', 'boczek',
            'kurczak*', 'drobiow*', 'indyk*', 'wolow*', 'wieprzow*', 'zeberk*', 'kosci*', 'szynk*',
            'krewet*', 'ryb*', 'losos*', 'dorsz*',
        ]);
    }

    /** "…w sosie pieczarkowym", "…z sosem" — a dish whose sauce wants soaking up. */
    public function isInSauce(string $title): bool
    {
        return TitleNeedle::matchesAny($this->normalizer->normalize($title), ['w sosie', 'z sosem', 'sosie', 'gulasz*', 'potrawk*', 'duszon*']);
    }

    /**
     * Liver, gizzards, tongue, tripe. Good food and cheap protein, and never
     * what a Polish family sits down to on a Sunday — "Wątróbka z kaczki" was
     * a generated Sunday obiad, picked because it is meat and has a few
     * ingredients, which is all the centrepiece rule could see.
     */
    public function isOffal(string $title): bool
    {
        return TitleNeedle::matchesAny($this->normalizer->normalize($title), [
            'watrob*', 'podrob*', 'zoladk*', 'ozor*', 'ozork*', 'nerk*', 'flak*', 'pluc*',
        ]);
    }

    /**
     * The words that name the dish, for telling the same dish from two sites
     * apart from two different dishes.
     *
     * "Nothing repeats within a month" is a rule about recipe rows, and the
     * catalogue has the same pasta salad with broccoli from two sources — it
     * was a supper on a Wednesday and again nine days later. Only the part of
     * the title before a headline's second sentence counts, cut to stems, with
     * the words every title uses ("pyszny", "przepis", "air fryer") removed.
     *
     * @return list<string>
     */
    public function signatureOf(string $title): array
    {
        $head = preg_split('/[.!?:(\[]|\s[–-]\s/u', $title)[0] ?? $title;
        $words = preg_split('/\s+/u', $this->normalizer->normalize($head)) ?: [];

        $stems = [];

        foreach ($words as $word) {
            if (mb_strlen($word) < 4 || in_array($word, self::FILLER, true)) {
                continue;
            }

            $stems[mb_substr($word, 0, 5)] = true;
        }

        return array_slice(array_keys($stems), 0, 5);
    }

    /**
     * Whether two signatures name the same dish: three stems in common, or
     * all of them when one title is that short.
     *
     * @param  list<string>  $one
     * @param  list<string>  $other
     */
    public static function sameDish(array $one, array $other): bool
    {
        $smaller = min(count($one), count($other));

        if ($smaller < 2) {
            return false;
        }

        return count(array_intersect($one, $other)) >= min(3, $smaller);
    }

    /** @var list<string> */
    private const array FILLER = [
        'przepis', 'przepisy', 'najlepszy', 'najlepsza', 'najlepsze', 'pyszny', 'pyszna', 'pyszne',
        'prosty', 'prosta', 'proste', 'szybki', 'szybka', 'szybkie', 'domowy', 'domowa', 'domowe',
        'idealny', 'idealna', 'idealne', 'fryer', 'fryera', 'fryerze', 'klasyczny', 'klasyczna',
        'zdrowy', 'zdrowa', 'zdrowe', 'ekspresowy', 'ekspresowa', 'ekspresowe', 'super', 'oraz',
    ];

    /**
     * The cuisine a title names, when it names one.
     *
     * A reviewed week had Thai soup on Monday and Thai chicken on Friday —
     * different families, different proteins, the same dinner twice to the
     * people eating it.
     */
    public function cuisineOf(string $title): ?string
    {
        $normalised = $this->normalizer->normalize($title);

        foreach (self::CUISINES as $cuisine => $needles) {
            if (TitleNeedle::matchesAny($normalised, $needles)) {
                return $cuisine;
            }
        }

        return null;
    }

    /** @var array<string, list<string>> */
    private const array CUISINES = [
        'azjatycka' => [
            'tajsk*', 'po tajsku', 'azjatyck*', 'po azjatycku', 'chinsk*', 'po chinsku', 'japonsk*',
            'koreansk*', 'po koreansku', 'wietnamsk*', 'teriyaki', 'pad thai', 'ramen', 'udon',
            'stir fry', 'spring rolls', 'edamame', 'tempeh*', 'pak choi', 'sriracha', 'bulgogi',
            'sajgonk*', 'wok', 'miso', 'kimchi', 'sushi', 'bao', 'noodle*', 'chow mein', 'sweet sour',
            'slodko kwasn*', 'imbirow*',
        ],
        'indyjska' => ['indyjsk*', 'curry', 'dahl', 'dal', 'tikka', 'masala', 'korma', 'butter chicken'],
        'meksykanska' => [
            'meksykansk*', 'po meksykansku', 'tex mex', 'tortilla', 'tortille', 'burrito', 'quesadill*',
            'tacos', 'nachos', 'enchilad*', 'chili con carne', 'fajit*',
        ],
    ];

    public function of(string $title): ?string
    {
        $normalised = $this->normalizer->normalize($title);

        foreach ($this->families as $family => $needles) {
            if (TitleNeedle::matchesAny($normalised, $needles)) {
                return $family;
            }
        }

        return null;
    }
}
