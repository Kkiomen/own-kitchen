<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Importing\Parsing\PolishTextNormalizer;
use PHPUnit\Framework\TestCase;

class PolishTextNormalizerTest extends TestCase
{
    private PolishTextNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new PolishTextNormalizer;
    }

    public function test_it_strips_polish_diacritics(): void
    {
        $this->assertSame('zoltko jajka', $this->normalizer->normalize('Żółtko jajka'));
        $this->assertSame('swiezy koperek', $this->normalizer->normalize('świeży koperek'));
    }

    /**
     * Borrowed names must survive intact: dropping the letter would split the word
     * and it would match no product at all.
     */
    public function test_it_keeps_borrowed_words_in_one_piece(): void
    {
        $this->assertSame('jalapeno', $this->normalizer->normalize('jalapeño'));
        $this->assertSame('puree ziemniaczane', $this->normalizer->normalize('purée ziemniaczane'));
        $this->assertSame('creme fraiche', $this->normalizer->normalize('crème fraîche'));
    }

    public function test_it_collapses_punctuation_and_whitespace(): void
    {
        $this->assertSame('natki pietruszki', $this->normalizer->normalize('  Natki,  pietruszki. '));
    }

    /**
     * Fat content distinguishes real products, so the digits and the sign stay.
     */
    public function test_it_keeps_percentages(): void
    {
        $this->assertSame('smietana 18%', $this->normalizer->normalize('Śmietana 18%'));
    }

    public function test_it_builds_a_slug(): void
    {
        $this->assertSame('maka-pszenna', $this->normalizer->slug('Mąka pszenna'));
    }
}
