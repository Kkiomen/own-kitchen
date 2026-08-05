<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import AppHeader from '@/components/AppHeader.vue';
import AppIcon from '@/components/AppIcon.vue';
import AppNav from '@/components/AppNav.vue';
import {
    currentState,
    deviceId,
    disablePush,
    enablePush,
    pushSupported,
} from '@/lib/push';
import type { PushState } from '@/lib/push';
import { clearDone, destroy, store, update } from '@/routes/tasks';

interface TaskEntry {
    id: number;
    title: string;
    assignee: string;
    dueOn: string | null;
    done: boolean;
    /** The day has passed — not the hour; a task carries a date. */
    overdue: boolean;
}

interface AssigneeOption {
    value: string;
    label: string;
    shortLabel: string;
}

const props = defineProps<{
    tasks: TaskEntry[];
    assignees: AssigneeOption[];
    /** The server's today, so "dziś" and the overdue marker agree. */
    today: string;
    /**
     * The VAPID public key, or null on an installation nobody generated one for.
     * Null hides the switch rather than showing one that could only fail.
     */
    pushKey: string | null;
}>();

/*
 * Who is being looked at. "Wszystkie" first and by default: the list is short
 * and shared, and a filter that hid what the other person still has to do would
 * defeat the point of writing it down where both can see it.
 */
const who = ref<string | null>(null);

const form = useForm({
    title: '',
    assignee: 'both',
    due_on: null as string | null,
    /*
     * Which phone is typing, so the notification skips it. Read once here rather
     * than per submit: it is a constant for the life of the installation, and a
     * localStorage hit on every keystroke-ending tap is noise.
     */
    device: pushSupported() ? deviceId() : null,
});

/*
 * Whether this phone gets told. Asked of the browser on mount rather than sent
 * from the server: a permission revoked in the phone's own settings leaves our
 * row behind, and a switch drawn from the row would read "on" beside a phone
 * that can no longer ring.
 */
const push = ref<PushState>('unsupported');
const pushBusy = ref(false);

onMounted(() => {
    void currentState().then((state) => {
        push.value = state;
    });
});

async function togglePush(): Promise<void> {
    if (pushBusy.value || props.pushKey === null) {
        return;
    }

    pushBusy.value = true;

    try {
        push.value =
            push.value === 'on'
                ? await disablePush()
                : await enablePush(props.pushKey);
    } catch {
        // Left as it was: the switch says what the phone actually is, and
        // pretending otherwise is the one thing it must not do.
        push.value = await currentState();
    } finally {
        pushBusy.value = false;
    }
}

function shortLabel(value: string): string {
    return (
        props.assignees.find((option) => option.value === value)?.shortLabel ??
        ''
    );
}

const open = computed<TaskEntry[]>(() =>
    props.tasks.filter(
        (task) =>
            !task.done && (who.value === null || task.assignee === who.value),
    ),
);

const done = computed<TaskEntry[]>(() =>
    props.tasks.filter(
        (task) =>
            task.done && (who.value === null || task.assignee === who.value),
    ),
);

/** How many are open per person, so the chips are worth tapping. */
function openFor(value: string | null): number {
    return props.tasks.filter(
        (task) => !task.done && (value === null || task.assignee === value),
    ).length;
}

/** Tomorrow, from the server's today rather than the phone's clock. */
const tomorrow = computed<string>(() => {
    const date = new Date(`${props.today}T00:00:00`);
    date.setDate(date.getDate() + 1);

    return date.toISOString().slice(0, 10);
});

/**
 * "dziś" and "jutro" as one tap each, and tapping the chosen one again clears
 * it. A date picker for "kup chleb" is a form; these two cover the day a task
 * is written on almost every time.
 */
function toggleDue(date: string): void {
    form.due_on = form.due_on === date ? null : date;
}

function dueLabel(date: string | null): string | null {
    if (date === null) {
        return null;
    }

    if (date === props.today) {
        return 'dziś';
    }

    if (date === tomorrow.value) {
        return 'jutro';
    }

    // Day and month: the year is noise on a list nobody plans months ahead on.
    const [, month, day] = date.split('-');

    return `${Number(day)}.${Number(month)}`;
}

function submit(): void {
    if (form.title.trim() === '') {
        return;
    }

    form.post(store.url(), {
        preserveScroll: true,
        preserveState: true,
        // The assignee and the day stay put: writing down three things for the
        // same person is the common case, and re-picking each time is friction.
        onSuccess: () => {
            form.title = '';
        },
    });
}

/** Ticking one off must be instant — it is done standing in a doorway. */
function toggle(task: TaskEntry): void {
    router.patch(
        update.url(task.id),
        { done: !task.done },
        { preserveScroll: true, preserveState: true },
    );
}

/** Handing it over: the same task, the other person. */
function reassign(task: TaskEntry, assignee: string): void {
    router.patch(
        update.url(task.id),
        { assignee },
        { preserveScroll: true, preserveState: true },
    );
}

function remove(task: TaskEntry): void {
    router.delete(destroy.url(task.id), {
        preserveScroll: true,
        preserveState: true,
    });
}

