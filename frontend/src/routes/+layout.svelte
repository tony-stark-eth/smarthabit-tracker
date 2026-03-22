<script lang="ts">
    import '../app.css';
    import { page } from '$app/stores';
    import { goto } from '$app/navigation';
    import { resolve } from '$app/paths';
    import { isAuthenticated, isLoading } from '$lib/stores/auth.svelte';
    import { flushQueue } from '$lib/api/offline';
    import { onMount } from 'svelte';

    const { children } = $props();

    function isAppRoute(pathname: string): boolean {
        // The (app) layout group pages: root /, /settings, /history, /stats
        // Exclude (auth) routes explicitly
        if (pathname.startsWith('/login') || pathname.startsWith('/register')) {
            return false;
        }
        // Root / and all non-auth routes are app routes
        return true;
    }

    $effect(() => {
        // Don't redirect while still bootstrapping (fetching user from token)
        if (isLoading()) return;

        const pathname = $page.url.pathname;

        if (isAppRoute(pathname) && !isAuthenticated()) {
            goto(resolve('/login'), { replaceState: true });
        }
    });

    onMount(() => {
        const handleOnline = (): void => {
            flushQueue(fetch);
        };

        window.addEventListener('online', handleOnline);

        // Flush any queued requests if we are already online at startup.
        if (navigator.onLine) {
            flushQueue(fetch);
        }

        return () => {
            window.removeEventListener('online', handleOnline);
        };
    });
</script>

{@render children()}
