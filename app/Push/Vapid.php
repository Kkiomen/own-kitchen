<?php

declare(strict_types=1);

namespace App\Push;

/**
 * The key pair that identifies this installation to the push services.
 *
 * One place reads the config, because three ask about it: the provider deciding
 * whether there is a gateway at all, the screen deciding whether to offer the
 * switch, and the command that generates a pair in the first place.
 */
final readonly class Vapid
{
    public function __construct(
        public string $subject,
        public ?string $publicKey,
        public ?string $privateKey,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array{subject: string, public_key: string|null, private_key: string|null} $vapid */
        $vapid = config('push.vapid');

        return new self(
            $vapid['subject'],
            $vapid['public_key'] ?: null,
            $vapid['private_key'] ?: null,
        );
    }

    /**
     * Missing keys are a state, not a fault. An installation nobody has run
     * `push:keys` on simply does not do notifications, and every screen and
     * every send has to read that as "quietly do nothing" rather than as an
     * error — the same three-answer discipline `Quantity::covers()` follows.
     */
    public function isConfigured(): bool
    {
        return $this->publicKey !== null && $this->privateKey !== null;
    }

    /**
     * @return array{subject: string, publicKey: string, privateKey: string}
     */
    public function forLibrary(): array
    {
        if (! $this->isConfigured()) {
            throw new \LogicException('Asked for VAPID keys that were never generated.');
        }

        return [
            'subject' => $this->subject,
            'publicKey' => (string) $this->publicKey,
            'privateKey' => (string) $this->privateKey,
        ];
    }
}
