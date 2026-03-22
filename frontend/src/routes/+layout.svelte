<script lang="ts">
    import '../app.css';
    import { page } from '$app/stores';
    import { goto } from '$app/navigation';
    import { resolve } from '$app/paths';
    import { isAuthenticated, isLoading } from '$lib/stores/auth.svelte';
    import { flushQueue } from '$lib/api/offline';
    import { onMount } from 'svelte';

    const { children } = $props();

    function isPublicRoute(pathname: string): boolean {
        return (
            pathname.startsWith('/login') ||
            pathname.startsWith('/register') ||
            pathname.startsWith('/welcome')
        );
    }

    $effect(() => {
        // Don't redirect while still bootstrapping (fetching user from token)
        if (isLoading()) return;

        const pathname = $page.url.pathname;

        if (!isPublicRoute(pathname) && !isAuthenticated()) {
            goto(resolve('/welcome'), { replaceState: true });
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
