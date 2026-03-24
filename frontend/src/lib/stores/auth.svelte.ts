/**
 * Auth store using Svelte 5 runes.
 * Must be a .svelte.ts file for $state runes to work outside components.
 */

import { client, clearTokens } from '$lib/api/client';
import { setLocale } from '$lib/i18n';
import type { User, RegisterData } from '$lib/types';

// ---------------------------------------------------------------------------
// State (module-level $state — works in .svelte.ts)
// ---------------------------------------------------------------------------

let user = $state<User | null>(null);
let loading = $state(true);

// Derive authenticated state from whether we have a stored access token.
// The token lives in localStorage (managed by client.ts).
function hasToken(): boolean {
    if (typeof localStorage === 'undefined') return false;
    return localStorage.getItem('access_token') !== null;
}

let authenticated = $state(hasToken());

// ---------------------------------------------------------------------------
// Getters (exported so components can subscribe to the reactive state)
// ---------------------------------------------------------------------------

export function getUser(): User | null {
    return user;
}

export function isLoading(): boolean {
    return loading;
}

export function isAuthenticated(): boolean {
    return authenticated;
}

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------

async function afterAuth(token: string, refreshToken?: string): Promise<void> {
    localStorage.setItem('access_token', token);
    if (refreshToken) {
        localStorage.setItem('refresh_token', refreshToken);
    }
    authenticated = true;
    await fetchUser();
}

export async function login(email: string, password: string): Promise<void> {
    const response = await client.post<{ token: string; refresh_token?: string }>('/login', {
        email,
        password,
    });
    await afterAuth(response.token, response.refresh_token);
}

export async function register(data: RegisterData): Promise<void> {
    const response = await client.post<{ token: string; refresh_token?: string }>('/register', data);
    await afterAuth(response.token, response.refresh_token);
}

export async function fetchUser(): Promise<void> {
    try {
        const response = await client.get<{ user: User }>('/user/me');
        user = response.user;
        setLocale(user.locale);
    } catch {
        user = null;
        authenticated = false;
        clearTokens();
    } finally {
        loading = false;
    }
}

export function logout(): void {
    user = null;
    authenticated = false;
    loading = false;
    clearTokens();
    if (typeof window !== 'undefined') {
        window.location.href = '/login';
    }
}

// ---------------------------------------------------------------------------
// Bootstrap — load user if a token already exists on page load
// ---------------------------------------------------------------------------

if (typeof localStorage !== 'undefined' && hasToken()) {
    fetchUser().catch(() => {
        loading = false;
    });
} else {
    loading = false;
}
