<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\Registration;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RegisteredUserController extends Controller
{
    public function __construct(private readonly Registration $registration) {}

    public function create(): Response
    {
        $this->guardClosed();

        return Inertia::render('Auth/Register');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guardClosed();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->route('home');
    }

    /**
     * Closed registration is hidden rather than refused: a 403 would confirm that
     * an account already exists here.
     */
    private function guardClosed(): void
    {
        if (! $this->registration->isOpen()) {
            throw new NotFoundHttpException;
        }
    }
}