function clearFinished(): void {
    router.delete(clearDone.url(), { preserveScroll: true });
}
</script>

<template>
    <Head title="Zadania" />

    <div class="min-h-dvh bg-paper pb-safe">
        <AppHeader title="Zadania" current="tasks" :count="openFor(null)" />

        <main class="mx-auto max-w-3xl px-4 py-6 pb-24 sm:px-8 sm:pb-6">
            <!--
                Writing one down is the whole screen, so the form is the first
                thing on it rather than behind a button: this gets opened
                mid-conversation, one-handed.
            -->
            <form
                class="mb-6 rounded-2xl border border-rule bg-paper-raised p-3"
                @submit.prevent="submit"
            >
                <label for="task-title" class="sr-only"
                    >Co jest do zrobienia</label
                >
                <input
                    id="task-title"
                    v-model="form.title"
                    type="text"
                    maxlength="200"
                    placeholder="Co jest do zrobienia? np. podjechać po chleb"
                    class="h-13 w-full rounded-xl border border-rule-strong px-4 text-base text-ink placeholder:text-ink-faint focus:border-accent focus:outline-none"
                />

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <button
                        v-for="option in assignees"
                        :key="option.value"
                        type="button"
                        class="h-11 rounded-full border px-4 text-sm transition-colors"
                        :class="
                            form.assignee === option.value
                                ? 'border-accent bg-accent font-medium text-ink'
                                : 'border-rule-strong text-ink-muted hover:text-ink'
                        "
                        :aria-pressed="form.assignee === option.value"
                        @click="form.assignee = option.value"
                    >
                        {{ option.label }}
                    </button>

                    <span class="mx-1 h-6 w-px bg-rule" aria-hidden="true" />

                    <button
                        type="button"
                        class="h-11 rounded-full border px-4 text-sm transition-colors"
                        :class="
                            form.due_on === today
                                ? 'border-accent bg-accent font-medium text-ink'
                                : 'border-rule-strong text-ink-muted hover:text-ink'
                        "
                        :aria-pressed="form.due_on === today"
                        @click="toggleDue(today)"
                    >
                        Dziś
                    </button>
                    <button
                        type="button"
                        class="h-11 rounded-full border px-4 text-sm transition-colors"
                        :class="
                            form.due_on === tomorrow
                                ? 'border-accent bg-accent font-medium text-ink'
                                : 'border-rule-strong text-ink-muted hover:text-ink'
                        "
                        :aria-pressed="form.due_on === tomorrow"
                        @click="toggleDue(tomorrow)"
                    >
                        Jutro
                    </button>

                    <button
                        type="submit"
                        :disabled="form.processing || form.title.trim() === ''"
                        class="ml-auto flex h-11 items-center gap-2 rounded-full bg-accent px-5 text-sm font-semibold text-ink disabled:opacity-50"
                    >
                        <AppIcon name="plus" />
                        Dopisz
                    </button>
                </div>

                <p v-if="form.errors.title" class="mt-2 text-sm text-flag">
                    {{ form.errors.title }}
                </p>
            </form>

            <!-- Whose list to look at. Counts, or the chips are a guess. -->
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="h-11 rounded-full border px-4 text-sm transition-colors"
                    :class="
                        who === null
                            ? 'border-accent bg-accent font-medium text-ink'
                            : 'border-rule-strong text-ink-muted hover:text-ink'
                    "
                    @click="who = null"
                >
                    Wszystkie
                    <span class="ml-1 tabular-nums">{{ openFor(null) }}</span>
                </button>
                <button
                    v-for="option in assignees"
                    :key="option.value"
                    type="button"
                    class="h-11 rounded-full border px-4 text-sm transition-colors"
                    :class="
                        who === option.value
                            ? 'border-accent bg-accent font-medium text-ink'
                            : 'border-rule-strong text-ink-muted hover:text-ink'
                    "
                    @click="who = option.value"
                >
                    {{ option.label }}
                    <span class="ml-1 tabular-nums">
                        {{ openFor(option.value) }}
                    </span>
                </button>

                <!--
                    Whether this phone gets told when the other one writes
                    something down. It lives here rather than in a settings
                    screen for the reason the shop picker sits on the plan: a
                    switch three taps away is one nobody remembers the state of,
                    and this one is the difference between the list working and
                    the list being another thing to remember to check.

                    Hidden entirely where push cannot work — no keys generated,
                    or a browser without the API — rather than shown disabled.
                -->
                <button
                    v-if="pushKey !== null && push !== 'unsupported'"
                    type="button"
                    :disabled="pushBusy || push === 'blocked'"
                    class="ml-auto flex h-11 items-center gap-2 rounded-full border px-4 text-sm transition-colors disabled:opacity-50"
                    :class="
                        push === 'on'
                            ? 'border-accent bg-accent-soft font-medium text-accent-strong'
                            : 'border-rule-strong text-ink-muted hover:text-ink'
                    "
                    :aria-pressed="push === 'on'"
                    @click="togglePush"
                >
                    <AppIcon :name="push === 'on' ? 'bell' : 'bellOff'" />
                    <span class="sr-only sm:not-sr-only">
                        {{ push === 'on' ? 'Powiadomienia' : 'Powiadom mnie' }}
                    </span>
                </button>
            </div>

            <!--
                Only the phone's own settings can undo a refusal, so the app
                cannot offer a button here — it can only say where to go.
            -->
            <p v-if="push === 'blocked'" class="mb-4 text-sm text-ink-muted">
                Powiadomienia są zablokowane w ustawieniach telefonu — trzeba je
                włączyć tam, dla tej aplikacji.
            </p>

            <p
                v-if="open.length === 0"
                class="rounded-2xl border border-dashed border-rule px-4 py-12 text-center text-sm text-ink-muted"
            >
                Nic do zrobienia. Wpisz coś wyżej, gdy padnie „a zrób jeszcze…”.
            </p>

            <ul
                v-else
                class="divide-y divide-rule rounded-2xl border border-rule bg-paper-raised"
            >
                <li
                    v-for="task in open"
                    :key="task.id"
                    class="flex flex-wrap items-center gap-1 pr-2"
                >
                    <!-- The whole row ticks it off: this is tapped in a hurry. -->
                    <button
                        type="button"
                        class="order-1 flex min-h-13 flex-1 items-center gap-3 py-3 pl-4 text-left"
                        :aria-pressed="task.done"
                        @click="toggle(task)"
                    >
                        <span
                            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md border border-rule-strong"
                        />

                        <span class="min-w-0 flex-1">
                            <span class="block text-ink">{{ task.title }}</span>
                            <span
                                class="mt-0.5 flex flex-wrap items-center gap-2 text-xs"
                            >
                                <span class="text-ink-muted">
                                    {{ shortLabel(task.assignee) }}
                                </span>
                                <span
                                    v-if="task.dueOn"
                                    :class="
                                        task.overdue
                                            ? 'font-medium text-flag'
                                            : 'text-ink-muted'
                                    "
                                >
                                    {{ dueLabel(task.dueOn) }}
                                </span>
                            </span>
                        </span>
                    </button>

                    <!--
                        Handing it over without opening anything: "zrób to ty"
                        is half the conversations this screen exists for.
                    -->
                    <div class="order-3 flex items-center gap-1 sm:order-2">
                        <button
                            v-for="option in assignees"
                            :key="option.value"
                            type="button"
                            class="h-11 rounded-full px-2.5 text-xs transition-colors"
                            :class="
                                task.assignee === option.value
                                    ? 'bg-accent-soft font-medium text-accent-strong'
                                    : 'text-ink-faint hover:bg-paper-sunk hover:text-ink'
                            "
                            :aria-label="`${option.label}: ${task.title}`"
                            :aria-pressed="task.assignee === option.value"
                            @click="reassign(task, option.value)"
                        >
                            {{ option.shortLabel }}
                        </button>
                    </div>

                    <button
                        type="button"
                        class="order-2 flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-ink-faint hover:bg-paper-sunk hover:text-ink sm:order-3"
                        :aria-label="`Usuń: ${task.title}`"
                        @click="remove(task)"
                    >
                        <AppIcon name="clear" />
                    </button>
                </li>
            </ul>

            <!--
                Kept rather than deleted on ticking, so "co dziś zrobiliśmy" is
                answerable and a mistaken tap is one tap back.
            -->
            <section v-if="done.length > 0" class="mt-8">
                <div class="mb-2 flex items-center justify-between gap-3">
                    <h2 class="label-caps text-ink-faint">
                        Zrobione ({{ done.length }})
                    </h2>
                    <button
                        type="button"
                        class="flex h-11 items-center gap-1.5 text-sm text-ink-muted hover:text-ink"
                        @click="clearFinished"
                    >
                        <AppIcon name="clear" />
                        Wyczyść
                    </button>
                </div>

                <ul
                    class="divide-y divide-rule rounded-2xl border border-rule bg-paper-raised"
                >
                    <li
                        v-for="task in done"
                        :key="task.id"
                        class="flex items-center gap-1 pr-2"
                    >
                        <button
                            type="button"
                            class="flex min-h-13 flex-1 items-center gap-3 py-3 pl-4 text-left"
                            :aria-pressed="task.done"
                            @click="toggle(task)"
                        >
                            <span
                                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md border border-accent-strong bg-accent-strong text-paper"
                            >
                                <AppIcon name="check" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-ink-faint line-through">
                                    {{ task.title }}
                                </span>
                                <span class="block text-xs text-ink-faint">
                                    {{ shortLabel(task.assignee) }}
                                </span>
                            </span>
                        </button>

                        <button
                            type="button"
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-ink-faint hover:bg-paper-sunk hover:text-ink"
                            :aria-label="`Usuń: ${task.title}`"
                            @click="remove(task)"
                        >
                            <AppIcon name="clear" />
                        </button>
                    </li>
                </ul>
            </section>
        </main>

        <AppNav current="tasks" />
    </div>
</template>
