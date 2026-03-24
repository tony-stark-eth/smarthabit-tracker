<script lang="ts">
    import { page } from '$app/stores';
    import { goto } from '$app/navigation';
    import { resolve } from '$app/paths';
    import { client } from '$lib/api/client';
    import { t } from '$lib/i18n';

    // ---------------------------------------------------------------------------
    // Types
    // ---------------------------------------------------------------------------

    interface WeeklyBucket {
        week_start: string;
        completion_rate: number;
    }

    interface HabitStats {
        habit_id: string;
        habit_name: string;
        habit_icon: string | null;
        current_streak: number;
        longest_streak: number;
        completion_rate_30d: number;
        completion_rate_prev_30d: number;
        average_completion_time: string | null;
        trend: number;
        weekly_buckets: WeeklyBucket[];
    }

    // ---------------------------------------------------------------------------
    // State
    // ---------------------------------------------------------------------------

    const habitId = $derived($page.params['id'] ?? '');

    let stats = $state<HabitStats | null>(null);
    let loading = $state(true);
    let error = $state<string | null>(null);

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    function formatPercent(rate: number): string {
        return Math.round(rate * 100).toString();
    }

    function trendLabel(trend: number): string {
        if (trend > 0.02) return t('stats_trend_improving');
        if (trend < -0.02) return t('stats_trend_declining');
        return t('stats_trend_stable');
    }

    function trendArrow(trend: number): string {
        if (trend > 0.02) return '↑';
        if (trend < -0.02) return '↓';
        return '→';
    }

    function trendClass(trend: number): string {
        if (trend > 0.02) return 'trend-up';
        if (trend < -0.02) return 'trend-down';
        return 'trend-stable';
    }

    // Weekly chart: normalize rates to bar heights (max 90px bar height)
    const weeklyBarData = $derived((): number[] => {
        if (stats === null) return [];
        const buckets = stats.weekly_buckets.slice(-4);
        return buckets.map((b) => Math.round(b.completion_rate * 90));
    });

    const weeklyLabels = $derived((): string[] => {
        if (stats === null) return [];
        const buckets = stats.weekly_buckets.slice(-4);
        return buckets.map((b) => {
            const d = new Date(b.week_start);
            return `${(d.getMonth() + 1).toString().padStart(2, '0')}/${d.getDate().toString().padStart(2, '0')}`;
        });
    });

    // ---------------------------------------------------------------------------
    // Data loading
    // ---------------------------------------------------------------------------

    async function load(): Promise<void> {
        const id = habitId;
        if (id === '') return;
        loading = true;
        error = null;
        try {
            stats = await client.get<HabitStats>(`/habits/${id}/stats`);
        } catch (e) {
            error = e instanceof Error ? e.message : 'Failed to load statistics';
        } finally {
            loading = false;
        }
    }

    $effect(() => {
        const id = habitId;
        if (id !== '') {
            load();
        }
    });
</script>

<svelte:head>
    <title>{stats !== null ? stats.habit_name + ' — Stats' : 'Statistics'} — SmartHabit</title>
</svelte:head>

