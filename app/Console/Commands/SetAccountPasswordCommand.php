<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Sets a new password on an existing account.
 *
 * There is no "forgot your password" e-mail here and there is not going to be:
 * `MAIL_MAILER=log`, because a two-person household app has nowhere to send one
 * from. Someone locked out of their own kitchen needs a way back in, and the
 * machine's command line is both the way and the proof they are entitled to it.
 */
class SetAccountPasswordCommand extends Command
{
    protected $signature = 'account:password
        {email : Whose password to change}
        {--password= : The new one; asked for when left out}';

    protected $description = 'Set a new password on an account';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('Nie znam takiego adresu.');
            $this->line('Konta w tej kuchni: '.User::query()->pluck('email')->implode(', '));

            return self::FAILURE;
        }

        $password = $this->option('password') ?? $this->secret('Nowe hasło');

        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', Password::defaults()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user->update(['password' => Hash::make($password)]);

        $this->info("Hasło do {$user->email} zmienione.");

        return self::SUCCESS;
    }
}
