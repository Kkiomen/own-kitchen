<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Appliance;
use App\Enums\StepAction;
use App\Importing\Parsing\PolishTextNormalizer;
use App\Importing\Parsing\StepInstructionParser;
use PHPUnit\Framework\TestCase;

class StepInstructionParserTest extends TestCase
{
    private StepInstructionParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new StepInstructionParser(new PolishTextNormalizer);
    }

    public function test_it_reads_the_oven_temperature(): void
    {
        $step = $this->parser->parse('Piekarnik nagrzać do 230 stopni C.');

        $this->assertSame(230, $step->temperatureCelsius);
        $this->assertSame(Appliance::Oven, $step->appliance);
    }

    public function test_it_turns_a_duration_into_seconds(): void
    {
        $this->assertSame(1800, $this->parser->parse('Piec przez 30 minut.')->durationSeconds);
        $this->assertSame(30, $this->parser->parse('Miksować przez 30 sekund.')->durationSeconds);
        $this->assertSame(3600, $this->parser->parse('Odstawić na 1 godzinę.')->durationSeconds);
    }

    public function test_it_understands_half_a_minute(): void
    {
        $this->assertSame(30, $this->parser->parse('Miksować jeszcze przez pół minuty.')->durationSeconds);
    }

    public function test_it_identifies_the_action_that_headlines_the_step(): void
    {
        $this->assertSame(StepAction::Boil, $this->parser->parse('Zagotować wodę w dużym garnku.')->action);
        $this->assertSame(StepAction::Fry, $this->parser->parse('Podsmażyć boczek na średnim ogniu.')->action);
        $this->assertSame(StepAction::Bake, $this->parser->parse('Sernik piec przez 60 minut.')->action);
        $this->assertSame(StepAction::Chop, $this->parser->parse('Herbatniki dokładnie pokruszyć.')->action);
        $this->assertSame(StepAction::Add, $this->parser->parse('Dodać mąkę i wymieszać.')->action);
    }

    public function test_it_identifies_the_tool_for_the_step_icon(): void
    {
        $this->assertSame(Appliance::Pan, $this->parser->parse('Na większą patelnię włożyć boczek.')->appliance);
        $this->assertSame(Appliance::Pot, $this->parser->parse('Zagotować wodę w dużym garnku.')->appliance);
        $this->assertSame(Appliance::Fridge, $this->parser->parse('Wstawić do lodówki do schłodzenia.')->appliance);
        $this->assertSame(Appliance::Mixer, $this->parser->parse('Serki umieścić w misie miksera.')->appliance);
    }

    public function test_a_step_with_no_recognisable_verb_is_flagged_for_review(): void
    {
        $this->assertTrue($this->parser->parse('Smacznego!')->needsReview());
    }

    /**
     * kwestiasmaku.com writes its method impersonally, the air fryer sites address
     * the cook. Both have to headline a guided-cooking screen.
     */
    public function test_it_reads_the_imperative_the_newer_sources_write_in(): void
    {
        $this->assertSame(StepAction::Chop, $this->parser->parse('Pokrój mięso w kostkę.')->action);
        $this->assertSame(StepAction::Prepare, $this->parser->parse('Przygotuj naczynie żaroodporne.')->action);
        $this->assertSame(StepAction::Add, $this->parser->parse('Ułóż udka w koszu w jednej warstwie.')->action);
        $this->assertSame(StepAction::Add, $this->parser->parse('Przełóż mięso do miski.')->action);
        $this->assertSame(StepAction::Add, $this->parser->parse('Wlej bulion do naczynia.')->action);
    }

    public function test_it_recognises_the_air_fryer_however_the_recipe_names_it(): void
    {
        $this->assertSame(Appliance::AirFryer, $this->parser->parse('Rozgrzej AirFryer do 180°C.')->appliance);
        $this->assertSame(Appliance::AirFryer, $this->parser->parse('Piecz we frytkownicy beztłuszczowej.')->appliance);
        // These recipes name the basket far more often than the device itself.
        $this->assertSame(Appliance::AirFryer, $this->parser->parse('Ułóż udka w koszu w jednej warstwie.')->appliance);
    }

    /**
     * airfryerprzepisy.pl publishes some steps with the first word cut off. The text
     * is imported as it stands, but it must not pass as understood.
     */
    public function test_a_step_that_begins_mid_sentence_is_flagged_for_review(): void
    {
        $step = $this->parser->parse('łuższy czas pieczenia o 3–5 minut zastosuj przy większych kawałkach.');

        $this->assertSame(StepAction::Bake, $step->action, 'The verb is still recognised.');
        $this->assertTrue($step->needsReview());
    }

    public function test_an_implausible_temperature_is_ignored(): void
    {
        $this->assertNull($this->parser->parse('Przepis na 1000 stopni')->temperatureCelsius);
    }
}
