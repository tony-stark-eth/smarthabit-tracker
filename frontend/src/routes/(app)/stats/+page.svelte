<script lang="ts">
    import { client } from '$lib/api/client';

    // ---------------------------------------------------------------------------
    // Types
    // ---------------------------------------------------------------------------

    interface HabitStat {
        id: string;
        name: string;
        icon: string | null;
        completion_rate_30d: number;
    }

    interface HouseholdStats {
        overall_completion_rate: number;
        weekday_heatmap: Record<string, number>;
        time_heatmap: Record<string, number>;
        habits: HabitStat[];
    }

    // ---------------------------------------------------------------------------
    // State
    // ---------------------------------------------------------------------------

    let stats = $state<HouseholdStats | null>(null);
    let loading = $state(true);
    let error = $state<string | null>(null);

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    const dayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    function heatmapColor(count: number, max: number): string {
        if (max === 0 || count === 0) return 'var(--color-border)';
        const intensity = count / max;
        if (intensity < 0.25) return 'var(--color-tag-blue-bg, #dbeafe)';
        if (intensity < 0.5) return '#99bbff';
        if (intensity < 0.75) return '#6699ff';
        return 'var(--color-accent)';
    }

    function formatPercent(rate: number): string {
        return Math.round(rate * 100).toString();
    }

    const weekdayData = $derived((): [string, number][] => {
        if (stats === null) return [];
        // weekday_heatmap keys are 1..7 (Mon..Sun)
        return [1, 2, 3, 4, 5, 6, 7].map((d) => [
            String(d),
            stats!.weekday_heatmap[String(d)] ?? 0,
        ]);
    });

    const maxWeekday = $derived((): number => {
        const values = weekdayData().map(([, v]) => v);
        return values.length > 0 ? Math.max(...values) : 0;
    });

    const timeData = $derived((): [string, number][] => {
        if (stats === null) return [];
        return Array.from({ length: 24 }, (_, h) => [
            String(h),
            stats!.time_heatmap[String(h)] ?? 0,
        ]);
    });

    const maxTime = $derived((): number => {
        const values = timeData().map(([, v]) => v);
        return values.length > 0 ? Math.max(...values) : 0;
    });

    // ---------------------------------------------------------------------------
    // Data loading
    // ---------------------------------------------------------------------------

    async function load(): Promise<void> {
        loading = true;
        error = null;
        try {
            stats = await client.get<HouseholdStats>('/stats/household');
        } catch (e) {
            error = e instanceof Error ? e.message : 'Failed to load statistics';
        } finally {
            loading = false;
        }
    }

    $effect(() => {
        load();
    });
</script>

<svelte:head>
    <title>Statistics — SmartHabit</title>
</svelte:head>

