/**
 * Shared TypeScript types for the SmartHabit frontend.
 */

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
