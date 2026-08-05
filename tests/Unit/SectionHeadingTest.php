<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Importing\Parsing\PolishInflection;
use App\Importing\Parsing\PolishTextNormalizer;
use App\Importing\Parsing\SectionHeading;
use PHPUnit\Framework\TestCase;

class SectionHeadingTest extends TestCase
{
    private SectionHeading $headings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->headings = new SectionHeading(new PolishTextNormalizer, new PolishInflection);
    }

    public function test_a_trailing_colon_marks_a_heading(): void
    {
        $this->assertSame('Sos', $this->headings->detect('Sos:'));
        $this->assertSame('Masa serowa', $this->headings->detect('Masa serowa:'));
    }

    public function test_a_known_heading_word_is_recognised_without_a_colon(): void
    {
        $this->assertSame('Przyprawy', $this->headings->detect('Przyprawy'));
        $this->assertSame('Dodatki', $this->headings->detect('Dodatki'));
    }

    /**
     * The whole point is not to lose ingredients, so anything carrying an amount
     * stays an ingredient no matter how it is worded.
     */
    public function test_a_line_with_an_amount_is_never_a_heading(): void
    {
        $this->assertNull($this->headings->detect('2 łyżki sosu sojowego'));
        $this->assertNull($this->headings->detect('100 g pasta'));
        $this->assertNull($this->headings->detect('1 sos:'));
    }

    public function test_a_real_ingredient_is_not_mistaken_for_a_heading(): void
    {
        $this->assertNull($this->headings->detect('sos sojowy'));
        $this->assertNull($this->headings->detect('świeżo zmielony czarny pieprz'));
        $this->assertNull($this->headings->detect('pasta miso'));
    }

    /**
     * A bare "sos:" line used to create a product called "Sos:" owning the alias
     * "sos", after which every sauce in the database resolved to it.
     */
    public function test_it_strips_a_heading_that_shares_a_line_with_its_ingredients(): void
    {
        $this->assertSame(
            ['4 łyżki jogurtu + 1 łyżka majonezu', 'sos'],
            $this->headings->stripPrefix('sos: 4 łyżki jogurtu + 1 łyżka majonezu'),
        );

        $this->assertSame(
            ['1 łyżeczka kurkumy, 1 łyżeczka oregano', 'przyprawy'],
            $this->headings->stripPrefix('przyprawy: 1 łyżeczka kurkumy, 1 łyżeczka oregano'),
        );
    }

    public function test_it_leaves_a_colon_that_is_not_a_heading_alone(): void
    {
        $this->assertSame(
            ['sos sojowy: ciemny', null],
            $this->headings->stripPrefix('sos sojowy: ciemny'),
        );
        $this->assertSame(['200 g mąki', null], $this->headings->stripPrefix('200 g mąki'));
    }

    /**
     * "1 łyżeczka sosu (rybnego)" reduces to "sosu". Turned into a product it owns
     * the alias "sos", and every sauce in the database then resolves to it — 83
     * unrelated lines did exactly that.
     */
    public function test_a_bare_group_name_is_not_a_product(): void
    {
        $this->assertTrue($this->headings->isGenericGroupName('sosu'));
        $this->assertTrue($this->headings->isGenericGroupName('przyprawy'));
        $this->assertTrue($this->headings->isGenericGroupName('płatków'));
    }

    public function test_a_qualified_name_is_a_product(): void
    {
        $this->assertFalse($this->headings->isGenericGroupName('sos sojowy'));
        $this->assertFalse($this->headings->isGenericGroupName('płatki owsiane'));
        $this->assertFalse($this->headings->isGenericGroupName('cebula'));
        $this->assertFalse($this->headings->isGenericGroupName(''));
    }

    public function test_it_ignores_blank_input(): void
    {
        $this->assertNull($this->headings->detect('   '));
        $this->assertNull($this->headings->detect(':'));
    }
}
