# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Private kitchen/meal-planning app for two people (the owner and his girlfriend). Goals:

- Suggest dishes based on what is currently in the fridge/pantry.
- Maintain a shopping list ("co kupić").
- Guide cooking step-by-step, Thermomix-style (ordered steps: what, where, how, temperature, time).
- Encourage dietary variety and keep the food budget under control.

Data cleanliness is an explicit requirement: ingredients, units, and similar concepts must be **first-class entities that are never duplicated as free text**. Recipes reference an `Ingredient` + `Unit` + quantity; they never store `"2 łyżki mąki"` as a string. Prices/budget hang off ingredients (or purchases of them), not off recipes.

Status (2026-08-04): the catalogue is built and populated — recipes from four sources, with a browsing UI — and the kitchen, the shopping list and the leaflet-driven "gdzie kupić taniej" plan are all live. Budget tracking over time is the piece still missing: `Money` and `promotions` now hold real prices, but nothing yet records what was actually spent, so `ingredients.current_price_per_unit` is still unused.

## Stack

Laravel 13 (PHP 8.3) · Inertia.js 3 · Vue 3 (`<script setup>`, TypeScript) · Tailwind CSS 4 · Vite 8 · SQLite · Pest 5 / PHPUnit · Laravel Wayfinder.

## Commands

```bash
composer setup           # install deps, .env, key, migrate, npm install, build
composer dev             # serve + queue:listen + vite concurrently
composer test            # config:clear + pint --test + phpstan + artisan test
composer ci:check        # what CI runs: eslint, prettier, vue-tsc, then composer test
composer lint            # pint --parallel (fix)
composer types:check     # phpstan analyse (level 7, larastan)

npm run dev              # vite dev server only
npm run lint             # eslint --fix
npm run format           # prettier --write resources/
npm run types:check      # vue-tsc --noEmit
```

Single test / filtering:

```bash
php artisan test tests/Feature/RecipeTest.php
php artisan test --filter=it_suggests_dishes_from_pantry
php artisan test --testsuite=Unit
```

The project lives under Herd, so it is also served at `https://kitchen.test` without `composer dev`; you still need `npm run dev` (or `npm run build`) for assets.

Tests run against SQLite `:memory:` (see `phpunit.xml`); local dev uses the committed `database/database.sqlite`.

## Architecture notes

**Inertia, no API layer.** Controllers return `Inertia::render('SomePage', [...])`. There is no REST/JSON API — `bootstrap/app.php` only renders JSON for `api/*` requests. Routes live in `routes/web.php` (currently a single `Route::inertia('/', 'Welcome')`).

**Per-page Vite entrypoints.** `resources/views/app.blade.php` does `@vite([... "resources/js/pages/{$page['component']}.vue"])`. A page component's name in `Inertia::render()` maps directly to a file under `resources/js/pages/`; nesting works (`'Recipes/Show'` → `resources/js/pages/Recipes/Show.vue`). `resources/js/app.ts` only calls `createInertiaApp` — there is no manual page resolver to register components in.

**Wayfinder generates typed route/action helpers.** The Vite plugin regenerates `resources/js/actions/**`, `resources/js/routes/**`, and `resources/js/wayfinder/**` from PHP routes and controllers. **Never hand-edit those directories** (they are in the eslint ignore list). Import from them instead of hardcoding URLs in Vue. Adding a controller/route makes new helpers appear after the dev server or a build runs.

**Shared Inertia props** are defined in `app/Http/Middleware/HandleInertiaRequests::share()` (`name`, `auth.user`). Their TypeScript shape is declared by augmenting `InertiaConfig['sharedPageProps']` in `resources/js/types/global.d.ts` — update both sides together when adding a shared prop.

**Framework defaults** are set in `AppServiceProvider::configureDefaults()`: `CarbonImmutable` as the date class, destructive DB commands prohibited in production, strict password rules in production only.

## Conventions

- **Models** use PHP attributes rather than properties: `#[Fillable([...])]`, `#[Hidden([...])]` (see `app/Models/User.php`), plus a full `@property` docblock — phpstan level 7 relies on it. Casts go in the `casts()` method.
- **Migrations** are per-concern and timestamped as usual; the starter's `0001_01_01_*` migrations cover users/cache/jobs.
- **PHP style** is Laravel Pint (`pint.json`, `laravel` preset). Run `composer lint` before finishing.
- **TS/Vue style** is enforced beyond Prettier by `eslint.config.js`:
  - `import type { X }` — separate type imports, top-level specifier style.
  - `import/order` alphabetized, grouped builtin → external → internal → parent → sibling → index.
  - Blank line required before and after every control statement (`if`, `return`, `for`, `while`, `switch`, `try`, `throw`).
  - Braces always (`curly: all`), 1tbs, no single-line blocks.
- **Class-based tests** — despite Pest being installed, `tests/Pest.php` is empty and existing tests are plain PHPUnit classes extending `Tests\TestCase` with `RefreshDatabase`. Follow the existing style unless deliberately switching the suite over.
- `@/*` in TS resolves to `resources/js/*`. `cn()` in `resources/js/lib/utils.ts` merges Tailwind classes.
- Node scripts are disabled by `.npmrc` (`ignore-scripts=true`).

## Recipe import (`app/Importing`)

Ports and adapters, because the pipeline must not care which website a recipe came from.

