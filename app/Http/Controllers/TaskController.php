<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Assignee;
use App\Models\Task;
use App\Push\Vapid;
use App\Tasks\AnnounceTask;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Zadania" — what the two of us asked each other for during the day.
 *
 * Scoped to the household throughout, and 404 (never 403) for anybody else's
 * row, exactly like the kitchen and the shopping list.
 */
class TaskController extends Controller
{
    public function index(Request $request, Vapid $vapid): Response
    {
        $tasks = Task::query()
            ->of($request->user())
            /*
             * Open first, then by when they are due — dated ones ahead of the
             * undated, because a task with a day on it is the one that can be
             * missed. Ticked ones sink to the bottom, newest first, so the
             * section reads as "what we just did".
             */
            ->orderByRaw('done_at is not null')
            ->orderByRaw('due_on is null')
            ->orderBy('due_on')
            ->orderByDesc('done_at')
            ->orderBy('id')
            ->get();

        return Inertia::render('Tasks/Index', [
            'tasks' => $tasks->map(fn (Task $task): array => $this->present($task))->all(),
            'assignees' => array_map(
                static fn (Assignee $assignee): array => [
                    'value' => $assignee->value,
                    'label' => $assignee->label(),
                    'shortLabel' => $assignee->shortLabel(),
                ],
                Assignee::cases(),
            ),
            /*
             * The date the browser should call "today". Taken from the server so
             * the "dziś" shortcut writes the same day the overdue marker judges
             * against, whatever a phone's clock says.
             */
            'today' => CarbonImmutable::today()->toDateString(),
            /*
             * What a phone needs to subscribe itself. Null on an installation
             * nobody has run `push:keys` on, and the switch then hides rather
             * than offering something that could only fail — the same rule the
             * "Mam wszystko" chip follows when there is no kitchen to match
             * against.
             */
            'pushKey' => $vapid->isConfigured() ? $vapid->publicKey : null,
        ]);
    }

    public function store(Request $request, AnnounceTask $announce): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'assignee' => ['required', Rule::enum(Assignee::class)],
            'due_on' => ['nullable', 'date'],
            /*
             * Which phone is typing. Not part of the task and never stored on
             * one — it exists so the notification skips the hand it was written
             * in. Optional, because a device that never turned notifications on
             * has no id to send, and "tell every phone" is the right answer for
             * a caller that cannot name itself.
             */
            'device' => ['nullable', 'string', 'max:64'],
        ]);

        $task = Task::query()->create([
            'user_id' => $request->user()->id,
            'title' => trim($data['title']),
            'assignee' => $data['assignee'],
            'due_on' => $data['due_on'] ?? null,
        ]);

        $announce->created($task, $data['device'] ?? null);

        return back();
    }

    /**
     * Ticking one off, handing it to the other person, or correcting what it
     * says — each on its own, so a screen can send one without restating the
     * rest of the row.
     */
    public function update(Request $request, Task $task): RedirectResponse
    {
        $this->authoriseOwnership($request, $task);

        $data = $request->validate([
            'done' => ['sometimes', 'boolean'],
            'title' => ['sometimes', 'string', 'max:200'],
            'assignee' => ['sometimes', Rule::enum(Assignee::class)],
            'due_on' => ['sometimes', 'nullable', 'date'],
        ]);

        if (array_key_exists('done', $data)) {
            $task->done_at = $data['done'] ? now() : null;
        }

        if (array_key_exists('title', $data)) {
            $task->title = trim($data['title']);
        }

        if (array_key_exists('assignee', $data)) {
            $task->assignee = Assignee::from($data['assignee']);
        }

        if (array_key_exists('due_on', $data)) {
            $task->due_on = $data['due_on'] === null ? null : CarbonImmutable::parse($data['due_on']);
        }

        $task->save();

        return back();
    }

    public function destroy(Request $request, Task $task): RedirectResponse
    {
        $this->authoriseOwnership($request, $task);

        $task->delete();

        return back();
    }

    /**
     * Everything ticked off, in one go. The list is a day's worth of small
     * things; clearing them one at a time is the tidying-up nobody does.
     */
    public function clearDone(Request $request): RedirectResponse
    {
        Task::query()->of($request->user())->whereNotNull('done_at')->delete();

        return back();
    }

    private function authoriseOwnership(Request $request, Task $task): void
    {
        abort_unless($task->user_id === $request->user()->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'assignee' => $task->assignee->value,
            'dueOn' => $task->due_on?->toDateString(),
            'done' => $task->isDone(),
            'overdue' => $task->isOverdue(),
        ];
    }
}
