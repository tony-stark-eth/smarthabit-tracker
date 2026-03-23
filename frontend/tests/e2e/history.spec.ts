import { test, expect } from '@playwright/test';
import { registerUser, createHabit, gotoAuthenticated } from './helpers';

/**
 * History E2E tests — habit list, "Never" state, navigation to detail, and
 * log entries after habit completion.
 *
 * Each test registers a fresh user so tests are independent and parallelisable.
 *
 * Requires:
 *   docker compose --profile dev up -d
 *   cd frontend && bun run test:e2e
 */

/**
 * Wait for the History page to finish loading.
 * The page title is "History — SmartHabit" and we wait for networkidle to
 * ensure the habit list has been fetched from /dashboard.
 */
async function waitForHistory(page: import('@playwright/test').Page): Promise<void> {
    await expect(page).toHaveTitle(/History/i, { timeout: 15_000 });
    await page.waitForLoadState('networkidle', { timeout: 15_000 });
}

/**
 * Wait for the habit detail page (habits/[id]) to finish loading.
 * The page title becomes the habit name once /habits/{id} resolves.
 */
async function waitForHabitDetail(
    page: import('@playwright/test').Page,
    habitName: string,
): Promise<void> {
    await expect(page).toHaveTitle(new RegExp(habitName, 'i'), { timeout: 15_000 });
    await page.waitForLoadState('networkidle', { timeout: 15_000 });
}

test.describe('History', () => {
    test('history page lists all habits by name', async ({ page }) => {
        const { token } = await registerUser(page);

        await createHabit(page, token, 'Yoga');
        await createHabit(page, token, 'Cold Shower');

        await gotoAuthenticated(page, '/history');
        await waitForHistory(page);

        // Both habit names should appear in the habit list
        await expect(page.getByText('Yoga')).toBeVisible({ timeout: 10_000 });
        await expect(page.getByText('Cold Shower')).toBeVisible({ timeout: 10_000 });
    });

    test('unlogged habit shows "Never" as last-completed text', async ({ page }) => {
        const { token } = await registerUser(page);

        await createHabit(page, token, 'Read Book');

        await gotoAuthenticated(page, '/history');
        await waitForHistory(page);

        // The history page renders "Last completed: Never" for unlogged habits
        // via formatLastCompleted() which returns 'Never' when last_log is null.
        const habitRow = page.getByRole('button', { name: /View history for Read Book/i });
        await expect(habitRow).toBeVisible({ timeout: 10_000 });
        await expect(habitRow.getByText('Never')).toBeVisible({ timeout: 5_000 });
    });

    test('clicking a habit row navigates to the detail page', async ({ page }) => {
        const { token } = await registerUser(page);

        const habitId = await createHabit(page, token, 'Evening Run');

        await gotoAuthenticated(page, '/history');
        await waitForHistory(page);

        // Click the habit row button (aria-label="View history for Evening Run")
        const habitRow = page.getByRole('button', { name: /View history for Evening Run/i });
        await expect(habitRow).toBeVisible({ timeout: 15_000 });
        await habitRow.click();

        // URL should change to /habits/{id}
        await expect(page).toHaveURL(new RegExp(`/habits/${habitId}`, 'i'), { timeout: 10_000 });

        // The detail page header (h1.page-title) should contain the habit name
        await waitForHabitDetail(page, 'Evening Run');
        await expect(page.getByRole('heading', { name: 'Evening Run' })).toBeVisible({ timeout: 10_000 });
    });

    test('habit detail shows log entry with "Today" group label after logging via API', async ({ page }) => {
        const { token } = await registerUser(page);

        const habitId = await createHabit(page, token, 'Journaling');

        // Log the habit via the API (source: 'manual')
        const logResponse = await page.request.post(`/api/v1/habits/${habitId}/log`, {
            headers: { Authorization: `Bearer ${token}` },
            data: { source: 'manual' },
        });
        expect(logResponse.ok()).toBe(true);

        await gotoAuthenticated(page, `/habits/${habitId}`);
        await waitForHabitDetail(page, 'Journaling');

        // The detail page groups log entries by date — today's logs appear under
        // a "TODAY" group header (text-transform: uppercase in CSS, but the DOM
        // content is 'Today' since getDateLabel() returns the literal string).
        const todayGroup = page.getByRole('heading', { name: /Today/i });
        await expect(todayGroup).toBeVisible({ timeout: 10_000 });

        // At least one log row should be present under the group
        const logList = page.locator('.log-list').first();
        await expect(logList).toBeVisible({ timeout: 5_000 });
        const logRows = logList.locator('.log-row');
        await expect(logRows).toHaveCount(1, { timeout: 5_000 });
    });
});
