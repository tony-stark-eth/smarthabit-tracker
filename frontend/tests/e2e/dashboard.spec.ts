import { test, expect } from '@playwright/test';
import { registerUser, createHabit } from './helpers';

/**
 * Dashboard E2E tests — habit display, one-tap logging, and progress bar.
 *
 * Each test registers a fresh user so tests are independent and parallelisable.
 *
 * Requires:
 *   docker compose --profile dev up -d
 *   cd frontend && bun run test:e2e
 */

/**
 * Wait for the dashboard to finish loading.
 * After a successful auth redirect the page fetches /dashboard; we wait for
 * the skeleton cards to disappear and for the page title to say "Today".
 */
async function waitForDashboard(page: import('@playwright/test').Page): Promise<void> {
    await expect(page).toHaveTitle(/Today/i, { timeout: 15_000 });
    await page.waitForLoadState('networkidle', { timeout: 15_000 });
}

test.describe('Dashboard — empty state', () => {
    test('new user sees the empty-state message before adding any habits', async ({ page }) => {
        await registerUser(page);
        await waitForDashboard(page);

        // The empty state renders "No habits yet" as visible text
        await expect(page.getByText('No habits yet')).toBeVisible({ timeout: 10_000 });
        await expect(page.getByText('Add your first habit to get started.')).toBeVisible();
    });
});

test.describe('Dashboard — habit card', () => {
    test('habit created via API appears as a card on the dashboard', async ({ page }) => {
        const { token } = await registerUser(page);

        await createHabit(page, token, 'Morning Run');

        // Reload to trigger dashboard fetch
        await page.goto('/');
        await waitForDashboard(page);

        // The habit card renders the habit name in a .card-name span
        await expect(page.getByText('Morning Run')).toBeVisible({ timeout: 10_000 });

        // The check button for the habit has aria-label="Log Morning Run" and is not pressed
        const checkBtn = page.getByRole('button', { name: /Log Morning Run/i });
        await expect(checkBtn).toBeVisible();
        await expect(checkBtn).toHaveAttribute('aria-pressed', 'false');
    });

    test('clicking the check button logs the habit and shows the done state', async ({ page }) => {
        const { token } = await registerUser(page);

        await createHabit(page, token, 'Evening Walk');

        await page.goto('/');
        await waitForDashboard(page);

        await expect(page.getByText('Evening Walk')).toBeVisible({ timeout: 10_000 });

        const checkBtn = page.getByRole('button', { name: /Log Evening Walk/i });
        await expect(checkBtn).toHaveAttribute('aria-pressed', 'false');

        // Tap the check button — triggers optimistic update
        await checkBtn.click();

        // After logging, the button becomes pressed (disabled) and aria-pressed=true
        await expect(checkBtn).toHaveAttribute('aria-pressed', 'true', { timeout: 5_000 });
        await expect(checkBtn).toBeDisabled();
    });

    test('logged habit shows done state after API log + page reload', async ({ page }) => {
        const { token } = await registerUser(page);
        const habitId = await createHabit(page, token, 'Drink Water');

        // Log the habit directly via API so the done-state is server-confirmed
        await page.request.post(`/api/v1/habits/${habitId}/log`, {
            headers: { Authorization: `Bearer ${token}` },
            data: { source: 'manual' },
        });

        await page.goto('/');
        await waitForDashboard(page);

        await expect(page.getByText('Drink Water')).toBeVisible({ timeout: 10_000 });

        // When the habit is done, the check button is disabled and aria-pressed=true
        const checkBtn = page.getByRole('button', { name: /Log Drink Water/i });
        await expect(checkBtn).toHaveAttribute('aria-pressed', 'true', { timeout: 5_000 });
        await expect(checkBtn).toBeDisabled();

        // The done subtitle contains "Today"
        await expect(page.getByText(/Today/i)).toBeVisible();
    });
});

test.describe('Dashboard — progress bar', () => {
    test('progress bar shows "0 of 1 done" when habit is not yet logged', async ({ page }) => {
        const { token } = await registerUser(page);

        await createHabit(page, token, 'Progress Habit');

        await page.goto('/');
        await waitForDashboard(page);

        // ProgressBar renders a .progress-label span with the summary text
        await expect(page.locator('.progress-label')).toContainText('0 of 1 done', { timeout: 10_000 });
    });

    test('progress bar reflects "1 of 2 done" after logging one of two habits', async ({ page }) => {
        const { token } = await registerUser(page);

        const habitId = await createHabit(page, token, 'Habit One');
        await createHabit(page, token, 'Habit Two');

        // Log only the first habit
        await page.request.post(`/api/v1/habits/${habitId}/log`, {
            headers: { Authorization: `Bearer ${token}` },
            data: { source: 'manual' },
        });

        await page.goto('/');
        await waitForDashboard(page);

        await expect(page.locator('.progress-label')).toContainText('1 of 2 done', { timeout: 10_000 });
    });

    test('progress bar shows "All done for today!" when every habit is logged', async ({ page }) => {
        const { token } = await registerUser(page);

        const habitId = await createHabit(page, token, 'Only Habit');

        await page.request.post(`/api/v1/habits/${habitId}/log`, {
            headers: { Authorization: `Bearer ${token}` },
            data: { source: 'manual' },
        });

        await page.goto('/');
        await waitForDashboard(page);

        await expect(page.locator('.progress-label')).toContainText('All done for today!', { timeout: 10_000 });
    });
});

test.describe('Dashboard — navigation', () => {
    test('bottom navigation bar is visible on the dashboard', async ({ page }) => {
        await registerUser(page);
        await waitForDashboard(page);

        // The nav has aria-label="Main navigation" in the layout
        const nav = page.getByRole('navigation', { name: /Main navigation/i });
        await expect(nav).toBeVisible({ timeout: 5_000 });

        // All four nav items should be present
        await expect(nav.getByText('Today')).toBeVisible();
        await expect(nav.getByText('History')).toBeVisible();
        await expect(nav.getByText('Stats')).toBeVisible();
        await expect(nav.getByText('Settings')).toBeVisible();
    });
});
