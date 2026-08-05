<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Makes an account from the command line.
 *
 * The sign-up form closes itself as soon as one account exists — see
 * `App\Auth\Registration`, and it stays that way — which leaves a real gap the
 * moment the app is running somewhere the browser cannot register against: a
 * container started from an imported database already has an account, so
 * `/rejestracja` answers 404 and there is no way in without one.
 *
 * This is the way in. Reaching the command line already means you own the
 * machine, so it grants nothing that was being withheld — unlike reopening the
 * public form, which grants it to everyone who can reach the address.
 */
class CreateAccountCommand extends Command
{
    protected $signature = 'account:create
        {--name= : What to call whoever is signing in}
        {--email= : The address they sign in with}
        {--password= : Their password; asked for when left out}';

    protected $description = 'Create an account without the sign-up form';

    public function handle(): int
    {
        $name = $this->option('name') ?? $this->ask('Imię');
        $email = $this->option('email') ?? $this->ask('E-mail');
        $password = $this->option('password') ?? $this->secret('Hasło');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                // The same rules the form applies, so an account made here is
                // not quietly weaker than one made in a browser.
                'password' => ['required', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            // An address already in use is the commonest way to land here, and
            // the answer is a new password rather than a second account: this
            // household shares one.
            if ($validator->errors()->has('email')) {
                $this->line('Konto z tym adresem już istnieje — zmień hasło: php artisan account:password '.$email);
            }

            return self::FAILURE;
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $this->info("Konto {$user->email} gotowe. Zaloguj się na /logowanie.");

        return self::SUCCESS;
    }
}
