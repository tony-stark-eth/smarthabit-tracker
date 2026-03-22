<script lang="ts">
    import { getUser } from '$lib/stores/auth.svelte';

    const user = $derived(getUser());
</script>

<svelte:head>
    <title>Today — SmartHabit</title>
</svelte:head>

<div class="page">
    <header class="page-header">
        <h1 class="page-title">SmartHabit</h1>
        {#if user}
            <p class="page-greeting">Hello, {user.display_name}</p>
        {/if}
    </header>

    <div class="placeholder-card">
        <p class="placeholder-label">Dashboard</p>
        <p class="placeholder-text">Coming in Wave 2</p>
    </div>
</div>

<style>
    .page {
        padding: 1.5rem 1rem;
        max-width: 40rem;
        margin: 0 auto;
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
    }

    .page-header {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .page-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--color-text-primary);
        letter-spacing: -0.02em;
    }

    .page-greeting {
        font-size: 0.9375rem;
        color: var(--color-text-secondary);
    }

    .placeholder-card {
        background: var(--color-surface-raised);
        border: 1px dashed var(--color-border-strong);
        border-radius: var(--radius-xl);
        padding: 3rem 2rem;
        text-align: center;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .placeholder-label {
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--color-text-muted);
    }

    .placeholder-text {
        font-size: 1.125rem;
        font-weight: 600;
        color: var(--color-text-secondary);
    }
</style>
