<script setup lang="ts">
defineProps<{ title: string; subtitle?: string }>();
</script>

<template>
    <!--
      Shared by every screen you can reach while signed out, so they cannot drift
      apart. Centred and narrow: these are phone-first forms.
    -->
    <div
        class="grid min-h-dvh place-items-center bg-paper px-5 py-10 pt-safe pb-safe"
    >
        <main class="w-full max-w-sm">
            <div
                class="mx-auto mb-6 grid h-14 w-14 place-items-center rounded-2xl bg-accent"
                aria-hidden="true"
            >
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="#17191a"
                    stroke-width="1.6"
                    stroke-linecap="round"
                    class="h-7 w-7"
                >
                    <path
                        d="M8 3v7a2 2 0 0 1-2 2 2 2 0 0 1-2-2V3M6 12v9M16 3c-1.5 1.5-2 3-2 5s.5 3 2 3h2V3M18 11v10"
                    />
                </svg>
            </div>

            <h1 class="text-center text-2xl font-semibold text-ink">
                {{ title }}
            </h1>
            <p
                v-if="subtitle"
                class="mt-2 text-center text-sm leading-relaxed text-ink-muted"
            >
                {{ subtitle }}
            </p>

            <div class="mt-8">
                <slot />
            </div>
        </main>
    </div>
</template>
