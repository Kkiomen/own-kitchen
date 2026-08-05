<?php

declare(strict_types=1);

/*
 * Draws public/favicon.ico from the same spoon-and-fork mark as favicon.svg.
 *
 *     php scripts/make-favicon.php
 *
 * Browsers ask for /favicon.ico by name whatever the <link> tags say, and some
 * places (pinned tabs, bookmark exports, an offline page opened from disk) only
 * ever see that file — so the SVG alone is not enough.
 *
 * Drawn with GD primitives rather than rasterised from the SVG, for the same
 * reason the app icons were: there is no SVG renderer and no TrueType support in
 * this environment. The two therefore have to be kept in step by hand; they are
 * simple enough shapes that this is a fair trade for being reproducible.
 *
 * The .ico wraps PNG frames. That is legal ICO (Vista onwards) and every current
 * browser reads it, which spares us writing a BMP encoder with an AND mask.
 */

const SIZES = [16, 32, 48];

$accent = [0x55, 0xB8, 0x50];
$ink = [0x17, 0x19, 0x1A];

/**
 * One frame of the mark, drawn on a 64-unit grid and scaled to $size.
 */
function frame(int $size, array $accent, array $ink): string
{
    $scale = 8; // Drawn large and shrunk: GD has no antialiasing on filled shapes.
    $canvas = imagecreatetruecolor($size * $scale, $size * $scale);

    $unit = static fn (float $value): int => (int) round($value * $size * $scale / 64);

    $green = imagecolorallocate($canvas, ...$accent);
    $dark = imagecolorallocate($canvas, ...$ink);

    imagefilledrectangle($canvas, 0, 0, $size * $scale, $size * $scale, $green);

    // Spoon: bowl, then handle.
    imagefilledellipse($canvas, $unit(22), $unit(22), $unit(15), $unit(19), $dark);
    imagefilledrectangle($canvas, $unit(19), $unit(29), $unit(25), $unit(52), $dark);

    // Fork: three tines, the shoulder they meet on, then the handle.
    foreach ([33.4, 39.2, 45.0] as $x) {
        imagefilledrectangle($canvas, $unit($x), $unit(12), $unit($x + 3.6), $unit(25), $dark);
    }

    imagefilledrectangle($canvas, $unit(33.4), $unit(23), $unit(48.6), $unit(31), $dark);
    imagefilledrectangle($canvas, $unit(38), $unit(29), $unit(44), $unit(52), $dark);

    $out = imagecreatetruecolor($size, $size);
    imagecopyresampled($out, $canvas, 0, 0, 0, 0, $size, $size, $size * $scale, $size * $scale);
    imagedestroy($canvas);

    ob_start();
    imagepng($out, null, 9);
    imagedestroy($out);

    return (string) ob_get_clean();
}

$frames = [];

foreach (SIZES as $size) {
    $frames[$size] = frame($size, $accent, $ink);
}

// ICONDIR: reserved, type 1 (icon), image count.
$ico = pack('vvv', 0, 1, count($frames));

// Each ICONDIRENTRY is 16 bytes and the data follows all of them.
$offset = 6 + 16 * count($frames);

foreach ($frames as $size => $png) {
    $ico .= pack(
        'CCCCvvVV',
        $size === 256 ? 0 : $size,  // 0 means 256 in this field.
        $size === 256 ? 0 : $size,
        0,                          // Palette size: 0 for a true-colour image.
        0,                          // Reserved.
        1,                          // Colour planes.
        32,                         // Bits per pixel.
        strlen($png),
        $offset,
    );

    $offset += strlen($png);
}

file_put_contents(
    __DIR__.'/../public/favicon.ico',
    $ico.implode('', $frames),
);

printf("favicon.ico: %d klatek, %d B\n", count($frames), filesize(__DIR__.'/../public/favicon.ico'));
