<?php

declare(strict_types=1);

return [
    /*
     * Sent on every request so the site owner can identify and contact us.
     */
    'user_agent' => env(
        'IMPORT_USER_AGENT',
        'KitchenBot/1.0 (private meal planner; contact: '.env('MAIL_FROM_ADDRESS', 'unknown').')',
    ),

    /*
     * Fetched pages are cached this long. A re-import, a parser fix or a failed run
     * then costs the source site nothing.
     */
    'page_cache_ttl_hours' => (int) env('IMPORT_PAGE_CACHE_TTL_HOURS', 24 * 14),

    'sources' => [
        'kwestiasmaku' => [
            'name' => 'kwestiasmaku.com',
            'base_url' => 'https://www.kwestiasmaku.com',

            /*
             * Honours the Crawl-delay declared in the site's robots.txt.
             * Do not lower this.
             */
            'crawl_delay_seconds' => 10,

            /*
             * Listing pages walked by discover(), chosen to spread the first import
             * across everyday cooking rather than filling the database with cake.
             */
            'listings' => [
                '/kuchnia_polska/kurczak/przepisy.html',
                '/kuchnia_polska/zupy/przepisy.html',
                '/dania_dla_dwojga/sniadania/przepisy.html',
                '/kuchnia_polska/wieprzowina/przepisy.html',
                '/dania_dla_dwojga/ryz/przepisy.html',
                '/ryby_i_owoce_morza/losos/przepisy.html',
                '/kuchnia_polska/wolowina/przepisy.html',
                '/dania_dla_dwojga/kuskus/przepisy.html',
                '/kuchnia_polska/indyk/przepisy.html',
                '/kuchnia_polska/dania_z_grilla/przepisy.html',
                '/ryby_i_owoce_morza/dorsz/przepisy.html',
                '/ryby_i_owoce_morza/krewetki/przepisy.html',
                '/kuchnia_meksykanska/przepisy.html',
                '/kuchnia_hiszpanska/przepisy.html',
                '/dania_dla_dwojga/tarty_tartaletki_quiche/przepisy.html',
                '/dania_dla_dwojga/sosy/przepisy.html',
                '/zielony_srodek/baklazan/przepisy.html',
                '/zielony_srodek/bataty/przepisy.html',
                '/kuchnia_polska/buliony/przepisy.html',
                '/dania_dla_dwojga/burgery_domowe/przepisy.html',
            ],
        ],

        'airfryerprzepisy' => [
            'name' => 'airfryerprzepisy.pl',
            'base_url' => 'https://airfryerprzepisy.pl',

            /*
             * The site's robots.txt excludes only /wp-admin/ and declares no
             * Crawl-delay, so this is our own politeness rather than a stated rule.
             */
            'crawl_delay_seconds' => 5,

            /*
             * One chronological archive covering every category, so walking it in
             * order already gives a mix rather than fifty desserts.
             */
            'listing' => '/przepisy/',
        ],

        'centrumrespo' => [
            'name' => 'centrumrespo.pl',
            'base_url' => 'https://centrumrespo.pl',

            /*
             * The site's robots.txt excludes only /wp-admin/ and declares no
             * Crawl-delay, so this is our own politeness rather than a stated rule.
             */
            'crawl_delay_seconds' => 5,

            /*
             * These listings are what makes the source a meal prep source: each one
             * is the site itself saying the dish is meant to be packed and carried,
             * which is why `meal_prep` below can be stated rather than guessed.
             * Adding a listing that is not is what would make the flag a lie — put
             * that in a separate source entry instead.
             *
             * The three named categories are the strong signal but only run to ~58
             * recipes between them. `catFilter=127` is the site's own "Na wynos"
             * (takeaway) tag, which it applies to roughly half its archive — around
             * 1080 recipes over 54 pages, so an import of the lot is long even
             * though every page is cached afterwards.
             */
            'meal_prep' => true,
            'listings' => [
                '/przepisy/kategoria/lunchbox/',
                '/przepisy/kategoria/jedzenie-na-wycieczke/',
                '/przepisy/kategoria/piknik/',
                '/przepisy/?catFilter=127',
            ],
        ],

        'beszamel' => [
            'name' => 'beszamel.se.pl',
            'base_url' => 'https://beszamel.se.pl',

            /*
             * The site's robots.txt excludes only API and CDN paths and declares no
             * Crawl-delay, so this is our own politeness rather than a stated rule.
             */
            'crawl_delay_seconds' => 5,

            /*
             * Category listings, NOT the site's /przepisy/ hub: that page ignores
             * ?page and answers with the same 296 links however many times it is
             * asked, so it cannot reach the archive behind it. These do paginate —
             * 80-odd pages each — and are chosen for everyday cooking over cake.
             */
            'listings' => [
                '/przepisy/dania-glowne-miesne/',
                '/przepisy/zupy-na-cieplo/',
                '/przepisy/sniadania/',
                '/przepisy/dania-glowne-bezmiesne/',
                '/przepisy/makarony-i-kluski/',
                '/przepisy/dania-glowne-rybne/',
                '/przepisy/warzywa-na-cieplo/',
                '/przepisy/salatki-z-warzyw/',
                '/przepisy/przystawki-i-przekaski/',
                '/przepisy/pierogi/',
                '/przepisy/nalesniki/',
                '/przepisy/salatki-sycace/',
            ],
        ],
    ],
];
