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

test.describe('Dashboard — empty state', () => {
    test('new user sees the empty-state message before adding any habits', async ({ page }) => {
        await registerUser(page);

        // Reload so the SvelteKit auth guard picks up the injected token.
        await page.goto('/');

        // Wait for the loading skeletons to clear
        await expect(page.locator('.skeleton-card').first()).not.toBeVisible({ timeout: 10_000 });

        // The empty state should be visible
        const emptyTitle = page.locator('.empty-title');
        await expect(emptyTitle).toBeVisible();
        await expect(emptyTitle).toHaveText('No habits yet');
    });
});

test.describe('Dashboard — habit card', () => {
    test('habit created via API appears as a card on the dashboard', async ({ page }) => {
        const { token } = await registerUser(page);

        await createHabit(page, token, 'Morning Run');

        // Reload to trigger dashboard fetch
        await page.goto('/');

        // Wait until the habit list is rendered (skeleton gone)
        await expect(page.locator('.skeleton-card').first()).not.toBeVisible({ timeout: 10_000 });

        // The habit card should show the habit name
        const habitCard = page.locator('.habit-card', { hasText: 'Morning Run' });
        await expect(habitCard).toBeVisible();

        // The check button should be in the pending (un-pressed) state
        const checkBtn = habitCard.locator('.check-btn');
        await expect(checkBtn).toBeVisible();
        await expect(checkBtn).not.toHaveClass(/check-btn--done/);
    });

    test('clicking the check button logs the habit and shows the done state', async ({ page }) => {
        const { token } = await registerUser(page);

        await createHabit(page, token, 'Evening Walk');

        await page.goto('/');

        // Wait for the habit list
        await expect(page.locator('.skeleton-card').first()).not.toBeVisible({ timeout: 10_000 });

        const habitCard = page.locator('.habit-card', { hasText: 'Evening Walk' });
        await expect(habitCard).toBeVisible();

        const checkBtn = habitCard.locator('.check-btn');
        await expect(checkBtn).not.toHaveClass(/check-btn--done/);

        // Tap the check button
        await checkBtn.click();

        // Optimistic update: button immediately transitions to the done state
        await expect(checkBtn).toHaveClass(/check-btn--done/, { timeout: 5_000 });

        // The card itself should also get the done CSS modifier
        await expect(habitCard).toHaveClass(/habit-card--done/, { timeout: 5_000 });
    });

    test('logged habit shows the done subtitle text', async ({ page }) => {
        const { token } = await registerUser(page);
        const habitId = await createHabit(page, token, 'Drink Water');

        // Log the habit directly via API so the done-state is server-confirmed
        await page.request.post(`/api/v1/habits/${habitId}/log`, {
            headers: { Authorization: `Bearer ${token}` },
            data: { source: 'manual' },
        });

        await page.goto('/');

        await expect(page.locator('.skeleton-card').first()).not.toBeVisible({ timeout: 10_000 });

        const habitCard = page.locator('.habit-card', { hasText: 'Drink Water' });
        await expect(habitCard).toBeVisible();

        // The done subtitle should contain "Today"
        const doneSubtitle = habitCard.locator('.card-subtitle--done');
        await expect(doneSubtitle).toBeVisible();
        await expect(doneSubtitle).toContainText('Today');
    });
});

test.describe('Dashboard — progress bar', () => {
    test('progress bar shows "0 of 1 done" when habit is not yet logged', async ({ page }) => {
        const { token } = await registerUser(page);

        await createHabit(page, token, 'Progress Habit');

        await page.goto('/');

        // Wait for dashboard data
        const progressLabel = page.locator('.progress-label');
        await expect(progressLabel).toBeVisible({ timeout: 10_000 });
        await expect(progressLabel).toContainText('0 of 1 done');
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

        const progressLabel = page.locator('.progress-label');
        await expect(progressLabel).toBeVisible({ timeout: 10_000 });
        await expect(progressLabel).toContainText('1 of 2 done');
    });

    test('progress bar shows "All done for today!" when every habit is logged', async ({ page }) => {
        const { token } = await registerUser(page);

        const habitId = await createHabit(page, token, 'Only Habit');

        await page.request.post(`/api/v1/habits/${habitId}/log`, {
            headers: { Authorization: `Bearer ${token}` },
            data: { source: 'manual' },
        });

        await page.goto('/');

        const progressLabel = page.locator('.progress-label');
        await expect(progressLabel).toBeVisible({ timeout: 10_000 });
        await expect(progressLabel).toContainText('All done for today!');
    });
});
