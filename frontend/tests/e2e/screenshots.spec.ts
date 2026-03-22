import { test, expect, type Page } from '@playwright/test';

/**
 * Screenshot generation script — captures all key screens for documentation.
 *
 * Run:  cd frontend && npx playwright test tests/e2e/screenshots.spec.ts
 *
 * Output: docs/screenshots/*.png (mobile 390×844 viewport)
 */

const SCREENSHOT_DIR = '../docs/screenshots';

// Mobile viewport matching iPhone 14 Pro
const MOBILE = { width: 390, height: 844 };

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

interface RegisterResult {
    email: string;
    token: string;
}

async function registerAndLogin(page: Page, name = 'Lisa', prettyEmail?: string): Promise<RegisterResult> {
    const suffix = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
    const email = prettyEmail ?? `${name.toLowerCase()}.${suffix}@smarthabit.de`;

    const response = await page.request.post('/api/v1/register', {
        data: {
            email,
            password: 'password123',
            display_name: name,
            timezone: 'Europe/Berlin',
            locale: 'en',
            household_name: 'Smith Family',
            consent: true,
        },
    });

    if (!response.ok()) {
        throw new Error(`Register failed: ${response.status()} ${await response.text()}`);
    }

    const data = (await response.json()) as { token: string };

    // Inject token
    await page.goto('/login');
    await page.evaluate(
        (t: string) => localStorage.setItem('access_token', t),
        data.token,
    );
    await page.goto('/');
    await expect(page).toHaveTitle(/Today/i, { timeout: 15_000 });

    return { email, token: data.token };
}

async function createHabit(
    page: Page,
    token: string,
    name: string,
    icon?: string,
): Promise<string> {
    const response = await page.request.post('/api/v1/habits', {
        headers: { Authorization: `Bearer ${token}` },
        data: { name, frequency: 'daily', icon },
    });
    const data = (await response.json()) as { id: string };
    return data.id;
}

async function logHabit(page: Page, token: string, habitId: string): Promise<void> {
    await page.request.post(`/api/v1/habits/${habitId}/log`, {
        headers: { Authorization: `Bearer ${token}` },
        data: { source: 'manual' },
    });
}

async function shot(page: Page, name: string): Promise<void> {
    // Small delay for animations to settle
    await page.waitForTimeout(500);
    await page.screenshot({
        path: `${SCREENSHOT_DIR}/${name}.png`,
        fullPage: false,
    });
}

// ---------------------------------------------------------------------------
// Auth screens (no login needed)
// ---------------------------------------------------------------------------

test.describe('Auth screens', () => {
    test.use({ viewport: MOBILE });

    test('01 — Login page', async ({ page }) => {
        await page.goto('/login');
        await expect(page).toHaveTitle(/Log in/i);
        await shot(page, '01-login');
    });

    test('02 — Register page', async ({ page }) => {
        await page.goto('/register');
        await expect(page).toHaveTitle(/Sign up/i);
        await shot(page, '02-register');
    });
});

// ---------------------------------------------------------------------------
// Welcome / Landing page
// ---------------------------------------------------------------------------

test.describe('Landing page', () => {
    test.use({ viewport: MOBILE });

    test('00 — Welcome page', async ({ page }) => {
        await page.goto('/welcome');
        await expect(page.getByText('SmartHabit')).toBeVisible({ timeout: 10_000 });
        await page.waitForLoadState('networkidle');
        await page.screenshot({
            path: `${SCREENSHOT_DIR}/00-welcome.png`,
            fullPage: true,
        });
    });
});

// ---------------------------------------------------------------------------
// Dashboard states
// ---------------------------------------------------------------------------

