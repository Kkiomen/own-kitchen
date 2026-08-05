<script setup lang="ts">
withDefaults(
    defineProps<{
        emoji: string;
        name: string;
        /**
         * Drops the fixed emoji column. For a chip sitting in a run of text,
         * where there is no column of names below it to line up with.
         */
        inline?: boolean;
    }>(),
    { inline: false },
);
</script>

<template>
    <span class="inline-flex items-baseline gap-2">
        <!--
            Decorative only. The name sits right beside it, so a screen reader
            announcing "marchewka marchewka" would be noise, not help.
            In a list the emoji gets a fixed column: some are wider than others,
            and a ragged left edge is exactly what a list of forty must not have.
        -->
        <span
            aria-hidden="true"
            :class="inline ? '' : 'w-5 shrink-0 text-center'"
        >
            {{ emoji }}
        </span>
        <span class="min-w-0"
            ><slot>{{ name }}</slot></span
        >
    </span>
</template>
