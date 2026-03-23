<script lang="ts">
    import { goto } from '$app/navigation';
    import { resolve } from '$app/paths';
    import { client } from '$lib/api/client';

    // ---------------------------------------------------------------------------
    // Types
    // ---------------------------------------------------------------------------

    interface LastLog {
        id: string;
        logged_at: string;
        user_display_name: string;
        source: string;
    }

    interface DashboardHabit {
        id: string;
        name: string;
        icon: string | null;
        sort_order: number;
        frequency: string;
        time_window_start: string | null;
        time_window_end: string | null;
        time_window_mode: string;
        is_done_today: boolean;
        last_log: LastLog | null;
    }

    interface DashboardSummary {
        total: number;
        done: number;
        completion_rate: number;
    }

    interface DashboardResponse {
        household_id: string;
        habits: DashboardHabit[];
        summary: DashboardSummary;
    }

    // ---------------------------------------------------------------------------
    // State
    // ---------------------------------------------------------------------------

    let data = $state<DashboardResponse | null>(null);
    let error = $state<string | null>(null);
    let loading = $state(true);
    let loaded = $state(false);

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    function formatLastCompleted(lastLog: LastLog | null): string {
        if (lastLog === null) return 'Never';

        const d = new Date(lastLog.logged_at);
        const now = new Date();

        const todayStr = toLocalDateString(now.toISOString());
        const logStr = toLocalDateString(lastLog.logged_at);
        const yesterdayStr = toLocalDateString(new Date(Date.now() - 86_400_000).toISOString());

        const timeStr = `${d.getHours().toString().padStart(2, '0')}:${d.getMinutes().toString().padStart(2, '0')}`;

        if (logStr === todayStr) return `Today at ${timeStr}`;
        if (logStr === yesterdayStr) return `Yesterday at ${timeStr}`;

        return d.toLocaleDateString(undefined, { month: 'long', day: 'numeric' }) + ` at ${timeStr}`;
    }

    function toLocalDateString(isoString: string): string {
        const d = new Date(isoString);
        const y = d.getFullYear();
        const mo = (d.getMonth() + 1).toString().padStart(2, '0');
        const day = d.getDate().toString().padStart(2, '0');
        return `${y}-${mo}-${day}`;
    }

    // ---------------------------------------------------------------------------
    // Data loading
    // ---------------------------------------------------------------------------

    async function load(): Promise<void> {
        loading = true;
        error = null;
        try {
            data = await client.get<DashboardResponse>('/dashboard');
            loaded = true;
        } catch (e) {
            error = e instanceof Error ? e.message : 'Failed to load';
        } finally {
            loading = false;
        }
    }

    // ---------------------------------------------------------------------------
    // Bootstrap
    // ---------------------------------------------------------------------------

    $effect(() => {
        load();
    });
</script>

<svelte:head>
    <title>History — SmartHabit</title>
</svelte:head>

<div class="page">
    <!-- Header -->
    <header class="page-header">
        <h1 class="page-title">History</h1>
        {#if data !== null}
            <p class="page-subtitle">{data.habits.length} {data.habits.length === 1 ? 'habit' : 'habits'}</p>
        {/if}
    </header>

    <!-- Loading state -->
    {#if loading && !loaded}
        <div class="state-container" aria-live="polite" aria-busy="true">
            {#each [1, 2, 3, 4] as i (i)}
                <div class="skeleton-card" style="animation-delay: {(i - 1) * 60}ms"></div>
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

    <!-- Habit list -->
    {#if data !== null && !loading}
        {#if data.habits.length === 0}
            <div class="empty-state">
                <p class="empty-title">No habits yet</p>
                <p class="empty-subtitle">Add your first habit to see history here.</p>
            </div>
        {:else}
            <ul class="habit-list" aria-label="All habits">
                {#each data.habits as habit, i (habit.id)}
                    <li
                        class="habit-list-item"
                        style="animation-delay: {loaded ? '0ms' : `${i * 50}ms`}"
                        class:habit-list-item--in={!loaded}
                    >
                        <button
                            class="habit-row"
                            onclick={() => goto(resolve(`/habits/${habit.id}`))}
                            aria-label="View history for {habit.name}"
                        >
                            <span class="habit-icon" aria-hidden="true">
                                {#if habit.icon !== null && habit.icon !== ''}
                                    {habit.icon}
                                {:else}
                                    <span class="habit-icon-fallback"></span>
                                {/if}
                            </span>

                            <span class="habit-body">
                                <span class="habit-name">{habit.name}</span>
                                <span class="habit-meta">Last completed: {formatLastCompleted(habit.last_log)}</span>
                            </span>

                            <svg
                                class="chevron"
                                width="16"
                                height="16"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <polyline points="9 18 15 12 9 6"></polyline>
                            </svg>
                        </button>
                    </li>
                {/each}
            </ul>
        {/if}
    {/if}
</div>

<style>
    @keyframes slide-up {
        from {
            opacity: 0;
            transform: translateY(12px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes shimmer {
        0%   { background-position: -200% 0; }
        100% { background-position: 200% 0; }
    }

    .page {
        padding: 1.5rem 1rem;
        max-width: 40rem;
        margin: 0 auto;
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    /* Header */
    .page-header {
        display: flex;
        flex-direction: column;
        gap: 0.125rem;
    }

    .page-title {
        font-size: 1.375rem;
        font-weight: 700;
        color: var(--color-text-primary);
        letter-spacing: -0.02em;
        margin: 0;
        line-height: 1.2;
    }

    .page-subtitle {
        font-size: 0.8125rem;
        color: var(--color-text-muted);
        margin: 0;
    }

    /* Habit list */
    .habit-list {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .habit-list-item {
        opacity: 1;
    }

    .habit-list-item--in {
        animation: slide-up 0.3s ease-out both;
    }

    /* Habit row */
    .habit-row {
        width: 100%;
        display: flex;
        align-items: center;
        gap: 0.875rem;
        padding: 0.875rem 1rem;
        background: var(--color-surface-raised);
        border: 1px solid var(--color-border);
        border-radius: var(--radius-lg);
        cursor: pointer;
        font-family: inherit;
        text-align: left;
        transition: background 0.15s, border-color 0.15s;
        -webkit-tap-highlight-color: transparent;
    }

    .habit-row:hover {
        background: var(--color-surface);
        border-color: var(--color-border-strong);
    }

    .habit-row:active {
        background: var(--color-surface);
    }

    /* Icon */
    .habit-icon {
        flex-shrink: 0;
        width: 2.25rem;
        height: 2.25rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.375rem;
        line-height: 1;
        background: color-mix(in srgb, var(--color-accent) 10%, transparent);
        border-radius: var(--radius-md);
    }

    .habit-icon-fallback {
        display: block;
        width: 1rem;
        height: 1rem;
        background: var(--color-border-strong);
        border-radius: 50%;
    }

    /* Body */
    .habit-body {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 0.1875rem;
    }

    .habit-name {
        font-size: 0.9375rem;
        font-weight: 600;
        color: var(--color-text-primary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        line-height: 1.3;
    }

    .habit-meta {
        font-size: 0.8125rem;
        color: var(--color-text-muted);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        line-height: 1.4;
    }

    /* Chevron */
    .chevron {
        flex-shrink: 0;
        color: var(--color-text-muted);
        transition: color 0.15s;
    }

    .habit-row:hover .chevron {
        color: var(--color-text-secondary);
    }

    /* Skeleton loading */
    .state-container {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .skeleton-card {
        height: 72px;
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

    /* Error state */
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
</style>
