<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\DeviceLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Putting a second phone on one account: the account holder shows a code, the
 * other person scans it and lands inside, without a password ever being spoken
 * aloud or typed into someone else's phone.
 */
class DeviceLinkController extends Controller
{
    public function __construct(private readonly DeviceLink $deviceLink) {}

    public function create(Request $request): Response
    {
        $issued = $this->deviceLink->issueFor($request->user(), $request->ip());

        return Inertia::render('Account/LinkDevice', [
            // The scanning phone will not be on the same session, so the code has
            // to carry an absolute URL it can reach.
            'joinUrl' => route('device-link.redeem', ['token' => $issued['token']]),
            'expiresAt' => $issued['model']->expires_at->toIso8601String(),
            'lifetimeMinutes' => DeviceLink::LIFETIME_MINUTES,
        ]);
    }

    /**
     * The scanner, opened from the login screen. Reading the code with the app's
     * own camera rather than the phone's camera app is what makes joining a
     * household one button instead of a set of instructions — and the decoding
     * happens entirely in the browser, so this renders a page and nothing else.
     */
    public function scan(): Response
    {
        return Inertia::render('Auth/ScanCode');
    }

    /**
     * Scanned on the second phone. Deliberately a plain GET so that opening the
     * link is all it takes — that convenience is the whole point, and it is why
     * the code is single use and expires in minutes.
     */
    public function redeem(Request $request, string $token): RedirectResponse
    {
        $user = $this->deviceLink->redeem($token, $request->ip());

        if ($user === null) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Kod wygasł albo został już użyty. Poproś o nowy.']);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->route('home');
    }
}
