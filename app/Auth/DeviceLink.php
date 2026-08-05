<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\DeviceLinkToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues and redeems the codes that put a second device on one account.
 *
 * The security model, stated plainly because a QR code that logs you in *is* a
 * credential: the code is single use, short lived, invalidates any earlier code
 * for the same account, and is only ever stored hashed. A photographed code is
 * still a way in — which is why the window is minutes, not days.
 */
final class DeviceLink
{
    /**
     * Long enough to be worth showing on screen while someone fetches their
     * phone, short enough that a photograph of it goes stale quickly.
     */
    public const int LIFETIME_MINUTES = 5;

    /**
     * @return array{token: string, model: DeviceLinkToken}
     */
    public function issueFor(User $user, ?string $ip = null): array
    {
        $token = Str::random(48);

        $model = DB::transaction(function () use ($user, $token, $ip): DeviceLinkToken {
            // Only one code may be live at a time: the owner pressing "generate"
            // again must make the code they walked away from useless.
            DeviceLinkToken::query()
                ->where('user_id', $user->id)
                ->usable()
                ->update(['used_at' => now()]);

            return DeviceLinkToken::query()->create([
                'user_id' => $user->id,
                'token_hash' => $this->hash($token),
                'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES),
                'issued_to_ip' => $ip,
            ]);
        });

        return ['token' => $token, 'model' => $model];
    }

    /**
     * Consumes a code and returns the account it belongs to, or null when it is
     * unknown, expired or already spent.
     */
    public function redeem(string $token, ?string $ip = null): ?User
    {
        return DB::transaction(function () use ($token, $ip): ?User {
            /*
             * Locked for the length of the transaction so two phones scanning the
             * same screen cannot both come away logged in.
             */
            $model = DeviceLinkToken::query()
                ->where('token_hash', $this->hash($token))
                ->lockForUpdate()
                ->first();

            if ($model === null || ! $model->isUsable()) {
                return null;
            }

            $model->update(['used_at' => now(), 'redeemed_by_ip' => $ip]);

            return $model->user;
        });
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