- **Ports**: `Contracts/RecipeSource` (one site), `Contracts/PageFetcher` (reading a page).
- **Boundary DTOs**: `Drafts/RecipeDraft` + `IngredientLineDraft`/`StepDraft` — still raw text. Everything above them is site-specific, everything below is not.
- **Adapters**: `Sources/KwestiaSmaku/*` and `Sources/AirFryerPrzepisy/*` (the only classes that know any site's markup), `Http/ThrottledPageFetcher`.
- **Shared, not site-specific**: `Sources/SchemaOrg/JsonLdRecipeParser` reads a `schema.org/Recipe` out of a page's JSON-LD. It retries a decode that fails on control characters, because sites do leave raw newlines inside JSON strings (beszamel.se.pl loses 2% of its recipes otherwise). It is the contract WordPress recipe plugins publish, so a site that emits it needs only a listing parser — no second HTML scraper. Prefer this over scraping when a candidate site has JSON-LD. Two things it has to do that reading the fields literally would miss: schema.org has no field for the parts of a multi-part recipe, so plugins smuggle them into `recipeIngredient` as a zero-amount entry ending in a colon (`"0 g Sos:"`) — those become the `section` of the lines that follow, not products called "Sos:"; and `image` is usually an `@id` pointing at an `ImageObject` defined elsewhere in the document, so the parser indexes the graph by `@id` and follows it. Without that second one every recipe on such a site imports without a picture.
- **Pipeline**: `ImportRecipes` → `StoreRecipeDraft` → `Parsing/*` + `Resolving/*` + `StepIngredientLinker`.
- **Composition root**: `Providers/ImportingServiceProvider` + `RecipeSourceRegistry`. Adding a site means one adapter and one registry entry; nothing else changes.

Sources: `kwestiasmaku` and `beszamel` (everyday Polish cooking), `airfryerprzepisy` (air fryer only) and `centrumrespo` (meal prep only — see below). Run it with `php artisan recipes:import kwestiasmaku --limit=30` (`--replace` to re-import existing), then **always** `php artisan recipes:quality` to see what it failed to understand. `--fail-over=N` makes that command exit non-zero when more than N% of lines need review, so it can gate a batch.

**A batch of more than ~50 recipes needs `php -d memory_limit=1G artisan recipes:import …`.** The default 128 MB dies partway through: beszamel.se.pl's listing pages are close to a megabyte and parsing one into a DOM costs many times that. The run stops where it ran out, so re-running simply continues — but the recipes it did not reach are silently missing until you look.

**Before adding any source, fetch its `robots.txt` and honour it.** `aniagotuje.pl` disallows `ClaudeBot` outright, which is why it is not a source — despite having the best air fryer section. `przepisy.pl` answers 403 to the bot entirely. `kwestiasmaku.com` allows crawling with `Crawl-delay: 10`; `airfryerprzepisy.pl` and `centrumrespo.pl` exclude only `/wp-admin/` and declare no delay, and `beszamel.se.pl` excludes only API and CDN paths, so their 5 s is our own politeness. The delay is per source and enforced by `ThrottledPageFetcher`; pages are cached in the **file** store (not the default) so `migrate:fresh` never triggers a re-crawl.

**`beszamel.se.pl` needs its own ingredient parser and that is why it has one.** Its JSON-LD is fine for everything except `recipeIngredient`, which it publishes as one string with every line glued to the next ("…odsączone i opłukane1–2 łyżki oliwy"). The lines are separated in its own markup, so `Sources/Beszamel/BeszamelPageParser` reads them from `div.ingredients__items` and `RecipeDraft::withIngredientLines()` swaps in only that part. **The site uses two list layouts at once** — one `<li>` per line on older recipes, a single `<li>` split by `<br>` on newer ones — and reading only one of them turns the other into a single thousand-character "ingredient". Both are covered by tests; do not simplify that away. Its titles are tabloid headlines ("Szakszuka podbiła pół świata, ta bliskowschodnia jajecznica…"), imported verbatim for now.

**A candidate needs machine-readable recipes, not just the right topic.** These were checked for a second meal prep source and rejected — do not re-tread them without a reason: `filozofiasmaku.pl`, `makebentonotwar.com`, `barbaradabrowska.pl`, `pieguskowakuchnia.pl` and `mniammniam.com` publish `BlogPosting`/`Article` or nothing, with the recipe left in prose; `jadlonomia.com` emits no JSON-LD at all; `kuchnialidla.pl` serves a 2 KB JavaScript shell; `doradcasmaku.pl` is an article portal with no lunchbox listing; `smacznastrona.pl` answers 403. Polish lunchbox writing is overwhelmingly editorial, which is why `centrumrespo.pl` is the only structured one so far.

**A recipe's slug is unique across every source.** Two sites publishing the same dish would collide, so `StoreRecipeDraft::availableSlug()` numbers the later one rather than losing it to a constraint violation.

### Data-quality rules these classes exist to enforce

- **Polish declension is the core problem.** "makaronu", "cebuli", "ząbki czosnku" must all reach one `Ingredient`. `ingredient_aliases` is the authority; `PolishInflection` only widens the net when no alias matches.
- **An invented product poisons resolution for every line that contains its name.** beszamel.se.pl captions the parts of a recipe with a bare list item ("Do smażenia", "Na ciasto"), indistinguishable in the markup from an ingredient. Imported as a product, "Do smażenia" then won every later "olej do smażenia" line, because the resolver tries the longest word groups first — correctly, since that is what keeps "ser pleśniowy" out of "ser". 222 lines resolved to a caption before this was found. `BeszamelPageParser::isSectionCaption()` now reads the "for the …" idiom as a section, but note the second half of the lesson: **fixing the parser does not undo the damage.** The invented product survives in the database holding its alias, so the cleanup is to delete such `IngredientSource::Import` products and re-import from cache.
- **A caption read as a product is recoverable; a product read as a caption is not.** That asymmetry is why the rule above is deliberately narrow — capitalised "Na "/"Do " with no digits, nothing else. Two-word captions such as "Farsz owocowy" stay in the review queue on purpose. Do not widen it to "starts with a capital": on real data "Masło do smażenia" and "Sól i pieprz do smaku" do too.
- **Guesses are never written back as aliases.** Only the curated dictionary and auto-created products own aliases. Recording a fuzzy match would promote a guess to authority, and every later import would trust it without re-checking. Do not "optimise" this by caching matches into the alias table.
- **Nothing is ever dropped.** An unrecognised line is still imported, with `raw_text` intact and `needs_review = true`. Improving the parser is therefore a re-run, not a re-crawl.
- **Grow `database/data/ingredients.php` before touching the parser.** That dictionary took the review queue from 10% of lines to 0%; parser cleverness did not.
- **The dictionary outranks a product the importer invented.** Aliases are otherwise first-writer-wins, but an invented product holding the very phrase you just curated would keep it for good and the new entry would never take effect — the queue could never shrink. `IngredientSeeder::supersedeInventedProduct()` therefore moves the alias, repoints the recipe lines and retires the invention once it holds nothing. Two *curated* products claiming one alias is still left alone: that is a real duplicate, not a promotion.
- **A product must own its own name.** `IngredientSeeder` throws if another entry already claimed it, and `tests/Unit/IngredientDictionaryTest.php` catches the same thing in a second without a database. Three real duplicates were found this way (concentrate vs passata, bread vs baguette, couscous listed twice).
- Units are a closed vocabulary (`database/data/units.php` + `Parsing/UnitVocabulary`). An unknown unit code is a bug, not data — unlike ingredients, they are never auto-created.
- **Some words are a unit in one recipe and part of the name in another** ("listek bazylii" vs "listek laurowy"). The parser keeps both spellings on `ParsedIngredientLine`, and `IngredientResolver::resolve()` retries with the measure word restored. Add cases here rather than removing words from the unit vocabulary.
- **A group name must never become a product.** This is the worst failure this codebase has had. A bare `sos:` line invented a product called "Sos:" owning the alias `sos`, after which *every* sauce in the database resolved to it — 83 unrelated lines on that one word, plus the same via `pasta` and `płatki`. One junk row silently absorbed dozens of real ingredients. `SectionHeading` now defends in three places, and all three are needed: a standalone heading becomes a section, an inline `sos: 4 łyżki jogurtu` prefix is stripped, and a line that reduces to a bare group word resolves to **nothing at all** (`ingredient_id` null, flagged). Leaving a line unresolved is always better than attaching it to an invention.
- **Only one word of a Polish phrase is usually declined.** Reducing every word at once turned "marynaty teriyaki" into "marynata teriyak**a**", matching nothing. `IngredientResolver::spellingsOf()` also tries each word lemmatised on its own. This was silent — it broke every multi-word product, not one.
- **A label in front of the amount hides it.** "opcjonalnie: 1 łyżeczka sosu" parsed as a nameless line whose "product" was the whole sentence. `IngredientLineParser::stripLeadingMarker()` removes those, with or without a separator.

### Working on import quality

Hard-won process, in order. Skipping a step here costs hours.

1. **Import, then `recipes:quality`, always.** The command exists because the numbers were previously computed by hand in a scratch file, which is neither repeatable nor comparable between runs.
2. **Seed the dictionary before re-importing.** `database/data/ingredients.php` is not live data — entries added to the file do nothing until `db:seed --class=IngredientSeeder` runs. Re-importing first makes the queue look unchanged and wastes the run.
3. **Diagnose from `raw_text`, never from the invented product's name.** The name is the *output* of the bug. Reading names led to a confidently wrong diagnosis ("headings are being counted as products"); one look at the raw lines showed the real, far worse mechanism.
4. **Attack the queue by frequency, not alphabetically.** Group `recipe_ingredients` by product where `ingredients.source = 'import'` and sort descending. The top of that list is usually a bug; the tail is genuinely missing vocabulary.
5. **Re-processing is free — re-crawling is not.** Every fetched page lives in the file cache, so `migrate:fresh --seed` plus a full re-import of ~2650 recipes costs zero requests and a few minutes. Never re-crawl to fix a parser.

Baseline to compare against, kwestiasmaku only, after nine dictionary passes: **100% of lines mapped to a product, 0.2% needing review, ~80% carrying an amount**. Across all four sources the queue sits near 3%, because the three later sources have not had the same dictionary work. A number far off these means something regressed.

### Running a long import

- **One import at a time.** Two writers on SQLite cost five recipes to `database is locked` before `journal_mode=WAL` and a 10 s `busy_timeout` were set in `config/database.php`. WAL lets readers (the quality report, the browsing UI) work alongside the writer, but a second *writer* is still a bad idea.
- **Do not pipe the command through `tail`** when backgrounding it: `tail` buffers until the process exits, so the log stays empty and progress is invisible for hours. Check `Recipe::count()` instead if it happens.
- **A category that returns nothing must drop out of the walk** (`KwestiaSmakuRecipeSource::discover()`). Re-asking exhausted categories for page after page burns one throttled request each, per round — hours of waiting on empty responses.
- Failures are reported per recipe and never abort the run, so **read the summary line**: a non-zero "Failed" column is real recipes lost, and re-running recovers them because the pages are cached.

### Guided cooking ("Thermomix" screens)

`recipes.is_meal_prep` marks a dish **cooked ahead in a batch and carried** — a lunchbox dish rather than something served off the pan. Like `appliance` it is stated by the adapter, never guessed from a title: every listing the `centrumrespo` source walks is the site itself saying the dish is meant to be packed and carried, and `config('importing.sources.centrumrespo.meal_prep')` is the flag those listings justify. A free-text tag read off a recipe could not be trusted for a filter; this can. Adding a listing that is not would make the flag a lie — give it its own source entry instead.

The lunchbox, picnic and packed-lunch categories are the strong signal but run to only ~58 recipes, which is too thin to plan a week from. `/przepisy/?catFilter=127` is the site's own "Na wynos" tag over roughly half its archive (~1080 recipes, 54 pages) and carries the bulk. Note that listing is a **query-string filter**, so `CentrumRespoRecipeSource::paged()` puts `page/N/` before the `?` — appending it after would request a URL that does not exist and end the walk after one page, losing the catalogue without reporting a single failure. `Recipe::mealPrep()` is the scope, and the browsing UI badges and filters on it.

Note the hazard if that ever changes: a recipe appears in several of a site's categories, so a second source entry over the same site with `meal_prep => false` would silently clear the flag on re-import.

`recipes.appliance` marks a recipe written **for one device** — every `airfryerprzepisy.pl` import is `Appliance::AirFryer`. It is a fact about the source, set by the adapter, not something guessed from step text, and it is separate from the per-step `appliance` below. An air fryer recipe is not an oven recipe with a shorter time, so the browsing UI badges it and can filter to it.

`recipe_steps` carries `action` (`StepAction`), `appliance` (`Appliance`, with a stable `iconKey()`), `temperature_celsius` and `duration_seconds`. `recipe_step_ingredients` links a step to the exact lines it consumes, which is what makes "ADD 150 g of courgette" possible instead of replaying prose.

### The cooking screen and its timer

`/przepis/{slug}/gotowanie` (`CookingController` → `pages/Cooking/Show.vue`) walks one dish one step at a time: the step's own action, appliance and temperature as chips, the instruction at 24 px, and the lines that step consumes underneath. It is a **page**, not a mode of `RecipeModal` — it has to survive a reload, be openable on the other phone, and give a timer somewhere to come back to. Its payload is deliberately *not* `RecipeController::detail()`: standing at the hob, the source, the tags and the state of the fridge are not questions, and asking the pantry about every line would be work for none of them. It honours the same `?porcje=` (`RecipeScale::requestedFrom()`, now shared by both controllers) and the "Gotujmy" link carries it over — being walked through the amounts for four while reading a recipe scaled to six is the one way this screen could be actively wrong.

**A timer is a deadline, never a countdown.** That single rule is what makes it survive being backgrounded, and everything in `lib/cook-timer.ts` follows from it. A hidden page's `setInterval` is throttled or frozen by the phone — not a bug to work around, it is how batteries last — so nothing counts down. The state holds `endsAt` (wall-clock ms) and what is left is recomputed from the clock on every tick, on `visibilitychange`, and on mount. The interval only decides how often the screen redraws; it can stop for four minutes and the number is still right. It is persisted in `localStorage`, so closing the app or reloading lands on a deadline that is still true, and the screen reopens on the step the pot belongs to rather than step one.

- **The alarm is best effort; the clock is not.** `sw.js` (bumped to **v3**) takes the deadline by `postMessage` and shows a notification, so the phone tells you while the app is in the background — but a worker gets shut down whenever the phone likes, and there is no push server here to wake it (Notification Triggers never shipped, and a two-person app does not warrant one). So the page also rings the moment it is looked at again. Nothing is lost either way, because neither of those is where the time is kept.
- The worker stays **silent when a window is visible**: the page itself rings, vibrates and says so on screen, and two alarms for one pot is one too many.
- **`acknowledged` is why it rings once.** Coming back to a finished timer must greet you — but mounting the screen again five times must not. It is set and persisted before the chime.
- Notification permission is asked for on the tap that starts a timer, never on page load, and the `AudioContext` is created on that same gesture: one made without one starts suspended and would be a silent alarm.
- `useWakeLock()` keeps the screen on while cooking. The browser **releases it whenever the page is hidden**, so it is retaken on the way back; where it is unsupported the honest answer is to carry on without it.
- One timer at a time, on purpose. A second clock needs somewhere to live on a phone screen already holding a step, and a timer you cannot see is worse than none. A timer left running on an earlier step follows the cook as a pill above the nav that taps back to it.

**Poppins needs `subsets: ['latin', 'latin-ext']` and this is not cosmetic.** The plugin's default is `latin`, whose `unicode-range` stops before ą, ę, ć, ł, ń, ś, ź and ż — every one of which then falls back to a system font *mid-word*, off the baseline, in a different face. On a Polish cookbook that is most of the text on the screen. It fails quietly, which is why it survived until somebody read a cooking step at 24 px.

`StepIngredientLinker` matches ingredient spellings inside step text. When a recipe lists the same product twice (butter for the soup, butter for the garlic bread) it keeps only the line from the step's own `section` — do not regress this into attaching both.

## Accounts and the QR device link

One household, one account, two phones. There are no per-user roles and no ownership on any record — everything in the catalogue is shared, which is the point.

- **Sign-up closes itself.** `App\Auth\Registration::isOpen()` returns true only while no account exists; after that `/rejestracja` answers **404**, not 403, because a 403 would confirm to a stranger that this address has an account. `ALLOW_REGISTRATION=true|false` overrides it. `DatabaseSeeder` therefore creates **no user** — a seeded account would lock the real owner out of the only sign-up form there is.
- **`App\Auth\DeviceLink`** issues and redeems the codes. The second phone scans a QR, opens `/dolacz/{token}` and is signed in.

**The QR code is a credential. Treat every one of these as load-bearing:**

- Stored **hashed only** (`token_hash`), never in plain text.
- **Single use** — redeeming marks it spent, inside a `lockForUpdate` transaction so two phones scanning the same screen cannot both get in.
- **Expires in `DeviceLink::LIFETIME_MINUTES`** (5). Long enough to fetch a phone, short enough that a photograph of the screen goes stale.
- **Issuing a new code kills the previous one**, so a code someone walked away from stops working.
- Redemption is a plain `GET` on purpose: opening the link is the whole convenience. That is precisely why the three properties above are not optional, and why the screen says so in plain Polish rather than hiding it.
- Rate limited (`throttle:10,1` on redeem, `throttle:6,1` on login). Login answers with **one message for both a wrong password and an unknown address**, so the form cannot be used to enumerate accounts.

`tests/Feature/Auth/DeviceLinkTest.php` pins expiry, single use, supersession, hashing and the unauthenticated case. If you change the flow, those tests are the specification.

## Look and feel

**White page, cool neutral greys, one green accent.** The palette lives in `resources/css/app.css` as `@theme` tokens — **use them, never raw Tailwind greys**: `paper` / `paper-raised` / `paper-sunk` for surfaces, `ink` / `ink-muted` / `ink-faint` for text, `rule` / `rule-strong` for hairlines, `flag` and `leaf` for the review and meal-prep annotations. Dark mode was deliberately removed: a second palette would halve the attention each gets.

The neutrals are cool on purpose. An earlier cream page came with a warm ramp (brown-black ink, beige hairlines); moved onto white, those same tones read as dirt. If the background ever changes again, **retune the whole ramp with it** rather than only the background.

**The accent is `#55b850`, and it comes in two shades for a reason.** It is a light green: white on it is only 2.5:1, and as text on white it is 2.4:1 — both unusable. So:

- `accent` — filled buttons and chips, which carry **ink** labels, not white (6.8:1). Also icons, borders, focus rings, the app icon and `theme_color`.
- `accent-strong` (`#2f7a2b`) — accent-coloured **text** on white, 5.3:1.
- `accent-soft` — tints for backgrounds and underlines.

Accent-coloured small text and white-on-accent are the two mistakes to avoid; both look passable on a desk monitor and vanish outdoors. Measured in the browser after the change: active chip 7.02:1, card meta 6.05:1, card title 17.64:1, placeholder 4.51:1.

**The tab says what you are looking at.** `APP_NAME=Kuchnia` (it shipped as `Laravel`, which every tab then read), and `resources/js/app.ts` composes `"<page> - Kuchnia"`. Two pages compute their half rather than stating it: an open recipe names the dish, because the detail has its own shareable URL and a bookmark reading "Przepisy" says nothing about which one it is; and the shopping list adds its list name once it is not the main one, since two tabs both reading "Co kupić" are the problem the switcher exists to solve. Note `VITE_APP_NAME` is baked in when Vite starts — changing the name needs a dev-server restart, not just `config:clear`.

Typeface is **Poppins** (loaded via `bunny()` in `vite.config.ts`), one family for everything. Hierarchy comes from size and weight, not a second face. `label-caps` is the small-caps label used for meta rows and section headings.

**Interface icons are drawn, never emoji** — emoji render differently per platform, sit off the baseline and cannot take the ink colour. Two sets, both one stroke weight on one 24-unit grid: `lib/icons.ts` + `components/AppIcon.vue` for interface icons, and `components/ApplianceIcon.vue` for cooking equipment. Adding an `Appliance` case means adding a path to the latter.

**Products are the one deliberate exception, and only products.** A control has to take the ink colour and match a stroke weight; a carrot beside the word "Marchewka" is doing a different job — it is what makes one row findable in a list of forty, and colour is exactly what does that. Nine hundred drawn vegetables were never going to happen either. So `components/IngredientLabel.vue` is the only place emoji appear, and every product name goes through it. Do not extend this to buttons, chips or appliances.

**Touch targets are ≥44px and this has been measured, not assumed.** An earlier pass claimed it and was wrong: filter chips were 36px and a checkbox was 13px. When changing controls, re-measure — the browser can list every offender in one query over `getBoundingClientRect()`.

## Installed app (PWA)

The app is meant to be installed on a phone and used by two people on one account at the same time.

- `public/manifest.webmanifest`, `public/sw.js`, `public/offline.html` and `public/icons/*` are plain static files; icons were generated by a GD script (there is no TrueType support in this environment, hence the drawn spoon and fork rather than a lettermark).
- **The tab wears the same mark as the installed app.** `public/favicon.svg` is that spoon and fork as paths, on `accent` behind `ink` — the manifest icons, `theme_color` and this are one green, and a favicon that disagreed would read as a different app in the tab strip. Both shipped as Laravel's red logo until it was noticed: the starter's files are the ones a browser asks for by name, so replacing the PWA icons alone changed nothing you actually look at. `public/favicon.ico` is regenerated by `php scripts/make-favicon.php` (16/32/48 PNG frames in an ICO container — legal since Vista, and it spares writing a BMP encoder with an AND mask); it is drawn with GD primitives rather than rasterised from the SVG, for the same reason the app icons were, so **the two have to be kept in step by hand**. `offline.html` names the icon itself: it is served from the cache with no Blade around it, and the one screen you see when the phone loses signal should not be the one with a blank tab. That file is precached, so editing it means bumping `VERSION` in `sw.js`.
- The service worker registers **only in production** (`app.ts`), so a stale cache never shadows a rebuild during development.
- **It deliberately never caches Inertia's XHR** (`X-Inertia` header). Two people share the account; serving one of them a cached response would show them the other's stale view. Pages are network-first with a cache fallback, hashed build assets are cache-first, and recipe photos are cache-first but capped at 300 entries — ~2650 recipes' images would otherwise fill the phone.
- Bump `VERSION` in `sw.js` when the caching strategy changes; the activate handler drops every cache that is not in the current set.

## Quick-pick categories

The row at the top of the list — Kurczak, Zupy, Makarony — is **derived, not imported**. The sources' own categories were never recorded, and their tags cover barely half the catalogue while mixing cuisine with diet with single ingredients ("Dla kobiet w ciąży", "kurkuma"). So `App\Catalogue\CategoriseRecipes` computes them from what is trustworthy everywhere: canonical ingredients first, with titles and tags as corroboration.

- Rules live in `database/data/categories.php`. Editing them and running `php artisan recipes:categorise` is the whole workflow — no re-import needed.
- It **replaces** a recipe's categories rather than adding to them, so a rule that stops matching actually releases the recipe. Without that the counts drift upward for ever.
- A recipe may sit in several categories; a chicken soup belongs in both, and forcing one home would make one of the two buttons lie.
- A category matching nothing is hidden from the row rather than shown as a dead button.
- `tests/Feature/CategoriseRecipesTest.php` pins ingredient matching, title matching, multi-membership and the replace-not-accumulate rule.
- **Run `php artisan recipes:meal-slots` after this one**, not before: the meal-time rules read these categories as their strongest signal, so tagging against stale ones tags against yesterday's answer.

**Title needles are whole words, and that is not a detail.** A plain substring search put "Coś **zupe**łnie osobliwego" in Soups and would have put a celery salad in Desserts ("**łody**ga"). A trailing `*` opts into prefix matching for stems that need it (`wieprzow*`). Three rules were removed after an audit against the real catalogue and are documented in the data file so they are not proposed again:

- **`pasta` in Makarony** — 80 wrong recipes. In Polish it means a spread: "Pasta z makreli", "Pasta warzywna".
- **`stek` in Wołowina** — matched "Steki z karkówki", which is pork.
- **`ciasto`/`tarta` in Desery** — means dough as much as cake, dragging tomato tarts and bacon puff pastry into desserts. Baking is now its own category.

**Wege refuses to guess.** It matches only when *every* ingredient on the recipe is curated. An unrecognised ingredient is stored under `Other`, so a chicken dish whose "filet z kurczaka" was never curated looks meat-free — trusting that put 2705 of 4580 recipes in the category, most wrongly. After the guard: 2019, with 1.8% of titles containing a meat word and those checked to be genuine ("Kotlety z bobu", "Pulpety z tofu"). Someone avoiding meat is exactly the person who must not be handed a guess.

## Browsing UI

The list holds ~4300 recipes and grows by 36 as you scroll, driven by an `IntersectionObserver` on a sentinel below the grid. Two things there are load-bearing: the observer **follows the sentinel ref** (`watch(sentinel, …)`) because filtering destroys and recreates that element — observing only the first one stops the loading silently after the first filter change; and the sentinel disappears at the end so the list has a bottom. Keyboard scrolling moves the sentinel into view exactly as a finger does, so this is reachable without a mouse.


One page, `resources/js/pages/Recipes/Index.vue`, served by `RecipeController`. `show` renders the **same** component as `index` with one extra `recipe` prop, so the detail opens as a modal over the list while keeping its own shareable URL. Opening is a partial visit (`only: ['recipe']`); closing must be a full visit, or the prop never clears and the modal stays open.

Filtering is client side over the `recipes` prop — "tylko do sprawdzenia", "tylko na airfryer", "tylko meal prep" — so every flag a filter uses has to be on the *summary*, not only on the open recipe.

Presentation helpers live in `resources/js/lib/recipe-display.ts` — appliance icons, Polish action labels and duration formatting. Icon keys mirror the `Appliance` enum, so a new appliance needs an entry in both.

**Amounts are formatted in exactly one place: `resources/js/lib/quantity.ts`.** The kitchen, the shopping list and a recipe's ingredient lines all render the same numbers, so they have to round and spell them identically. `formatNumber()` prefers the fractions a cook writes (`½`, `⅓`, `1 ¼`) and falls back to two decimals with a Polish comma; `formatQuantity()` adds the unit. This is not cosmetic: a recipe's "1/3 szklanki" is stored as a float, **SQLite does not honour the `decimal(10,3)` the migrations declare** (precision is advisory there — the column keeps whatever the sum produced), and the shopping list was reading `0.3333333333 szkl. pomarańcza`. Never interpolate a raw `quantity` into a template.

**Every product name renders through `components/IngredientLabel.vue`** — emoji plus name, with the emoji `aria-hidden` (the name beside it already says what it is) in a fixed-width column so a list of names has a straight left edge. `inline` drops that column for a chip inside a run of text. Adding a screen that names a product means using this component and shipping an `emoji` on that payload.

The modal has a "pokaż oryginalny tekst" toggle showing each line's `raw_text`. It exists to verify imports against the source; keep it.

## Weights and conversions (`app/Support/Measurement`)

`Quantity` will never cross a dimension on its own and that is correct: grams and millilitres are genuinely different things **until you say of what**. `IngredientMeasures` is the "of what", and `database/data/ingredient-measures.php` is the data behind it. Without them a recipe wanting "2 cebule", a fridge holding "300 g cebuli" and a leaflet selling a 1 kg bag are three unrelated facts, and the app can only shrug at all three.

**A weight belongs to a (product, unit) pair, not to either one alone.** A ząbek of garlic is 5 g and a whole główka is 45 g — same product, factor of nine. A ząbek in the abstract weighs nothing at all. That is why `ingredient_measures` is a two-column key and why the count units in `units.php` all have `factor_to_base = 1`: the factor cannot live on the unit.

Lookup is layered, most specific first, exactly like ingredient aliases:

1. **An explicit weight** for this (product, unit) pair — the authority.
2. **Density** (`ingredients.density_g_per_ml`), for any volume unit with no explicit weight. One entry covers ml, l, łyżka, łyżeczka and szklanka at once.
3. **Nothing** — and nothing means `null`, never an average.

**The override layer is not tidiness.** A tablespoon of flour is not 15 ml × 0.53 = 8 g; a Polish recipe means a heaped spoon, nearer 15 g, and every recipe in the catalogue was written by someone who meant that. So flour carries a density for glasses *and* an explicit spoon weight. Liquids that genuinely pour — oil, milk, soy sauce — need only the density.

**`covers()` has three answers, not two: yes, no, and null for "cannot tell".** Every caller must read null as *stay quiet*. Telling someone they are out of onions because nobody recorded what an onion weighs is worse than saying nothing, and it is the failure this data was added to remove, not to introduce in a new form.

`Quantity::sum()` deliberately still refuses grams + millilitres. It knows nothing about products, and without a product there is no answer. `IngredientMeasures::sum()` is the same rule with the missing half supplied, and it keeps the **first** amount's unit, so a kitchen counted in pieces goes on reading in pieces.

`MeasureBook` loads the whole book in two queries and is a **singleton** (`AppServiceProvider`). One shopping plan asks it from the planner, the pantry and the shopping list inside a single call; separate copies would read the table three times to answer one question. `Pantry` holds it and exposes `measuresFor()`, so `RecipeAvailability` does not need a second collaborator saying the same thing.

### Working on the weights

```bash
php artisan ingredients:measures                                  # coverage + what is missing, by frequency
php artisan db:seed --class=IngredientMeasureSeeder               # after editing the data file
```

- **Coverage is measured against recipe lines, not products.** A dictionary of four hundred products missing the hundred every recipe uses is not 75% done. `MeasureCoverage` therefore counts lines and sorts the gaps by how often the catalogue leans on them — the same "attack by frequency" rule the ingredient review queue follows.
- The seeder is **authoritative**: it replaces a product's measures rather than adding to them, so correcting a number and re-seeding is the whole workflow. It throws on a product name or a unit code it does not recognise, because a silently ignored entry would look like coverage while the app went on saying "cannot tell".
- `tests/Unit/IngredientMeasureDictionaryTest.php` catches the same drift in a second without a database, including a density with the decimal point in the wrong place.
- Baseline after the first pass: **92.3% of amount-carrying lines convert to grams** (100% mass, 89.5% volume, 88.1% count). A number far below that means something regressed.

**Leaving a weight out is a real answer.** "1 szt. soli", "1 szt. wody", "1 szt. mąki" are parser artefacts from lines like "sól do smaku"; giving them a weight would invent an amount for every seasoning line in the catalogue. They are named in the data file's header so nobody helpfully adds them.

**Known gap, and it is not a missing weight.** `Papryka (łyżeczka)` is the largest remaining entry at ~850 lines, and every one of them is *ground paprika* resolved to the bell pepper — a teaspoon of a vegetable. No weight can fix it and no alias can either, because the word is identical; only the unit disambiguates. Fixing it means teaching `IngredientResolver` that a small dry measure of a vegetable is the spice of the same name, then re-importing (free — the pages are cached).

## The kitchen: fridge, freezer, pantry (`app/Pantry`)

`pantry_items` is **scoped to `user_id`**, not global — every account has its own kitchen. `PantryController` answers 404 (never 403) for another account's row, matching how registration hides itself.

One product in one place is **one row**: `store()` uses `updateOrCreate` on `[user_id, ingredient_id, location]`. Two "cheese in the fridge" rows would make every amount comparison wrong.

An entry may have **no amount**, and that means *"some, amount unknown"* — never *"none"*. `Pantry::amountOf()` returns `null` in that case, and in the two cases where a total cannot honestly be formed (one unmeasured entry among several, or grams and millilitres of the same product). Every caller must read `null` as "say nothing" rather than "short of it". The same product on two shelves adds up.

`RecipeAvailability` deliberately answers in two different places:
- `missingCounts(User)` — one SQL aggregate over ~30 000 ingredient lines, giving *how many products* each of ~4800 recipes is short of. Optional lines, staples (`is_staple`) and `IngredientCategory::Equipment` never count; an **unrecognised** line always does, because we cannot claim to have something we cannot name.
- `missingFor(Recipe, Pantry)` — PHP, one recipe, refined with `Quantity::covers()` so it can say `not_enough` as well as `absent`. This is what a shopping list will be built from, so it reports *why*.

Adding is one flow, not one per shelf: a single "Dodaj zakupy" button opens a sheet where you search, pick, and the shelf, unit **and amount** are **pre-filled** (`StorageLocation::suggestFor()` from the ingredient's category, `default_unit_id` for the unit, `1` for the amount) — all one tap from being changed, and the amount field selects itself on focus so overtyping is one gesture. After saving it returns to the search box with a running "Dodane (n)" list, because the real task is emptying a whole bag, not adding one thing.

**The amount is prefilled only when a unit is known**, in both the kitchen and the shopping list. An amount with no unit is refused by validation, so prefilling one without the other would turn a shortcut into an error message. Note the honest limit of `1`: for a product measured in grams it is a placeholder to type over, not a claim — a "sensible pack size" would be an invented number written onto a shelf or a shopping list, which is exactly what `Quantity::sum()` exists to prevent.

**`App\Catalogue\DefaultUnits` gives the other ~1300 products a unit.** The dictionary states one for its 344 curated entries; every product the importer invented had `default_unit_id` null, so ~79% of what you can search for prefilled nothing. The catalogue already holds the answer — ~80 000 recipe lines say how each product is actually measured — so the modal unit of those lines becomes the default. `php artisan ingredients:default-units` is the whole workflow (`--overwrite` to replace curated ones too; don't, without a reason). Three rules it exists to keep:

- **Only empty ones, by default.** The dictionary is the authority for what it covers, and derived usage must never quietly overrule a curated entry.
- **An exact unit beats an approximate one however rare it is.** Nobody stocks "1 szczypta" of salt; `pinch`/`drop`/`handful` are kept only when the catalogue has nothing else.
- **A product no recipe measures keeps no default.** Nothing is invented. On the current catalogue that leaves 306 of 1296 without one, which is the correct answer for them.

`tests/Feature/DefaultUnitsTest.php` pins all three.

**`App\Catalogue\IngredientEmoji` picks the picture beside a product's name**, in two layers: the product's own name first (`database/data/ingredient-emoji.php`, ~430 needles), its category second (`IngredientCategory::emoji()`). The category is always available, so a needle nobody wrote is not a gap — the product just keeps the broader picture. On the current catalogue **70% of products match a needle and 64% end up with a picture their category alone would not have given them**; resolving all 1640 costs ~40 ms, which is why it is derived per request rather than stored in a column: a better needle takes effect on the next page load instead of needing a migration and a backfill.

Needles follow the same rules `database/data/categories.php` learned the hard way — written already normalised, matched as **whole words**, trailing `*` for a prefix — plus one of its own: **the longest matching needle wins**, which is what keeps `serdelki` a sausage rather than cheese, `papryka wędzona` a spice rather than a bell pepper, and `owoce morza` seafood rather than fruit. Equal lengths are settled **alphabetically**, so the answer cannot depend on which word of a name came first.

Five stems are deliberately absent and must stay absent — **`mak*`** (swallows "makaron", "makrela"), **`cuk*`** ("cukinia"), **`lod*`** ("łodyga"), **`ziel*`** ("zielona"), **`rzep*`** (rapeseed) — each replaced by whole-word entries that cannot reach the words they would have eaten. Watch the **genitive plural** too: Polish drops the stem vowel, so `skrzydelk*` misses "skrzydełek" and `frytk*` misses "frytek". `tests/Unit/IngredientEmojiTest.php` pins every one of these.

Lookup is bucketed by the needle's first letter, because a needle can only match at a word boundary — so a name reaches two or three buckets, not all 430. That is what keeps the cost flat as the file grows, and it will grow.

**Measure coverage against needle matches, not against the rendered glyph.** Counting products whose emoji differs from their category's says "Sól" is unmatched — its needle gives 🧂 and so does `spice`. Passing a null category isolates the needle, and the first pass at this was 20 points wrong.

The product list is shipped whole (~900 rows, minus equipment) and filtered in the browser; a search endpoint would be slower and would not work offline.

`Recipes/Index.vue` gets `hasPantry` so the "Mam wszystko" / "Brakuje 1–2" chips stay hidden until there is something to match against — a chip reading 0 looks like a fault, not an invitation. `php artisan pantry:starter` stocks an account with the ~33 products the catalogue leans on hardest, which is how you get a kitchen worth filtering against without typing one. It is a command and **not** part of `DatabaseSeeder`: it writes into somebody's real kitchen, and a `migrate:fresh --seed` claiming you own thirty products would be a lie the shopping list then acts on. It writes **no amounts** — "some, amount unknown" is what is actually true of a kitchen nobody measured.

**Seasoning never counts as missing, and the rule is the category rather than `is_staple`.** A dish is not blocked by the garnish on top of it: `IngredientCategory::isSeasoning()` covers Spice, Fat and **Herb**, and `RecipeAvailability` applies it in both directions — the aggregate and `missingFor()` — so a recipe cannot read "masz wszystko" and then put paprika in the trolley. Two things to know before touching it:

- **`is_staple` alone was not enough**, which is why this exists. That column is written by the dictionary seeder, so it is true for the 344 curated products and false for every one of the ~1300 the importer invented — "przyprawa gyros" counted as a missing product and hid a perfectly cookable recipe.
- **Herbs are in on the numbers, not just the argument.** Exempting spices and fats alone moved cookable from 350 to 363, because curated spices already carry `is_staple`. Adding herbs moves it to 587; "Natka pietruszki" alone sits on 1 480 ingredient lines. **Sauces and sweeteners stay out** — soy sauce, mustard and honey are things you either have or must buy, and exempting them would make the filter lie in the expensive direction.
- The aggregate spells the category list out as **three SQL placeholders** so the expression stays a `literal-string`. `CookableFilterTest::test_the_seasoning_rule_matches_the_query` fails if a fourth seasoning category is ever added.

**The shortfall aggregate is computed once per request, and that took two fixes.** `RecipeListing` memoises it, because `page()` and `counts()` both need it and it scans ~98 000 ingredient lines (~120 ms) for an answer that cannot change in between. And `counts`/`categories`/`hasPantry` are **closures** in `RecipeController::list()`: Inertia drops props a partial visit did not ask for *before* evaluating them, so scrolling the list was running the catalogue-wide aggregate on every page of infinite scroll and throwing it away.

`RecipeCard` shows the shortfall — "masz wszystko" / "brakuje 2" — gated on `hasPantry` and silent past two, where a number stops being a nudge and becomes a shopping list. The modal marks lines you already hold with a green "masz" and **never marks the ones you lack**: on a half-catalogued kitchen that would put a warning on nearly every line of every recipe. Both exist so the chip can be checked; a filter nobody can verify is a filter nobody trusts.

## The week's meals (`app/Planning`)

`meal_plan_entries` is one meal on one day: `user_id`, `date`, `slot` (`MealSlot`), and **either** a `recipe_id` **or** a `note`, never both and never neither. A note ("kanapki", "obiad u rodziców") is a real plan that simply has no ingredients, so the shopping passes over it and counts it; both halves of that are reported to the screen.

`MealSlot` is a closed vocabulary for the same reason units are — typed-in slots would give "obiad", "Obiad" and "obiadek", and nothing could line one day up against the next. Five cases, but `MealSlot::everyday()` (śniadanie, obiad, kolacja) is what a day shows unasked: seven days × five empty rows is a wall, not a plan, so second breakfast and podwieczorek appear on the day they are asked for.

**Portions are what turn a plan into shopping, and the scaling rule is the household's own.** `servings` on the entry is how many portions that meal is for (default 2, `MealPlan::DEFAULT_SERVINGS`). `PlannedIngredients` groups entries **by recipe first** and sums their portions, then scales once: a recipe for 6, planned as 2 portions on three days, is factor 1 — one pot, cooked on Sunday, eaten three times. Scaling each meal separately and adding the results would buy three pots. `MealPlanTest::test_one_recipe_planned_on_three_days_is_cooked_once` pins it.

**A recipe that never stated its portions is used as written and said out loud.** 78 of ~10 200 recipes have no `servings`; there is no honest factor for them, so the amounts go on the list unscaled and the recipe's title comes back in `unscaled` for the screen to name. Guessing a portion size would silently halve or double a week's shopping.

**A week's shopping gets its own list, named after the days.** `PlanShoppingList` makes "3–9 sierpnia" (or "30 lipca – 2 sierpnia" when the ends fall in different months, because "30–2" reads as nonsense on the shop floor) and `PlannedShopping` writes there rather than onto the standing list — a week is one trip with a beginning and an end, and folded into the main list neither can be read. The name comes back in the flash so the screen can link to it.

**The same days always mean the same list, and pressing the button again rebuilds it.** That is how it is really used: swap a dish, press again, and the list has to become what the plan says *now* — not the old plan plus the new one, and not a drawer of near-identical "(2)", "(3)" lists with no way to tell which to take to the shop. `clearUnbought()` therefore deletes the untouched lines and the shortfall is written afresh. **Ticked-off lines survive untouched and are not asked for again**: you already have that, even though the kitchen will not know until the trolley is unpacked, and re-adding would un-tick it halfway down an aisle. `kept` reports how many, or re-running looks like it lost them.

**Month names are spelled out in `App\Support\PolishDate`, not by `translatedFormat()`.** That follows `app.locale`, which is `en` by house rule — identifiers and framework strings are English, only user-facing copy is Polish — so switching the locale to get one month name would drag every framework string with it. Note the genitive: a Polish date is "3 sierpnia", never "3 sierpień".

**Never below one whole cooking.** `PlannedIngredients::factorFor()` floors the factor at 1: two portions planned from a recipe for four is not half the shopping, it is half an egg and a quarter of a jar of passata. The pot is bought whole, the extra portions are leftovers, and the recipes that happened to come back in `wholeBatches` for the screen to name — buying more than was asked for is exactly what this app may not do quietly. Above one batch it scales normally (six portions from a recipe for four is 1.5).

**The kitchen is subtracted once, from the week's total — not once per recipe.** This is why `PlannedShopping` exists instead of a loop over `Trolley::addMissingFor()`: that method re-reads the pantry for every recipe, so two dishes each wanting 300 g of courgette against 500 g held would *both* find themselves covered and the week would shop 100 g short. `PlannedIngredients::toBuy()` applies the same three-answer rule one recipe gets (`covers()` returning null means *stay quiet*, so an unweighed shelf is trusted rather than bought twice), and products the kitchen covers are **absent from the result** rather than present with a null — null there means "some, amount unknown" and belongs on the list.

Two rules are shared rather than copied, and must stay that way: `RecipeAvailability::isAssumedAtHand()` is public so the week exempts exactly the same optional/equipment/staple/seasoning lines the per-recipe check does, and the subtraction itself lives in `IngredientMeasures::shortfall()`, used by both. A second copy of either would drift the first time a fourth exemption is argued about.

`RecipeAvailability::missingCounts()` takes an optional list of recipe ids: the browsing filters need the whole catalogue, a week needs a dozen, and scanning ~98 000 lines for a dozen is a hundredfold too much work.

The screen (`resources/js/pages/Plan/Index.vue`, `/jadlospis`) is a calendar strip over a week of day cards. The strip pages one week at a time and dots the days that hold something; **tapping a date jumps to its card rather than ticking it**, because ticking is what the cards are for and one control doing two things is how the wrong one gets pressed. **The whole day header is the tick**, because that tick is what the shopping button is built on and a 20 px box is not what a thumb aims at. Choosing a recipe is a sheet whose search is a partial visit (`only: ['matches']`, `preserveUrl`) against `RecipeListing::search()` — the catalogue is far too big to ship — and it searches **within the meal it was opened from**, so "jajka" under Obiad does not offer scrambled eggs. "Szukaj w całym katalogu" widens it, which is not optional: a quarter of the catalogue suits no tagged meal, and a dish visible in the list has to stay plannable. The shopping button reports in place and the message expires, exactly like the recipe modal's, because its result lives on another screen.

Routes are named **`meal-plan.*`, not `plan.*`**: `shopping.plan` is a different plan entirely — which shop to drive to — and two things called "plan" in one route file is how the wrong one gets linked.

**A `date` cast is stored as a full timestamp.** `MealPlanEntry::scopeOnDates()` therefore runs the dates through `fromDateTime()`; comparing against bare `2026-08-10` matches nothing, and matches it *silently* — an empty week reads exactly like a week nobody planned. `PlanGenerator` creates its entries one by one for the same reason: a bulk `insert()` skips the casts and would write a date the screen cannot find.

### Which meal a recipe suits (`recipe_meal_slots`)

Derived exactly like the quick-pick categories, from `database/data/meal-slots.php`, because **no source states it**. `php artisan recipes:meal-slots` recomputes the lot (~10 200 recipes, well under a minute) and **replaces** each recipe's slots rather than adding to them, so a rule that stops matching releases the recipe. Editing the data file and re-running is the whole workflow — no re-import.

The signals, in the order they are trusted: the **quick-pick categories** already computed from ingredients and titles (`zupy` → obiad, `desery` → podwieczorek), then `is_meal_prep` for the lunch box, then source **tags**, then **title needles**. Tags alone could never carry this — the ones that name a meal cover 198 + 153 + 34 recipes out of ~10 200.

- **Title needles are whole words**, shared with the categories through `App\Catalogue\TitleNeedle`. That class exists so the two rule sets cannot drift apart on the rule that cost the most to learn: "zupełnie" contains "zupe".
- **Disqualifiers run first.** `excludeCategories: [desery, wypieki]` on every savoury meal is what keeps "Sernik z tostami" out of breakfast. Two stems stay out of the file on purpose: `ciast*` (dough as much as cake) and `past*` (reaches "pasternak").
- **A recipe may suit several meals** — an omelette is breakfast and supper — and nothing forces one home.
- Current spread: śniadanie 1384, drugie śniadanie 2366, obiad 4122, podwieczorek 959, kolacja 2414, and **2864 recipes suit none**. That last number is honest rather than a gap to close: those are the titles no rule was confident about, and the picker's "szukaj w całym katalogu" reaches them.

**`PlanGenerator` is what the tagging was for.** It is asked for meals **day by day** — `array<date, list<MealSlot>>`, not "these meals on all these days" — because a week is not uniform: a podwieczorek at the weekend and a drugie śniadanie on working days is a normal way to eat, and an all-or-nothing generator would fill in meals nobody intends to make. The screen asks for it as a grid, one row per day and one column per meal, with the row label and the column heading ticking a whole day or a whole meal. A cell that is already planned shows as taken rather than as a box that does nothing.

A batch runs along the days that **want that meal**, not along the calendar: Monday and Wednesday lunches with nothing on Tuesday is one pot, because Tuesday was never going to eat it.

Three rules make its week one somebody would actually cook, and all three are pinned by tests:

- **It never overwrites.** Only empty (day, meal) pairs are filled, so a hand-planned week survives the button and pressing it twice tops up the gaps.
- **Nothing repeats within a month** — not inside the week being filled, and not against the weeks either side of it (`VARIETY_DAYS = 30`, looked at in both directions because weeks are not always planned in order). The exception is a batch: an `is_meal_prep` dish is deliberately placed on **two days running**, one pot eaten twice, which `PlannedIngredients` then sums into a single cooking. Used recipes are rejected **before** `take(POOL)`, or a month of dinners could empty a window of eighty while thousands of untried recipes sat one row outside it.
- **The fridge comes first** — candidates are ordered by `missingCounts()` and shuffled within the best 80, so suggestions lean on what is in the kitchen without being the same three dishes for ever. **An empty kitchen skips that ordering entirely**: there it would only rank recipes by how few ingredients they have, which is a different question and a worse week.

A meal nothing is tagged for comes back in `empty` and the screen says so. Filling a slot from an empty pool is exactly the sort of invention this app does not do.

`PlanGenerator::swap()` is the same machinery for one meal: the shuffle button beside a planned dish. The dish being replaced sits inside its own month window, so it cannot be handed back — which is the whole point of pressing it — and the **portions are kept**, because swapping a dish says nothing about how many people are eating. Null when there is nothing left to offer, and then nothing changes and the screen says so rather than looking like a dead button.

**A side dish must never be served as a meal**, and telling one from a meal took three goes. The word alone cannot: "Surówka z młodej kapusty" is a side, "Kotlety rybne z łososia z surówką z kapusty" is dinner beside one. **Position cannot either** — an `opensWithAny()` that looked at the first three words was written, tested against the catalogue and removed, because beszamel.se.pl writes headlines and puts the dish in the second sentence ("Pieczone buraki mieszam z ogórkiem małosolnym. Ta surówka znika…"). What works is corroboration: `sideWords` disqualifies unless `mainCategories` — chicken, beef, pork, fish, pasta, soup, all derived from *ingredients* — vouches that it is a main course. `salatki` deliberately cannot vouch: that is the category a surówka lands in. `sideAlways` closes the one blind spot left, where the title itself names the meat it accompanies ("Surówka do karkówki", "…dodatek do kotletów schabowych") and thereby earns a pork category it has no business holding; nothing overrides those. On the live catalogue this took surówki tagged as kolacja from 208 to 5, and four of the five are genuine main courses. It errs towards dropping a recipe, which is the right way round: a vegetarian main lost from the suggestions is one search away, a bowl of grated carrot for supper is the joke this exists to prevent.

## Tasks ("Zadania")

Not about food at all, and that is the point: "podjedź po chleb", "oddaj buty do szewca" arrive over Messenger during the day and are then remembered in a thread nobody scrolls back through. `/zadania` is where they land instead.

- **`App\Enums\Assignee` is `him`/`her`/`both`, deliberately not a `user_id`.** One household is one account — that is what the QR device link is for — so there is nobody to point at. It is also why the cases are third person: both phones are signed in as the same account, so "dla mnie" would mean the opposite thing depending on who was holding the phone. These three read the same from either side of the kitchen.
- There is no `created_by` for the same reason. The app genuinely cannot tell which of the two wrote a task down, and a column claiming otherwise would be a guess on every row.
- **Ticking keeps the row** (`done_at`), like a shopping line's `bought_at`: "co dziś zrobiliśmy" stays answerable and a mistaken tap is one tap back. `DELETE /zadania/zrobione` clears them all at once, because one at a time is the tidying-up nobody does — declared *before* `/zadania/{task}`, or "zrobione" is read as a task id.
- **A task carries a date, never a time.** Asking for an hour would turn writing one down into filling a form; "dziś" and "jutro" are one tap each and cover nearly every case. `today` comes from the server so the shortcut and the overdue marker agree whatever a phone's clock says, and *overdue means the day has passed* — something due today is not late until tomorrow.
- Handing a task over is a chip on the row, not an edit screen: "zrób to ty" is half the conversations this exists for.
- **The open count is a shared prop (`openTasks`), so the badge rides on every screen.** The point of writing something down is not having to remember it, and a count you only see once you are already on the tasks screen is a reminder you have to go looking for. It is a closure, so a partial visit that did not ask for it — infinite scroll — does not pay for the query. **Flat, not nested under `tasks`**: the tasks screen has a page prop by that name and a page prop wins, which made the badge vanish on the one screen where the number is most obviously right.
- Adding this made the nav five sections. `resources/js/lib/navigation.ts` had "four is the ceiling" written in it; the fifth was added and then *measured* — at 390px each cell is 78px and the widest label renders at 50px. **Podróż later made it six, measured the same way: 65px cells, widest label 49.6px, nothing overflowing.** That is the last one that fits — a seventh leaves 55.7px against a 49.6px label — so the next section is a "więcej" sheet, not another cell. Measure, do not argue.

## Podróż — the flight-deals window (`app/Travel`)

`/podroz` is not a feature of this app; it is a **window onto another one of ours** that watches airline and blog prices. Nothing is stored here, nothing is written there — the scanning and pruning stay that app's own scheduled commands — so this module is a client and a screen and nothing else. `api.md` in the repo root is that app's contract, and it is the specification: read it before changing anything here.

- **`config/travel.php` holds the address, and it must stay a private one.** That app has no authentication and is not built to have any; its protection is that the port is not on the internet. Pointing `TRAVEL_API_URL` at a public host publishes the board to whoever finds it. Same host, an SSH tunnel or an overlay network — those are the options.
- **`DealsApi` is the only class that knows the API exists**, caches for two minutes (the far end rescans hourly, so shorter buys nothing and only makes tapping a filter slower), and turns *every* failure — refused connection, timeout, 500, unparseable body — into `DealsUnavailable`.
- **`available: false` is a first-class answer, never a 500.** An empty board and an unreachable one look identical in the data and mean opposite things: one is "change the dates", the other is "go and start the other app". The screen says each in different words.
- **`TravelBoard` converts prices to integer grosze at the boundary.** They arrive as `61.8`. Not `App\Support\Money`, which is PLN by construction — these carry their own currency code, and it travels beside the number. `thresholds.score` is the trap: it sits among price ceilings but is a 0-100 rating, and converting it would print "0,60 zł" as a quality bar.
- **Nothing is filtered or sorted on this side.** At most 200 of hundreds of thousands of deals are ever sent, so re-ranking the page in hand would rank the wrong ones and can show an empty screen while the far end is full of matches. Every control is a fresh GET with a new query string, kept in the URL so a board is linkable to the other phone.
- **Filters are not validated here either.** The API ignores unusable input rather than rejecting it and echoes back what took effect, so the controls render from the *response*. A second opinion in `DealFilters` could only disagree with the far end. It does enforce one thing: `from`/`to` go both or neither, because a holiday must contain the whole journey.
- A deal `id` is a **fingerprint that changes when the price changes**, so the details sheet is built from data already in hand rather than re-fetching — asking again is the one call that can 404 about something visibly on screen.
- Polish copy lives in `resources/js/lib/travel.ts` and only there; the API serves codes, never display strings. `nightsLabel()` counts three ways ("1 noc", "3 noce", "8 nocy").
- **Dates are converted, never sliced.** A flight carries an instant; cutting the first ten characters out of `2026-10-29T22:30:00+00:00` puts that departure a day early.
- `tests/Feature/TravelTest.php` pins the grosze conversion, the score-is-not-money rule, filter forwarding, the half-a-holiday rule and the unreachable case. `phpunit.xml` forces `TRAVEL_CACHE_STORE=array` and a fake URL, for the reason the pricing client already documents.

**Blue is the one exception to the one-accent rule**, and it is scoped to this section: `voyage` / `voyage-strong` / `voyage-soft` in `app.css`, built to the same measured contrast as the green (ink on `voyage` 6.85:1 against the accent's 7.02:1; `voyage-strong` on white 5.28:1; white on `voyage` 2.58:1 and therefore never used). It says "this is another app's data, not food" before the heading does. A blue button anywhere else would make both hues mean nothing.

## Shopping list (`app/Shopping`)

**A household keeps several lists** — the weekly shop, the barbecue, the Asian grocer — and always exactly one *main* list. `shopping_lists` is scoped to `user_id`; `shopping_list_items` hangs off the list and **no longer carries `user_id` at all**, because the list already knows whose it is and two columns saying so is the duplication this database exists to avoid.

- **One product is one line** (unique on `[shopping_list_id, ingredient_id]`) however many recipes asked for it — the trolley does not care that two dishes both want a courgette. `Trolley::add()` merges rather than appends, inside a `lockForUpdate` transaction. That is *per list*: the same butter on the weekly shop and on the barbecue is two honest lines, not a duplicate.
- **`App\Models\ShoppingList::defaultFor()` is the guarantee that there is always somewhere to write.** It creates the main list on demand — never in a seeder, since `DatabaseSeeder` deliberately creates no account — and every screen that writes without being asked which list (the kitchen form, a recipe with nothing chosen, the meal plan) lands there. Both places that can create *another* list first call `defaultFor()`, or an account whose very first action was naming one would own a single non-default list and deleting it would leave none.
- **The main list is renameable but not deletable** (`isDeletable()`, 403). Deleting any other takes its lines with it — that is what deleting a list means, and the rows cascade for the same reason.
- Another household's list answers **404, never 403**, exactly like registration and the kitchen: a 403 would confirm the list exists.
- **The service is `App\Shopping\Trolley`, renamed from `ShoppingList` when the model took that name.** It is the behaviour that acts on whichever list is open — put things in, take them out, unpack them into the kitchen — and every method takes the list rather than the user. The kitchen it reads is still the household's: what you own does not change with the trip you are planning.
- `App\Shopping\ShoppingLists` answers "what lists does this household have", with the still-to-buy count on each. Two screens ask it — the switcher and the recipe modal — so it is one class rather than whichever controller needed it first. It `array_values()` the result: `map()` keeps the collection's keys, and a gap in them crosses to the browser as an object, which the switcher would iterate to nothing.
- **Routes come in two forms**, without a list (the main one, and the URL to bookmark) and with one: `/zakupy`, `/zakupy/lista/{list}`, and the same for `plan` and `do-kuchni`. `lista/` is in the path so a list id can never be read as an item id — the same reason `/zakupy/sklepy` is declared before `/zakupy/{shoppingListItem}`.
- **The recipe modal asks which list only when there is more than one.** With a single list the question has one answer and asking it is a tap for nothing. The picker also creates: `new_list_name` on `shopping.recipe` makes the list and writes to it in one request, so "add this to a new list for Saturday" never navigates away from the recipe being read. The confirmation says where — "Dodano 7 → Grill" — because with several lists the count alone is half an answer, and `listId` comes back in the flash so "dodaj też to, co masz" follows onto the same list.
- `shoppingLists` is sent by `RecipeController::show` only, and the modal-opening partial visit asks for it **by name** (`only: ['recipe', 'shoppingLists']`). Scrolling the catalogue must not pay for a query about shopping lists, and a dropped prop would leave the picker with nothing to offer.

Adding two amounts that may not be knowable is one rule in one place, and `null` ("some, amount unknown") is **infectious on purpose**: unknown + 300 g is unknown. Inventing a number here would put a wrong figure on a shopping list.

Which function does it depends on whether a product is in hand. `Quantity::sum()` knows nothing about products and therefore still refuses grams + millilitres — correctly, because without a product there is no answer. Everywhere a product *is* known — `Trolley::add()`, `stockUp()`, `Pantry::amountOf()` — it is `IngredientMeasures::sum()`, which bridges the units when the product has been weighed and returns null when it has not. See **Weights and conversions**.

- `addMissingFor()` writes down **only the shortfall** — the whole amount when there is none in the kitchen, the difference when there is some. `RecipeAvailability::missingFor()` returns that as `quantity`.
- **What the kitchen covered is reported, and can be overruled — but only on purpose.** The shortfall stays the default and always will; the honest gap is that an entry with no amount ("some, unknown") and a product nobody has weighed both read as *held*, so the line silently leaves the list. `addMissingFor()` therefore returns `skipped` alongside `added`, and `addHeldFor()` (`RecipeAvailability::heldFor()`, `POST /zakupy/z-przepisu/{recipe}/reszta`) writes those lines down at the **full** recipe amount — subtracting from an amount that is itself in doubt would be the same guess in a smaller disguise. Lines exempt by policy (optional, equipment, seasoning) are in neither set, so "the rest" can never put paprika in the trolley. In `RecipeModal` the offer deliberately **outlives** the `CONFIRMATION_MS` receipt above it: three seconds is enough to read "Dodano 7" and nowhere near enough to decide.
- A line the importer never resolved to a product is **counted and reported, never invented**. There is no product to write down, and a free-text line would be exactly the duplication the catalogue exists to prevent.
- Ticking an item **keeps** the row (`bought_at`), so the trolley can be untangled at the till. Adding to an already-ticked line un-ticks it and starts the amount again: asking for it means it is wanted again.
- **An amount is corrected on the row itself** — minus, a box, plus — because the amount a recipe worked out is a starting point and the shelf has the last word. `PATCH /zakupy/{item}` takes `bought` and `quantity` independently, so changing the number never ticks the line off. Three rules it enforces: a line that never had a unit borrows the product's `default_unit_id`, since an amount with nothing to count it in is refused by validation; where even that is unknown the screen shows no stepper at all, because there is no honest number to write down; and **emptying the box means "some, amount unknown" again, not zero** — the row stays on the list. Steps come from `stepFor()` in `resources/js/lib/quantity.ts` (50 g, 0,5 kg, 50 ml, one of anything countable): unlike a prefilled amount this invents nothing, as nothing is stored until the number on screen says so. The box shows two decimals — the raw `0.3333333333` behind "⅓ szklanki" would read as a fault in a box four characters wide.
- `stockUp()` closes the loop — what was ticked off moves onto the shelves (`StorageLocation::suggestFor()`) and leaves the list. It **adds** to what is on the shelf rather than replacing it, unlike the kitchen's own form, which states what you have.
- The list is grouped by `ShoppingAisle`, not by ingredient category: nineteen categories would split a small list into nineteen headings, and alphabetical order sends you back across the shop for the yoghurt you passed.

`RecipeModal`'s "Dodaj do listy zakupów" posts with `only: ['flash']` and `preserveUrl`. Without both, the redirect re-renders the list underneath and throws away the search and chip the user had set.

**That button is the only write on the screen and its result is invisible** — the list it feeds is another page — so the button reports back in place: a spinner while posting, then the count, a ring that expands outward once (`animate-confirmed` in `app.css`), and the unresolved-line note underneath. Three things there are deliberate. The confirmation **expires** after `CONFIRMATION_MS`, because a permanent "Dodano 7" makes a second helping look like it failed. `added === 0` says **"Masz już wszystko"** rather than nothing, since zero is a real answer when the kitchen already covers the recipe. And the timer is cleared on unmount, or closing the modal mid-confirmation writes to refs that are gone.

It is called "Dodaj do listy zakupów", not "Dopisz braki": it does add only the shortfall, but "braki" only parses once you already know the fridge is being consulted, which the button cannot say in two words. The shortfall behaviour is `Trolley::addMissingFor()`'s business and is documented where it lives.

## Shop leaflets and the cheapest plan (`app/Offers`, `app/Shopping/Planning`)

Answers "gdzie po to pojechać, żeby wyszło najtaniej". Ports and adapters, exactly like recipe import, because the pipeline must not care which site a price came from: `Contracts/OfferSource` is the port, `Drafts/OfferDraft` the boundary, `Sources/GazetkiPl/*` the only classes that know any markup, `ImportPromotions` → `StorePromotionDraft` the pipeline, `Providers/OffersServiceProvider` + `OfferSourceRegistry` the composition root. Adding a second aggregator is one adapter and one registry entry.

`App\Support\Http\PageFetcher` and `ThrottledPageFetcher` **moved out of `app/Importing`** when this landed: reading a page politely is knowledge both modules need and neither owns. The cache key stays `import:page:` on purpose — it is not a namespace, it is the key of a store holding thousands of already fetched recipe pages, and renaming it would orphan every one of them.

**gazetki.pl is the source, and the choice was not close.** It is the only Polish leaflet aggregator that publishes offers as *text* rather than page scans: every entry is an element with a name, a price, the price it replaces and a remaining duration. blix.pl disallows its own `/api/` and shows mostly scans; ding.pl disallows its search. Both were checked — do not re-tread them.

- **`page` must be the first and only query parameter.** The site's own paginator links carry `?sort=…&page=N`, but its robots.txt disallows `/sklepy/*?*` while allowing exactly `/sklepy/*?page=*`. Copying the site's href would crawl a path we were asked not to; the bare form returns the same offers in the same order.
- Its robots.txt bans Scrapy and the SEO crawlers outright and gives the AI crawlers it names `Crawl-delay: 10`. We are not on that list, so ten seconds is the number *the site* calls polite rather than one addressed to us. Do not lower it.
- `parseLastPage()` reads the paginator rather than walking until a page comes back empty — **the site answers an out-of-range page with page one**, so "empty" never arrives and a naive walk would only stop at the page cap.
- **The offers cache TTL is six hours, not the recipe crawler's fortnight.** A recipe page is effectively immutable; a leaflet page *is* its prices. A fortnight-old cache would put last fortnight's prices on a plan and look authoritative doing it.

### The rule that matters most: a promotion never invents a product

`IngredientResolver::match()` exists for this and is the only entry point leaflets use. `resolve()` — which creates a product when nothing matches — must never be called here. A chain's leaflet is mostly not food: lawnmowers, exercise books, loyalty coupons. Four thousand of those through `resolve()` would create four thousand products, each permanently owning an alias, and the **next recipe import would resolve real ingredient lines onto them**. That is the "Sos:" incident at a hundred times the scale. Not matching is always the better answer, and nothing is dropped — an unmatched entry keeps its `title` and `needs_review`, so improving the matcher is a re-run over cached pages, never a re-crawl.

`database/data/promotion-noise.php` is the second half of that defence, and it exists for the entries that are *not* harmless — the non-food that names a food and would otherwise match it. Every rule in it came from a real run:

- **"Karma dla psa wołowina Dolina Noteci"** would have been the cheapest beef in town.
- **"Żel pod prysznic mleko i miód"**, **"Świeca zapachowa wanilia"** — cosmetics and candles are full of milk, honey, almonds and fruit.
- **"Aperitivo Aperol + foremka do lodu"** matched **Lód**: the freebie won, not the drink.

**A negated ingredient is not that ingredient**, and that is a separate rule from the noise list. "Napój gazowany Coca-Cola Zero Cukru" matched **Cukier** on the first live run — a sugar-free drink offered as the cheapest sugar. Dropping the whole entry would be wrong too, because "Jogurt naturalny bez cukru" is real yoghurt: `PromotionIngredientResolver::withoutNegations()` therefore removes only the negated word. It is narrow on purpose — "bez" is also an elderberry.

Two dictionary fixes came out of the same run and belong to the catalogue, not to this module: **Fasolka szparagowa** is now its own product (it was resolving to Fasola on the alias "fasolka"), and **Camembert** holds "ser pleśniowy camembert" (the two-word "ser pleśniowy" belongs to Gorgonzola, and the resolver tries longer groups first).

### Comparing two prices honestly

`Money` is integer grosze — never a float, in PHP or in TypeScript. A plan adds a dozen shelf prices and subtracts a dozen regular ones; in floats, "oszczędzasz 12,00 zł" eventually renders as 11,999999999 on the one screen whose entire job is a number you can trust. It crosses to the browser as an integer and becomes text in exactly one place, `resources/js/lib/money.ts`, for the same reason `quantity.ts` exists.

`UnitPrice` is what makes a comparison true: **12,49 zł is the better butter than 5,99 zł** when one is half a kilo and the other 200 g. `PackSizeParser` reads the size off the name when it is there ("Piwo Garage 400 ml", "4 x 150 g", and *not* "Smartwatch Hero 1.39" or "śmietana 18%").

**Be aware how rarely it is there.** On a live listing walk only ~3% of entries carry a size — verified as a data limit, not a parser bug: both titles that contained one were parsed correctly, and the product detail pages carry no more. `PromotionRanking` is built around that: offers with a comparable unit price rank first, then those with no pack size, then those priced per a different dimension. **An offer with no size is not demoted for being a bad deal — it is demoted because we cannot show it is a good one**, and a plan that says "drive here" has to be able to defend itself. The runners-up are carried to the screen precisely because the cook at the shelf can see the size we never learned.

`valid_to` is the stated *duration* added to the day the page was read, not a date the site published. Good enough to drop offers that ran out; never quoted as a promise about a given day. An offer with **no** stated end still counts — the source is simply quiet about some, and dropping those would hide real promotions.

### The plan

`ShoppingPlanner` joins the shopping list to `promotions` on `ingredient_id`. That join is only possible because both sides already point at one canonical product — this feature is the payoff for the catalogue rule, not a new mechanism.

- Strategies live behind `Contracts/PlanStrategy`: `CheapestOverall` and `FewestStops(2)`. A third rule is a new class, not another branch.
- `FewestStops` optimises **coverage first, price second** — someone asking for two shops wants most of the list on offer, not eleven groszy off the butter — and picks shops greedily. Deliberate: the exact answer is set cover, and on a list of twenty products the greedy one is optimal or a line off it, and explainable to whoever is reading the screen.
- `packsNeeded()` rounds up (500 g wanted, 200 g packs → 3) and falls back to **one** whenever either side is unknown. Never a multiplication by a number nobody stated.
- **The total covers the promoted lines only, and the screen says so.** We know what a leaflet charges for butter this week; we do not know the shelf price of the flour that is not on offer. A guess for those would make the confident number the wrong one.
- A saving nobody printed is `null`, not zero — zero claims we checked and found none.
- The plan screen distinguishes "nothing on your list is on offer" from "no leaflets have been read yet" (`hasPromotions`). They look identical in the data and mean opposite things.

### Running it

```bash
php artisan promotions:import gazetki --shop=biedronka --pages=3   # while working on the matcher
php artisan promotions:import gazetki                              # weekly refresh, all chains, ~30 min
php artisan promotions:quality                                     # always, after an import
```

`promotions:quality` prints two lists read in opposite directions. **`Najczęściej dopasowane` is where wrong matches hide** — a product suddenly holding forty offers is not popular, it is swallowing entries that belong elsewhere. `Bez produktu` is the missing vocabulary: food goes to `database/data/ingredients.php` (seed, then re-import), non-food to `database/data/promotion-noise.php`.

**A falling match rate can be the fix working.** The first live run scored 54.5%; after the three corrections above it scored 50.5%, because four wrong matches stopped being matches. Read the lists, not the percentage.

Offers this source stops publishing are pruned after `offers.stale_after_days` (14) rather than on sight: a run that only reached page 3 of 120 must not delete everything it never got to.

**The refresh is unattended, and it asks a question rather than watching a clock.** `routes/console.php` checks hourly and imports only when `App\Offers\OfferRefresh::isStale()` says the newest offer we hold is older than `offers.refresh_after_days` (7 — one Polish leaflet cycle). It used to be `weeklyOn(1, '04:00')`, which is right for a server and wrong for the desktop this runs on: a machine asleep at four on a Monday misses the slot outright and then quotes last fortnight's prices for a week without saying so. Staleness self-heals — a missed window costs hours, not a week — and needs no record of the last run, because the age of the rows *is* the record. `updated_at`, not `created_at`: an offer running a second week is touched, not re-created.

Nothing fires at all unless something calls `schedule:run` every minute. On this machine that is a Windows scheduled task, registered once with `scripts\install-scheduler.ps1` (which calls `scripts\schedule-run.cmd`); it runs as the logged-in user, so no elevation and no stored password, and it only ticks while somebody is logged in — which is the other half of why the import is guarded on staleness rather than pinned to an hour. `tests/Feature/OfferRefreshTest.php` pins the rule.

## Which shops we drive to (`App\Shopping\SelectedShops`)

One choice, read by both the plan and the cost estimate, stored in `shop_user`.

**An empty selection means "all of them", never "none".** `ids()` returns `null` for "not chosen yet", and every caller has to tell that apart from a list. Read the other way, a fresh account would get an empty plan and a blank estimate — a feature that switches itself off until it is configured, which on screen is indistinguishable from a fault. `Promotion::scopeInShops(?array)` encodes the same rule: null narrows nothing.

There is deliberately **no default set of chains**. Guessing Biedronka and Lidl would put a stop on a plan nobody asked for, and it would look exactly like a choice they had made.

Ticking every chain stores every chain rather than collapsing back to the empty state. The two behave identically today, but one is a decision and the other is its absence — so `shopsNarrowed` travels to the screen and the plan can say *why* a chain is missing rather than looking broken.

The picker lives on the plan screen, not in settings: a chain ticked three taps away is a chain nobody remembers ticking when the plan later fails to mention Lidl. Each chip carries that chain's live offer count, which is what makes the choice checkable — ticking a chain whose leaflet nobody has read yet gives a plan that ignores it.

## What it will cost (`app/Pricing`, `App\Shopping\CostEstimate`)

Answers "ile mniej więcej zapłacę za te zakupy" on `/zakupy`. Ports and adapters again, for the same reason as recipes and leaflets: `Contracts/PriceSource` is the port, `Drafts/PriceDraft` the boundary, `Sources/Gus/*` and `Sources/Leaflets/*` the adapters, `ImportPrices` → `StorePriceDraft` the pipeline, `Providers/PricingServiceProvider` + `PriceSourceRegistry` the composition root.

**`promotions` could not answer this and that is why `price_observations` exists.** It is a picture of *this week*, pruned when an offer stops running, so it has no memory; a single `current_price` column on `ingredients` would be worse still, overwritten by whichever source ran last so that cut-price butter erased the national average. Observations are append-only and **never pruned** — a leaflet that stops running has stopped being true, but a price that held last March still held last March, and that history is what a median is taken over. Readings age out of the *estimate*, not out of the table.

### The two sources, and why neither is enough alone

- **GUS, via the BDL API** (`bdl.stat.gov.pl/api/v1`, open, no key). Impartial and broad. Without it the estimate would be built from leaflets only and therefore systematically low — lowest on exactly the things bought most.
- **Our own leaflets** (`Sources/Leaflets`). Reads no website: it projects `promotions` rows the offers pipeline already fetched and matched. Covers the fresh produce GUS does not.

Three facts about the GUS data are load-bearing and cost an afternoon each:

- **The monthly series died in 2019.** Only the annual one is current, and it is published months after the year it covers. So a figure is stamped with **the last day of its year**, never the day it was read, and `pricing.max_age_days` is 500 — a stricter window would discard the only figures that exist for most staples. This is why the screen says "mniej więcej" and never quotes a price.
- **The API paginates in tens whatever `page-size` you send**, and mentions it only in `totalRecords`. Reading the first page and stopping looked exactly like a source publishing 20 prices instead of 49: request succeeded, JSON well formed, more than half silently missing. `GusBdlClient::results()` follows the pages; the `page-size` parameter only makes the loop shorter.
- **A list is passed by repeating the key** (`var-id=1&var-id=2`). Every standard encoder writes `var-id[0]=1`, which the API answers *partially and without an error*. Hence `queryString()` by hand.

Fresh produce is absent from GUS entirely (it was in the discontinued monthly set), which is the whole reason leaflets remain a source rather than a nicety.

### Only a leaflet's *regular* price becomes an observation

A leaflet states two figures; only the "before" one is evidence of a normal price. Averaging the promotional one would say the household pays sale prices for everything all year — wrong, and wrong in the direction that quietly under-promises the bill. An offer with no "before" printed is skipped, losing nothing: the promotion row keeps every figure, and re-running over it is free.

A leaflet draft carries its **already-resolved `ingredientId`**, the one exception to "nothing at the boundary has been matched yet". Re-deriving it from the title would at best repeat work and at worst disagree with the offers pipeline — which has a noise list and a negation rule `Pricing\Resolving\PriceIngredientResolver` deliberately does not — and price butter from an offer the plan has filed under something else. That resolver only ever sees a statistical office's own labels, which are food by construction; it still uses `match()`, never `resolve()`, so no source can invent a product.

### `PriceBook`: a median, and two different answers

**A median, never a mean.** The sources disagree by design, and one 90 zł/kg parmesan among ten ordinary cheeses moves a mean enough to notice on a bill and moves a median not at all.

It holds **a price per kilo/litre/piece** *and* **a typical price per pack**, because barely 3% of leaflet entries state a size — a limit of the data, already verified, not a parser fault. Insisting on a unit price would throw away almost everything the leaflets know. Most list lines name no amount either ("masło", not "300 g masła"), and for those a pack price is not a worse answer, it is the right one. Coverage of real shopping lists went **17.9% → 53.6%** the moment pack prices were added. A singleton, like `MeasureBook`, and for the same reason.

### The estimate is layered, and the last layer is the important one

1. **A promotion in a chain being driven to** — not an estimate at all, it is what the till charges, for the number of packs the amount needs (`PlannedBuy::packsNeeded`, so the list and the plan cannot disagree).
2. **A typical price per kilo × the amount**, when the line states one.
3. **A typical pack price** — what most lines actually get.
4. **Nothing**, reported as unpriced and left out of the total.

Layer 4 is what makes the other three trustworthy. Treating an unknown line as free would be wrong in the direction that costs money *and* invisible — the total would simply be too small. So `unpriced` travels with the total, the screen prints "nie znam ceny N produktów — rachunek będzie wyższy", and the whole block hides when nothing can be priced, because "0 zł" over a full list is not a smaller number but a wrong one. `costBasis` reaches each row so a leaflet price shows plain and a typical one shows with a `~`.

Only what is still to buy is priced: a ticked line is money already spent, and including it would make the trolley look more expensive the further round the shop you got.

### Running it

```bash
php artisan prices:import                # every source; two API calls plus a pass over promotions
php artisan prices:import gus            # one of them, while working on the matcher
php artisan prices:quality               # always, after an import
```

`prices:quality` reads bottom-up: **`Bez ceny, a na liście`** is the working list, sorted by how often a list asks for it — the top is where one dictionary entry buys the most coverage. `Bez produktu` is missing vocabulary; food goes to `database/data/ingredients.php`, then re-seed and re-import (free, nothing is re-fetched). Baseline after the first pass: **49 GUS readings, 81.6% matched; 53.6% of shopping-list lines priced.**

The refresh is scheduled on the same staleness rule as the leaflets (`App\Pricing\PriceRefresh`, `pricing.refresh_after_days`), hourly, `withoutOverlapping`, and **not** chained to the leaflet crawl — the two are independent readings, and if the crawl is failing the statistical figures still price flour, milk, butter and eggs.

**`phpunit.xml` forces `PRICING_CACHE_STORE=array`, and that is not tidiness.** The client caches in the *file* store so a `migrate:fresh` does not re-spend the API's rate limit — and that store is shared with development, so a test faking the HTTP layer was answered out of a live run's cached response and failed with a number appearing nowhere in it.

## Browsing at catalogue scale

**The list page must never ship the whole catalogue.** It used to: ~4 800 summaries in one prop, filtered in the browser. When the catalogue passed 10 000 recipes that stopped working outright — hydrating the models cost more than PHP's 128 MB limit and **every visit answered 500**. Payload was 4.65 MB; after the change it is ~24 KB and ~250 ms.

`App\Catalogue\RecipeListing` + `RecipeFilter` now do search, filter and paging in SQL:

- Rows come back through the **query builder, not Eloquent** — nothing on that path needs behaviour, only values, and the counts run over the whole table.
- `recipes` is an `Inertia::merge()` prop, so infinite scroll is a partial visit that appends. Changing the search or the chip must send `reset: ['recipes']`, or the narrower result set piles up under the old one.
- Chip counts come from the server and deliberately **ignore the search box**: a count that changed as you typed would make the row jump under your thumb.
- `cookable` / `almost` are the only filters that are not a column. They come from `missingCounts()`, and the ids come **only** from that aggregate — so a recipe with no ingredient lines cannot pass itself off as cookable.

## Code standards

**All code is in English** — class, method, variable, table, column, and route names, plus comments and commit messages. Polish appears only in user-facing copy (translation files / UI strings), never in identifiers. Domain terms get their English name: `Ingredient`, `Unit`, `ShoppingList`, `PantryItem` — not `Skladnik`, `Jednostka`.

Write to SOLID / KISS / DRY / YAGNI, applied with judgement rather than ceremony:

- **SRP** — controllers only translate HTTP to a use case and back. Business rules live in Actions/Services (`app/Actions/…`), query logic in Eloquent scopes or dedicated query classes, validation in Form Requests, response shaping in Inertia data objects. A controller method doing math on ingredient quantities is a smell.
- **DIP** — depend on an interface when there is a real reason to swap or fake the implementation (e.g. a price provider, a recipe-suggestion strategy). Bind it in `AppServiceProvider`. Do **not** put an interface in front of an Eloquent model or a single concrete class that will never have a second implementation.
- **OCP via strategy** — things that will grow (dish suggestion ranking, unit conversion rules, budget calculations) belong behind a small interface with one class per strategy, so a new rule is a new class rather than another `elseif`.
- **DRY where the knowledge is shared** — a single canonical place for unit conversion, for "can this recipe be cooked from current stock", and for price/budget math. Two code paths computing the same thing is a bug waiting to happen. But duplicated *shape* that happens to look alike is not duplication — don't merge unrelated things to save lines.
- **KISS / YAGNI** — this is a two-person private app. No repositories over Eloquent, no CQRS, no event sourcing, no abstraction added "for later". Reach for a pattern when the code already hurts, not in anticipation.
- **Value objects** for concepts with rules attached — `Quantity` (amount + unit, knows how to convert and add), `Money` (integer minor units, never floats for prices). Cast them on models rather than passing loose floats around.
- **Typed everywhere** — parameter and return types on every method, generics in docblocks for collections (phpstan level 7 will not accept less). Same on the Vue side: props and Inertia page props are typed interfaces, no `any`.
- **Small, named units** — a method that needs a comment to explain its sections wants to be several methods with those names.
- **Tests express behaviour** — feature tests for use cases through the Inertia endpoint, unit tests for value objects and strategies. Test names read as sentences describing the rule.

## When adding domain features

Keep the reference data normalized and reusable so the same ingredient/unit is never entered twice:

- `Ingredient` (canonical name, category, default unit, optional current price per unit) — the deduplication anchor.
- `Unit` (name, abbreviation, type: mass/volume/piece) with explicit conversions where they exist; never store units as free text on a recipe line.
- `Recipe` → ordered `RecipeStep` rows (instruction, optional appliance/vessel, temperature, speed, duration) so the UI can drive a guided, one-step-at-a-time cooking flow.
- `RecipeIngredient` pivot carrying quantity + `unit_id` + optional note.
- Pantry/fridge stock, shopping-list items, and purchase/price history all reference `Ingredient`, which is what makes "what can I cook now?", "what to buy", and budget tracking fall out of the same data.

Prefer a database-level unique constraint on canonical ingredient/unit names plus a lookup-or-create path over trusting the UI to avoid duplicates.
