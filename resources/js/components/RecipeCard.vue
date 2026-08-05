<script setup lang="ts">
import { computed } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import ApplianceIcon from '@/components/ApplianceIcon.vue';
import { applianceLabel } from '@/lib/recipe-display';
import type { RecipeSummary } from '@/types/recipe';

const props = withDefaults(
    defineProps<{
        recipe: RecipeSummary;
        /** The lead item spans the row, the way a chapter opens on a full page. */
        featured?: boolean;
        /**
         * Whether this kitchen holds anything at all. With nothing on the
         * shelves every card would read "brakuje 9", which is nine hundred
         * accusations rather than information.
         */
        hasPantry?: boolean;
    }>(),
    { featured: false, hasPantry: false },
);

defineEmits<{ open: [slug: string] }>();

/**
 * What the card says about the shortfall. Only ever shown once there is a
 * kitchen to compare against, and silent past two missing products: beyond that
 * the number stops being a nudge and becomes a shopping list.
 */
const shortfall = computed<{ text: string; cookable: boolean } | null>(() => {
    if (!props.hasPantry) {
        return null;
    }

    if (props.recipe.missing === 0) {
        return { text: 'masz wszystko', cookable: true };
    }

    if (props.recipe.missing <= 2) {
        return { text: `brakuje ${props.recipe.missing}`, cookable: false };
    }

    return null;
});

/**
 * What this dish would use up before it goes off.
 *
 * Named, not counted, and capped at two names: the point of the badge is that
 * you recognise the thing in your own fridge, and a third name turns a nudge
 * into a label nobody reads on a card this size.
 */
const useUp = computed<string | null>(() => {
    const products = props.recipe.expiring;

    if (products.length === 0) {
        return null;
    }

    return products.length <= 2
        ? products.join(', ')
        : `${products.slice(0, 2).join(', ')} +${products.length - 2}`;
});
</script>

<template>
    <article :class="featured ? 'col-span-2' : ''">
        <button
            type="button"
            class="group block w-full text-left"
            @click="$emit('open', recipe.slug)"
        >
            <!--
              The ring keeps a card visible while its photo is still loading: the
              placeholder tint alone is so close to the page that a lazy image
              reads as a hole in the grid.
            -->
            <div
                class="relative overflow-hidden rounded-2xl bg-paper-sunk ring-1 ring-rule ring-inset"
                :class="featured ? 'aspect-16/10' : 'aspect-4/3'"
            >
                <img
                    v-if="recipe.imageUrl"
                    :src="recipe.imageUrl"
                    alt=""
                    loading="lazy"
                    decoding="async"
                    class="h-full w-full object-cover transition-transform duration-300 ease-out group-hover:scale-[1.03]"
                />

                <!-- A device-specific recipe is not interchangeable with an oven
                     one, so it is called out here and not only in the detail. -->
                <span
                    v-if="recipe.appliance"
                    class="absolute top-1.5 left-1.5 flex items-center gap-1 rounded-full bg-paper/95 px-2 py-0.5 label-caps text-ink backdrop-blur-sm"
                >
                    <ApplianceIcon :appliance="recipe.appliance" />
                    {{ applianceLabel(recipe.appliance) }}
                </span>

                <!-- Opposite corner, since a recipe can carry both badges. -->
                <span
                    v-if="recipe.isMealPrep"
                    class="absolute right-1.5 bottom-1.5 rounded-full bg-leaf-soft/95 px-2 py-0.5 label-caps text-leaf backdrop-blur-sm"
                >
                    do pudełka
                </span>

                <!--
                    Bottom left, so it never collides with the meal-prep badge.
                    This is what makes the "Mam wszystko" chip checkable: without
                    it the filter asks to be taken on trust.
                -->
                <span
                    v-if="shortfall"
                    class="absolute bottom-1.5 left-1.5 rounded-full px-2 py-0.5 label-caps backdrop-blur-sm"
                    :class="
                        shortfall.cookable
                            ? 'bg-accent-soft/95 text-accent-strong'
                            : 'bg-paper/95 text-ink-muted'
                    "
                >
                    {{ shortfall.text }}
                </span>
            </div>

            <!--
              Clamped to two lines so the cards in a row end at the same height.
              Ragged card bottoms are the thing that makes a grid look untidy, and
              titles here run from three words to fifteen.
            -->
            <h3
                class="mt-2.5 line-clamp-2 leading-snug font-medium text-ink"
                :class="
                    featured ? 'text-base sm:text-lg' : 'text-sm sm:text-base'
                "
            >
                {{ recipe.title }}
            </h3>

            <!--
                Under the title rather than over the photo: the two corners are
                already taken, and this line is words rather than a two-word
                badge. `flag`, because a use-by date is the same kind of "look at
                this" the review marker is, not a green all-clear.
            -->
            <p
                v-if="useUp"
                class="mt-1.5 flex items-center gap-1 text-xs text-flag"
                :title="`Zużyj: ${recipe.expiring.join(', ')}`"
            >
                <AppIcon name="clock" class="h-3.5 w-3.5" />
                <span class="truncate">zużyj: {{ useUp }}</span>
            </p>

            <!--
              Time first: when deciding what to cook tonight it beats every other
              number. Many recipes carry none, and then the item simply
              disappears instead of leaving a gap.

              ink-muted, not ink-faint: at 12px the fainter tone measured 3.2:1,
              which is unreadable in daylight.
            -->
            <p
                class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-muted"
            >
                <span
                    v-if="recipe.totalTimeMinutes"
                    class="flex items-center gap-1"
                >
                    <AppIcon name="clock" class="h-3.5 w-3.5" />
                    <span class="tabular-nums">
                        {{ recipe.totalTimeMinutes }} min
                    </span>
                </span>
                <span
                    v-if="recipe.servingsLabel"
                    class="flex min-w-0 items-center gap-1"
                >
                    <AppIcon name="servings" class="h-3.5 w-3.5" />
                    <span class="truncate">{{ recipe.servingsLabel }}</span>
                </span>
                <span class="flex items-center gap-1">
                    <AppIcon name="ingredients" class="h-3.5 w-3.5" />
                    <span class="tabular-nums">
                        {{ recipe.ingredientCount }}
                    </span>
                </span>
                <span
                    v-if="recipe.needsReview"
                    class="ml-auto text-flag"
                    title="Do sprawdzenia"
                >
                    <AppIcon
                        name="flag"
                        class="h-3.5 w-3.5"
                        label="Do sprawdzenia"
                    />
                </span>
            </p>
        </button>
    </article>
</template>
