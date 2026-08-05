<?php

declare(strict_types=1);

namespace App\Push\Contracts;

use App\Models\PushSubscription;
use App\Push\PushMessage;
use Illuminate\Support\Collection;

/**
 * The one thing that talks to Google and Apple.
 *
 * An interface here rather than over the library directly, for the reason the
 * house rule allows one: the implementation makes real HTTPS calls to servers we
 * do not own, so without a seam every test of "does adding a task tell the other
 * phone" would either hit the network or test nothing.
 */
interface PushGateway
{
    /**
     * Deliver one message to every subscription given.
     *
     * Best effort by contract. A phone that is off, out of signal or has had the
     * app deleted is a normal outcome, not a failure to report upwards — the
     * deadline-versus-countdown lesson from the cooking timer applies here too:
     * what matters is written down, and the notification is only a nudge towards
     * it.
     *
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @return list<string> The endpoints the push service says are gone for good
     *                      (404/410), for the caller to delete. Anything else —
     *                      a timeout, a 500 — is not in this list, because a
     *                      service having a bad afternoon must never cost a
     *                      phone its subscription.
     */
    public function deliver(PushMessage $message, Collection $subscriptions): array;
}
