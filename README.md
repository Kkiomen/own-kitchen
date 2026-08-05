# Kuchnia

Domowa aplikacja kuchenna: co ugotować z tego, co jest w lodówce, co dokupić,
gdzie kupić taniej i co jemy w tym tygodniu.

- **Katalog** ~10 200 przepisów zaimportowanych z czterech polskich serwisów,
  rozłożonych na składniki i kroki — nie na tekst.
- **Kuchnia** — lodówka, zamrażarka, spiżarnia. Lista przepisów filtruje się do
  tych, które da się z tego ugotować.
- **Plan tygodnia** — śniadanie, drugie śniadanie, obiad, podwieczorek, kolacja;
  ręcznie albo jednym przyciskiem, z przepisów pasujących do danej pory.
- **Zakupy** — braki liczone po odjęciu tego, co macie, zgrupowane po alejkach
  sklepu, z gazetkami promocyjnymi i planem „gdzie po to pojechać".

Stack: Laravel 13 (PHP 8.4) · Inertia 3 · Vue 3 + TypeScript · Tailwind 4 ·
Vite 8 · SQLite.

---

## Uruchomienie w Dockerze

```bash
docker compose up -d --build
```

Aplikacja stoi na **http://localhost:8001**.

**Pierwsze uruchomienie importuje istniejącą bazę** z `database/database.sqlite`
— cały katalog przepisów, słownik składników, wasza kuchnia i listy. Katalog
`database/` jest podmontowany tylko do odczytu; kopia trafia do wolumenu
`kitchen-data` i od tej pory kontener czyta wyłącznie ją. Herd i Docker nigdy
nie piszą do tego samego pliku — po starcie to są dwie osobne kopie danych,
więc używajcie jednej albo drugiej.

Bez pliku bazy kontener startuje z pustą i uruchamia seedery (jednostki, słownik
składników, wagi). Działa, ale katalogu nie odtworzy — to dni uprzejmego
crawlowania.

Wstają dwa kontenery:

| Kontener    | Co robi                                                             |
| ----------- | ------------------------------------------------------------------- |
| `app`       | serwuje aplikację (FrankenPHP) na porcie 8001                        |
| `scheduler` | `php artisan schedule:work` — odświeża gazetki i ceny, sam z siebie |

Nic nie trzeba konfigurować po `up`. Harmonogram działa automatycznie; na
Windowsie **nie** uruchamiajcie przy tym `scripts\install-scheduler.ps1` — dwa
harmonogramy to dwóch piszących do jednego SQLite.

```bash
docker compose logs -f app                      # co się dzieje
docker compose exec app php artisan schedule:list
docker compose down                             # stop (dane zostają w wolumenie)
docker compose down -v                          # stop i skasowanie danych
```

### Telefon w tej samej sieci

Kod QR do zalogowania drugiego telefonu koduje `APP_URL`, więc kod wygenerowany
z `localhost` wyśle tamten telefon pod jego własny localhost. Przed skanowaniem:

```bash
KITCHEN_URL=http://192.168.1.20:8001 docker compose up -d
```

### Konta

Rejestracja jest **otwarta** — formularz jest pod „Załóż konto" na ekranie
logowania. Każde konto ma własną kuchnię, własne listy i własny plan; katalog
przepisów jest wspólny.

```bash
ALLOW_REGISTRATION=false        # zamyka formularz (odpowiada 404, nie 403)
```

Konto można też założyć z konsoli — przydaje się, gdy formularz jest zamknięty:

```bash
docker compose exec app php artisan account:create
docker compose exec app php artisan account:password ktos@example.com
```

---

## Uruchomienie lokalne (Herd)

Projekt leży pod Herdem i jest serwowany na `https://kitchen.test` bez
uruchamiania czegokolwiek. Potrzebne są tylko assety:

```bash
composer setup      # zależności, .env, klucz, migracje, npm install, build
npm run dev         # albo npm run build
```

```bash
composer dev        # serve + queue:listen + vite naraz
composer test       # pint --test, phpstan, artisan test
composer ci:check   # to, co sprawdza CI: eslint, prettier, vue-tsc, composer test
```

Testy chodzą na SQLite `:memory:`; lokalny development na
`database/database.sqlite`.

---

## Skąd się bierze zawartość

Wszystko poniżej działa na już pobranych stronach (cache plikowy), więc
powtórzenie kosztuje minuty i zero zapytań do serwisów źródłowych.

```bash
php -d memory_limit=1G artisan recipes:import kwestiasmaku   # import przepisów
php artisan recipes:quality                                  # co się nie sparsowało
php artisan recipes:categorise                               # kategorie (Kurczak, Zupy…)
php artisan recipes:meal-slots                               # pory posiłków — PO kategoriach
php artisan promotions:import gazetki                        # gazetki promocyjne
php artisan promotions:quality
php artisan ingredients:measures                             # pokrycie wag
php artisan pantry:starter                                    # zapełnia kuchnię startowym zestawem
```

Przed dodaniem nowego źródła przepisów: **sprawdźcie jego `robots.txt`**.
`aniagotuje.pl` zabrania `ClaudeBot`, `przepisy.pl` odpowiada 403 — dlatego ich
tu nie ma.

---

Szczegóły projektowe, reguły danych i wszystkie „dlaczego tak, a nie inaczej"
są w [CLAUDE.md](CLAUDE.md).
