/**
 * Shared TypeScript types for the SmartHabit frontend.
 */

export interface User {
    id: string;
    email: string;
    display_name: string;
    timezone: string;
    locale: string;
    theme: string;
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
