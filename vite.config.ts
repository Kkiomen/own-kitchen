import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Poppins', {
                    weights: [400, 500, 600, 700],
                    /*
                     * `latin-ext` is not optional in a Polish app, and leaving it
                     * out fails quietly rather than loudly: the plugin defaults
                     * to `latin`, whose unicode-range stops before ą, ę, ć, ł, ń,
                     * ś, ź and ż. Every one of those then falls back to a system
                     * font *mid-word* — "zeszkli**ć**" in two typefaces, off the
                     * baseline. It reads as a rendering fault, which is exactly
                     * what it is.
                     */
                    subsets: ['latin', 'latin-ext'],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
    ],
});
