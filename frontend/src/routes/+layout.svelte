<script lang="ts">
    import '../app.css';
    import { page } from '$app/stores';
    import { goto } from '$app/navigation';
    import { isAuthenticated, isLoading } from '$lib/stores/auth.svelte';

    const { children } = $props();

    // Protected route prefix — all routes under (app) group require auth.
    // In adapter-static SPA mode, route segments are detected from $page.url.pathname.
    const APP_ROUTES = ['/settings', '/history', '/stats'];

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
            goto('/login', { replaceState: true });
        }
    });
</script>

{@render children()}