<div class="page">
    <!-- Header -->
    <header class="page-header">
        <button
            class="back-btn"
            onclick={() => goto(resolve(`/habits/${habitId}`))}
            aria-label={t('history_title')}
        >
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <polyline points="15 18 9 12 15 6"></polyline>
            </svg>
        </button>

        <div class="header-text">
            <h1 class="page-title">
                {stats !== null ? stats.habit_name : t('stats_title')}
            </h1>
            <p class="page-subtitle">{t('stats_subtitle')}</p>
        </div>
    </header>

    <!-- Loading -->
    {#if loading}
        <div class="skeleton-group" aria-live="polite" aria-busy="true">
            {#each [1, 2, 3] as i (i)}
                <div class="skeleton-block" style="animation-delay: {(i - 1) * 80}ms"></div>
            {/each}
        </div>
    {/if}

    <!-- Error -->
    {#if error !== null && !loading}
        <div class="error-card" role="alert">
            <p class="error-text">{error}</p>
            <button class="retry-btn" onclick={load}>{t('common_try_again')}</button>
        </div>
    {/if}

    {#if stats !== null && !loading}
        <!-- Streaks row -->
        <div class="metrics-row">
            <div class="metric-card">
                <span class="metric-label">{t('stats_current_streak')}</span>
                <div class="metric-number">
                    <span class="metric-value">{stats.current_streak}</span>
                    <span class="metric-unit">{t('stats_days')}</span>
                </div>
            </div>
            <div class="metric-card">
                <span class="metric-label">{t('stats_longest_streak')}</span>
                <div class="metric-number">
                    <span class="metric-value">{stats.longest_streak}</span>
                    <span class="metric-unit">{t('stats_days')}</span>
                </div>
            </div>
        </div>

        <!-- Completion rate 30d -->
        <section class="card">
            <h2 class="card-title">{t('stats_completion_rate')}</h2>
            <div class="big-number">
                <span class="big-value">{formatPercent(stats.completion_rate_30d)}</span>
                <span class="big-unit">%</span>
            </div>
            <div class="progress-track" role="progressbar" aria-valuenow={Math.round(stats.completion_rate_30d * 100)} aria-valuemin={0} aria-valuemax={100}>
                <div class="progress-fill" style="width: {formatPercent(stats.completion_rate_30d)}%"></div>
            </div>
            <p class="card-hint">{t('stats_last_30_days')}</p>
        </section>

        <!-- Average time + trend -->
        <div class="metrics-row">
            <div class="metric-card">
                <span class="metric-label">{t('stats_avg_time')}</span>
                {#if stats.average_completion_time !== null}
                    <span class="metric-time">{stats.average_completion_time}</span>
                {:else}
                    <span class="metric-na">—</span>
                {/if}
            </div>
            <div class="metric-card">
                <span class="metric-label">{t('stats_trend')}</span>
                <div class="trend-display {trendClass(stats.trend)}">
                    <span class="trend-arrow">{trendArrow(stats.trend)}</span>
                    <span class="trend-text">{trendLabel(stats.trend)}</span>
                </div>
            </div>
        </div>

        <!-- Weekly bar chart (last 4 weeks) -->
        {#if weeklyBarData().length > 0}
            <section class="card">
                <h2 class="card-title">{t('stats_weekly_chart')}</h2>
                <svg viewBox="0 0 200 110" class="chart" aria-label="Weekly completion chart">
                    {#each weeklyBarData() as value, i (i)}
                        <rect
                            x={i * 50 + 5}
                            y={100 - value}
                            width="40"
                            height={value === 0 ? 2 : value}
                            rx="4"
                            fill={value === 0 ? 'var(--color-border)' : 'var(--color-accent)'}
                        />
                    {/each}
                    <!-- Baseline -->
                    <line x1="0" y1="100" x2="200" y2="100" stroke="var(--color-border)" stroke-width="1" />
                </svg>
                {#if weeklyLabels().length > 0}
                    <div class="chart-labels" aria-hidden="true">
                        {#each weeklyLabels() as label (label)}
                            <span class="chart-label">{label}</span>
                        {/each}
                    </div>
                {/if}
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

    /* Metrics row */
    .metrics-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
    }

    .metric-card {
        background: var(--color-surface-raised);
        border: 1px solid var(--color-border);
        border-radius: var(--radius-xl);
        padding: 1rem;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .metric-label {
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--color-text-muted);
    }

    .metric-number {
        display: flex;
        align-items: baseline;
        gap: 0.25rem;
    }

    .metric-value {
        font-family: var(--font-mono);
        font-size: 2.5rem;
        font-weight: 700;
        color: var(--color-accent);
        line-height: 1;
    }

    .metric-unit {
        font-size: 0.875rem;
        color: var(--color-text-secondary);
    }

    .metric-time {
        font-family: var(--font-mono);
        font-size: 2rem;
        font-weight: 700;
        color: var(--color-text-primary);
        line-height: 1;
    }

    .metric-na {
        font-family: var(--font-mono);
        font-size: 2rem;
        color: var(--color-text-muted);
        line-height: 1;
    }

    /* Trend */
    .trend-display {
        display: flex;
        align-items: center;
        gap: 0.375rem;
    }

    .trend-arrow {
        font-size: 1.75rem;
        font-family: var(--font-mono);
        line-height: 1;
    }

    .trend-text {
        font-size: 0.875rem;
        font-weight: 600;
    }

    .trend-up .trend-arrow,
    .trend-up .trend-text {
        color: var(--color-success);
    }

    .trend-down .trend-arrow,
    .trend-down .trend-text {
        color: var(--color-error);
    }

    .trend-stable .trend-arrow,
    .trend-stable .trend-text {
        color: var(--color-text-secondary);
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

    /* Chart */
    .chart {
        width: 100%;
        overflow: visible;
    }

    .chart-labels {
        display: flex;
        justify-content: space-around;
    }

    .chart-label {
        font-family: var(--font-mono);
        font-size: 0.625rem;
        color: var(--color-text-muted);
        text-align: center;
    }

    /* Skeleton */
    .skeleton-group {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .skeleton-block {
        height: 100px;
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
