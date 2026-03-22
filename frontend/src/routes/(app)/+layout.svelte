<script lang="ts">
    import { page } from '$app/stores';
    import { resolve } from '$app/paths';

    const { children } = $props();

    const hrefToday = resolve('/');
    const hrefHistory = resolve('/history');
    const hrefStats = resolve('/stats');
    const hrefSettings = resolve('/settings');

    interface NavItem {
        href: string;
        label: string;
        icon: string;
        ariaLabel: string;
    }

    const navItems: NavItem[] = [
        { href: hrefToday, label: 'Today', icon: 'today', ariaLabel: 'Today' },
        { href: hrefHistory, label: 'History', icon: 'history', ariaLabel: 'History' },
        { href: hrefStats, label: 'Stats', icon: 'stats', ariaLabel: 'Stats' },
        { href: hrefSettings, label: 'Settings', icon: 'settings', ariaLabel: 'Settings' },
    ];

    function isActive(path: string): boolean {
        const pathname = $page.url.pathname;
        if (path === hrefToday) return pathname === hrefToday;
        return pathname.startsWith(path);
    }
</script>

<div class="app-shell">
    <main class="app-main">
        {@render children()}
    </main>

    <nav class="bottom-nav" aria-label="Main navigation">
        {#each navItems as item (item.href)}
            <a
                href={item.href}
                class="nav-item"
                class:nav-item--active={isActive(item.href)}
                aria-label={item.ariaLabel}
                aria-current={isActive(item.href) ? 'page' : undefined}
            >
                <span class="nav-icon" aria-hidden="true">
                    {#if item.icon === 'today'}
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line>
                            <line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                        </svg>
                    {:else if item.icon === 'history'}
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="12 8 12 12 14 14"></polyline>
                            <path d="M3.05 11a9 9 0 1 1 .5 4m-.5 5v-5h5"></path>
                        </svg>
                    {:else if item.icon === 'stats'}
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="20" x2="18" y2="10"></line>
                            <line x1="12" y1="20" x2="12" y2="4"></line>
                            <line x1="6" y1="20" x2="6" y2="14"></line>
                        </svg>
                    {:else if item.icon === 'settings'}
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="3"></circle>
                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                        </svg>
                    {/if}
                </span>
                <span class="nav-label">{item.label}</span>
            </a>
        {/each}
    </nav>
</div>

<style>
    .app-shell {
        display: flex;
        flex-direction: column;
        min-height: 100dvh;
        background: var(--color-bg);
    }

    .app-main {
        flex: 1;
        /* Leave space for bottom nav */
        padding-bottom: 5rem;
        overflow-y: auto;
    }

    .bottom-nav {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        height: 4rem;
        background: var(--color-surface-raised);
        border-top: 1px solid var(--color-border);
        display: flex;
        align-items: stretch;
        z-index: 100;
        /* Safe area for iOS home indicator */
        padding-bottom: env(safe-area-inset-bottom);
    }

    .nav-item {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 0.2rem;
        text-decoration: none;
        color: var(--color-text-muted);
        font-size: 0.6875rem;
        font-weight: 500;
        transition: color 0.15s;
        padding: 0.5rem 0.25rem;
        -webkit-tap-highlight-color: transparent;
    }

    .nav-item:hover {
        color: var(--color-text-secondary);
    }

    .nav-item--active {
        color: var(--color-accent);
    }

    .nav-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        line-height: 1;
    }

    .nav-label {
        line-height: 1;
    }
</style>
