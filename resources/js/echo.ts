import { configureEcho } from '@laravel/echo-vue';

configureEcho({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    // An empty VITE_REVERB_HOST means "the host the page was served from", so
    // one image can serve several domains (stage and production, say).
    wsHost:
        import.meta.env.VITE_REVERB_HOST ||
        (typeof window === 'undefined' ? undefined : window.location.hostname),
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
