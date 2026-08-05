<script setup lang="ts">
import { computed } from 'vue';
import { ICON_PATHS } from '@/lib/icons';
import type { IconName } from '@/lib/icons';

const props = withDefaults(
    defineProps<{
        name: IconName;
        /** Give it a name only when it stands alone; otherwise it repeats the label. */
        label?: string;
    }>(),
    { label: undefined },
);

const path = computed((): string => ICON_PATHS[props.name]);
</script>

<template>
    <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.5"
        stroke-linecap="round"
        stroke-linejoin="round"
        class="h-4 w-4 shrink-0"
        :aria-hidden="props.label === undefined ? 'true' : undefined"
        :role="props.label === undefined ? undefined : 'img'"
        :aria-label="props.label"
    >
        <path :d="path" />
    </svg>
</template>
