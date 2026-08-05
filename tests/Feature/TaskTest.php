<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Assignee;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Zadania" — the things the two of us ask each other for during the day.
 *
 * The rules worth pinning are about the shared account: a task belongs to the
 * household and says who it is *for*, which is not the same as who owns it.
 */
class TaskTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_a_task_can_be_written_down_for_the_other_person(): void
    {
        $this->actingAs($this->user)
            ->post(route('tasks.store'), [
                'title' => 'Podjechać po chleb',
                'assignee' => 'him',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $task = Task::query()->firstOrFail();

        $this->assertSame('Podjechać po chleb', $task->title);
        $this->assertSame(Assignee::Him, $task->assignee);
        $this->assertNull($task->due_on);
        $this->assertFalse($task->isDone());
    }

    public function test_a_task_needs_somebody_to_be_for(): void
    {
        $this->actingAs($this->user)
            ->post(route('tasks.store'), ['title' => 'Coś'])
            ->assertSessionHasErrors('assignee');

        $this->assertSame(0, Task::query()->count());
    }

    public function test_a_task_can_be_ticked_off_and_back_on(): void
    {
        $task = $this->task('Wynieść śmieci');

        $this->actingAs($this->user)->patch(route('tasks.update', $task), ['done' => true]);
        $this->assertTrue($task->refresh()->isDone());

        $this->actingAs($this->user)->patch(route('tasks.update', $task), ['done' => false]);
        $this->assertFalse($task->refresh()->isDone());
    }

    /**
     * "Zrób to ty" is half the conversations this screen exists for, so handing
     * a task over must not mean rewriting it.
     */
    public function test_a_task_can_be_handed_to_the_other_person(): void
    {
        $task = $this->task('Odebrać paczkę', Assignee::Her);

        $this->actingAs($this->user)
            ->patch(route('tasks.update', $task), ['assignee' => 'him'])
            ->assertSessionHasNoErrors();

        $task->refresh();

        $this->assertSame(Assignee::Him, $task->assignee);
        $this->assertSame('Odebrać paczkę', $task->title);
    }

    /**
     * Ticking keeps the row, so the finished ones need a way out — one at a
     * time is the tidying-up nobody does.
     */
    public function test_finished_tasks_can_be_cleared_in_one_go(): void
    {
        $done = $this->task('Zrobione');
        $done->update(['done_at' => now()]);
        $this->task('Jeszcze nie');

        $this->actingAs($this->user)->delete(route('tasks.clear-done'));

        $this->assertSame(['Jeszcze nie'], Task::query()->pluck('title')->all());
    }

    /**
     * A day, not an hour: something due today is not late until tomorrow.
     */
    public function test_a_task_is_late_only_once_its_day_has_passed(): void
    {
        $today = $this->task('Dziś');
        $today->update(['due_on' => now()->toDateString()]);

        $yesterday = $this->task('Wczoraj');
        $yesterday->update(['due_on' => now()->subDay()->toDateString()]);

        $this->assertFalse($today->refresh()->isOverdue());
        $this->assertTrue($yesterday->refresh()->isOverdue());
    }

    public function test_a_finished_task_is_never_late(): void
    {
        $task = $this->task('Spóźnione ale zrobione');
        $task->update(['due_on' => now()->subWeek()->toDateString(), 'done_at' => now()]);

        $this->assertFalse($task->refresh()->isOverdue());
    }

    public function test_the_screen_lists_what_is_open_and_what_is_done(): void
    {
        $this->task('Otwarte');
        $this->task('Zamknięte')->update(['done_at' => now()]);

        $this->actingAs($this->user)
            ->get(route('tasks.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tasks/Index')
                ->has('tasks', 2)
                // Open first: the done ones sink to the bottom of the list.
                ->where('tasks.0.title', 'Otwarte')
                ->where('tasks.0.done', false)
                ->where('tasks.1.done', true)
                ->has('assignees', 3));
    }

    /**
     * The badge is the point of writing anything down here, so it travels with
     * every page rather than only the tasks screen.
     */
    public function test_every_screen_carries_the_count_of_what_is_open(): void
    {
        $this->task('Otwarte');
        $this->task('Zrobione')->update(['done_at' => now()]);

        $this->actingAs($this->user)
            ->get(route('pantry.index'))
            ->assertInertia(fn ($page) => $page->where('openTasks', 1));
    }

    public function test_another_household_cannot_touch_a_task(): void
    {
        $task = $this->task('Nasze');
        $other = User::factory()->create();

        $this->actingAs($other)->patch(route('tasks.update', $task), ['done' => true])->assertNotFound();
        $this->actingAs($other)->delete(route('tasks.destroy', $task))->assertNotFound();

        $this->assertFalse($task->refresh()->isDone());
    }

    /**
     * `zrobione` is declared before `{task}`, or clearing the finished ones
     * would be read as deleting a task with that id.
     */
    public function test_clearing_the_finished_is_not_read_as_a_task_id(): void
    {
        $this->task('Otwarte');

        $this->actingAs($this->user)
            ->delete('/zadania/zrobione')
            ->assertRedirect();

        $this->assertSame(1, Task::query()->count());
    }

    private function task(string $title, Assignee $assignee = Assignee::Both): Task
    {
        return Task::query()->create([
            'user_id' => $this->user->id,
            'title' => $title,
            'assignee' => $assignee,
        ]);
    }
}
