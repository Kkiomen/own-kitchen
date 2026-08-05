<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_link_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            /*
             * Only the hash is stored. A QR code that logs someone in is a
             * credential, and a credential kept in plain text is one database
             * leak away from being someone else's.
             */
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            /* Kept so the owner can tell a stolen scan from their own. */
            $table->string('issued_to_ip', 45)->nullable();
            $table->string('redeemed_by_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_link_tokens');
    }
};
