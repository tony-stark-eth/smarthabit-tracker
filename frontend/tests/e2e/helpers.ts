import { type Page } from '@playwright/test';
import { expect } from '@playwright/test';

// ---------------------------------------------------------------------------
// Response shapes
// ---------------------------------------------------------------------------

interface RegisterResponse {
    token: string;
    refresh_token?: string;
}

interface HabitResponse {
    id: string;
    name: string;
}

// ---------------------------------------------------------------------------
// Register a fresh user via the API and inject the JWT into localStorage.
//
// Using the API directly (not through the UI) keeps helper setup fast and
// decoupled from the registration form's own test coverage.
// ---------------------------------------------------------------------------

export async function registerUser(page: Page): Promise<{ email: string; token: string; refreshToken: string }> {
    const email = `e2e-${Date.now()}-${Math.random().toString(36).slice(2)}@test.com`;

    const response = await page.request.post('/api/v1/register', {
        data: {
            email,
            password: 'password123',
            display_name: 'E2E Test User',
            timezone: 'Europe/Berlin',
            locale: 'en',
            household_name: 'E2E Household',
            consent: true,
        },
    });

    if (!response.ok()) {
        throw new Error(
            `registerUser: API returned ${response.status()} — ${await response.text()}`,
        );
    }

    const data = (await response.json()) as RegisterResponse;
    const token = data.token;
    const refreshToken = data.refresh_token ?? '';

    // Navigate to the app once so that localStorage is available on the right
    // origin, then inject both tokens so the auth store considers us logged in.
    await page.goto('/login');
    await page.evaluate(
        ({ t, rt }: { t: string; rt: string }) => {
            localStorage.setItem('access_token', t);
            localStorage.setItem('refresh_token', rt);
        },
        { t: token, rt: refreshToken },
    );

    // Navigate to / so the SvelteKit module-level auth store re-initializes
    // with the injected tokens. The auth store calls fetchUser() which must
    // complete before the auth guard $effect fires. Wait for the dashboard
    // title to confirm the auth flow completed successfully.
    await page.goto('/');
    await expect(page).toHaveTitle(/Today/i, { timeout: 15_000 });

    return { email, token, refreshToken };
}

// ---------------------------------------------------------------------------
// Create a habit via the API and return its UUID.
// ---------------------------------------------------------------------------

export async function createHabit(
    page: Page,
    token: string,
    name: string = 'Test Habit',
): Promise<string> {
    const response = await page.request.post('/api/v1/habits', {
        headers: { Authorization: `Bearer ${token}` },
        data: { name, frequency: 'daily' },
    });

    const data = (await response.json()) as HabitResponse;
    return data.id;
}
