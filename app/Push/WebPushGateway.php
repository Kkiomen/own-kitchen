<?php

declare(strict_types=1);

namespace App\Push;

use App\Models\PushSubscription;
use App\Push\Contracts\PushGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * The real thing: VAPID-signed, payload-encrypted posts to FCM and APNs.
 *
 * The only class in the app that knows the Web Push protocol exists, exactly as
 * `GazetkiPl` is the only one that knows a leaflet site's markup.
 */
final readonly class WebPushGateway implements PushGateway
{
    /**
     * @param  array{subject: string, publicKey: string, privateKey: string}  $vapid
     */
    public function __construct(
        private array $vapid,
        private int $timeoutSeconds,
    ) {}

    /**
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @return list<string>
     */
    public function deliver(PushMessage $message, Collection $subscriptions): array
    {
        if ($subscriptions->isEmpty()) {
            return [];
        }

        try {
            $webPush = new WebPush(
                ['VAPID' => $this->vapid],
                timeout: $this->timeoutSeconds,
            );
        } catch (Throwable $exception) {
            // A malformed key pair. Worth saying loudly once, but not worth
            // failing the request that happened to be holding the door.
            Log::warning('Push is configured with keys the library will not take.', [
                'exception' => $exception->getMessage(),
            ]);

            return [];
        }

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->public_key,
                    'authToken' => $subscription->auth_token,
                    // What every current browser negotiates. Named rather than
                    // left to the library's default, which is still the older
                    // `aesgcm` for compatibility with browsers this app has
                    // never supported.
                    'contentEncoding' => 'aes128gcm',
                ]),
                $message->payload(),
            );
        }

        $gone = [];

        /*
         * `flush()` sends the queue as one concurrent pool and yields a report
         * per subscription. Nothing here is retried: the next task written is a
         * better second attempt than a redelivery of the last one.
         */
        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                continue;
            }

            if ($report->isSubscriptionExpired()) {
                $gone[] = $report->getEndpoint();

                continue;
            }

            Log::info('A push was not delivered.', [
                'endpoint' => $report->getEndpoint(),
                'reason' => $report->getReason(),
            ]);
        }

        return $gone;
    }
}
