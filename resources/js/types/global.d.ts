import type { Auth } from '@/types/auth';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            /**
             * Drives the badge on the nav's "Zadania". Flat rather than nested
             * under `tasks`, which is a page prop on the tasks screen and would
             * shadow it there.
             */
            openTasks?: number;
            /** What the last write did, when it has something to report. */
            flash: {
                shopping?: {
                    added?: number;
                    unknown?: number;
                    /** Lines left off because the kitchen says it has them. */
                    skipped?: number;
                    stocked?: number;
                    /**
                     * Which list it went on. A household keeps several, so
                     * "dodano 7" is only half the answer.
                     */
                    list?: string;
                    listId?: number;
                };
                /** What a week of planned days put on the shopping list. */
                plan?: {
                    added: number;
                    /** Ingredient lines the importer never resolved to a product. */
                    unknown: number;
                    /** Entries that were a note, so nothing could be counted. */
                    notes: number;
                    /** Recipes that never said how many portions they make. */
                    unscaled: string[];
                    /** Recipes bought as a whole cooking, leftovers included. */
                    wholeBatches: string[];
                    /** Lines already ticked off, left exactly as they were. */
                    kept: number;
                    meals: number;
                    /** The list it went on — named after the days it covers. */
                    list: string;
                    listId: number;
                };
                /** What "wygeneruj tydzień" filled in. */
                generated?: {
                    added: number;
                    /** Slots left alone because something was already planned. */
                    skipped: number;
                    /** Meals no recipe is tagged for — an answer, not an error. */
                    empty: string[];
                };
            };
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
