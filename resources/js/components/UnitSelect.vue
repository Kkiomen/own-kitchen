<script setup lang="ts">
import { computed } from 'vue';
import type { PantryUnit, UnitsOfProduct } from '@/lib/pantry-units';
import { unitGroupsFor } from '@/lib/pantry-units';

/**
 * Picking a measure for one product.
 *
 * One component rather than two, because the kitchen form and the fridge photo
 * are two ways to state the same thing and a picker that narrowed the list on
 * one screen but not the other would be worse than one that never narrowed it —
 * whichever screen you happened to use would decide whether the kitchen made
 * sense.
 *
 * The measures this product is actually used in come first; the rest of the
 * vocabulary stays reachable underneath, because a shelf occasionally holds
 * something no recipe ever measured that way.
 */
const props = defineProps<{
    units: PantryUnit[];
    /** The product being measured, or null before one is chosen. */
    product: UnitsOfProduct | null;
}>();

const model = defineModel<number | null>({ required: true });

const groups = computed(() => unitGroupsFor(props.units, props.product));
</script>

<template>
    <select v-model="model">
        <!-- No measure at all: "some, amount unknown", which every screen here
             treats as a real answer rather than a gap. -->
        <option :value="null">—</option>
        <template v-for="group in groups" :key="group.label">
            <optgroup v-if="group.label !== ''" :label="group.label">
                <option
                    v-for="unit in group.units"
                    :key="unit.id"
                    :value="unit.id"
                >
                    {{ unit.name }}
                </option>
            </optgroup>
            <!-- A product the catalogue has never measured has no opinion to
                 group by, so it gets one plain list. -->
            <template v-else>
                <option
                    v-for="unit in group.units"
                    :key="unit.id"
                    :value="unit.id"
                >
                    {{ unit.name }}
                </option>
            </template>
        </template>
    </select>
</template>
