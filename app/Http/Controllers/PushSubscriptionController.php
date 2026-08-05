<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A phone signing itself up to be told things, and signing itself off again.
 *
 * Both halves are needed. The browser's permission and our row can fall out of
 * step in either direction — a permission revoked in iOS settings leaves a row
 * that will never ring, and a row deleted here leaves a permission the phone
 * still thinks it granted — so the switch on screen writes both every time
 * rather than trusting one to imply the other.
 */
class PushSubscriptionController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:500', 'url'],
            'public_key' => ['required', 'string', 'max:255'],
            'auth_token' => ['required', 'string', 'max:255'],
            'device' => ['required', 'string', 'max:64'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        /*
         * On the endpoint, not on the device: a browser may hand the same phone
         * a new endpoint at any time, and matching on the device would then keep
         * the old dead one alive under a second row. Matching on the endpoint
         * also moves a subscription between accounts rather than colliding, which
         * is what a phone changing hands looks like.
         */
        PushSubscription::query()->updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'user_id' => $request->user()->id,
                'public_key' => $data['public_key'],
                'auth_token' => $data['auth_token'],
                'device_id' => $data['device'],
                'label' => $data['label'] ?? null,
            ],
        );

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
        ]);

        /*
         * Scoped to the household even though the endpoint is unique, for the
         * same reason every other delete here is: an id that is unique globally
         * is still not a licence to delete somebody else's row by guessing it.
         */
        PushSubscription::query()
            ->of($request->user())
            ->where('endpoint', $data['endpoint'])
            ->delete();

        return back();
    }
}
