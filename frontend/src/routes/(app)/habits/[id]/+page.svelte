<script lang="ts">
    import { page } from '$app/stores';
    import { goto } from '$app/navigation';
    import { resolve } from '$app/paths';
    import { client } from '$lib/api/client';

    // ---------------------------------------------------------------------------
    // Types
    // ---------------------------------------------------------------------------

    interface HistoryLog {
        id: string;
        logged_at: string;
        user_display_name: string;
        note: string | null;
        source: string;
    }

    interface HistoryResponse {
        data: HistoryLog[];
        page: number;
        limit: number;
        total: number;
    }

    interface HabitInfo {
        id: string;
        name: string;
        icon: string | null;
    }

    interface LogGroup {
        label: string;
        logs: HistoryLog[];
    }

    // ---------------------------------------------------------------------------
    // State
    // ---------------------------------------------------------------------------

    const habitId = $derived($page.params['id'] ?? '');

    let logs = $state<HistoryLog[]>([]);
    let habitInfo = $state<HabitInfo | null>(null);
    let currentPage = $state(1);
    let totalLogs = $state(0);
    let loading = $state(true);
    let loadingMore = $state(false);
    let error = $state<string | null>(null);

    const LIMIT = 20;

    const hasMore = $derived(logs.length < totalLogs);

    // ---------------------------------------------------------------------------
    // Date grouping helpers
    // ---------------------------------------------------------------------------

    function toLocalDateString(isoString: string): string {
        // Returns "YYYY-MM-DD" in local time — used for grouping by calendar day.
        const d = new Date(isoString);
        const y = d.getFullYear();
        const mo = (d.getMonth() + 1).toString().padStart(2, '0');
        const day = d.getDate().toString().padStart(2, '0');
        return `${y}-${mo}-${day}`;
    }

    function todayLocalString(): string {
        return toLocalDateString(new Date().toISOString());
    }

    function yesterdayLocalString(): string {
        // Subtract 24 hours in ms — avoids mutating a Date instance.
        return toLocalDateString(new Date(Date.now() - 86_400_000).toISOString());
    }

    function getDateLabel(isoString: string): string {
        const localDay = toLocalDateString(isoString);
        if (localDay === todayLocalString()) return 'Today';
        if (localDay === yesterdayLocalString()) return 'Yesterday';

        // Format as "March 20" etc.
        const d = new Date(isoString);
        return d.toLocaleDateString(undefined, { month: 'long', day: 'numeric' });
    }

    function formatTime(isoString: string): string {
        const d = new Date(isoString);
        const h = d.getHours().toString().padStart(2, '0');
        const m = d.getMinutes().toString().padStart(2, '0');
        return `${h}:${m}`;
    }

    function buildGroups(logList: HistoryLog[]): LogGroup[] {
        const order: string[] = [];
        const map: Record<string, HistoryLog[]> = {};

        for (const log of logList) {
            const label = getDateLabel(log.logged_at);
            if (map[label] === undefined) {
                order.push(label);
                map[label] = [];
            }
            map[label]!.push(log);
        }

        return order.map((label) => ({ label, logs: map[label]! }));
    }

    const groupedLogs = $derived(buildGroups(logs));

    // ---------------------------------------------------------------------------
    // Data loading
    // ---------------------------------------------------------------------------

    async function loadPage(pg: number): Promise<void> {
        const id = habitId;
        if (id === '') return;

        try {
            const response = await client.get<HistoryResponse>(
                `/habits/${id}/history?page=${pg}&limit=${LIMIT}`,
            );
            totalLogs = response.total;
            if (pg === 1) {
                logs = response.data;
            } else {
                logs = [...logs, ...response.data];
            }
            currentPage = pg;

            // Extract habit name from first log if not yet set
            // (the API may return habit info in a wrapper — we fall back to a generic title)
        } catch (e) {
            error = e instanceof Error ? e.message : 'Failed to load history';
        }
    }

    async function loadHabitInfo(): Promise<void> {
        const id = habitId;
        if (id === '') return;
        try {
            // Attempt to fetch habit info from dashboard endpoint or a dedicated one.
            // If the API doesn't have a standalone /habits/{id} endpoint yet, we just
            // show the id — the name will come from the history response wrapper.
            const info = await client.get<HabitInfo>(`/habits/${id}`);
            habitInfo = info;
        } catch {
            // Non-fatal — page still works without habit name
        }
    }

    async function load(): Promise<void> {
        loading = true;
        error = null;
        await Promise.all([loadHabitInfo(), loadPage(1)]);
        loading = false;
    }

    async function loadMore(): Promise<void> {
        if (loadingMore || !hasMore) return;
        loadingMore = true;
        await loadPage(currentPage + 1);
        loadingMore = false;
    }

    // ---------------------------------------------------------------------------
    // Infinite scroll via IntersectionObserver
    // ---------------------------------------------------------------------------

    let sentinel = $state<HTMLDivElement | null>(null);

    $effect(() => {
        if (sentinel === null) return;

        const observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        loadMore();
                    }
                }
            },
            { rootMargin: '100px' },
        );

        observer.observe(sentinel);

        return () => {
            observer.disconnect();
        };
    });

    // ---------------------------------------------------------------------------
    // Bootstrap
    // ---------------------------------------------------------------------------

    $effect(() => {
        // Re-load whenever the route param changes
        const id = habitId;
        if (id !== '') {
            load();
        }
    });
