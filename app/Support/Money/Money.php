<?php

declare(strict_types=1);

namespace App\Support\Money;

use InvalidArgumentException;

/**
 * An amount of money in integer minor units — grosze.
 *
 * Never a float. A shopping plan adds up a dozen shelf prices and subtracts them
 * from a dozen regular prices; done in floats, "oszczędzasz 12,00 zł" eventually
 * renders as 11,999999999. The whole point of this screen is a number the user
 * can trust, so the arithmetic is exact by construction.
 *
 * Single currency on purpose. This is a private app shopping in Polish shops; a
 * currency field would be a column nobody ever reads and a comparison nobody ever
 * fails. Add it when there is a second currency, not before.
 */
final readonly class Money
{
    public function __construct(public int $grosze)
    {
        if ($grosze < 0) {
            throw new InvalidArgumentException("A price cannot be negative, got {$grosze} gr.");
        }
    }

    public static function fromZloty(float $zloty): self
    {
        return new self((int) round($zloty * 100));
    }

    /**
     * Reads a price the way a Polish shop writes it: "54,99 zł", "1,99zł", "6,99".
     *
     * Returns null rather than zero when there is no number to read. Zero is a
     * real price ("gratis") and would quietly win every comparison it entered.
     */
    public static function parse(string $text): ?self
    {
        // Non-breaking spaces are what separate the thousands on these pages, and
        // they are not \s in every PCRE build — strip them by codepoint instead.
        $cleaned = str_replace(["\u{00A0}", "\u{202F}", ' '], '', $text);

        if (preg_match('/(\d+)(?:[.,](\d{1,2}))?/', $cleaned, $matches) !== 1) {
            return null;
        }

        $minor = str_pad($matches[2] ?? '', 2, '0');

        return new self((int) $matches[1] * 100 + (int) $minor);
    }

    public function plus(self $other): self
    {
        return new self($this->grosze + $other->grosze);
    }

    /**
     * Clamped at zero: a price that rose since the last crawl is a saving of
     * nothing, not a negative one.
     */
    public function minus(self $other): self
    {
        return new self(max(0, $this->grosze - $other->grosze));
    }

    public function isLessThan(self $other): bool
    {
        return $this->grosze < $other->grosze;
    }

    public function isZero(): bool
    {
        return $this->grosze === 0;
    }

    /**
     * Scaled and rounded once, at the end. Used to turn a pack price into a price
     * per kilo, where the factor is rarely a round number.
     */
    public function scaledBy(float $factor): self
    {
        if ($factor < 0) {
            throw new InvalidArgumentException("Cannot scale a price by {$factor}.");
        }

        return new self((int) round($this->grosze * $factor));
    }

    /**
     * How much cheaper this is than the price it was, as whole percent.
     * Null when there is nothing to compare against or the "before" was free.
     */
    public function percentOff(?self $before): ?int
    {
        if ($before === null || $before->grosze <= 0 || ! $this->isLessThan($before)) {
            return null;
        }

        return (int) round(($before->grosze - $this->grosze) / $before->grosze * 100);
    }

    public function toZloty(): float
    {
        return $this->grosze / 100;
    }
}
