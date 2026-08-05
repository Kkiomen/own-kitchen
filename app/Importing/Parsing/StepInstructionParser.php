<?php

declare(strict_types=1);

namespace App\Importing\Parsing;

use App\Enums\Appliance;
use App\Enums\StepAction;

/**
 * Turns prose into the structured facts a guided-cooking screen needs: which verb
 * headlines the step, which tool it happens in, and any temperature or timer.
 *
 * "Piec przez 30 minut w 180 stopniach" -> Bake, Oven, 180 °C, 1800 s
 */
final class StepInstructionParser
{
    /**
     * Verb stems in the order they are searched for. Whichever appears earliest in
     * the sentence wins, because Polish recipe steps lead with their main verb.
     *
     * @var array<string, StepAction>
     */
    private const array ACTION_STEMS = [
        'podsmaz' => StepAction::Fry,
        'usmaz' => StepAction::Fry,
        'smaz' => StepAction::Fry,
        'upiec' => StepAction::Bake,
        'piec' => StepAction::Bake,
        'zapiec' => StepAction::Bake,
        'zagotow' => StepAction::Boil,
        'gotow' => StepAction::Boil,
        'nagrz' => StepAction::Heat,
        'rozgrz' => StepAction::Heat,
        'podgrz' => StepAction::Heat,
        'zmiksow' => StepAction::Blend,
        'miksow' => StepAction::Mix,
        'ubi' => StepAction::Mix,
        'wymiesz' => StepAction::Mix,
        'miesz' => StepAction::Mix,
        'roztrzep' => StepAction::Mix,
        'rozdrobni' => StepAction::Chop,
        'pokroi' => StepAction::Chop,
        // Imperative forms. kwestiasmaku.com writes its method impersonally
        // ("pokroić", "przygotować"), the air fryer sites address the cook
        // ("Pokrój", "Przygotuj"). Stripped of diacritics the two share no prefix —
        // "pokroj" does not start with "pokroi" — so every imperative step was
        // arriving with no verb and no headline for the guided-cooking screen.
        'pokroj' => StepAction::Chop,
        'podziel' => StepAction::Chop,
        'posiek' => StepAction::Chop,
        'zetrz' => StepAction::Chop,
        'pokrusz' => StepAction::Chop,
        'obra' => StepAction::Prepare,
        'umy' => StepAction::Prepare,
        'oczysc' => StepAction::Prepare,
        'przygotow' => StepAction::Prepare,
        'przygotuj' => StepAction::Prepare,
        'osusz' => StepAction::Prepare,
        'rozwin' => StepAction::Prepare,
        'zwin' => StepAction::Prepare,
        'dodaw' => StepAction::Add,
        'doda' => StepAction::Add,
        'wsyp' => StepAction::Add,
        'wla' => StepAction::Add,
        'wloz' => StepAction::Add,
        'wrzuc' => StepAction::Add,
        'uloz' => StepAction::Add,
        'umiesc' => StepAction::Add,
        'przeloz' => StepAction::Add,
        'przelej' => StepAction::Add,
        'przela' => StepAction::Add,
        'wlej' => StepAction::Add,
        'dopraw' => StepAction::Add,
        'sprysk' => StepAction::Add,
        'posmaruj' => StepAction::Add,
        'schlodz' => StepAction::Chill,
        'ostudz' => StepAction::Chill,
        'odstaw' => StepAction::Rest,
        'podaw' => StepAction::Serve,
        'posyp' => StepAction::Serve,
        'wylozyc na talerze' => StepAction::Serve,
    ];

    /**
     * @var array<string, Appliance>
     */
    private const array APPLIANCE_STEMS = [
        'piekarnik' => Appliance::Oven,
        'airfryer' => Appliance::AirFryer,
        'air fryer' => Appliance::AirFryer,
        'frytkownic' => Appliance::AirFryer,
        // Air fryer recipes say "ułóż w koszu" far more often than they name the
        // device; a steam basket is rare enough that this is not ambiguous.
        'kosz' => Appliance::AirFryer,
        'patelni' => Appliance::Pan,
        'garnk' => Appliance::Pot,
        'garnek' => Appliance::Pot,
        'rondl' => Appliance::Pot,
        'miksera' => Appliance::Mixer,
        'mikser' => Appliance::Mixer,
        'blender' => Appliance::Blender,
        'melakser' => Appliance::Blender,
        'rozdrabniacz' => Appliance::Blender,
        'tortownic' => Appliance::BakingTin,
        'formy' => Appliance::BakingTin,
        'forme' => Appliance::BakingTin,
        'blach' => Appliance::BakingTray,
        'lodowk' => Appliance::Fridge,
        'zamrazark' => Appliance::Freezer,
        'mikrofalow' => Appliance::Microwave,
        'tarce' => Appliance::Grater,
        'tarki' => Appliance::Grater,
        'misce' => Appliance::Bowl,
        'miski' => Appliance::Bowl,
        'misy' => Appliance::Bowl,
    ];

    public function __construct(private readonly PolishTextNormalizer $normalizer) {}

    public function parse(string $instruction): ParsedStep
    {
        $text = trim(preg_replace('/\s+/u', ' ', $instruction) ?? $instruction);
        $normalised = $this->normalizer->normalize($text);

        return new ParsedStep(
            instruction: $text,
            action: $this->earliestMatch($normalised, self::ACTION_STEMS),
            appliance: $this->earliestMatch($normalised, self::APPLIANCE_STEMS),
            temperatureCelsius: $this->extractTemperature($normalised),
            durationSeconds: $this->extractDuration($normalised),
        );
    }

    /**
     * @template TValue of StepAction|Appliance
     *
     * @param  array<string, TValue>  $stems
     * @return TValue|null
     */
    private function earliestMatch(string $normalised, array $stems): StepAction|Appliance|null
    {
        $bestPosition = PHP_INT_MAX;
        $best = null;

        foreach ($stems as $stem => $value) {
            $position = mb_strpos($normalised, $stem);

            if ($position === false || $position >= $bestPosition) {
                continue;
            }

            $bestPosition = $position;
            $best = $value;
        }

        return $best;
    }

    private function extractTemperature(string $normalised): ?int
    {
        // "230 stopni c", "180 stopniach", "180 c"
        if (preg_match('/(\d{2,3})\s*(?:stopni\w*|c\b|°)/u', $normalised, $match) === 1) {
            $degrees = (int) $match[1];

            return $degrees >= 30 && $degrees <= 300 ? $degrees : null;
        }

        return null;
    }

    private function extractDuration(string $normalised): ?int
    {
        if (preg_match('/\bpol\s+(minuty|godziny)\b/u', $normalised, $match) === 1) {
            return $match[1] === 'minuty' ? 30 : 1800;
        }

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(sekund\w*|minut\w*|godzin\w*)/u', $normalised, $match) !== 1) {
            return null;
        }

        $amount = (float) str_replace(',', '.', $match[1]);

        $multiplier = match (true) {
            str_starts_with($match[2], 'sekund') => 1,
            str_starts_with($match[2], 'minut') => 60,
            default => 3600,
        };

        return (int) round($amount * $multiplier);
    }
}