</script>

<svelte:head>
    <title>{habitInfo !== null ? habitInfo.name : 'History'} — SmartHabit</title>
</svelte:head>

<div class="page">
    <!-- Header -->
    <header class="page-header">
        <button
            class="back-btn"
            onclick={() => goto(resolve('/'))}
            aria-label="Back to dashboard"
        >
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <polyline points="15 18 9 12 15 6"></polyline>
            </svg>
        </button>

        <div class="header-text">
            <h1 class="page-title">
                {habitInfo !== null ? habitInfo.name : 'History'}
            </h1>
            {#if totalLogs > 0}
                <p class="page-subtitle">{totalLogs} {totalLogs === 1 ? 'entry' : 'entries'}</p>
            {/if}
        </div>
    </header>

    <!-- Loading state -->
    {#if loading}
        <div class="state-container" aria-live="polite" aria-busy="true">
            {#each [1, 2, 3, 4] as i (i)}
                <div class="skeleton-row" style="animation-delay: {(i - 1) * 60}ms"></div>
            {/each}
        </div>
    {/if}

    <!-- Error state -->
    {#if error !== null && !loading}
        <div class="error-card" role="alert">
            <p class="error-text">{error}</p>
            <button class="retry-btn" onclick={load}>Try again</button>
        </div>
    {/if}

    <!-- Log list -->
    {#if !loading && error === null}
        {#if logs.length === 0}
            <div class="empty-state">
                <p class="empty-title">No logs yet</p>
                <p class="empty-subtitle">Complete this habit to see history here.</p>
            </div>
        {:else}
            <div class="log-groups">
                {#each groupedLogs as group (group.label)}
                    <div class="log-group">
                        <h2 class="group-label">{group.label}</h2>

                        <div class="log-list">
                            {#each group.logs as log (log.id)}
                                <div class="log-row">
                                    <div class="log-left">
                                        <span class="log-time">{formatTime(log.logged_at)}</span>
                                        {#if log.source !== 'manual'}
                                            <span class="source-tag">{log.source}</span>
                                        {/if}
                                    </div>

                                    <div class="log-right">
                                        <span class="log-name">{log.user_display_name}</span>
                                        {#if log.note !== null && log.note !== ''}
                                            <span class="log-note">{log.note}</span>
                                        {/if}
                                    </div>
                                </div>
                            {/each}
                        </div>
                    </div>
                {/each}

                <!-- Sentinel for IntersectionObserver (infinite scroll) -->
                {#if hasMore}
                    <div bind:this={sentinel} class="scroll-sentinel" aria-hidden="true">
                        {#if loadingMore}
                            <div class="load-more-spinner" aria-label="Loading more..."></div>
                        {/if}
                    </div>
                {:else}
                    <p class="end-of-list">All entries loaded</p>
                {/if}
            </div>
        {/if}
    {/if}
</div>

<style>
    @keyframes shimmer {
        0%   { background-position: -200% 0; }
        100% { background-position: 200% 0; }
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    .page {
        padding: 1.5rem 1rem;
        max-width: 40rem;
        margin: 0 auto;
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
    }

    /* Header */
    .page-header {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .back-btn {
        flex-shrink: 0;
        width: 2.25rem;
        height: 2.25rem;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--color-surface-raised);
        border: 1px solid var(--color-border);
        border-radius: var(--radius-md);
        color: var(--color-text-secondary);
        cursor: pointer;
        transition: background 0.15s, color 0.15s;
        -webkit-tap-highlight-color: transparent;
    }

    .back-btn:hover {
        background: var(--color-surface);
        color: var(--color-text-primary);
    }

    .header-text {
        display: flex;
        flex-direction: column;
        gap: 0.125rem;
        min-width: 0;
    }

    .page-title {
        font-size: 1.25rem;
        font-weight: 700;
        color: var(--color-text-primary);
        letter-spacing: -0.02em;
        margin: 0;
        line-height: 1.2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .page-subtitle {
        font-size: 0.8125rem;
        color: var(--color-text-muted);
        margin: 0;
    }

    /* Log groups */
    .log-groups {
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
    }

    .log-group {
        display: flex;
        flex-direction: column;
        gap: 0.375rem;
    }

    .group-label {
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--color-text-muted);
        margin: 0 0 0.25rem;
        padding: 0 0.25rem;
    }

    /* Log list */
    .log-list {
        display: flex;
        flex-direction: column;
        gap: 0.375rem;
    }

    .log-row {
        display: flex;
        align-items: flex-start;
        gap: 0.875rem;
        padding: 0.75rem 0.875rem;
        background: var(--color-surface-raised);
        border: 1px solid var(--color-border);
        border-radius: var(--radius-lg);
        transition: background 0.15s;
    }

    .log-left {
        flex-shrink: 0;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 0.25rem;
        min-width: 3.5rem;
    }

    .log-time {
        font-family: var(--font-mono);
        font-size: 0.875rem;
        font-weight: 500;
        color: var(--color-text-primary);
        line-height: 1.4;
    }

    .source-tag {
        font-family: var(--font-mono);
        font-size: 0.625rem;
        font-weight: 500;
        color: var(--color-info);
        background: color-mix(in srgb, var(--color-info) 12%, transparent);
        border-radius: var(--radius-sm);
        padding: 0.0625rem 0.3125rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .log-right {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .log-name {
        font-size: 0.9375rem;
        font-weight: 600;
        color: var(--color-text-primary);
        line-height: 1.4;
    }

    .log-note {
        font-size: 0.8125rem;
        color: var(--color-text-secondary);
        line-height: 1.5;
        font-style: italic;
    }

    /* Skeleton */
    .state-container {
        display: flex;
        flex-direction: column;
        gap: 0.375rem;
    }

    .skeleton-row {
        height: 60px;
        border-radius: var(--radius-lg);
        background: linear-gradient(
            90deg,
            var(--color-surface) 25%,
            var(--color-surface-raised) 50%,
            var(--color-surface) 75%
        );
        background-size: 200% 100%;
        animation: shimmer 1.4s infinite ease-in-out;
    }

    /* Error */
    .error-card {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.75rem;
        padding: 1.5rem;
        background: color-mix(in srgb, var(--color-error) 6%, transparent);
        border: 1px solid color-mix(in srgb, var(--color-error) 20%, transparent);
        border-radius: var(--radius-xl);
        text-align: center;
    }

    .error-text {
        font-size: 0.9375rem;
        color: var(--color-error);
        margin: 0;
    }

    .retry-btn {
        padding: 0.5rem 1.25rem;
        font-size: 0.875rem;
        font-weight: 600;
        font-family: inherit;
        color: var(--color-accent);
        border: 1px solid var(--color-accent);
        border-radius: var(--radius-md);
        background: transparent;
        cursor: pointer;
        transition: background 0.15s;
    }

    .retry-btn:hover {
        background: color-mix(in srgb, var(--color-accent) 10%, transparent);
    }

    /* Empty state */
    .empty-state {
        padding: 3rem 2rem;
        text-align: center;
        background: var(--color-surface-raised);
        border: 1px dashed var(--color-border-strong);
        border-radius: var(--radius-xl);
        display: flex;
        flex-direction: column;
        gap: 0.375rem;
    }

    .empty-title {
        font-size: 1rem;
        font-weight: 600;
        color: var(--color-text-secondary);
        margin: 0;
    }

    .empty-subtitle {
        font-size: 0.875rem;
        color: var(--color-text-muted);
        margin: 0;
    }

    /* Scroll sentinel / load more */
    .scroll-sentinel {
        display: flex;
        justify-content: center;
        padding: 1rem 0;
        min-height: 2rem;
    }

    .load-more-spinner {
        width: 1.25rem;
        height: 1.25rem;
        border: 2px solid var(--color-border-strong);
        border-top-color: var(--color-accent);
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
    }

    .end-of-list {
        text-align: center;
        font-size: 0.8125rem;
        color: var(--color-text-muted);
        margin: 0.5rem 0;
    }
</style>