<div class="page">
    <header class="page-header">
        <h1 class="page-title">Statistics</h1>
        <p class="page-subtitle">Household Overview</p>
    </header>

    {#if loading}
        <div class="skeleton-group" aria-live="polite" aria-busy="true">
            {#each [1, 2, 3] as i (i)}
                <div class="skeleton-block" style="animation-delay: {(i - 1) * 80}ms"></div>
            {/each}
        </div>
    {/if}

    {#if error !== null && !loading}
        <div class="error-card" role="alert">
            <p class="error-text">{error}</p>
            <button class="retry-btn" onclick={load}>Try again</button>
        </div>
    {/if}

    {#if stats !== null && !loading}
        <!-- Overall completion rate -->
        <section class="card">
            <h2 class="card-title">Completion Rate</h2>
            <div class="big-number">
                <span class="big-value">{formatPercent(stats.overall_completion_rate)}</span>
                <span class="big-unit">%</span>
            </div>
            <div class="progress-track" role="progressbar" aria-valuenow={Math.round(stats.overall_completion_rate * 100)} aria-valuemin={0} aria-valuemax={100}>
                <div class="progress-fill" style="width: {formatPercent(stats.overall_completion_rate)}%"></div>
            </div>
            <p class="card-hint">Last 30 days across all habits</p>
        </section>

        <!-- Weekday heatmap -->
        <section class="card">
            <h2 class="card-title">Activity by Weekday</h2>
            {#if maxWeekday() === 0}
                <p class="no-data">Not enough data yet</p>
            {:else}
                <svg viewBox="0 0 280 58" class="heatmap" aria-label="Weekday activity heatmap">
                    {#each weekdayData() as [_day, count], i (i)}
                        <rect
                            x={i * 40}
                            y="0"
                            width="35"
                            height="35"
                            rx="6"
                            fill={heatmapColor(count, maxWeekday())}
                        />
                        <text x={i * 40 + 17} y="52" text-anchor="middle" font-size="10" fill="var(--color-text-secondary)">
                            {dayLabels[i]}
                        </text>
                    {/each}
                </svg>
            {/if}
        </section>

        <!-- Time-of-day heatmap -->
        <section class="card">
            <h2 class="card-title">Activity by Hour</h2>
            {#if maxTime() === 0}
                <p class="no-data">Not enough data yet</p>
            {:else}
                <div class="time-heatmap-grid" aria-label="Hourly activity heatmap">
                    {#each timeData() as [hour, count] (hour)}
                        <div
                            class="time-cell"
                            style="background: {heatmapColor(count, maxTime())}"
                            title="{hour}:00 — {count} logs"
                            aria-label="{hour}:00, {count} completions"
                        ></div>
                    {/each}
                </div>
                <div class="time-labels" aria-hidden="true">
                    {#each [0, 6, 12, 18, 23] as h (h)}
                        <span class="time-label" style="left: {(h / 23) * 100}%">{String(h).padStart(2, '0')}h</span>
                    {/each}
                </div>
            {/if}
        </section>

        <!-- Per-habit mini cards -->
        {#if stats.habits.length > 0}
            <section class="card">
                <h2 class="card-title">Per Habit (30 days)</h2>
                <div class="habit-list">
                    {#each stats.habits as habit (habit.id)}
                        <div class="habit-row">
                            <span class="habit-icon">{habit.icon ?? '✓'}</span>
                            <span class="habit-name">{habit.name}</span>
                            <span class="habit-rate">{formatPercent(habit.completion_rate_30d)}%</span>
                        </div>
                    {/each}
                </div>
            </section>
        {/if}
    {/if}
</div>

<style>
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
        gap: 1.25rem;
    }

    /* Header */
    .page-header {
        display: flex;
        flex-direction: column;
        gap: 0.125rem;
    }

    .page-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--color-text-primary);
        letter-spacing: -0.02em;
        margin: 0;
    }

    .page-subtitle {
        font-size: 0.875rem;
        color: var(--color-text-muted);
        margin: 0;
    }

    /* Card */
    .card {
        background: var(--color-surface-raised);
        border: 1px solid var(--color-border);
        border-radius: var(--radius-xl);
        padding: 1.25rem;
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }

    .card-title {
        font-size: 0.8125rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--color-text-muted);
        margin: 0;
    }

    .card-hint {
        font-size: 0.75rem;
        color: var(--color-text-muted);
        margin: 0;
    }

    /* Big number */
    .big-number {
        display: flex;
        align-items: baseline;
        gap: 0.125rem;
    }

    .big-value {
        font-family: var(--font-mono);
        font-size: 3.5rem;
        font-weight: 700;
        color: var(--color-accent);
        line-height: 1;
    }

    .big-unit {
        font-family: var(--font-mono);
        font-size: 1.5rem;
        font-weight: 500;
        color: var(--color-text-secondary);
    }

    /* Progress bar */
    .progress-track {
        height: 6px;
        background: var(--color-border);
        border-radius: 999px;
        overflow: hidden;
    }

    .progress-fill {
        height: 100%;
        background: var(--color-accent);
        border-radius: 999px;
        transition: width 0.4s ease;
    }

    /* Heatmap SVG */
    .heatmap {
        width: 100%;
        overflow: visible;
    }

    /* Time heatmap grid */
    .time-heatmap-grid {
        display: grid;
        grid-template-columns: repeat(24, 1fr);
        gap: 2px;
    }

    .time-cell {
        height: 28px;
        border-radius: 3px;
        transition: background 0.15s;
    }

    .time-labels {
        position: relative;
        height: 1.25rem;
        margin-top: 0.25rem;
    }

    .time-label {
        position: absolute;
        transform: translateX(-50%);
        font-size: 0.625rem;
        color: var(--color-text-muted);
        font-family: var(--font-mono);
    }

    /* Habit list */
    .habit-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .habit-row {
        display: flex;
        align-items: center;
        gap: 0.625rem;
        padding: 0.625rem 0.75rem;
        background: var(--color-surface);
        border: 1px solid var(--color-border);
        border-radius: var(--radius-lg);
    }

    .habit-icon {
        font-size: 1.125rem;
        flex-shrink: 0;
        width: 1.5rem;
        text-align: center;
    }

    .habit-name {
        flex: 1;
        font-size: 0.9375rem;
        font-weight: 600;
        color: var(--color-text-primary);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .habit-rate {
        font-family: var(--font-mono);
        font-size: 0.875rem;
        font-weight: 500;
        color: var(--color-accent);
        flex-shrink: 0;
    }

    /* No data */
    .no-data {
        font-size: 0.875rem;
        color: var(--color-text-muted);
        margin: 0;
        text-align: center;
        padding: 1rem 0;
    }

    /* Skeleton */
    .skeleton-group {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .skeleton-block {
        height: 120px;
        border-radius: var(--radius-xl);
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
</style>
