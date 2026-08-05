<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Importing\Parsing\PolishTextNormalizer;
use App\Importing\Parsing\UnitVocabulary;
use App\Offers\Parsing\PackSizeParser;
use PHPUnit\Framework\TestCase;

class PackSizeParserTest extends TestCase
{
    private PackSizeParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new PackSizeParser(new PolishTextNormalizer, new UnitVocabulary);
    }

    public function test_it_reads_a_pack_size_off_the_end_of_a_name(): void
    {
        $pack = $this->parser->parse('Piwo Garage 400 ml');

        $this->assertSame(400.0, $pack?->amount);
        $this->assertSame('ml', $pack?->unitCode);
        $this->assertSame('Piwo Garage', $pack?->remainingTitle);
    }

    public function test_it_reads_a_decimal_size_written_with_a_comma(): void
    {
        $pack = $this->parser->parse('Woda źródlana Żywiec Zdrój 1,75 l');

        $this->assertSame(1.75, $pack?->amount);
        $this->assertSame('l', $pack?->unitCode);
    }

    public function test_a_multipack_is_multiplied_out(): void
    {
        $pack = $this->parser->parse('Serek wiejski Piątnica 4 x 150 g');

        $this->assertSame(600.0, $pack?->amount);
        $this->assertSame('g', $pack?->unitCode);
    }

    /**
     * The size is stated last, after the brand and any strength. Taking the first
     * number in the line would price this brandy by the alcohol percentage.
     */
    public function test_a_strength_before_the_size_is_not_the_size(): void
    {
        $pack = $this->parser->parse('Brandy Napoleon Recherché Pons 36% vol., 700 ml');

        $this->assertSame(700.0, $pack?->amount);
        $this->assertSame('ml', $pack?->unitCode);
    }

    /**
     * A percentage belongs to the product name for dairy, and the parser must
     * leave it there rather than reading "18" as a size.
     */
    public function test_a_dairy_percentage_is_left_in_the_name(): void
    {
        $pack = $this->parser->parse('Śmietana Łaciata 18% 400 g');

        $this->assertSame(400.0, $pack?->amount);
        $this->assertSame('Śmietana Łaciata 18%', $pack?->remainingTitle);
    }

    public function test_a_model_number_is_not_a_pack_size(): void
    {
        $this->assertNull($this->parser->parse('TRACER Smartwatch SMR11 Hero 1.39'));
    }

    public function test_a_name_with_no_size_stays_unknown(): void
    {
        $this->assertNull($this->parser->parse('Cukier biały'));
        $this->assertNull($this->parser->parse('Papryka słodka czerwona na wagę'));
    }

    /**
     * A spoon is a unit in a recipe and part of a brand name on a shelf. Only the
     * units a shop prints on a package count as a size.
     */
    public function test_a_kitchen_measure_is_not_a_pack_size(): void
    {
        $this->assertNull($this->parser->parse('Przyprawa 2 łyżki smaku'));
    }

    public function test_pieces_are_a_pack_size(): void
    {
        $pack = $this->parser->parse('Jaja z wolnego wybiegu 10 szt.');

        $this->assertSame(10.0, $pack?->amount);
        $this->assertSame('piece', $pack?->unitCode);
    }
}
