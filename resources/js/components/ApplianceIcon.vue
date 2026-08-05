<script setup lang="ts">
import { computed } from 'vue';
import { applianceLabel } from '@/lib/recipe-display';

const props = withDefaults(
    defineProps<{
        appliance: string | null;
        /** Decorative next to a visible label; give it a name when it stands alone. */
        labelled?: boolean;
    }>(),
    { labelled: false },
);

/**
 * Drawn rather than typed: emoji render differently on every platform, sit off
 * the text baseline, and cannot take the ink colour. These are one stroke weight
 * on one 24-unit grid, so a row of them looks like a set.
 */
const PATHS: Record<string, string> = {
    pan: 'M3 14h12a0 0 0 0 1 0 0v1a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5v-1zM15 15l6-3',
    pot: 'M4 9h14v6a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4V9zM2 9h2M18 9h2M7 6V4M15 6V4',
    oven: 'M4 3h16v18H4zM4 9h16M8 6h.01M12 6h.01M8 14h8',
    air_fryer:
        'M6 3h12v6H6zM5 9h14v9a3 3 0 0 1-3 3H8a3 3 0 0 1-3-3V9zM9 6h6M10 14h4',
    mixer: 'M6 3h10l-1 9H7zM11 12v6M8 21h6M18 6h2v4',
    blender:
        'M7 3h10l-1.5 10h-7zM9 13v5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2v-5M8 7h8',
    bowl: 'M3 11h18a9 9 0 0 1-9 9 9 9 0 0 1-9-9zM8 7c0-2 2-2 2-4M14 7c0-2 2-2 2-4',
    baking_tin:
        'M12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16zM12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z',
    baking_tray: 'M3 7h18v10H3zM6 7v10M18 7v10',
    fridge: 'M5 2h14v20H5zM5 10h14M8 6v2M8 13v3',
    freezer:
        'M12 3v18M4 8l16 8M20 8L4 16M12 3l-2 2M12 3l2 2M12 21l-2-2M12 21l2-2',
    microwave: 'M2 5h20v14H2zM15 5v14M5 9h6M5 13h6M18 12h.01',
    grater: 'M8 2h5l4 20H4zM9 7h.01M12 7h.01M9 11h.01M12 11h.01M10 15h.01',
    knife: 'M4 20L14 10M14 10l6-7-3 9-3 3zM4 20l-1 1',
};

const FALLBACK = 'M12 5v14M5 12h14';

const path = computed((): string => {
    if (props.appliance === null) {
        return FALLBACK;
    }

    return PATHS[props.appliance] ?? FALLBACK;
});

const label = computed((): string => applianceLabel(props.appliance));
</script>

<template>
    <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.4"
        stroke-linecap="round"
        stroke-linejoin="round"
        class="h-4 w-4 shrink-0"
        :aria-hidden="props.labelled ? undefined : 'true'"
        :role="props.labelled ? 'img' : undefined"
        :aria-label="props.labelled ? label : undefined"
    >
        <path :d="path" />
    </svg>
</template>
