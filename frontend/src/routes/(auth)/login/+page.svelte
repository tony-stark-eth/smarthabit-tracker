<script lang="ts">
    import { goto } from '$app/navigation';
    import { resolve } from '$app/paths';
    import { login } from '$lib/stores/auth.svelte';

    // ---------------------------------------------------------------------------
    // Form state
    // ---------------------------------------------------------------------------
    let email = $state('');
    let password = $state('');
    let submitting = $state(false);
    let errorMessage = $state('');

    async function handleSubmit(e: SubmitEvent): Promise<void> {
        e.preventDefault();
        submitting = true;
        errorMessage = '';

        try {
            await login(email, password);
            await goto(resolve('/'));
        } catch (err) {
            errorMessage = err instanceof Error ? err.message : 'Something went wrong';
        } finally {
            submitting = false;
        }
    }
</script>

<svelte:head>
    <title>Log in — SmartHabit</title>
</svelte:head>

<main class="auth-shell">
    <div class="auth-card">
        <header class="auth-header">
            <h1 class="auth-title">SmartHabit</h1>
            <p class="auth-subtitle">Log in to your account</p>
        </header>

        <form onsubmit={handleSubmit} class="auth-form" novalidate>
            <div class="field">
                <label for="email" class="field-label">Email</label>
                <input
                    id="email"
                    type="email"
                    bind:value={email}
                    autocomplete="email"
                    required
                    class="field-input"
                    placeholder="you@example.com"
                    disabled={submitting}
                />
            </div>

            <div class="field">
                <label for="password" class="field-label">Password</label>
                <input
                    id="password"
                    type="password"
                    bind:value={password}
                    autocomplete="current-password"
                    required
                    class="field-input"
                    placeholder="••••••••"
                    disabled={submitting}
                />
            </div>

            {#if errorMessage}
                <p class="form-error" role="alert">{errorMessage}</p>
            {/if}

            <button type="submit" class="btn-primary" disabled={submitting}>
                {submitting ? 'Logging in…' : 'Log in'}
            </button>
        </form>

        <footer class="auth-footer">
            <p>
                Don't have an account?
                <a href={resolve('/register')} class="auth-link">Sign up</a>
            </p>
        </footer>
    </div>
</main>

<style>
    .auth-shell {
        min-height: 100dvh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
        background: var(--color-bg);
    }

    .auth-card {
        width: 100%;
        max-width: 24rem;
        background: var(--color-surface-raised);
        border: 1px solid var(--color-border);
        border-radius: var(--radius-xl);
        padding: 2rem;
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
    }

    .auth-header {
        text-align: center;
    }

    .auth-title {
        font-size: 1.75rem;
        font-weight: 700;
        color: var(--color-text-primary);
        letter-spacing: -0.025em;
    }

    .auth-subtitle {
        margin-top: 0.25rem;
        font-size: 0.875rem;
        color: var(--color-text-secondary);
    }

    .auth-form {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .field {
        display: flex;
        flex-direction: column;
        gap: 0.375rem;
    }

    .field-label {
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--color-text-primary);
    }

    .field-input {
        padding: 0.625rem 0.75rem;
        border: 1px solid var(--color-border-strong);
        border-radius: var(--radius-md);
        background: var(--color-surface);
        color: var(--color-text-primary);
        font-size: 0.9375rem;
        font-family: inherit;
        transition: border-color 0.15s;
        outline: none;
    }

    .field-input:focus {
        border-color: var(--color-accent);
    }

    .field-input:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .form-error {
        font-size: 0.875rem;
        color: var(--color-error);
        padding: 0.625rem 0.75rem;
        background: color-mix(in srgb, var(--color-error) 10%, transparent);
        border-radius: var(--radius-md);
        border: 1px solid color-mix(in srgb, var(--color-error) 30%, transparent);
    }

    .btn-primary {
        padding: 0.75rem 1rem;
        background: var(--color-accent);
        color: var(--color-accent-text);
        border: none;
        border-radius: var(--radius-md);
        font-size: 0.9375rem;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
        transition: background 0.15s, opacity 0.15s;
    }

    .btn-primary:hover:not(:disabled) {
        background: var(--color-accent-hover);
    }

    .btn-primary:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .auth-footer {
        text-align: center;
        font-size: 0.875rem;
        color: var(--color-text-secondary);
    }

    .auth-link {
        color: var(--color-accent);
        font-weight: 600;
        text-decoration: none;
    }

    .auth-link:hover {
        text-decoration: underline;
    }
</style>
