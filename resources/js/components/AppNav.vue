<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import type { IconName } from '@/lib/icons';
import type { NavSection } from '@/lib/navigation';
import { home } from '@/routes';
import { index as mealPlan } from '@/routes/meal-plan';
import { index as pantry } from '@/routes/pantry';
import { index as shopping } from '@/routes/shopping';
import { index as tasks } from '@/routes/tasks';

const props = withDefaults(
    defineProps<{
        current: NavSection;
        /**
         * `bar` is the phone's fixed bottom row; `inline` is the pill row that
         * takes its place in the header once there is width for it.
         */
        variant?: 'bar' | 'inline';
    }>(),
    { variant: 'bar' },
);

const SECTIONS: {
    key: NavSection;
    label: string;
    icon: IconName;
    url: string;
}[] = [
    { key: 'recipes', label: 'Przepisy', icon: 'servings', url: home.url() },
    { key: 'plan', label: 'Plan', icon: 'calendar', url: mealPlan.url() },
    { key: 'tasks', label: 'Zadania', icon: 'checklist', url: tasks.url() },
    { key: 'pantry', label: 'Kuchnia', icon: 'ingredients', url: pantry.url() },
    { key: 'shopping', label: 'Zakupy', icon: 'cart', url: shopping.url() },
];

/*
 * How many tasks are still open, from the shared props.
 *
 * The whole point of writing "podjedź po chleb" down is not having to remember
 * it, so the number has to be visible from wherever you are — a count only the
 * tasks screen shows is a reminder you have to go looking for. Anything from ten
 * up reads as "10+": past that it stops being a nudge and starts being a mood.
 */
const openTasks = computed<number>(() => {
    const count = usePage().props.openTasks;

    return typeof count === 'number' ? count : 0;
});

const badge = computed<string>(() =>
    openTasks.value > 9 ? '9+' : String(openTasks.value),
);
</script>

<template>
    <!--
      Bottom bar: the destination row a phone user reaches for with a thumb.
      Hidden from sm up, where the header carries the same four as pills.
    -->
    <nav
        v-if="props.variant === 'bar'"
        class="fixed inset-x-0 bottom-0 z-30 border-t border-rule bg-paper/95 pb-safe backdrop-blur sm:hidden"
        aria-label="Nawigacja"
    >
        <ul class="mx-auto flex max-w-6xl">
            <li v-for="section in SECTIONS" :key="section.key" class="flex-1">
                <Link
                    :href="section.url"
                    class="flex h-14 flex-col items-center justify-center gap-0.5 text-xs"
                    :class="
                        section.key === props.current
                            ? 'font-medium text-accent-strong'
                            : 'text-ink-muted'
                    "
                    :aria-current="
                        section.key === props.current ? 'page' : undefined
                    "
                >
                    <span class="relative">
                        <AppIcon :name="section.icon" class="h-5 w-5" />
                        <!--
                            On the icon rather than beside the label: the cell is
                            78px wide and a number after "Zadania" would not fit
                            without shrinking every other label to match.
                        -->
                        <span
                            v-if="section.key === 'tasks' && openTasks > 0"
                            class="absolute -top-1.5 -right-2.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-accent px-1 text-[10px] font-semibold text-ink tabular-nums"
                            :aria-label="`${openTasks} do zrobienia`"
                        >
                            {{ badge }}
                        </span>
                    </span>
                    {{ section.label }}
                </Link>
            </li>
        </ul>
    </nav>

    <nav v-else class="flex items-center gap-1" aria-label="Nawigacja">
        <Link
            v-for="section in SECTIONS"
            :key="section.key"
            :href="section.url"
            class="flex h-11 items-center gap-2 rounded-full border px-4 text-sm transition-colors"
            :class="
                section.key === props.current
                    ? 'border-accent bg-accent text-ink'
                    : 'border-rule-strong text-ink-muted hover:text-ink'
            "
            :aria-current="section.key === props.current ? 'page' : undefined"
        >
            <AppIcon :name="section.icon" />
            {{ section.label }}
            <span
                v-if="section.key === 'tasks' && openTasks > 0"
                class="flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-semibold tabular-nums"
                :class="
                    section.key === props.current
                        ? 'bg-ink text-paper'
                        : 'bg-accent text-ink'
                "
                :aria-label="`${openTasks} do zrobienia`"
            >
                {{ badge }}
            </span>
        </Link>
    </nav>
</template>
