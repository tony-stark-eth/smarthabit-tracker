import { test, expect } from '@playwright/test';

/**
 * PWA / infrastructure E2E tests.
 *
 * These tests verify that static PWA assets are served correctly and that the
 * backend health endpoint is reachable through the Vite proxy.
 *
 * Requires:
 *   docker compose --profile dev up -d
 *   cd frontend && bun run test:e2e
 */

// ---------------------------------------------------------------------------
// Typed response shapes
// ---------------------------------------------------------------------------

interface ManifestJson {
    name: string;
    short_name: string;
    display: string;
    start_url: string;
}

interface HealthResponse {
    status: string;
    timestamp: string;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test.describe('PWA manifest', () => {
    test('manifest.json is served with status 200', async ({ page }) => {
        const response = await page.goto('/manifest.json');
        expect(response?.status()).toBe(200);
    });

    test('manifest.json contains the correct app name and display mode', async ({ page }) => {
        const response = await page.goto('/manifest.json');
        expect(response?.status()).toBe(200);

        const json = (await response?.json()) as ManifestJson;
        expect(json.name).toBe('SmartHabit Tracker');
        expect(json.short_name).toBe('SmartHabit');
        expect(json.display).toBe('standalone');
        expect(json.start_url).toBe('/');
    });
});

test.describe('API health', () => {
    test('health endpoint returns status ok', async ({ request }) => {
        const response = await request.get('/api/v1/health');
        expect(response.status()).toBe(200);

        const body = (await response.json()) as HealthResponse;
        expect(body.status).toBe('ok');
        expect(body.timestamp).toBeTruthy();
    });

    test('ready endpoint confirms database connectivity', async ({ request }) => {
        const response = await request.get('/api/v1/health/ready');
        expect(response.status()).toBe(200);

        const body = (await response.json()) as { status: string };
        expect(body.status).toBe('ok');
    });
});

test.describe('Frontend shell', () => {
    test('unauthenticated visit to / redirects to /login', async ({ page }) => {
        // Navigate with a fresh context — no localStorage tokens.
        await page.goto('/');

        await expect(page).toHaveURL('/login', { timeout: 10_000 });
    });

    test('/login page renders with the correct title', async ({ page }) => {
        await page.goto('/login');
        await expect(page).toHaveTitle(/Log in/i);
    });

    test('/register page renders with the correct title', async ({ page }) => {
        await page.goto('/register');
        await expect(page).toHaveTitle(/Sign up/i);
    });
});
