<script lang="ts">
    import { resolve } from '$app/paths';

    const features = [
        {
            icon: '✓',
            title: 'One-Tap Logging',
            description: 'Log habits with a single tap. Optimistic UI updates instantly.',
        },
        {
            icon: '🏠',
            title: 'Household Sharing',
            description: 'Track habits together. See who completed what in real-time.',
        },
        {
            icon: '🔔',
            title: 'Smart Reminders',
            description: 'Adaptive notifications learn when you actually do your habits.',
        },
        {
            icon: '📊',
            title: 'Statistics',
            description: 'Streaks, completion rates, and heatmaps to visualize progress.',
        },
        {
            icon: '🌙',
            title: 'Dark Mode',
            description: 'Beautiful light and dark themes that follow your preference.',
        },
        {
            icon: '🔒',
            title: 'Privacy First',
            description: 'Self-hosted. GDPR compliant. Your data stays yours.',
        },
    ];
</script>

<svelte:head>
    <title>SmartHabit — Household Habit Tracking</title>
</svelte:head>

<div class="landing">
    <!-- Hero -->
    <header class="hero">
        <h1 class="hero-title">SmartHabit</h1>
        <p class="hero-tagline">Build better habits, together.</p>
        <p class="hero-subtitle">
            Track daily habits with your household. Get adaptive reminders
            that learn your routine. See your streaks grow.
        </p>
        <div class="hero-actions">
            <a href={resolve('/register')} class="btn btn-primary">Get Started</a>
            <a href={resolve('/login')} class="btn btn-secondary">Log In</a>
        </div>
    </header>

    <!-- Screenshots -->
    <section class="screenshots">
        <div class="screenshot-scroll">
            <img src="/screenshots/04-dashboard-progress.png" alt="Dashboard with progress" class="screenshot" />
            <img src="/screenshots/05-dashboard-alldone.png" alt="All habits completed" class="screenshot" />
            <img src="/screenshots/09-dashboard-dark.png" alt="Dashboard dark mode" class="screenshot" />
            <img src="/screenshots/10-stats.png" alt="Statistics" class="screenshot" />
        </div>
    </section>

    <!-- Features -->
    <section class="features">
        <h2 class="section-heading">Everything you need</h2>
        <div class="feature-grid">
            {#each features as feature}
                <div class="feature-card">
                    <span class="feature-icon">{feature.icon}</span>
                    <h3 class="feature-title">{feature.title}</h3>
                    <p class="feature-description">{feature.description}</p>
                </div>
            {/each}
        </div>
    </section>

    <!-- CTA -->
    <section class="cta">
        <h2 class="cta-heading">Start tracking today</h2>
        <p class="cta-subtitle">Free. Self-hosted. No ads, no tracking, no compromises.</p>
        <a href={resolve('/register')} class="btn btn-primary btn-lg">Create your account</a>
    </section>

    <!-- Footer -->
    <footer class="landing-footer">
        <p>
            Built with Symfony + SvelteKit.
            <a href="https://github.com/tony-stark-eth/smarthabit-tracker" class="footer-link" target="_blank" rel="noopener">
                Source on GitHub
            </a>
        </p>
    </footer>
</div>

<style>
    .landing {
        min-height: 100dvh;
        background: var(--color-bg);
        color: var(--color-text-primary);
        font-family: var(--font-sans);
    }

    /* ── Hero ───────────────────────────────────────── */
    .hero {
        text-align: center;
        padding: 4rem 1.5rem 2rem;
        max-width: 36rem;
        margin: 0 auto;
    }

    .hero-title {
        font-size: 2.5rem;
        font-weight: 700;
        letter-spacing: -0.03em;
        color: var(--color-accent);
        margin: 0;
    }

    .hero-tagline {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--color-text-primary);
        margin: 0.5rem 0 0;
    }

    .hero-subtitle {
        font-size: 1rem;
        color: var(--color-text-secondary);
        line-height: 1.6;
        margin: 1rem 0 0;
    }

    .hero-actions {
        display: flex;
        gap: 0.75rem;
        justify-content: center;
        margin-top: 2rem;
    }

    /* ── Buttons ────────────────────────────────────── */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.75rem 1.5rem;
        font-size: 0.9375rem;
        font-weight: 600;
        font-family: inherit;
        border-radius: var(--radius-md);
        text-decoration: none;
        transition: all 0.15s;
        cursor: pointer;
        border: none;
    }

    .btn-primary {
        background: var(--color-accent);
        color: var(--color-accent-text);
    }

    .btn-primary:hover {
        background: var(--color-accent-hover);
    }

    .btn-secondary {
        background: var(--color-surface);
        color: var(--color-text-primary);
        border: 1px solid var(--color-border-strong);
    }

    .btn-secondary:hover {
        border-color: var(--color-accent);
        color: var(--color-accent);
    }

    .btn-lg {
        padding: 1rem 2rem;
        font-size: 1.0625rem;
    }

    /* ── Screenshots ───────────────────────────────── */
    .screenshots {
        padding: 1rem 0 2rem;
        overflow: hidden;
    }

    .screenshot-scroll {
        display: flex;
        gap: 1rem;
        padding: 0 1.5rem;
        overflow-x: auto;
        scroll-snap-type: x mandatory;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
        justify-content: center;
    }

    .screenshot-scroll::-webkit-scrollbar {
        display: none;
    }

    .screenshot {
        flex-shrink: 0;
        width: 180px;
        border-radius: var(--radius-xl);
        border: 1px solid var(--color-border);
        box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08);
        scroll-snap-align: center;
    }

    /* ── Features ───────────────────────────────────── */
    .features {
        padding: 3rem 1.5rem;
        max-width: 48rem;
        margin: 0 auto;
    }

    .section-heading {
        text-align: center;
        font-size: 1.5rem;
        font-weight: 700;
        letter-spacing: -0.02em;
        margin: 0 0 2rem;
    }

    .feature-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
        gap: 1.25rem;
    }

    .feature-card {
        background: var(--color-surface-raised);
        border: 1px solid var(--color-border);
        border-radius: var(--radius-xl);
        padding: 1.25rem;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .feature-icon {
        font-size: 1.5rem;
        line-height: 1;
    }

    .feature-title {
        font-size: 0.9375rem;
        font-weight: 600;
        margin: 0;
    }

    .feature-description {
        font-size: 0.8125rem;
        color: var(--color-text-secondary);
        line-height: 1.5;
        margin: 0;
    }

    /* ── CTA ────────────────────────────────────────── */
    .cta {
        text-align: center;
        padding: 3rem 1.5rem 2rem;
    }

    .cta-heading {
        font-size: 1.5rem;
        font-weight: 700;
        letter-spacing: -0.02em;
        margin: 0;
    }

    .cta-subtitle {
        color: var(--color-text-secondary);
        margin: 0.5rem 0 1.5rem;
    }

    /* ── Footer ─────────────────────────────────────── */
    .landing-footer {
        text-align: center;
        padding: 2rem 1.5rem;
        font-size: 0.8125rem;
        color: var(--color-text-muted);
        border-top: 1px solid var(--color-border);
    }

    .footer-link {
        color: var(--color-accent);
        text-decoration: none;
        font-weight: 500;
    }

    .footer-link:hover {
        text-decoration: underline;
    }
</style>
