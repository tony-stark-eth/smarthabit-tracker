<script lang="ts">
    interface Props {
        done: number;
        total: number;
    }

    const { done, total }: Props = $props();

    const percent = $derived(total > 0 ? Math.round((done / total) * 100) : 0);
    const allDone = $derived(total > 0 && done >= total);
    const label = $derived(
        allDone
            ? 'All done for today!'
            : `${done} of ${total} done`
    );
</script>

<div class="progress-wrapper" role="group" aria-label="Today's progress">
    <div class="progress-header">
        <span class="progress-label">{label}</span>
        <span class="progress-pct" aria-hidden="true">{percent}%</span>
    </div>
    <div
        class="progress-track"
        role="progressbar"
        aria-valuenow={done}
        aria-valuemin={0}
        aria-valuemax={total}
        aria-label="{done} of {total} habits completed"
    >
        <div
            class="progress-fill"
            class:progress-fill--complete={allDone}
            style="width: {percent}%"
        ></div>
    </div>
</div>

<style>
    .progress-wrapper {
        display: flex;
        flex-direction: column;
        gap: 0.375rem;
    }

    .progress-header {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
    }

    .progress-label {
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--color-text-secondary);
    }

    .progress-pct {
        font-family: var(--font-mono);
        font-size: 0.8125rem;
        color: var(--color-text-muted);
    }

    .progress-track {
        height: 6px;
        background: var(--color-border);
        border-radius: 9999px;
        overflow: hidden;
    }

    .progress-fill {
        height: 100%;
        border-radius: 9999px;
        background: var(--color-accent);
        transition: width 0.4s ease-out, background 0.3s ease;
    }

    .progress-fill--complete {
        background: var(--color-success);
    }
</style>