test.describe('Dashboard', () => {
    test.use({ viewport: MOBILE });

    test('03 — Empty state', async ({ page }) => {
        await registerAndLogin(page);
        await expect(page.getByText('No habits yet')).toBeVisible({ timeout: 10_000 });
        await shot(page, '03-dashboard-empty');
    });

    test('04 — With habits (partial progress)', async ({ page }) => {
        const { token } = await registerAndLogin(page);

        // Create 5 habits
        const h1 = await createHabit(page, token, 'Morning Run');
        await createHabit(page, token, 'Read 30 Minutes');
        await createHabit(page, token, 'Drink 2L Water');
        const h4 = await createHabit(page, token, 'Meditate');
        await createHabit(page, token, 'Practice Guitar');

        // Log 2 of 5
        await logHabit(page, token, h1);
        await logHabit(page, token, h4);

        await page.goto('/');
        await expect(page).toHaveTitle(/Today/i, { timeout: 15_000 });
        await page.waitForLoadState('networkidle');
        await expect(page.getByText('Morning Run')).toBeVisible({ timeout: 10_000 });
        await shot(page, '04-dashboard-progress');
    });

    test('05 — All done state', async ({ page }) => {
        const { token } = await registerAndLogin(page);

        const h1 = await createHabit(page, token, 'Morning Run');
        const h2 = await createHabit(page, token, 'Read 30 Minutes');
        const h3 = await createHabit(page, token, 'Drink 2L Water');

        await logHabit(page, token, h1);
        await logHabit(page, token, h2);
        await logHabit(page, token, h3);

        await page.goto('/');
        await expect(page).toHaveTitle(/Today/i, { timeout: 15_000 });
        // Wait for dashboard to load and show 100% progress
        await expect(page.locator('.progress-label')).toContainText('All done', {
            timeout: 15_000,
        });
        await shot(page, '05-dashboard-alldone');
    });

    test('06 — One-tap logging animation', async ({ page }) => {
        const { token } = await registerAndLogin(page);

        await createHabit(page, token, 'Morning Run');
        await createHabit(page, token, 'Read 30 Minutes');
        await createHabit(page, token, 'Drink 2L Water');

        await page.goto('/');
        await expect(page).toHaveTitle(/Today/i, { timeout: 15_000 });
        await page.waitForLoadState('networkidle');
        await expect(page.getByText('Morning Run')).toBeVisible({ timeout: 10_000 });

        // Tap the first habit check button
        const checkBtn = page.getByRole('button', { name: /Log Morning Run/i });
        await checkBtn.click();
        // Capture right after the tap (optimistic update visible)
        await page.waitForTimeout(300);
        await shot(page, '06-dashboard-tap');
    });
});

// ---------------------------------------------------------------------------
// Settings — Light & Dark mode
// ---------------------------------------------------------------------------

test.describe('Settings', () => {
    test.use({ viewport: MOBILE });

    test('07 — Settings page (light)', async ({ page }) => {
        const email = `lisa.${Date.now() % 10000}@smarthabit.de`;
        await registerAndLogin(page, 'Lisa', email);
        await page.goto('/settings');
        await expect(page.getByRole('heading', { name: 'Settings', level: 1 })).toBeVisible({
            timeout: 10_000,
        });
        await shot(page, '07-settings-light');
    });

    test('08 — Settings page (dark)', async ({ page }) => {
        const email = `lisa.dark${Date.now() % 10000}@smarthabit.de`;
        await registerAndLogin(page, 'Lisa', email);
        await page.goto('/settings');
        await expect(page.getByRole('heading', { name: 'Settings', level: 1 })).toBeVisible({
            timeout: 10_000,
        });

        // Apply dark mode directly to avoid flaky button click timing
        await page.evaluate(() => {
            document.documentElement.setAttribute('data-theme', 'dark');
            localStorage.setItem('theme', 'dark');
        });
        await shot(page, '08-settings-dark');
    });
});

// ---------------------------------------------------------------------------
// Dashboard — Dark mode
// ---------------------------------------------------------------------------

test.describe('Dashboard dark mode', () => {
    test.use({ viewport: MOBILE });

    test('09 — Dashboard with habits (dark mode)', async ({ page }) => {
        const { token } = await registerAndLogin(page);

        const h1 = await createHabit(page, token, 'Morning Run');
        await createHabit(page, token, 'Read 30 Minutes');
        await createHabit(page, token, 'Drink 2L Water');
        const h4 = await createHabit(page, token, 'Meditate');
        await createHabit(page, token, 'Practice Guitar');

        await logHabit(page, token, h1);
        await logHabit(page, token, h4);

        await page.goto('/');
        await expect(page).toHaveTitle(/Today/i, { timeout: 15_000 });
        await page.waitForLoadState('networkidle');
        await expect(page.getByText('Morning Run')).toBeVisible({ timeout: 10_000 });

        // Enable dark mode
        await page.evaluate(() => {
            document.documentElement.setAttribute('data-theme', 'dark');
            localStorage.setItem('theme', 'dark');
        });
        await shot(page, '09-dashboard-dark');
    });
});

// ---------------------------------------------------------------------------
// Stats page
// ---------------------------------------------------------------------------

test.describe('Stats', () => {
    test.use({ viewport: MOBILE });

    test('10 — Stats page with data', async ({ page }) => {
        const { token } = await registerAndLogin(page);

        const h1 = await createHabit(page, token, 'Morning Run');
        const h2 = await createHabit(page, token, 'Read 30 Minutes');
        await createHabit(page, token, 'Drink 2L Water');

        await logHabit(page, token, h1);
        await logHabit(page, token, h2);

        await page.goto('/stats');
        await expect(page.getByText('Statistics')).toBeVisible({ timeout: 15_000 });
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1000);
        await shot(page, '10-stats');
    });
});
