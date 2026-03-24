<script lang="ts">
    import { onMount, onDestroy } from 'svelte';
    import { client } from '$lib/api/client';
    import { getUser } from '$lib/stores/auth.svelte';
    import { registerPushSubscription } from '$lib/api/push';
    import HabitCard from '$lib/components/HabitCard.svelte';
    import ProgressBar from '$lib/components/ProgressBar.svelte';
    import CreateHabitSheet from '$lib/components/CreateHabitSheet.svelte';
    import { t } from '$lib/i18n';

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

    const user = $derived(getUser());

    let data = $state<DashboardResponse | null>(null);
    let error = $state<string | null>(null);
    let loading = $state(true);
    let loaded = $state(false);
    let sheetOpen = $state(false);

    // ---------------------------------------------------------------------------
    // Greeting
    // ---------------------------------------------------------------------------

    function getGreeting(): string {
        const hour = new Date().getHours();
        if (hour < 12) return t('dashboard_good_morning');
        if (hour < 17) return t('dashboard_good_afternoon');
        return t('dashboard_good_evening');
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
    // One-tap logging with optimistic update
    // ---------------------------------------------------------------------------

    async function logHabit(habitId: string): Promise<void> {
        if (data === null) return;

        const habit = data.habits.find(h => h.id === habitId);
        if (habit === undefined || habit.is_done_today) return;

        // Optimistic update
        habit.is_done_today = true;
        data.summary.done += 1;
        data.summary.completion_rate = data.summary.done / data.summary.total;

        try {
            await client.post(`/habits/${habitId}/log`, { source: 'manual' });
        } catch {
            // Revert on error
            await load();
        }
    }

    // ---------------------------------------------------------------------------
    // One-tap unlogging with optimistic update
    // ---------------------------------------------------------------------------

    async function unlogHabit(habitId: string): Promise<void> {
        if (data === null) return;

        const habit = data.habits.find(h => h.id === habitId);
        if (habit === undefined || !habit.is_done_today || habit.last_log === null) return;

        const lastLogId = habit.last_log.id;

        // Optimistic update
        habit.is_done_today = false;
        habit.last_log = null;
        data.summary.done -= 1;
        data.summary.completion_rate = data.summary.done / data.summary.total;

        try {
            await client.delete(`/habits/${habitId}/log/${lastLogId}`);
        } catch {
            // Revert on error
            await load();
        }
    }

    // ---------------------------------------------------------------------------
    // Sheet helpers
    // ---------------------------------------------------------------------------

    function openSheet(): void {
        sheetOpen = true;
    }

    function closeSheet(): void {
        sheetOpen = false;
    }

    function handleHabitCreated(): void {
        sheetOpen = false;
        load();
    }

    // ---------------------------------------------------------------------------
    // Bootstrap
    // ---------------------------------------------------------------------------

    $effect(() => {
        load();
    });

    // ---------------------------------------------------------------------------
    // Mercure SSE — real-time dashboard updates from other household members
    // ---------------------------------------------------------------------------

    let eventSource: EventSource | null = null;

    onMount(() => {
        // Subscribe once data is available (household_id comes from dashboard response)
        $effect(() => {
            if (data?.household_id) {
                subscribeToMercure(data.household_id);
            }
        });

        // Only ask once, after first successful dashboard load
        if (Notification.permission === 'default') {
            registerPushSubscription().catch(() => {
                // Ignore errors — permission denied or push not supported
            });
        }
    });

    onDestroy(() => {
        eventSource?.close();
        eventSource = null;
    });

    function subscribeToMercure(householdId: string): void {
        // Close existing connection before opening a new one
        eventSource?.close();

        const mercureUrl = new URL('/.well-known/mercure', window.location.origin);
        mercureUrl.searchParams.append('topic', `household/${householdId}/dashboard`);

        eventSource = new EventSource(mercureUrl.toString());

        eventSource.onmessage = (event: MessageEvent) => {
            try {
                const update = JSON.parse(event.data as string) as { type: string };
                if (update.type === 'habit_logged' || update.type === 'habit_unlogged') {
                    load();
                }
            } catch {
                // Ignore malformed messages
            }
        };

        eventSource.onerror = () => {
            // SSE auto-reconnects — no action needed
        };
    }
</script>

<svelte:head>
    <title>Today — SmartHabit</title>
</svelte:head>

<div class="page">
    <!-- Header -->
    <header class="page-header">
        <div class="header-top">
            <div>
                <h1 class="page-title">
                    {getGreeting()}{user ? `, ${user.display_name.split(' ')[0]}` : ''}
                </h1>
                <p class="page-date">
                    {new Date().toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' })}
                </p>
            </div>
        </div>

        {#if data !== null}
            <ProgressBar done={data.summary.done} total={data.summary.total} />
        {/if}
    </header>

    <!-- All done banner -->
    {#if data !== null && data.summary.done >= data.summary.total && data.summary.total > 0}
        <div class="all-done-banner" role="status">
            <span class="all-done-emoji" aria-hidden="true">🎉</span>
            <span class="all-done-text">{t('dashboard_all_done')}</span>
        </div>
    {/if}

    <!-- Loading state -->
    {#if loading && !loaded}
        <div class="state-container" aria-live="polite" aria-busy="true">
            {#each [1, 2, 3] as i (i)}
                <div class="skeleton-card" style="animation-delay: {(i - 1) * 80}ms"></div>
            {/each}
        </div>
    {/if}

    <!-- Error state -->
    {#if error !== null && !loading}
        <div class="error-card" role="alert">
            <p class="error-text">{error}</p>
            <button class="retry-btn" onclick={load}>{t('common_try_again')}</button>
        </div>
    {/if}

    <!-- Habit list -->
    {#if data !== null && !loading}
        {#if data.habits.length === 0}
            <div class="empty-state">
                <p class="empty-title">{t('dashboard_no_habits')}</p>
                <p class="empty-subtitle">{t('dashboard_no_habits_hint')}</p>
                <button class="empty-cta" onclick={openSheet}>
                    {t('dashboard_create_first')}
                </button>
            </div>
        {:else}
            <ul class="habit-list" aria-label="Today's habits">
                {#each data.habits as habit, i (habit.id)}
                    <li
                        class="habit-list-item"
                        style="animation-delay: {loaded ? '0ms' : `${i * 60}ms`}"
                        class:habit-list-item--in={!loaded}
                    >
                        <HabitCard
                            id={habit.id}
                            name={habit.name}
                            icon={habit.icon}
                            timeWindowStart={habit.time_window_start}
                            timeWindowEnd={habit.time_window_end}
                            timeWindowMode={habit.time_window_mode}
                            isDoneToday={habit.is_done_today}
                            lastLog={habit.last_log}
                            onLog={() => logHabit(habit.id)}
                            onUnlog={() => unlogHabit(habit.id)}
                        />
                    </li>
                {/each}
            </ul>
        {/if}
    {/if}
</div>

<!-- Floating action button -->
<button class="fab" onclick={openSheet} aria-label="Create habit">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <line x1="12" y1="5" x2="12" y2="19"></line>
        <line x1="5" y1="12" x2="19" y2="12"></line>
    </svg>
</button>

<CreateHabitSheet open={sheetOpen} onClose={closeSheet} onCreated={handleHabitCreated} />

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
        gap: 0.75rem;
    }

    .header-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
    }

    .page-title {
        font-size: 1.375rem;
        font-weight: 700;
        color: var(--color-text-primary);
        letter-spacing: -0.02em;
        margin: 0;
        line-height: 1.2;
    }

    .page-date {
        font-size: 0.8125rem;
        color: var(--color-text-muted);
        margin: 0.2rem 0 0;
    }

    /* All done banner */
    .all-done-banner {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.75rem 1rem;
        background: color-mix(in srgb, var(--color-success) 12%, transparent);
        border: 1px solid color-mix(in srgb, var(--color-success) 30%, transparent);
        border-radius: var(--radius-lg);
        animation: slide-up 0.35s ease-out both;
    }

    .all-done-emoji {
        font-size: 1.25rem;
        line-height: 1;
    }

    .all-done-text {
        font-size: 0.9375rem;
        font-weight: 600;
        color: var(--color-success);
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

    /* Skeleton loading */
    .state-container {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .skeleton-card {
        height: 72px;
        border-radius: 12px;
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
        align-items: center;
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

    .empty-cta {
        margin-top: 1rem;
        padding: 0.625rem 1.25rem;
        font-size: 0.9375rem;
        font-weight: 600;
        font-family: var(--font-sans);
        color: var(--color-accent-text);
        background: var(--color-accent);
        border: none;
        border-radius: var(--radius-md);
        cursor: pointer;
        transition: background 0.15s;
        -webkit-tap-highlight-color: transparent;
    }

    .empty-cta:hover {
        background: var(--color-accent-hover);
    }

    /* Floating action button */
    .fab {
        position: fixed;
        bottom: calc(4rem + 1rem + env(safe-area-inset-bottom));
        right: 1.25rem;
        width: 3.25rem;
        height: 3.25rem;
        border-radius: 50%;
        background: var(--color-accent);
        color: var(--color-accent-text);
        border: none;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        z-index: 50;
        box-shadow: 0 4px 16px color-mix(in srgb, var(--color-accent) 40%, transparent);
        transition: background 0.15s, transform 0.15s, box-shadow 0.15s;
        -webkit-tap-highlight-color: transparent;
    }

    .fab:hover {
        background: var(--color-accent-hover);
        transform: scale(1.06);
        box-shadow: 0 6px 20px color-mix(in srgb, var(--color-accent) 50%, transparent);
    }

    .fab:active {
        transform: scale(0.94);
    }
</style>
