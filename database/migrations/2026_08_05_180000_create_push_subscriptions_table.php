<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One phone's permission to be told something.
     *
     * A row per *device*, not per account: one household is one account and two
     * people carry two phones, so the account is exactly the wrong grain — a
     * single subscription on it would mean only whichever phone subscribed last
     * ever rings.
     *
     * Everything here except `device_id` is issued by the browser and is opaque
     * to us. It is also a credential of sorts, though a narrow one: it permits
     * showing a notification on that phone and nothing else. It is not hashed,
     * unlike a device-link token, because unlike that token it has to be *sent*
     * to the push service on every delivery rather than merely compared.
     */
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * Where the push service wants the message posted. Unique on its own
             * rather than per user: the browser mints one per installation, and
             * the same endpoint turning up under a second account would mean a
             * phone that changed hands. Re-subscribing then moves the row rather
             * than leaving the previous account able to ring it.
             *
             * 500 characters because these are long — FCM's run to about 200,
             * Apple's rather less, and neither publishes a ceiling.
             */
            $table->string('endpoint', 500)->unique();

            // The keys the payload is encrypted to. Without them a message can
            // only be sent empty, and an empty push is a notification with
            // nothing to say.
            $table->string('public_key');
            $table->string('auth_token');

            /*
             * Which phone this is, in our terms rather than the browser's: a
             * value the client mints once and keeps in localStorage.
             *
             * It exists for one thing — not buzzing the phone that just wrote
             * the task. The endpoint cannot do that job, because the browser is
             * free to replace it at any time and the client does not know when
             * it has, so a request could never reliably name its own.
             */
            $table->string('device_id')->index();

            /*
             * Something to recognise the row by when two are listed and one is
             * to be revoked ("iPhone", "Chrome"). Read off the user agent, so it
             * is a hint and never an identity.
             */
            $table->string('label')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
