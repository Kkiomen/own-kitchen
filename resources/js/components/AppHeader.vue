<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import AppIcon from '@/components/AppIcon.vue';
import AppNav from '@/components/AppNav.vue';
import type { NavSection } from '@/lib/navigation';
import { logout } from '@/routes';
import { create as linkDevice } from '@/routes/device-link';

/**
 * The one header every screen wears. It exists because the pages each grew
 * their own bar with a different set of links, and a screen that forgot one
 * became a dead end — there was no way back to the recipes from the plan.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        /** Which of the four sections this screen belongs to. */
        current: NavSection;
        /** Shown small beside the title; a total, never a decoration. */
        count?: number;
        /**
         * A screen reached from another one gets an explicit way back, on top
         * of the section row. The label names the destination, not "wstecz".
         */
        back?: { href: string; label: string };
    }>(),
    { count: undefined, back: undefined },
);

function signOut(): void {
    router.post(logout.url());
}
</script>

<template>
    <header
        class="sticky top-0 z-20 border-b border-rule bg-paper/95 pt-safe backdrop-blur"
    >
        <!--
          One measure for every screen: the header, the content and the bottom
          bar line up wherever you are, so switching sections does not shift the
          page under the pointer.
        -->
        <div class="mx-auto max-w-6xl px-4 sm:px-8">
            <div class="flex items-center justify-between gap-3 pt-3 pb-3">
                <div class="flex min-w-0 items-center gap-1">
                    <Link
                        v-if="props.back"
                        :href="props.back.href"
                        class="-ml-2 flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-ink-muted hover:text-ink"
                        :title="props.back.label"
                        :aria-label="`Wróć do: ${props.back.label}`"
                    >
                        <AppIcon name="chevronLeft" class="h-5 w-5" />
                    </Link>

                    <div class="min-w-0">
                        <h1
                            class="truncate text-xl font-semibold text-ink sm:text-2xl"
                        >
                            {{ props.title }}
                            <span
                                v-if="props.count !== undefined"
                                class="ml-1 text-sm font-normal text-ink-muted tabular-nums"
                            >
                                {{ props.count }}
                            </span>
                        </h1>
                        <slot name="subtitle" />
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-1">
                    <!-- On a phone the same four sit in the bottom bar instead. -->
                    <AppNav
                        :current="props.current"
                        variant="inline"
                        class="mr-1 hidden sm:flex"
                    />

                    <slot name="actions" />

                    <Link
                        :href="linkDevice.url()"
                        class="flex h-11 w-11 items-center justify-center rounded-full text-ink-muted hover:text-ink"
                        title="Dodaj urządzenie"
                        aria-label="Dodaj urządzenie"
                    >
                        <AppIcon name="qr" class="h-5 w-5" />
                    </Link>

                    <button
                        type="button"
                        class="flex h-11 w-11 items-center justify-center rounded-full text-ink-muted hover:text-ink"
                        title="Wyloguj"
                        aria-label="Wyloguj"
                        @click="signOut"
                    >
                        <AppIcon name="signOut" class="h-5 w-5" />
                    </button>
                </div>
            </div>

            <!-- Anything the screen keeps within reach: a search box, a chip row. -->
            <slot />
        </div>
    </header>
</template>
