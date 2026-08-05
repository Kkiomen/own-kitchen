<?php

declare(strict_types=1);

namespace App\Push;

use App\Models\PushSubscription;
use App\Push\Contracts\PushGateway;
use Illuminate\Support\Collection;

/**
 * What an installation with no VAPID keys sends: nothing.
 *
 * Bound instead of the real gateway rather than guarded at every call site, so
 * that "is push set up here" is answered once, in the composition root, and no
 * feature that wants to say something has to ask.
 *
 * It reports no expired endpoints, which matters: returning the whole list would
 * delete every subscription the moment the keys went missing from an `.env`.
 */
final readonly class NullPushGateway implements PushGateway
{
    /**
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @return list<string>
     */
    public function deliver(PushMessage $message, Collection $subscriptions): array
    {
        return [];
    }
}
