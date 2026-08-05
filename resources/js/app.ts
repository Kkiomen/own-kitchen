import { createInertiaApp } from '@inertiajs/vue3';

const appName = import.meta.env.VITE_APP_NAME || 'Kuchnia';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        color: '#55b850',
    },
});

/*
 * Registering after load keeps the worker off the critical path — it is only
 * needed for the second visit onwards. Dev is skipped so a stale cache never
 * shadows a rebuild.
 */
if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', () => {
        void navigator.serviceWorker.register('/sw.js');
    });
}
