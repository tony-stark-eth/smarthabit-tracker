import { test, expect } from '@playwright/test';
import { registerUser, createHabit, gotoAuthenticated } from './helpers';

/**
 * Habit CRUD E2E tests — create via FAB, edit, and delete flows.
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

test.describe('Habit CRUD', () => {
    test('create habit via FAB — sheet opens, fills name and frequency, habit appears on dashboard', async ({ page }) => {
        await registerUser(page);
        await waitForDashboard(page);

        // Click the FAB (aria-label="Create habit")
        const fab = page.getByRole('button', { name: /Create habit/i });
        await expect(fab).toBeVisible({ timeout: 5_000 });
        await fab.click();

        // The CreateHabitSheet should open (role="dialog")
        const sheet = page.getByRole('dialog', { name: /Create habit/i });
        await expect(sheet).toBeVisible({ timeout: 5_000 });

        // Fill in the habit name
        const nameInput = sheet.getByLabel(/Name/i);
        await nameInput.fill('Meditation');

        // Select frequency "daily" (it is the default, but be explicit)
        const frequencySelect = sheet.getByLabel(/Frequency/i);
        await frequencySelect.selectOption('daily');

        // Submit the form
        const submitBtn = sheet.getByRole('button', { name: /Create habit/i });
        await submitBtn.click();

        // Sheet should close after submission
        await expect(sheet).not.toBeVisible({ timeout: 5_000 });

        // Dashboard reloads — habit name should appear
        await expect(page.getByText('Meditation')).toBeVisible({ timeout: 10_000 });
    });

    test('create habit with time window — time window tag appears on habit card', async ({ page }) => {
        await registerUser(page);
        await waitForDashboard(page);

        const fab = page.getByRole('button', { name: /Create habit/i });
        await fab.click();

        const sheet = page.getByRole('dialog', { name: /Create habit/i });
        await expect(sheet).toBeVisible({ timeout: 5_000 });

        await sheet.getByLabel(/Name/i).fill('Morning Pages');

        // Fill the time window fields
        await sheet.getByLabel(/From/i).fill('09:00');
        await sheet.getByLabel(/To/i).fill('10:00');

        await sheet.getByRole('button', { name: /Create habit/i }).click();
        await expect(sheet).not.toBeVisible({ timeout: 5_000 });

        // The time-tag renders as "09:00–10:00" (slice(0,5) on each side)
        await expect(page.getByText('09:00–10:00')).toBeVisible({ timeout: 10_000 });
    });

    test('edit habit name — navigate to detail page, click edit, change name, save', async ({ page }) => {
        const { token } = await registerUser(page);
        const habitId = await createHabit(page, token, 'Original Name');

        await gotoAuthenticated(page, `/habits/${habitId}`);
        await expect(page.getByRole('heading', { name: 'Original Name' })).toBeVisible({ timeout: 15_000 });

        // Click the Edit button (aria-label="Edit habit")
        const editBtn = page.getByRole('button', { name: /Edit habit/i });
        await expect(editBtn).toBeVisible({ timeout: 5_000 });
        await editBtn.click();

        // The CreateHabitSheet opens in edit mode
        const sheet = page.getByRole('dialog', { name: /Edit habit/i });
        await expect(sheet).toBeVisible({ timeout: 5_000 });

        // Clear and replace the name
        const nameInput = sheet.getByLabel(/Name/i);
        await nameInput.clear();
        await nameInput.fill('Updated Name');

        await sheet.getByRole('button', { name: /Save changes/i }).click();
        await expect(sheet).not.toBeVisible({ timeout: 5_000 });

        // Page should now show the updated habit name
        await expect(page.getByRole('heading', { name: 'Updated Name' })).toBeVisible({ timeout: 10_000 });
    });

    test('delete habit — confirm dialog appears, confirm redirects to dashboard and habit is gone', async ({ page }) => {
        const { token } = await registerUser(page);
        const habitId = await createHabit(page, token, 'Habit To Delete');

        await gotoAuthenticated(page, `/habits/${habitId}`);
        await expect(page.getByRole('heading', { name: 'Habit To Delete' })).toBeVisible({ timeout: 15_000 });

        // Click the Delete button (aria-label="Delete habit")
        const deleteBtn = page.getByRole('button', { name: /Delete habit/i });
        await expect(deleteBtn).toBeVisible({ timeout: 5_000 });
        await deleteBtn.click();

        // ConfirmDialog (role="alertdialog") should appear
        const dialog = page.getByRole('alertdialog');
        await expect(dialog).toBeVisible({ timeout: 5_000 });
        await expect(dialog.getByRole('heading', { name: /Delete habit/i })).toBeVisible();

        // Confirm the deletion
        await dialog.getByRole('button', { name: /Delete/i }).click();

        // Should redirect to the dashboard (/)
        await waitForDashboard(page);

        // The deleted habit must no longer appear
        await expect(page.getByText('Habit To Delete')).not.toBeVisible({ timeout: 5_000 });
    });

    test('create habit from empty state — clicking "Create your first habit" opens the sheet', async ({ page }) => {
        await registerUser(page);
        await waitForDashboard(page);

        // New user has no habits — the empty state CTA should be present
        const ctaBtn = page.getByRole('button', { name: /Create your first habit/i });
        await expect(ctaBtn).toBeVisible({ timeout: 10_000 });
        await ctaBtn.click();

        // The CreateHabitSheet should open
        const sheet = page.getByRole('dialog', { name: /Create habit/i });
        await expect(sheet).toBeVisible({ timeout: 5_000 });
    });
});
