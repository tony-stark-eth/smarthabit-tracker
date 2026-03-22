<script lang="ts">
    import { client } from '$lib/api/client';
    import { getUser, logout, fetchUser } from '$lib/stores/auth.svelte';
    import type { Household } from '$lib/types';

    type Theme = 'auto' | 'light' | 'dark';

    const user = $derived(getUser());

    // ---------------------------------------------------------------------------
    // Theme
    // ---------------------------------------------------------------------------
    let theme = $state<Theme>(
        typeof localStorage !== 'undefined'
            ? ((localStorage.getItem('theme') as Theme | null) ?? 'auto')
            : 'auto',
    );
    let savingTheme = $state(false);

    async function setTheme(newTheme: Theme): Promise<void> {
        theme = newTheme;
        savingTheme = true;

        // Apply theme to document
        if (newTheme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        } else if (newTheme === 'light') {
            document.documentElement.setAttribute('data-theme', 'light');
        } else {
            document.documentElement.removeAttribute('data-theme');
        }
        localStorage.setItem('theme', newTheme);

        try {
            await client.put('/user/me', { theme: newTheme });
        } catch {
            // Non-critical — local theme already applied
        } finally {
            savingTheme = false;
        }
    }

    // ---------------------------------------------------------------------------
    // Locale
    // ---------------------------------------------------------------------------
    // Initialize from user once available, keep in sync via $effect
    let locale = $state('en');
    $effect(() => {
        if (user?.locale) locale = user.locale;
    });
    let savingLocale = $state(false);
    let localeSaved = $state(false);

    async function saveLocale(): Promise<void> {
        savingLocale = true;
        localeSaved = false;
        try {
            await client.put('/user/me', { locale });
            await fetchUser();
            localeSaved = true;
            setTimeout(() => (localeSaved = false), 2000);
        } catch {
            // Surface error if needed
        } finally {
            savingLocale = false;
        }
    }

    // ---------------------------------------------------------------------------
    // Export
    // ---------------------------------------------------------------------------
    let exporting = $state(false);

    async function exportData(): Promise<void> {
        exporting = true;
        try {
            const response = await fetch('/api/v1/user/export', {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('access_token') ?? ''}`,
                },
            });
            if (!response.ok) throw new Error('Export failed');

            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `smarthabit-export-${new Date().toISOString().slice(0, 10)}.json`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        } catch {
            // TODO: show toast notification
        } finally {
            exporting = false;
        }
    }

    // ---------------------------------------------------------------------------
    // Household
    // ---------------------------------------------------------------------------

    let inviteCodeCopied = $state(false);
    let joinCode = $state('');
    let joining = $state(false);
    let joinError = $state<string | null>(null);
    let joinSuccess = $state(false);

    async function copyInviteCode(): Promise<void> {
        const code = user?.household?.invite_code;
        if (!code) return;
        try {
            await navigator.clipboard.writeText(code);
            inviteCodeCopied = true;
            setTimeout(() => (inviteCodeCopied = false), 2000);
        } catch {
            // Clipboard not available — no-op
        }
    }

    async function joinHousehold(): Promise<void> {
        if (joinCode.trim() === '') return;
        joining = true;
        joinError = null;
        joinSuccess = false;
        try {
            await client.post<{ household: Household }>('/household/join', { invite_code: joinCode.trim() });
            await fetchUser();
            joinCode = '';
            joinSuccess = true;
            setTimeout(() => (joinSuccess = false), 3000);
        } catch (e) {
            joinError = e instanceof Error ? e.message : 'Failed to join household.';
        } finally {
            joining = false;
        }
    }

    // ---------------------------------------------------------------------------
    // Delete account
    // ---------------------------------------------------------------------------
    let showDeleteConfirm = $state(false);
    let deleting = $state(false);
    let deleteConfirmText = $state('');

    async function deleteAccount(): Promise<void> {
        deleting = true;
        try {
            await client.delete('/user/me');
            logout();
        } catch {
            deleting = false;
            showDeleteConfirm = false;
        }
    }
</script>

<svelte:head>
    <title>Settings — SmartHabit</title>
</svelte:head>

<div class="page">
    <header class="page-header">
        <h1 class="page-title">Settings</h1>
        {#if user}
            <p class="page-subtitle">{user.email}</p>
        {/if}
    </header>

    <!-- Language -->
    <section class="settings-section">
        <h2 class="section-title">Language</h2>
        <div class="row">
            <select
                bind:value={locale}
                class="field-input"
                disabled={savingLocale}
                onchange={saveLocale}
                aria-label="Language"
            >
                <option value="en">English</option>
                <option value="de">Deutsch</option>
            </select>
            {#if localeSaved}
                <span class="saved-badge">Saved</span>
            {/if}
        </div>
    </section>

    <!-- Theme -->
    <section class="settings-section">
        <h2 class="section-title">Theme</h2>
        <div class="theme-buttons">
            <button
                class="theme-btn"
                class:theme-btn--active={theme === 'auto'}
                onclick={() => setTheme('auto')}
                disabled={savingTheme}
            >
                System
            </button>
            <button
                class="theme-btn"
                class:theme-btn--active={theme === 'light'}
                onclick={() => setTheme('light')}
                disabled={savingTheme}
            >
                Light
            </button>
            <button
                class="theme-btn"
                class:theme-btn--active={theme === 'dark'}
                onclick={() => setTheme('dark')}
                disabled={savingTheme}
            >
                Dark
            </button>
        </div>
    </section>

    <!-- Household -->
    {#if user?.household}
        <section class="settings-section">
            <h2 class="section-title">Household</h2>
            <p class="household-name">{user.household.name}</p>
            <div class="invite-code-row">
                <div class="invite-code-display">
                    <span class="invite-code-label">Invite code</span>
                    <code class="invite-code-value">{user.household.invite_code}</code>
                </div>
                <button
                    class="action-btn action-btn--copy"
                    onclick={copyInviteCode}
                    aria-label="Copy invite code"
                >
                    {inviteCodeCopied ? 'Copied!' : 'Copy'}
                </button>
            </div>
            <p class="invite-hint">Share this code so others can join your household.</p>
        </section>
    {/if}

    <!-- Join Household -->
    <section class="settings-section">
        <h2 class="section-title">Join another household</h2>
        <p class="join-hint">Enter an invite code to switch to a different household.</p>
        <div class="join-row">
            <input
                type="text"
                bind:value={joinCode}
                class="field-input"
                placeholder="8-character code"
                disabled={joining}
                maxlength={8}
                aria-label="Invite code"
            />
            <button
                class="action-btn action-btn--join"
                onclick={joinHousehold}
                disabled={joining || joinCode.trim() === ''}
            >
                {joining ? 'Joining…' : 'Join'}
            </button>
        </div>
        {#if joinError !== null}
            <p class="join-error" role="alert">{joinError}</p>
        {/if}
        {#if joinSuccess}
            <p class="join-success" role="status">Joined household successfully!</p>
        {/if}
    </section>

    <!-- Data -->
    <section class="settings-section">
        <h2 class="section-title">Data</h2>

        <button
            class="action-btn"
            onclick={exportData}
            disabled={exporting}
        >
            {exporting ? 'Exporting…' : 'Export my data'}
        </button>
    </section>

    <!-- Account -->
    <section class="settings-section">
        <h2 class="section-title">Account</h2>

        <button
            class="action-btn action-btn--logout"
            onclick={() => logout()}
        >
            Log out
        </button>

        {#if !showDeleteConfirm}
            <button
                class="action-btn action-btn--danger"
                onclick={() => (showDeleteConfirm = true)}
            >
                Delete account
            </button>
        {:else}
            <div class="delete-confirm-box">
                <p class="delete-warning">
                    Are you sure? This cannot be undone. All your data will be permanently deleted.
                </p>
                <p class="delete-instruction">
                    Type <strong>DELETE</strong> to confirm:
                </p>
                <input
                    type="text"
                    bind:value={deleteConfirmText}
                    class="field-input"
                    placeholder="DELETE"
                    disabled={deleting}
                />
                <div class="delete-actions">
                    <button
                        class="action-btn"
                        onclick={() => { showDeleteConfirm = false; deleteConfirmText = ''; }}
                        disabled={deleting}
                    >
                        Cancel
                    </button>
                    <button
                        class="action-btn action-btn--danger"
                        onclick={deleteAccount}
                        disabled={deleting || deleteConfirmText !== 'DELETE'}
                    >
                        {deleting ? 'Deleting…' : 'Delete my account'}
                    </button>
                </div>
            </div>
        {/if}
    </section>
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

    .page-subtitle {
        font-size: 0.875rem;
        color: var(--color-text-muted);
    }

    .settings-section {
        background: var(--color-surface-raised);
        border: 1px solid var(--color-border);
        border-radius: var(--radius-xl);
        padding: 1.25rem;
        display: flex;
        flex-direction: column;
        gap: 0.875rem;
    }

    .section-title {
        font-size: 0.8125rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--color-text-muted);
    }

    .row {
        display: flex;
        align-items: center;
        gap: 0.75rem;
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
        flex: 1;
    }

    .field-input:focus {
        border-color: var(--color-accent);
    }

    .saved-badge {
        font-size: 0.8125rem;
        font-weight: 600;
        color: var(--color-success);
        white-space: nowrap;
    }

    .theme-buttons {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.5rem;
    }

    .theme-btn {
        padding: 0.625rem 0.5rem;
        font-size: 0.875rem;
        font-weight: 500;
        font-family: inherit;
        border-radius: var(--radius-md);
        border: 1px solid var(--color-border-strong);
        background: var(--color-surface);
        color: var(--color-text-secondary);
        cursor: pointer;
        transition: all 0.15s;
    }

    .theme-btn--active {
        background: var(--color-accent);
        color: var(--color-accent-text);
        border-color: var(--color-accent);
    }

    .theme-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .action-btn {
        padding: 0.75rem 1rem;
        background: var(--color-surface);
        color: var(--color-text-primary);
        border: 1px solid var(--color-border-strong);
        border-radius: var(--radius-md);
        font-size: 0.9375rem;
        font-weight: 500;
        font-family: inherit;
        cursor: pointer;
        transition: all 0.15s;
        text-align: left;
        width: 100%;
    }

    .action-btn:hover:not(:disabled) {
        border-color: var(--color-accent);
        color: var(--color-accent);
    }

    .action-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .action-btn--logout {
        color: var(--color-text-secondary);
    }

    .action-btn--danger {
        color: var(--color-error);
        border-color: color-mix(in srgb, var(--color-error) 40%, transparent);
    }

    .action-btn--danger:hover:not(:disabled) {
        background: color-mix(in srgb, var(--color-error) 10%, transparent);
        border-color: var(--color-error);
        color: var(--color-error);
    }

    .delete-confirm-box {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        padding: 1rem;
        border-radius: var(--radius-lg);
        background: color-mix(in srgb, var(--color-error) 6%, transparent);
        border: 1px solid color-mix(in srgb, var(--color-error) 25%, transparent);
    }

    .delete-warning {
        font-size: 0.875rem;
        color: var(--color-text-primary);
    }

    .delete-instruction {
        font-size: 0.875rem;
        color: var(--color-text-secondary);
    }

    .delete-actions {
        display: flex;
        gap: 0.5rem;
    }

    .delete-actions .action-btn {
        flex: 1;
    }

    /* Household section */
    .household-name {
        font-size: 1rem;
        font-weight: 600;
        color: var(--color-text-primary);
        margin: 0;
    }

    .invite-code-row {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .invite-code-display {
        display: flex;
        flex-direction: column;
        gap: 0.2rem;
        flex: 1;
        min-width: 0;
    }

    .invite-code-label {
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--color-text-muted);
    }

    .invite-code-value {
        font-family: 'JetBrains Mono', monospace;
        font-size: 1.125rem;
        font-weight: 600;
        color: var(--color-accent);
        letter-spacing: 0.12em;
        background: color-mix(in srgb, var(--color-accent) 8%, transparent);
        border: 1px solid color-mix(in srgb, var(--color-accent) 25%, transparent);
        border-radius: var(--radius-md);
        padding: 0.375rem 0.625rem;
    }

    .action-btn--copy {
        min-width: 5rem;
        text-align: center;
        color: var(--color-accent);
        border-color: color-mix(in srgb, var(--color-accent) 40%, transparent);
    }

    .action-btn--copy:hover:not(:disabled) {
        background: color-mix(in srgb, var(--color-accent) 10%, transparent);
        border-color: var(--color-accent);
        color: var(--color-accent);
    }

    .invite-hint {
        font-size: 0.8125rem;
        color: var(--color-text-muted);
        margin: 0;
    }

    /* Join household */
    .join-hint {
        font-size: 0.875rem;
        color: var(--color-text-secondary);
        margin: 0;
    }

    .join-row {
        display: flex;
        gap: 0.75rem;
        align-items: center;
    }

    .action-btn--join {
        min-width: 5rem;
        text-align: center;
        white-space: nowrap;
    }

    .join-error {
        font-size: 0.875rem;
        color: var(--color-error);
        margin: 0;
    }

    .join-success {
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--color-success);
        margin: 0;
    }
</style>
