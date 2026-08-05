<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import ApplianceIcon from '@/components/ApplianceIcon.vue';
import IngredientLabel from '@/components/IngredientLabel.vue';
import { formatRemaining, useCookTimer } from '@/lib/cook-timer';
import {
    actionLabel,
    applianceLabel,
    formatAmount,
    formatDuration,
} from '@/lib/recipe-display';
import { useWakeLock } from '@/lib/wake-lock';
import { show as showRecipe } from '@/routes/recipes';
import type { CookRecipe, CookStep } from '@/types/recipe';

const props = defineProps<{ recipe: CookRecipe }>();

const index = ref(0);

const step = computed<CookStep | undefined>(
    () => props.recipe.steps[index.value],
);
const isLast = computed<boolean>(
    () => index.value >= props.recipe.steps.length - 1,
);
const progress = computed<number>(() =>
    props.recipe.steps.length === 0
        ? 0
        : ((index.value + 1) / props.recipe.steps.length) * 100,
);

/*
 * Destructured, because refs nested in an object are not unwrapped in a
 * template — `timer.state.value` would have to be written by hand everywhere and
 * would silently render nothing the day somebody forgot the `.value`.
 */
const {
    state: cook,
    remaining,
    finished,
    running,
    start,
    pause,
    resume,
    extend,
    stop,
} = useCookTimer(props.recipe.slug);

useWakeLock();

/** The clock belongs to a step, and you walk away from it while it runs. */
const timerIsForThisStep = computed<boolean>(
    () => cook.value?.stepPosition === step.value?.position,
);

const timerStepIndex = computed<number>(() =>
    props.recipe.steps.findIndex(
        (candidate) => candidate.position === cook.value?.stepPosition,
    ),
);

function go(to: number): void {
    index.value = Math.min(Math.max(to, 0), props.recipe.steps.length - 1);
    window.scrollTo({ top: 0 });
}

function startTimer(): void {
    const current = step.value;

    if (current === undefined || current.durationSeconds === null) {
        return;
    }

    start({
        position: current.position,
        label: `Krok ${current.position + 1}: ${current.instruction}`.slice(
            0,
            120,
        ),
        seconds: current.durationSeconds,
    });
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowRight') {
        go(index.value + 1);

        return;
    }

    if (event.key === 'ArrowLeft') {
        go(index.value - 1);
    }
}

