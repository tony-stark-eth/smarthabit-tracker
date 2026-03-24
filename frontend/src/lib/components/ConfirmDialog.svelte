<script lang="ts">
    import { t } from '$lib/i18n';

    // ---------------------------------------------------------------------------
    // Types
    // ---------------------------------------------------------------------------

    interface Props {
        open: boolean;
        title: string;
        message: string;
        confirmLabel: string;
        onConfirm: () => void;
        onCancel: () => void;
    }

    // ---------------------------------------------------------------------------
    // Props & state
    // ---------------------------------------------------------------------------

    const { open, title, message, confirmLabel, onConfirm, onCancel }: Props = $props();

    const titleId = 'confirm-dialog-title';
    const descId = 'confirm-dialog-desc';

    // ---------------------------------------------------------------------------
    // Keyboard handling
    // ---------------------------------------------------------------------------

    function handleKeydown(e: KeyboardEvent): void {
        if (e.key === 'Escape') {
            e.preventDefault();
            onCancel();
        }
    }

    function handleBackdropKeydown(e: KeyboardEvent): void {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            onCancel();
        }
    }
</script>

{#if open}
    <div
        class="backdrop"
        role="button"
        tabindex="-1"
        aria-label="Cancel"
        onclick={onCancel}
        onkeydown={handleBackdropKeydown}
    ></div>

    <div
        class="dialog"
        role="alertdialog"
        tabindex="-1"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={descId}
        onkeydown={handleKeydown}
    >
        <h2 id={titleId} class="dialog-title">{title}</h2>
        <p id={descId} class="dialog-message">{message}</p>

        <div class="dialog-actions">
            <button type="button" class="btn-cancel" onclick={onCancel}>
                {t('common_cancel')}
            </button>
            <button type="button" class="btn-confirm" onclick={onConfirm}>
                {confirmLabel}
            </button>
        </div>
    </div>
{/if}

<style>
    @keyframes fade-in {
        from { opacity: 0; }
        to   { opacity: 1; }
    }

    @keyframes dialog-in {
        from {
            opacity: 0;
            transform: translate(-50%, -50%) scale(0.95);
        }
        to {
            opacity: 1;
            transform: translate(-50%, -50%) scale(1);
        }
    }

    .backdrop {
        position: fixed;
        inset: 0;
        background: color-mix(in srgb, #000000 50%, transparent);
        z-index: 300;
        animation: fade-in 0.15s ease-out both;
        cursor: pointer;
        -webkit-tap-highlight-color: transparent;
    }

    .dialog {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        z-index: 301;
        background: var(--color-surface-raised);
        border: 1px solid var(--color-border-strong);
        border-radius: var(--radius-xl);
        padding: 1.5rem;
        width: min(calc(100vw - 2rem), 22rem);
        animation: dialog-in 0.2s cubic-bezier(0.32, 0.72, 0, 1) both;
    }

    .dialog-title {
        font-size: 1.0625rem;
        font-weight: 700;
        color: var(--color-text-primary);
        margin: 0 0 0.5rem;
        letter-spacing: -0.02em;
    }

    .dialog-message {
        font-size: 0.9375rem;
        color: var(--color-text-secondary);
        margin: 0 0 1.5rem;
        line-height: 1.5;
    }

    .dialog-actions {
        display: flex;
        gap: 0.75rem;
    }

    .btn-cancel {
        flex: 1;
        padding: 0.6875rem 1rem;
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

    .btn-cancel:hover {
        background: var(--color-border);
    }

    .btn-confirm {
        flex: 1;
        padding: 0.6875rem 1rem;
        font-size: 0.9375rem;
        font-weight: 600;
        font-family: var(--font-sans);
        color: #ffffff;
        background: var(--color-error);
        border: none;
        border-radius: var(--radius-md);
        cursor: pointer;
        transition: opacity 0.15s;
        -webkit-tap-highlight-color: transparent;
    }

    .btn-confirm:hover {
        opacity: 0.88;
    }
</style>
