<script lang="ts">
    import { client, ApiRequestError } from '$lib/api/client';
    import type { HabitData } from '$lib/types';

    // ---------------------------------------------------------------------------
    // Types
    // ---------------------------------------------------------------------------

    interface HabitPayload {
        name: string;
        frequency: 'daily' | 'weekly' | 'custom';
        icon: string | null;
        description: string | null;
        time_window_start: string | null;
        time_window_end: string | null;
    }

    interface Props {
        open: boolean;
        onClose: () => void;
        onCreated: () => void;
        habit?: HabitData | undefined;
    }

    // ---------------------------------------------------------------------------
    // Props & state
    // ---------------------------------------------------------------------------

    const { open, onClose, onCreated, habit = undefined }: Props = $props();

    let name = $state('');
    let icon = $state('');
    let frequency = $state<'daily' | 'weekly' | 'custom'>('daily');
    let timeStart = $state('');
    let timeEnd = $state('');

    let submitting = $state(false);
    let fieldErrors = $state<Record<string, string>>({});
    let generalError = $state<string | null>(null);

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /** Strip the seconds component from "HH:MM:SS" so time inputs get "HH:MM". */
    function toTimeInput(value: string | null): string {
        if (value === null || value === '') return '';
        // "HH:MM:SS" → "HH:MM", plain "HH:MM" passes through unchanged
        return value.length > 5 ? value.slice(0, 5) : value;
    }

    // ---------------------------------------------------------------------------
    // Reset / pre-fill form when sheet is opened
    // ---------------------------------------------------------------------------

    $effect(() => {
        if (open) {
            if (habit !== undefined) {
                name = habit.name;
                icon = habit.icon ?? '';
                frequency = habit.frequency;
                timeStart = toTimeInput(habit.time_window_start);
                timeEnd = toTimeInput(habit.time_window_end);
            } else {
                name = '';
                icon = '';
                frequency = 'daily';
                timeStart = '';
                timeEnd = '';
            }
            submitting = false;
            fieldErrors = {};
            generalError = null;
        }
    });

    // ---------------------------------------------------------------------------
    // Submit
    // ---------------------------------------------------------------------------

    async function handleSubmit(e: SubmitEvent): Promise<void> {
        e.preventDefault();
        if (submitting) return;

        fieldErrors = {};
        generalError = null;

        const trimmedName = name.trim();
        const trimmedIcon = icon.trim();

        if (trimmedName === '') {
            fieldErrors = { name: 'Name is required.' };
            return;
        }

        const payload: HabitPayload = {
            name: trimmedName,
            frequency,
            icon: trimmedIcon !== '' ? trimmedIcon : null,
            description: null,
            time_window_start: timeStart !== '' ? timeStart : null,
            time_window_end: timeEnd !== '' ? timeEnd : null,
        };

        submitting = true;

        try {
            if (habit !== undefined) {
                await client.put(`/habits/${habit.id}`, payload);
            } else {
                await client.post('/habits', payload);
            }
            onCreated();
        } catch (err) {
            if (err instanceof ApiRequestError) {
                generalError = err.message;
            } else {
                generalError = 'Something went wrong. Please try again.';
            }
        } finally {
            submitting = false;
        }
    }

    // ---------------------------------------------------------------------------
    // Keyboard close
    // ---------------------------------------------------------------------------

    function handleBackdropKeydown(e: KeyboardEvent): void {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            onClose();
        }
    }
</script>

