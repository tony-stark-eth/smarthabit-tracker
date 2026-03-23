/**
 * Shared TypeScript types for the SmartHabit frontend.
 */

export interface HabitData {
    id: string;
    name: string;
    icon: string | null;
    frequency: 'daily' | 'weekly' | 'custom';
    /** "HH:MM:SS" format returned by the API, or null. */
    time_window_start: string | null;
    /** "HH:MM:SS" format returned by the API, or null. */
    time_window_end: string | null;
}

export interface Household {
    id: string;
    name: string;
    invite_code: string;
}

export interface User {
    id: string;
    email: string;
    display_name: string;
    timezone: string;
    locale: string;
    theme: string;
    household: Household;
}

export interface RegisterData {
    email: string;
    password: string;
    display_name: string;
    timezone: string;
    locale: string;
    household_name?: string;
    invite_code?: string;
    consent: boolean;
}
