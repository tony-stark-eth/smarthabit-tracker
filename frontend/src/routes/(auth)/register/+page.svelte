<script lang="ts">
    import { goto } from '$app/navigation';
    import { register } from '$lib/stores/auth.svelte';

    // ---------------------------------------------------------------------------
    // Form state — Svelte 5 runes
    // ---------------------------------------------------------------------------
    let email = $state('');
    let password = $state('');
    let confirmPassword = $state('');
    let displayName = $state('');
    let timezone = $state(
        typeof Intl !== 'undefined'
            ? Intl.DateTimeFormat().resolvedOptions().timeZone
            : 'Europe/Berlin',
    );
    let locale = $state('en');
    let householdMode = $state<'create' | 'join'>('create');
    let householdName = $state('');
    let inviteCode = $state('');
    let consent = $state(false);

    let submitting = $state(false);
    let errorMessage = $state('');

    // Field-level errors
    let fieldErrors = $state<Record<string, string>>({});

    function validate(): boolean {
        const errors: Record<string, string> = {};

        if (!email) errors['email'] = 'Email is required.';
        if (!password) errors['password'] = 'Password is required.';
        else if (password.length < 8) errors['password'] = 'Password must be at least 8 characters.';
        if (password !== confirmPassword) errors['confirmPassword'] = 'Passwords do not match.';
        if (!displayName.trim()) errors['displayName'] = 'Display name is required.';
        if (householdMode === 'create' && !householdName.trim())
            errors['householdName'] = 'Household name is required.';
        if (householdMode === 'join' && !inviteCode.trim())
            errors['inviteCode'] = 'Invite code is required.';
        if (!consent) errors['consent'] = 'You must accept the privacy policy.';

        fieldErrors = errors;
        return Object.keys(errors).length === 0;
    }

    async function handleSubmit(e: SubmitEvent): Promise<void> {
        e.preventDefault();
        errorMessage = '';
        if (!validate()) return;

        submitting = true;
        try {
            await register({
                email,
                password,
                display_name: displayName,
                timezone,
                locale,
                ...(householdMode === 'create'
                    ? { household_name: householdName }
                    : { invite_code: inviteCode }),
                consent,
            });
            await goto('/');
        } catch (err) {
            errorMessage = err instanceof Error ? err.message : 'Something went wrong';
        } finally {
            submitting = false;
        }
    }
</script>

<svelte:head>
    <title>Sign up — SmartHabit</title>
</svelte:head>