{#if open}
    <!-- Backdrop -->
    <div
        class="backdrop"
        role="button"
        tabindex="-1"
        aria-label="Close sheet"
        onclick={onClose}
        onkeydown={handleBackdropKeydown}
    ></div>

    <!-- Sheet -->
    <div class="sheet" role="dialog" aria-modal="true" aria-label={habit !== undefined ? 'Edit habit' : 'Create habit'}>
        <div class="sheet-handle" aria-hidden="true"></div>

        <header class="sheet-header">
            <h2 class="sheet-title">{habit !== undefined ? 'Edit habit' : 'New habit'}</h2>
            <button class="close-btn" onclick={onClose} aria-label="Close">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </header>

        <form class="sheet-form" onsubmit={handleSubmit} novalidate>
            <!-- Name -->
            <div class="field" class:field--error={fieldErrors.name !== undefined}>
                <label class="field-label" for="habit-name">Name <span class="required" aria-hidden="true">*</span></label>
                <input
                    id="habit-name"
                    class="field-input"
                    type="text"
                    bind:value={name}
                    placeholder="e.g. Morning run"
                    maxlength="120"
                    autocomplete="off"
                    aria-required="true"
                    aria-describedby={fieldErrors.name !== undefined ? 'habit-name-error' : undefined}
                />
                {#if fieldErrors.name !== undefined}
                    <p id="habit-name-error" class="field-error" role="alert">{fieldErrors.name}</p>
                {/if}
            </div>

            <!-- Icon -->
            <div class="field">
                <label class="field-label" for="habit-icon">Icon <span class="field-hint">(optional emoji)</span></label>
                <input
                    id="habit-icon"
                    class="field-input field-input--icon"
                    type="text"
                    bind:value={icon}
                    placeholder="e.g. 🏃"
                    maxlength="8"
                    autocomplete="off"
                />
            </div>

            <!-- Frequency -->
            <div class="field">
                <label class="field-label" for="habit-frequency">Frequency</label>
                <div class="select-wrapper">
                    <select id="habit-frequency" class="field-select" bind:value={frequency}>
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="custom">Custom</option>
                    </select>
                    <svg class="select-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </div>
            </div>

            <!-- Time window -->
            <fieldset class="field fieldset">
                <legend class="field-label">Time window <span class="field-hint">(optional)</span></legend>
                <div class="time-row">
                    <div class="time-field">
                        <label class="time-label" for="habit-time-start">From</label>
                        <input
                            id="habit-time-start"
                            class="field-input"
                            type="time"
                            bind:value={timeStart}
                        />
                    </div>
                    <span class="time-sep" aria-hidden="true">–</span>
                    <div class="time-field">
                        <label class="time-label" for="habit-time-end">To</label>
                        <input
                            id="habit-time-end"
                            class="field-input"
                            type="time"
                            bind:value={timeEnd}
                        />
                    </div>
                </div>
            </fieldset>

            <!-- General error -->
            {#if generalError !== null}
                <p class="general-error" role="alert">{generalError}</p>
            {/if}

            <!-- Actions -->
            <div class="sheet-actions">
                <button type="button" class="btn-cancel" onclick={onClose} disabled={submitting}>
                    Cancel
                </button>
                <button type="submit" class="btn-submit" disabled={submitting} aria-busy={submitting}>
                    {#if submitting}
                        <span class="spinner" aria-hidden="true"></span>
                        Saving…
                    {:else}
                        {habit !== undefined ? 'Save changes' : 'Create habit'}
                    {/if}
                </button>
            </div>
        </form>
    </div>
{/if}

<style>
    @keyframes fade-in {
        from { opacity: 0; }
        to   { opacity: 1; }
    }

    @keyframes sheet-up {
        from {
            opacity: 0;
            transform: translateY(100%);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    /* Backdrop */
    .backdrop {
        position: fixed;
        inset: 0;
        background: color-mix(in srgb, #000000 50%, transparent);
        z-index: 200;
        animation: fade-in 0.2s ease-out both;
        cursor: pointer;
        -webkit-tap-highlight-color: transparent;
    }

    /* Sheet */
    .sheet {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        z-index: 201;
        background: var(--color-surface-raised);
        border-top: 1px solid var(--color-border-strong);
        border-radius: var(--radius-xl) var(--radius-xl) 0 0;
        padding: 0 1.25rem calc(1.5rem + env(safe-area-inset-bottom));
        animation: sheet-up 0.3s cubic-bezier(0.32, 0.72, 0, 1) both;
        max-width: 40rem;
        margin: 0 auto;
    }

    /* Drag handle */
    .sheet-handle {
        width: 2.5rem;
        height: 4px;
        background: var(--color-border-strong);
        border-radius: 9999px;
        margin: 0.75rem auto 0;
    }

    /* Header */
    .sheet-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 1rem 0 0.75rem;
    }

    .sheet-title {
        font-size: 1.125rem;
        font-weight: 700;
        color: var(--color-text-primary);
        margin: 0;
        letter-spacing: -0.02em;
    }

    .close-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 2rem;
        height: 2rem;
        border-radius: var(--radius-md);
        border: none;
        background: var(--color-surface);
        color: var(--color-text-muted);
        cursor: pointer;
        transition: background 0.15s, color 0.15s;
        -webkit-tap-highlight-color: transparent;
        padding: 0;
    }

    .close-btn:hover {
        background: var(--color-border);
        color: var(--color-text-secondary);
    }

    /* Form */
    .sheet-form {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    /* Field */
    .field {
        display: flex;
        flex-direction: column;
        gap: 0.375rem;
    }

    .fieldset {
        border: none;
        padding: 0;
        margin: 0;
    }

    .field-label {
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--color-text-secondary);
    }

    .field-hint {
        font-weight: 400;
        color: var(--color-text-muted);
    }

    .required {
        color: var(--color-error);
        margin-left: 0.125rem;
    }

    .field-input {
        width: 100%;
        padding: 0.625rem 0.75rem;
        font-size: 0.9375rem;
        font-family: var(--font-sans);
        color: var(--color-text-primary);
        background: var(--color-surface);
        border: 1px solid var(--color-border-strong);
        border-radius: var(--radius-md);
        outline: none;
        transition: border-color 0.15s, box-shadow 0.15s;
        -webkit-appearance: none;
        appearance: none;
    }

    .field-input:focus {
        border-color: var(--color-accent);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-accent) 18%, transparent);
    }

    .field-input--icon {
        font-size: 1.25rem;
        max-width: 6rem;
    }

    .field--error .field-input {
        border-color: var(--color-error);
    }

    .field--error .field-input:focus {
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-error) 18%, transparent);
    }

    .field-error {
        font-size: 0.8125rem;
        color: var(--color-error);
        margin: 0;
    }

    /* Select */
    .select-wrapper {
        position: relative;
        display: flex;
        align-items: center;
    }

    .field-select {
        width: 100%;
        padding: 0.625rem 2.25rem 0.625rem 0.75rem;
        font-size: 0.9375rem;
        font-family: var(--font-sans);
        color: var(--color-text-primary);
        background: var(--color-surface);
        border: 1px solid var(--color-border-strong);
        border-radius: var(--radius-md);
        outline: none;
        cursor: pointer;
        transition: border-color 0.15s, box-shadow 0.15s;
        -webkit-appearance: none;
        appearance: none;
    }

    .field-select:focus {
        border-color: var(--color-accent);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-accent) 18%, transparent);
    }

    .select-chevron {
        position: absolute;
        right: 0.75rem;
        pointer-events: none;
        color: var(--color-text-muted);
    }

    /* Time window */
    .time-row {
        display: flex;
        align-items: flex-end;
        gap: 0.5rem;
    }

    .time-field {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        flex: 1;
    }

    .time-label {
        font-size: 0.75rem;
        font-weight: 500;
        color: var(--color-text-muted);
    }

    .time-sep {
        padding-bottom: 0.625rem;
        color: var(--color-text-muted);
        font-size: 1rem;
        flex-shrink: 0;
    }

    /* General error */
    .general-error {
        font-size: 0.875rem;
        color: var(--color-error);
        background: color-mix(in srgb, var(--color-error) 8%, transparent);
        border: 1px solid color-mix(in srgb, var(--color-error) 22%, transparent);
        border-radius: var(--radius-md);
        padding: 0.625rem 0.75rem;
        margin: 0;
    }

    /* Actions */
    .sheet-actions {
        display: flex;
        gap: 0.75rem;
        padding-top: 0.25rem;
    }

    .btn-cancel {
        flex: 1;
        padding: 0.75rem 1rem;
        font-size: 0.9375rem;
        font-weight: 600;
        font-family: var(--font-sans);
        color: var(--color-text-secondary);
        background: var(--color-surface);
        border: 1px solid var(--color-border-strong);
        border-radius: var(--radius-md);
        cursor: pointer;
        transition: background 0.15s;
        -webkit-tap-highlight-color: transparent;
    }

    .btn-cancel:hover:not(:disabled) {
        background: var(--color-border);
    }

    .btn-cancel:disabled {
        opacity: 0.5;
        cursor: default;
    }

    .btn-submit {
        flex: 2;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.75rem 1rem;
        font-size: 0.9375rem;
        font-weight: 600;
        font-family: var(--font-sans);
        color: var(--color-accent-text);
        background: var(--color-accent);
        border: none;
        border-radius: var(--radius-md);
        cursor: pointer;
        transition: background 0.15s, opacity 0.15s;
        -webkit-tap-highlight-color: transparent;
    }

    .btn-submit:hover:not(:disabled) {
        background: var(--color-accent-hover);
    }

    .btn-submit:disabled {
        opacity: 0.65;
        cursor: default;
    }

    /* Spinner */
    .spinner {
        display: inline-block;
        width: 1rem;
        height: 1rem;
        border: 2px solid color-mix(in srgb, var(--color-accent-text) 40%, transparent);
        border-top-color: var(--color-accent-text);
        border-radius: 50%;
        animation: spin 0.65s linear infinite;
    }
</style>
