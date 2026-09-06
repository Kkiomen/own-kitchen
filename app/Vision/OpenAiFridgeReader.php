<?php

declare(strict_types=1);

namespace App\Vision;

use App\Vision\Contracts\FridgeReader;
use App\Vision\Drafts\SpottedItem;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * The only class in this codebase that knows OpenAI exists.
 *
 * Two things it does are load-bearing rather than incidental:
 *
 * - **The unit vocabulary is enforced in the schema, not afterwards.** The
 *   allowed codes are sent as a JSON-schema `enum`, so the model is structurally
 *   unable to answer "słoiczek" or "kawałek". Filtering afterwards would work
 *   too, but this way an unknown unit never even costs us the amount beside it.
 * - **The prompt asks for the nominative singular.** Everything downstream is
 *   the recipe importer's alias table, which was built from recipe text and is
 *   at its best on "cebula" rather than "cebuli". Asking the model for the form
 *   we can look up is free; teaching the matcher a second declension is not.
 *
 * It answers with what it can see and nothing else. The prompt says so twice
 * because the failure mode of a vision model on a dark shelf is to fill the gap
 * with the groceries a fridge usually holds — and a product this app did not
 * actually see becomes an ingredient the shopping list then declines to buy.
 */
final class OpenAiFridgeReader implements FridgeReader
{
    private const string INSTRUCTION = <<<'TEXT'
        Jesteś asystentem kuchennym. Na zdjęciu jest wnętrze lodówki, zamrażarki lub spiżarni.

        Wypisz TYLKO te produkty spożywcze, które faktycznie widzisz na zdjęciu.
        Nie zgaduj i nie dopisuj rzeczy, które zwykle bywają w lodówce, a których tu nie widać.
        Jeśli czegoś nie potrafisz rozpoznać, pomiń to — pominięty produkt jest lepszy niż zmyślony.

        Wypisz każdy produkt osobno, także wtedy, gdy leży ich kilka rodzajów obok siebie.
        Przejrzyj po kolei wszystkie półki, szuflady i półki na drzwiach.

        Zasady zapisu:
        - nazwa po polsku, w mianowniku liczby pojedynczej ("cebula", nie "cebuli", nie "cebule"),
        - nazwa ogólna produktu, bez marki i bez opisu opakowania ("mleko", nie "mleko Łaciate 3,2%"),
        - policz to, co widać: trzy papryki to quantity 3 i unit "piece",
        - opakowania też licz w sztukach ("piece") — jeden kubek jogurtu to 1 sztuka,
        - jeśli na etykiecie widać wyraźnie gramaturę ("200 g", "1 l"), podaj ją zamiast sztuk,
        - nie zgaduj wagi, której nie widać; wtedy policz sztuki albo zostaw quantity null,
        - nie wypisuj rzeczy niejadalnych (pojemniki, półki, magnesy, chemia, leki).
        TEXT;

    public function __construct(private readonly Http $http) {}

    public function read(string $image, string $mimeType, array $unitCodes): array
    {
        $body = $this->ask($image, $mimeType, $unitCodes);

        return $this->itemsFrom($body);
    }

    /**
     * @param  list<string>  $unitCodes
     * @return array<string, mixed>
     */
    private function ask(string $image, string $mimeType, array $unitCodes): array
    {
        $key = (string) config('vision.key');

        if ($key === '') {
            // Not a misconfiguration to debug at the till: the screen that calls
            // this is not supposed to be reachable without a key at all.
            throw new VisionUnavailable('Rozpoznawanie zdjęć nie jest skonfigurowane.');
        }

        try {
            $response = $this->http
                ->withToken($key)
                ->timeout((int) config('vision.timeout_seconds', 45))
                ->acceptJson()
                ->post(config('vision.url').'/chat/completions', [
                    'model' => (string) config('vision.model'),
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => self::INSTRUCTION],
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    // Sent inline rather than as a URL: the photo
                                    // is somebody's kitchen and there is nowhere
                                    // public to put it, nor any reason to.
                                    'url' => $this->dataUri($image, $mimeType),
                                    /*
                                     * Stated rather than left to "auto". The
                                     * browser already shrinks the picture to
                                     * 1280 px, and at that size the low-detail
                                     * path is a 512 px thumbnail of a packed
                                     * shelf — which is how a tub of skyr becomes
                                     * "jogurt" and a label becomes unreadable.
                                     * The extra tiles cost a fraction of a
                                     * grosz per photo.
                                     */
                                    'detail' => 'high',
                                ],
                            ],
                        ],
                    ]],
                    'response_format' => $this->schema($unitCodes),
                ])
                ->throw();

            /** @var array<string, mixed> $decoded */
            $decoded = $response->json() ?? [];

            return $decoded;
        } catch (Throwable $failure) {
            throw new VisionUnavailable(
                'Nie udało się odczytać zdjęcia.',
                previous: $failure,
            );
        }
    }

    /**
     * Structured output, so the answer is parsed rather than interpreted.
     *
     * `strict` means every property must be listed as required, hence the
     * `['number', 'null']` types: "unknown" has to be expressible, or the model
     * will invent an amount purely to satisfy the schema.
     *
     * @param  list<string>  $unitCodes
     * @return array<string, mixed>
     */
    private function schema(array $unitCodes): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'fridge_contents',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['items'],
                    'properties' => [
                        'items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['name', 'quantity', 'unit'],
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'quantity' => ['type' => ['number', 'null']],
                                    'unit' => [
                                        'type' => ['string', 'null'],
                                        'enum' => [...$unitCodes, null],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function dataUri(string $image, string $mimeType): string
    {
        return 'data:'.$mimeType.';base64,'.base64_encode($image);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<SpottedItem>
     */
    private function itemsFrom(array $body): array
    {
        $content = $body['choices'][0]['message']['content'] ?? null;

        if (! is_string($content)) {
            throw new VisionUnavailable('Model nie odpowiedział listą produktów.');
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded) || ! is_array($decoded['items'] ?? null)) {
            throw new VisionUnavailable('Odpowiedź modelu nie da się odczytać.');
        }

        $items = [];

        foreach ($decoded['items'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $quantity = $item['quantity'] ?? null;
            $unit = $item['unit'] ?? null;

            $items[] = new SpottedItem(
                name: $name,
                // An amount is only ever kept with a unit beside it: a bare "2"
                // on a shelf is not something this app is willing to write down.
                quantity: is_numeric($quantity) && is_string($unit) ? (float) $quantity : null,
                unitCode: is_string($unit) ? $unit : null,
            );
        }

        return $items;
    }
}
