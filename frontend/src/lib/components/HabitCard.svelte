<script lang="ts">
    import { goto } from '$app/navigation';
    import { resolve } from '$app/paths';
    import { t } from '$lib/i18n';

    interface LastLog {
        logged_at: string;
        user_display_name: string;
    }

    interface Props {
        id: string;
        name: string;
        icon: string | null;
        timeWindowStart: string | null;
        timeWindowEnd: string | null;
        timeWindowMode: string;
        isDoneToday: boolean;
        lastLog: LastLog | null;
        onLog: () => void;
        onUnlog: () => void;
    }

    const {
        id,
        name,
        icon,
        timeWindowStart,
        timeWindowEnd,
        timeWindowMode,
        isDoneToday,
        lastLog,
        onLog,
        onUnlog,
    }: Props = $props();

    let justLogged = $state(false);

    function navigateToDetail(): void {
        goto(resolve(`/habits/${id}`));
    }

    function formatTime(isoString: string): string {
        const d = new Date(isoString);
        const h = d.getHours().toString().padStart(2, '0');
        const m = d.getMinutes().toString().padStart(2, '0');
        return `${h}:${m}`;
    }

    function isOverdue(): boolean {
        if (isDoneToday || timeWindowEnd === null) return false;
        const now = new Date();
        const nowMinutes = now.getHours() * 60 + now.getMinutes();
        const parts = timeWindowEnd.split(':');
        const endH = parseInt(parts[0] ?? '0', 10);
        const endM = parseInt(parts[1] ?? '0', 10);
        const endMinutes = endH * 60 + endM;
        return nowMinutes > endMinutes;
    }

    function handleCheckButton(event: MouseEvent): void {
        event.stopPropagation();
        if (isDoneToday) {
            onUnlog();
        } else {
            justLogged = true;
            onLog();
            // Reset animation class after animation completes
            setTimeout(() => {
                justLogged = false;
            }, 350);
        }
    }
</script>

<div
    class="habit-card"
    class:habit-card--done={isDoneToday}
    class:just-logged={justLogged}
    data-id={id}
    role="article"
>
    <div class="card-icon" aria-hidden="true">
        {#if icon}
            <span class="emoji">{icon}</span>
        {:else}
            <span class="emoji">✓</span>
        {/if}
    </div>

    <div
        class="card-body"
        role="button"
        tabindex="0"
        aria-label={t('habit_view_details', { name })}
        onclick={navigateToDetail}
        onkeydown={(e) => { if (e.key === 'Enter' || e.key === ' ') navigateToDetail(); }}
    >
        <span class="card-name">{name}</span>

        <div class="card-meta">
            {#if timeWindowStart !== null && timeWindowEnd !== null}
                <span
                    class="time-tag"
                    class:time-tag--overdue={isOverdue()}
                    aria-label="Time window {timeWindowStart} to {timeWindowEnd}"
                >
                    {timeWindowStart.slice(0, 5)}–{timeWindowEnd.slice(0, 5)}
                    {#if isOverdue()}
                        <span class="overdue-label">{t('habit_overdue')}</span>
                    {/if}
                </span>
                {#if timeWindowMode === 'auto'}
                    <span class="auto-badge" aria-label="Learned time window">{t('habit_auto_badge')}</span>
                {/if}
            {/if}

            {#if isDoneToday && lastLog !== null}
                <span class="card-subtitle card-subtitle--done">
                    {t('habit_done_by', { time: formatTime(lastLog.logged_at), name: lastLog.user_display_name })}
                </span>
            {:else if !isDoneToday}
                <span class="card-subtitle card-subtitle--pending">{t('habit_not_yet')}</span>
            {/if}
        </div>
    </div>

    <button
        class="check-btn"
        class:check-btn--done={isDoneToday}
        onclick={handleCheckButton}
        aria-label={isDoneToday ? t('habit_undo', { name }) : t('habit_log', { name })}
        aria-pressed={isDoneToday}
    >
        {#if isDoneToday}
            <!-- Filled checkmark -->
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
        {:else}
            <!-- Empty checkmark -->
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
        {/if}
    </button>
</div>

<style>
    @keyframes tap-pulse {
        0%   { transform: scale(1); }
        30%  { transform: scale(0.9); }
        60%  { transform: scale(1.1); }
        100% { transform: scale(1); }
    }

    .habit-card {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.875rem 1rem;
        background: var(--color-surface-raised);
        border: 1px solid var(--color-border);
        border-left: 3px solid var(--color-border);
        border-radius: 12px;
        transition: border-left-color 0.25s ease, background 0.25s ease;
    }

    .habit-card--done {
        border-left-color: var(--color-success);
    }

    .habit-card.just-logged {
        animation: tap-pulse 0.3s ease-out;
    }

    .card-icon {
        flex-shrink: 0;
        width: 2.25rem;
        height: 2.25rem;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--color-surface);
        border-radius: var(--radius-md);
    }

    .emoji {
        font-size: 1.25rem;
        line-height: 1;
    }

    .card-body {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        cursor: pointer;
        background: none;
        border: none;
        padding: 0;
        text-align: left;
    }

    .card-name {
        font-size: 0.9375rem;
        font-weight: 600;
        color: var(--color-text-primary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .card-meta {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.375rem;
    }

    .card-subtitle {
        font-size: 0.8125rem;
    }

    .card-subtitle--done {
        color: var(--color-text-secondary);
    }

    .card-subtitle--pending {
        color: var(--color-text-muted);
    }

    .log-time {
        font-family: var(--font-mono);
        font-size: 0.8125rem;
    }

    .time-tag {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.125rem 0.4375rem;
        border-radius: var(--radius-sm);
        font-family: var(--font-mono);
        font-size: 0.6875rem;
        font-weight: 500;
        background: color-mix(in srgb, var(--color-info) 12%, transparent);
        color: var(--color-info);
    }

    .time-tag--overdue {
        background: color-mix(in srgb, var(--color-warning) 15%, transparent);
        color: var(--color-warning);
    }

    .auto-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.125rem 0.375rem;
        border-radius: var(--radius-sm);
        font-family: var(--font-sans);
        font-size: 0.625rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        background: color-mix(in srgb, var(--color-accent) 12%, transparent);
        color: var(--color-accent);
        border: 1px solid color-mix(in srgb, var(--color-accent) 25%, transparent);
    }

    .overdue-label {
        font-family: var(--font-sans);
        font-size: 0.6875rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .check-btn {
        flex-shrink: 0;
        width: 44px;
        height: 44px;
        border-radius: 50%;
        border: 2px solid var(--color-accent);
        background: transparent;
        color: var(--color-accent);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: background 0.2s ease, color 0.2s ease, border-color 0.2s ease;
        -webkit-tap-highlight-color: transparent;
    }

    .check-btn:hover:not(:disabled) {
        background: color-mix(in srgb, var(--color-accent) 10%, transparent);
    }

    .check-btn--done {
        background: var(--color-success);
        border-color: var(--color-success);
        color: #ffffff;
    }

    .check-btn--done:hover {
        background: color-mix(in srgb, var(--color-success) 85%, #000);
        border-color: color-mix(in srgb, var(--color-success) 85%, #000);
    }
</style>