onMounted(() => {
    document.addEventListener('keydown', onKeydown);

    /*
     * Coming back to a dish that was already on: the timer is restored from its
     * deadline, so the step it belongs to is where the cook left off — not step
     * one, which would be the app forgetting what the pot is doing.
     */
    if (timerStepIndex.value >= 0) {
        index.value = timerStepIndex.value;
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <Head :title="`Gotujemy: ${props.recipe.title}`" />

    <div class="flex min-h-dvh flex-col bg-paper pb-safe">
        <header class="border-b border-rule px-4 pt-safe sm:px-8">
            <div class="mx-auto flex max-w-2xl items-center gap-3 py-3">
                <Link
                    :href="showRecipe.url(props.recipe.slug)"
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-ink-muted hover:bg-paper-sunk"
                    aria-label="Zakończ gotowanie"
                >
                    <AppIcon name="clear" />
                </Link>

                <div class="min-w-0 flex-1">
                    <p class="truncate font-medium text-ink">
                        {{ props.recipe.title }}
                    </p>
                    <p class="label-caps text-ink-faint">
                        Krok {{ index + 1 }} z {{ props.recipe.steps.length }}
                        <!-- Said out loud when it is not what the source wrote:
                             the amounts below are for this number, and a cook
                             halfway through a recipe must not have to wonder. -->
                        <span
                            v-if="
                                props.recipe.isScaled && props.recipe.servings
                            "
                            class="text-accent-strong"
                        >
                            · na {{ props.recipe.servings }} porcji
                        </span>
                        <span v-else-if="props.recipe.servingsLabel">
                            · {{ props.recipe.servingsLabel }}
                        </span>
                    </p>
                </div>
            </div>

            <div
                class="mx-auto h-1 max-w-2xl overflow-hidden rounded-full bg-paper-sunk"
            >
                <div
                    class="h-full rounded-full bg-accent transition-[width] duration-300"
                    :style="{ width: `${progress}%` }"
                />
            </div>
        </header>

        <main
            v-if="step"
            class="mx-auto w-full max-w-2xl flex-1 px-4 py-8 sm:px-8"
        >
            <p v-if="step.section" class="mb-2 label-caps text-accent-strong">
                {{ step.section }}
            </p>

            <!-- What, where, how hot: the row a Thermomix screen leads with. -->
            <div
                v-if="step.action || step.appliance || step.temperatureCelsius"
                class="mb-5 flex flex-wrap items-center gap-2"
            >
                <span
                    v-if="step.action"
                    class="rounded-full bg-accent-soft px-3 py-1 text-sm font-medium text-accent-strong"
                >
                    {{ actionLabel(step.action) }}
                </span>
                <span
                    v-if="step.appliance"
                    class="flex items-center gap-1.5 rounded-full border border-rule-strong px-3 py-1 text-sm text-ink-muted"
                >
                    <ApplianceIcon :appliance="step.appliance" />
                    {{ applianceLabel(step.appliance) }}
                </span>
                <span
                    v-if="step.temperatureCelsius"
                    class="rounded-full border border-rule-strong px-3 py-1 text-sm text-ink-muted tabular-nums"
                >
                    {{ step.temperatureCelsius }}°C
                </span>
            </div>

            <!-- Read at arm's length, over a hob, in a hurry. -->
            <p class="text-2xl leading-relaxed text-ink">
                {{ step.instruction }}
            </p>

            <section v-if="step.uses.length > 0" class="mt-8">
                <h2 class="mb-3 label-caps text-ink-faint">Do tego kroku</h2>
                <ul
                    class="divide-y divide-rule rounded-2xl border border-rule bg-paper-raised"
                >
                    <li
                        v-for="line in step.uses"
                        :key="line.id"
                        class="flex items-baseline justify-between gap-4 px-4 py-3"
                    >
                        <span class="min-w-0 text-ink">
                            <IngredientLabel
                                v-if="line.name"
                                :emoji="line.emoji"
                                :name="line.name"
                            />
                            <!-- Never resolved to a product: the original wording
                                 is all there is, and it still has to be cookable. -->
                            <span v-else>{{ line.rawText }}</span>
                            <span
                                v-if="line.note"
                                class="ml-1 text-sm text-ink-faint"
                            >
                                ({{ line.note }})
                            </span>
                        </span>
                        <span
                            v-if="formatAmount(line)"
                            class="shrink-0 font-medium text-ink tabular-nums"
                        >
                            {{ formatAmount(line) }}
                        </span>
                    </li>
                </ul>
            </section>

            <!-- The wait. Offered only when the step itself stated one. -->
            <section
                v-if="step.durationSeconds"
                class="mt-8 rounded-2xl border border-rule bg-paper-raised p-5"
            >
                <template v-if="timerIsForThisStep && cook">
                    <p
                        class="text-center text-6xl font-semibold text-ink tabular-nums"
                        :class="finished ? 'text-accent-strong' : ''"
                        role="timer"
                        aria-live="off"
                    >
                        {{ formatRemaining(remaining) }}
                    </p>

                    <p
                        v-if="finished"
                        class="mt-2 animate-confirmed text-center font-medium text-accent-strong"
                    >
                        Gotowe!
                    </p>
                    <p v-else class="mt-2 text-center text-sm text-ink-muted">
                        Możesz zminimalizować apkę — minutnik leci dalej.
                    </p>

                    <div class="mt-5 flex flex-wrap justify-center gap-2">
                        <button
                            v-if="running"
                            type="button"
                            class="flex h-11 items-center gap-2 rounded-full border border-rule-strong px-4 text-sm text-ink"
                            @click="pause"
                        >
                            <AppIcon name="pause" />
                            Pauza
                        </button>
                        <button
                            v-else-if="!finished"
                            type="button"
                            class="flex h-11 items-center gap-2 rounded-full bg-accent px-4 text-sm font-medium text-ink"
                            @click="resume"
                        >
                            <AppIcon name="play" />
                            Wznów
                        </button>

                        <button
                            type="button"
                            class="flex h-11 items-center gap-2 rounded-full border border-rule-strong px-4 text-sm text-ink"
                            @click="extend(60)"
                        >
                            <AppIcon name="plus" />
                            1 min
                        </button>

                        <button
                            type="button"
                            class="h-11 rounded-full border border-rule-strong px-4 text-sm text-ink-muted"
                            @click="stop"
                        >
                            {{ finished ? 'Wyłącz' : 'Anuluj' }}
                        </button>
                    </div>
                </template>

                <button
                    v-else
                    type="button"
                    class="flex h-13 w-full items-center justify-center gap-2 rounded-full bg-accent text-base font-semibold text-ink"
                    @click="startTimer"
                >
                    <AppIcon name="timer" />
                    Włącz minutnik · {{ formatDuration(step.durationSeconds) }}
                </button>
            </section>
        </main>

        <!--
            A pot left boiling on an earlier step. The cook has moved on, so the
            clock follows them down the screen rather than being left behind on a
            step nobody is looking at.
        -->
        <button
            v-if="cook && !timerIsForThisStep"
            type="button"
            class="sticky bottom-20 mx-auto mb-2 flex h-11 items-center gap-2 rounded-full border border-accent bg-paper px-4 text-sm text-ink shadow-sm"
            @click="go(timerStepIndex)"
        >
            <AppIcon name="timer" class="text-accent-strong" />
            <span class="font-medium tabular-nums">
                {{ formatRemaining(remaining) }}
            </span>
            <span class="text-ink-muted">
                krok {{ cook.stepPosition + 1 }}
            </span>
        </button>

        <nav
            class="sticky bottom-0 border-t border-rule bg-paper/95 px-4 pb-safe backdrop-blur sm:px-8"
        >
            <div class="mx-auto flex max-w-2xl gap-3 py-3">
                <button
                    type="button"
                    :disabled="index === 0"
                    class="flex h-13 items-center gap-2 rounded-full border border-rule-strong px-5 text-ink disabled:opacity-40"
                    @click="go(index - 1)"
                >
                    <AppIcon name="chevronLeft" />
                    Wstecz
                </button>

                <button
                    v-if="!isLast"
                    type="button"
                    class="flex h-13 flex-1 items-center justify-center gap-2 rounded-full bg-accent text-base font-semibold text-ink"
                    @click="go(index + 1)"
                >
                    Dalej
                    <AppIcon name="chevronRight" />
                </button>

                <Link
                    v-else
                    :href="showRecipe.url(props.recipe.slug)"
                    class="flex h-13 flex-1 items-center justify-center gap-2 rounded-full bg-accent text-base font-semibold text-ink"
                >
                    <AppIcon name="check" />
                    Gotowe
                </Link>
            </div>
        </nav>
    </div>
</template>
