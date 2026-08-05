<?php

declare(strict_types=1);

namespace App\Catalogue;

use Illuminate\Http\Request;

/**
 * What the list is currently narrowed to: a search phrase and one chip.
 *
 * One chip at a time, the way a food app's category row behaves — several at
 * once would let you reach an empty intersection by accident.
 */
final readonly class RecipeFilter
{
    public const string ALL = 'all';

    private function __construct(
        public string $search,
        public string $key,
        public int $page,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', 'string', 'max:60'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        return new self(
            trim((string) ($data['search'] ?? '')),
            $data['filter'] ?? self::ALL,
            (int) ($data['page'] ?? 1),
        );
    }

    /** The slug when a quick-pick category is chosen, otherwise null. */
    public function categorySlug(): ?string
    {
        return str_starts_with($this->key, 'cat:') ? substr($this->key, 4) : null;
    }

    /**
     * Whether the chip needs the pantry comparison, which is the one filter that
     * cannot be expressed as a column.
     */
    public function needsPantry(): bool
    {
        return in_array($this->key, ['cookable', 'almost'], true);
    }
}
