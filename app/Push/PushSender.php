<?php

declare(strict_types=1);

namespace App\Push;

use App\Models\PushSubscription;
use App\Models\User;
use App\Push\Contracts\PushGateway;

/**
 * Tells the household's *other* phones that something happened.
 *
 * The exclusion is the whole reason this is not a one-line call to the gateway.
 * Both phones are signed in as one account — that is what the QR device link is
 * for — so "notify the user" would buzz the pocket of the person who just typed
 * the thing in, which reads as a bug the first time and as noise for ever after.
 */
final readonly class PushSender
{
    public function __construct(private PushGateway $gateway) {}

    /**
     * @param  string|null  $exceptDevice  The phone that caused this. Null tells
     *                                     every device, which is right for
     *                                     anything the app decided on its own.
     * @return int How many phones were written to.
     */
    public function toOtherDevices(User $user, PushMessage $message, ?string $exceptDevice = null): int
    {
        $subscriptions = PushSubscription::query()
            ->of($user)
            ->exceptDevice($exceptDevice)
            ->get();

        $gone = $this->gateway->deliver($message, $subscriptions);

        /*
         * A phone that has deleted the app, or whose subscription the browser
         * retired, answers 404/410 for good. Leaving the row would have the
         * queue knock on a dead endpoint after every task for ever, and would
         * show a revoked phone in a list of what can be told.
         */
        if ($gone !== []) {
            PushSubscription::query()->whereIn('endpoint', $gone)->delete();
        }

        return $subscriptions->count() - count($gone);
    }
}
