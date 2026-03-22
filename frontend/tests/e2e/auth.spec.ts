import { test, expect } from '@playwright/test';

/**
 * Auth E2E tests — registration and login flows.
 *
 * Each test registers a unique user so tests are fully independent and can
 * run in parallel without conflicting on database state.
 *
 * Requires:
 *   docker compose --profile dev up -d
 *   cd frontend && bun run test:e2e
 */

test.describe('Registration flow', () => {
    test('register a new user and land on the dashboard', async ({ page }) => {
        const email = `e2e-reg-${Date.now()}@test.com`;

        await page.goto('/register');

        // Ensure the page loaded
        await expect(page).toHaveTitle(/Sign up/i);

        // Fill in all required fields using IDs (match what the component renders)
        await page.fill('#email', email);
        await page.fill('#display_name', 'E2E Register Test');
        await page.fill('#password', 'password123');
        await page.fill('#confirm_password', 'password123');

        // Timezone is auto-populated; leave it as-is.
        // Household mode defaults to "create" — fill household name.
        await page.fill('#household_name', 'E2E Test Household');

        // Accept privacy policy — the checkbox has class consent-checkbox
        await page.locator('.consent-checkbox').check();

        // Submit
        await page.click('button[type="submit"]');

        // After successful registration the app redirects to /
        await page.waitForURL('/', { timeout: 15_000 });
        await expect(page).toHaveURL('/');

        // The page title should be "Today — SmartHabit"
        await expect(page).toHaveTitle(/Today/i);
    });

    test('register form shows field-level errors when submitted empty', async ({ page }) => {
        await page.goto('/register');

        // Submit without filling anything
        await page.click('button[type="submit"]');

        // Client-side validation should surface errors without a network round-trip.
        // The component renders <p class="field-error"> elements with the error text.
        await expect(page.getByText('Email is required.')).toBeVisible();
        await expect(page.getByText('Password is required.')).toBeVisible();

        // Stay on the register page
        await expect(page).toHaveURL('/register');
    });

    test('register form shows error when passwords do not match', async ({ page }) => {
        await page.goto('/register');

        await page.fill('#email', `mismatch-${Date.now()}@test.com`);
        await page.fill('#display_name', 'Test');
        await page.fill('#password', 'password123');
        await page.fill('#confirm_password', 'different456');
        await page.fill('#household_name', 'Home');
        await page.locator('.consent-checkbox').check();

        await page.click('button[type="submit"]');

        await expect(page.getByText('Passwords do not match.')).toBeVisible();

        await expect(page).toHaveURL('/register');
    });

    test('register page has a link to the login page', async ({ page }) => {
        await page.goto('/register');

        await expect(page.getByRole('link', { name: /Log in/i })).toBeVisible();
    });
});

test.describe('Login flow', () => {
    test('login with valid credentials and land on the dashboard', async ({ page }) => {
        // First register a user so we have valid credentials to log in with.
        const email = `e2e-login-${Date.now()}@test.com`;

        await page.request.post('/api/v1/register', {
            data: {
                email,
                password: 'password123',
                display_name: 'E2E Login Test',
                timezone: 'Europe/Berlin',
                locale: 'en',
                household_name: 'E2E Login Household',
                consent: true,
            },
        });

        // Now log in via the UI
        await page.goto('/login');
        await expect(page).toHaveTitle(/Log in/i);

        await page.fill('#email', email);
        await page.fill('#password', 'password123');
        await page.click('button[type="submit"]');

        // Successful login redirects to /
        await page.waitForURL('/', { timeout: 15_000 });
        await expect(page).toHaveURL('/');
        await expect(page).toHaveTitle(/Today/i);
    });

    test('login with wrong password shows an error message', async ({ page }) => {
        await page.goto('/login');

        await page.fill('#email', 'nobody@test.com');
        await page.fill('#password', 'wrongpassword');
        await page.click('button[type="submit"]');

        // Error message should appear (role="alert")
        const errorMessage = page.locator('[role="alert"]');
        await expect(errorMessage).toBeVisible({ timeout: 10_000 });

        // Must stay on the login page
        await expect(page).toHaveURL('/login');
    });

    test('login page has a link to the registration page', async ({ page }) => {
        await page.goto('/login');

        await expect(page.getByRole('link', { name: /Sign up/i })).toBeVisible();
    });

    test('unauthenticated user visiting / is redirected to /login', async ({ page }) => {
        // Do not set any tokens — navigate directly to the app root.
        await page.goto('/');

        // The root layout guard redirects to /login when no token is present.
        await expect(page).toHaveURL('/login', { timeout: 10_000 });
    });
});
