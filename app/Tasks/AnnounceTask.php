<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Models\Task;
use App\Push\PushMessage;
use App\Push\PushSender;
use App\Support\PolishDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Illuminate\Support\defer;

/**
 * "Podjedź po chleb" arriving on the other phone.
 *
 * This is the whole reason the tasks screen exists rather than a Messenger
 * thread: something written down where both can see it is only useful if the
 * other person finds out it was written.
 */
final readonly class AnnounceTask
{
    public function __construct(private PushSender $sender) {}

    /**
     * @param  string|null  $exceptDevice  The phone that wrote it, which must not
     *                                     buzz in the hand that is still holding it.
     */
    public function created(Task $task, ?string $exceptDevice): void
    {
        $message = new PushMessage(
            title: 'Nowe zadanie',
            body: $this->body($task),
            url: '/zadania',
            /*
             * One tag for the whole list, so five things written down in a
             * minute replace one another rather than stacking five banners. What
             * is lost is the earlier titles, which are one tap away on a screen
             * that shows all of them properly.
             */
            tag: 'kuchnia-tasks',
            badge: Task::query()->of($task->user)->stillToDo()->count(),
        );

        /*
         * After the response, not during it, and not on a queue.
         *
         * Not during: a phone waiting on two round-trips to Apple before its own
         * "dodano" appears would make writing a task feel broken on a slow
         * connection, for a result that is not even on the screen it is looking
         * at.
         *
         * Not on a queue: `QUEUE_CONNECTION=database` here but nothing in
         * `compose.yaml` runs a worker, so a queued job would sit in the table
         * for ever and the feature would look wired up while staying silent. A
         * best-effort nudge does not warrant a third container.
         *
         * `defer()` rather than `dispatch(...)->afterResponse()`, and the
         * difference is not stylistic: the latter goes through the queue's
         * payload, which **serializes this object graph and runs a restored
         * copy**. Everything it writes to the database survives, and everything
         * it does to an object does not — so it works in production and is
         * untestable, which is the worst of the two possible ways round.
         */
        defer(function () use ($task, $message, $exceptDevice): void {
            try {
                $this->sender->toOtherDevices($task->user, $message, $exceptDevice);
            } catch (Throwable $exception) {
                // The task is saved. A push service having a bad afternoon is
                // not a reason for anything else to know about it.
                Log::warning('Nie udało się wysłać powiadomienia o zadaniu.', [
                    'task' => $task->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        });
    }

    /**
     * What it says under the title: the task, and the day if it has one.
     *
     * The assignee is deliberately absent. Both phones are one account, so "Dla
     * niej" on a lock screen is read by whoever picks the phone up and tells
     * them nothing they cannot see on the row itself.
     */
    private function body(Task $task): string
    {
        if ($task->due_on === null) {
            return $task->title;
        }

        $today = CarbonImmutable::today();

        $when = match (true) {
            $task->due_on->isSameDay($today) => 'dziś',
            $task->due_on->isSameDay($today->addDay()) => 'jutro',
            default => PolishDate::dayAndMonth($task->due_on),
        };

        return "{$task->title} — {$when}";
    }
}
