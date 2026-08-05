<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One phone that has agreed to be told about a new task.
 *
 * @property int $id
 * @property int $user_id
 * @property string $endpoint
 * @property string $public_key
 * @property string $auth_token
 * @property string $device_id
 * @property string|null $label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'endpoint', 'public_key', 'auth_token', 'device_id', 'label'])]
#[Hidden(['endpoint', 'public_key', 'auth_token'])]
class PushSubscription extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOf(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }

    /**
     * Everything but the phone that caused the notification.
     *
     * Null narrows nothing, deliberately — the same rule `SelectedShops` follows
     * for shops. A caller that cannot say which device it is speaking for wants
     * every phone told, not none of them.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeExceptDevice(Builder $query, ?string $deviceId): void
    {
        if ($deviceId === null) {
            return;
        }

        $query->where('device_id', '!=', $deviceId);
    }
}
