<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Assignee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing to do, for one of the two people or for both.
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property Assignee $assignee
 * @property CarbonImmutable|null $due_on
 * @property CarbonImmutable|null $done_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'title', 'assignee', 'due_on', 'done_at'])]
class Task extends Model
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
     * @param  Builder<$this>  $query
     */
    public function scopeStillToDo(Builder $query): void
    {
        $query->whereNull('done_at');
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    /**
     * Late means the day has passed, not the hour: a task carries a date, and
     * something due today is not late until tomorrow.
     */
    public function isOverdue(): bool
    {
        return ! $this->isDone()
            && $this->due_on !== null
            && $this->due_on->isBefore(CarbonImmutable::today());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assignee' => Assignee::class,
            'due_on' => 'immutable_date',
            'done_at' => 'immutable_datetime',
        ];
    }
}
