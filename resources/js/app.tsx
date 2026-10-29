import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';
import { initializeTheme } from './hooks/use-appearance';
import { heldDictionaryKey } from './lib/i18n';
import { type AnalyticsIds, setAnalytics, track } from './lib/track';

declare global {
    const route: typeof routeFn;
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();

// Tell the server which dictionary is already here, so a move to another page does not carry it again.
router.on('before', (event) => {
    const key = heldDictionaryKey();
    if (key) event.detail.visit.headers = { ...event.detail.visit.headers, 'X-Dictionary': key };
});

// Visitor statistics: every page the visitor opens, whether the browser loaded it or the app moved to it (only when the owner switched them on).
router.on('navigate', (event) => {
    setAnalytics((event.detail.page.props as { analytics?: AnalyticsIds }).analytics);
    track('PageView');
});