<main class="auth-shell">
    <div class="auth-card">
        <header class="auth-header">
            <h1 class="auth-title">SmartHabit</h1>
            <p class="auth-subtitle">Create your account</p>
        </header>

        <form onsubmit={handleSubmit} class="auth-form" novalidate>
            <!-- Email -->
            <div class="field">
                <label for="email" class="field-label">Email</label>
                <input
                    id="email"
                    type="email"
                    bind:value={email}
                    autocomplete="email"
                    class="field-input"
                    class:field-input--error={fieldErrors['email']}
                    placeholder="you@example.com"
                    disabled={submitting}
                />
                {#if fieldErrors['email']}
                    <p class="field-error">{fieldErrors['email']}</p>
                {/if}
            </div>

            <!-- Display name -->
            <div class="field">
                <label for="display_name" class="field-label">Display name</label>
                <input
                    id="display_name"
                    type="text"
                    bind:value={displayName}
                    autocomplete="name"
                    class="field-input"
                    class:field-input--error={fieldErrors['displayName']}
                    placeholder="Your name"
                    disabled={submitting}
                />
                {#if fieldErrors['displayName']}
                    <p class="field-error">{fieldErrors['displayName']}</p>
                {/if}
            </div>

            <!-- Password -->
            <div class="field">
                <label for="password" class="field-label">Password</label>
                <input
                    id="password"
                    type="password"
                    bind:value={password}
                    autocomplete="new-password"
                    class="field-input"
                    class:field-input--error={fieldErrors['password']}
                    placeholder="Min. 8 characters"
                    disabled={submitting}
                />
                {#if fieldErrors['password']}
                    <p class="field-error">{fieldErrors['password']}</p>
                {/if}
            </div>

            <!-- Confirm password -->
            <div class="field">
                <label for="confirm_password" class="field-label">Confirm password</label>
                <input
                    id="confirm_password"
                    type="password"
                    bind:value={confirmPassword}
                    autocomplete="new-password"
                    class="field-input"
                    class:field-input--error={fieldErrors['confirmPassword']}
                    placeholder="••••••••"
                    disabled={submitting}
                />
                {#if fieldErrors['confirmPassword']}
                    <p class="field-error">{fieldErrors['confirmPassword']}</p>
                {/if}
            </div>

            <!-- Timezone (auto-detected, still editable) -->
            <div class="field">
                <label for="timezone" class="field-label">Timezone</label>
                <input
                    id="timezone"
                    type="text"
                    bind:value={timezone}
                    class="field-input"
                    placeholder="Europe/Berlin"
                    disabled={submitting}
                />
            </div>

            <!-- Locale -->
            <div class="field">
                <label for="locale" class="field-label">Language</label>
                <select
                    id="locale"
                    bind:value={locale}
                    class="field-input"
                    disabled={submitting}
                >
                    <option value="en">English</option>
                    <option value="de">Deutsch</option>
                </select>
            </div>

            <!-- Household mode toggle -->
            <fieldset class="toggle-group">
                <legend class="field-label">Household</legend>
                <div class="toggle-buttons">
                    <button
                        type="button"
                        class="toggle-btn"
                        class:toggle-btn--active={householdMode === 'create'}
                        onclick={() => (householdMode = 'create')}
                    >
                        Create new household
                    </button>
                    <button
                        type="button"
                        class="toggle-btn"
                        class:toggle-btn--active={householdMode === 'join'}
                        onclick={() => (householdMode = 'join')}
                    >
                        Join with invite code
                    </button>
                </div>

                {#if householdMode === 'create'}
                    <div class="field" style="margin-top: 0.5rem;">
                        <label for="household_name" class="field-label">Household name</label>
                        <input
                            id="household_name"
                            type="text"
                            bind:value={householdName}
                            class="field-input"
                            class:field-input--error={fieldErrors['householdName']}
                            placeholder="e.g. Smith Family"
                            disabled={submitting}
                        />
                        {#if fieldErrors['householdName']}
                            <p class="field-error">{fieldErrors['householdName']}</p>
                        {/if}
                    </div>
                {:else}
                    <div class="field" style="margin-top: 0.5rem;">
                        <label for="invite_code" class="field-label">Invite code</label>
                        <input
                            id="invite_code"
                            type="text"
                            bind:value={inviteCode}
                            class="field-input"
                            class:field-input--error={fieldErrors['inviteCode']}
                            placeholder="ABC-1234"
                            disabled={submitting}
                        />
                        {#if fieldErrors['inviteCode']}
                            <p class="field-error">{fieldErrors['inviteCode']}</p>
                        {/if}
                    </div>
                {/if}
            </fieldset>

            <!-- Consent -->
            <label class="consent-row" class:consent-row--error={fieldErrors['consent']}>
                <input
                    type="checkbox"
                    bind:checked={consent}
                    disabled={submitting}
                    class="consent-checkbox"
                />
                <span class="consent-label">I accept the privacy policy</span>
            </label>
            {#if fieldErrors['consent']}
                <p class="field-error">{fieldErrors['consent']}</p>
            {/if}

            {#if errorMessage}
                <p class="form-error" role="alert">{errorMessage}</p>
            {/if}

            <button type="submit" class="btn-primary" disabled={submitting}>
                {submitting ? 'Creating account…' : 'Sign up'}
            </button>
        </form>

        <footer class="auth-footer">
            <p>
                Already have an account?
                <a href="/login" class="auth-link">Log in</a>
            </p>
        </footer>
    </div>
</main>

<style>
    .auth-shell {
        min-height: 100dvh;
        display: flex;
        align-items: flex-start;
        justify-content: center;
        padding: 2rem 1.5rem;
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

    .field-input--error {
        border-color: var(--color-error);
    }

    .field-error {
        font-size: 0.8125rem;
        color: var(--color-error);
    }

    .toggle-group {
        border: 1px solid var(--color-border);
        border-radius: var(--radius-lg);
        padding: 0.75rem;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .toggle-buttons {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.375rem;
    }

    .toggle-btn {
        padding: 0.5rem 0.5rem;
        font-size: 0.8125rem;
        font-family: inherit;
        font-weight: 500;
        border-radius: var(--radius-md);
        border: 1px solid var(--color-border-strong);
        background: var(--color-surface);
        color: var(--color-text-secondary);
        cursor: pointer;
        transition: all 0.15s;
        text-align: center;
    }

    .toggle-btn--active {
        background: var(--color-accent);
        color: var(--color-accent-text);
        border-color: var(--color-accent);
    }

    .consent-row {
        display: flex;
        align-items: flex-start;
        gap: 0.5rem;
        cursor: pointer;
    }

    .consent-checkbox {
        margin-top: 0.125rem;
        flex-shrink: 0;
        accent-color: var(--color-accent);
        width: 1rem;
        height: 1rem;
    }

    .consent-label {
        font-size: 0.875rem;
        color: var(--color-text-secondary);
    }

    .consent-row--error .consent-label {
        color: var(--color-error);
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
