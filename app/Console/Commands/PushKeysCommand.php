<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Push\Vapid;
use Illuminate\Console\Command;
/*
 * Aliased, and it has to be: PHP compares class names case-insensitively, so
 * importing this beside our own `App\Push\Vapid` is a fatal error — "cannot use
 * ... as VAPID because the name is already in use". It fires at class load, so
 * it takes out every artisan call and the whole test suite, not just this file.
 */
use Minishlink\WebPush\VAPID as WebPushVapid;

class PushKeysCommand extends Command
{
    protected $signature = 'push:keys {--force : Print a new pair even though one is already configured}';

    protected $description = 'Generate the VAPID key pair that identifies this installation to the push services';

    public function handle(Vapid $vapid): int
    {
        /*
         * Refusing by default is the point of the command having a flag at all.
         * The public half is baked into every subscription a phone has already
         * made, so replacing the pair does not re-key anything — it silently
         * orphans every device, which go on looking subscribed and never ring
         * again. That is a bad afternoon to diagnose and a trivial one to
         * prevent.
         */
        if ($vapid->isConfigured() && ! $this->option('force')) {
            $this->warn('Klucze już są. Nowa para wycisza wszystkie telefony, które zdążyły się zapisać.');
            $this->line('Jeśli naprawdę o to chodzi: php artisan push:keys --force');

            return self::FAILURE;
        }

        $keys = WebPushVapid::createVapidKeys();

        $this->info('Wklej to do .env i zrestartuj aplikację:');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();

        /*
         * Not written to the file by the command. In Docker the environment
         * lives on the data volume at /data/.env, and a command that helpfully
         * rewrote whichever .env it found would edit the wrong copy on the one
         * machine where it matters.
         */
        $this->comment('W Dockerze to /data/.env na wolumenie kitchen-data, nie plik w repozytorium.');

        return self::SUCCESS;
    }
}
