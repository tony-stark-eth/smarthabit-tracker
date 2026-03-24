/**
 * Lightweight reactive i18n using Svelte 5 runes.
 *
 * Usage:
 *   import { t, setLocale } from '$lib/i18n';
 *   t('dashboard_good_morning')           → "Good morning" / "Guten Morgen"
 *   t('dashboard_progress', { done: 3, total: 5 }) → "3 of 5 done"
 *
 * The locale is stored as $state — when setLocale() is called, all components
 * that read t() in their template will re-render automatically.
 */

import { en } from './en';
import { de } from './de';

type Locale = 'en' | 'de';
type Translations = Record<string, string>;

const translations: Record<Locale, Translations> = { en, de };

// ---------------------------------------------------------------------------
// Reactive locale state ($state requires .svelte.ts file)
// ---------------------------------------------------------------------------

let currentLocale = $state<Locale>('en');

export function getLocale(): Locale {
    return currentLocale;
}

export function setLocale(locale: string): void {
    currentLocale = locale === 'de' ? 'de' : 'en';
}

// ---------------------------------------------------------------------------
// Translation function — reads $state, so Svelte tracks it as a dependency
// ---------------------------------------------------------------------------

/**
 * Look up a translation key and interpolate `{placeholder}` tokens.
 * Falls back to English, then to the raw key.
 */
export function t(key: string, params?: Record<string, string | number>): string {
    // Reading currentLocale inside this function creates a reactive dependency.
    // When setLocale() changes the $state, any component calling t() re-renders.
    const locale = currentLocale;

    let text = translations[locale][key]
        ?? translations.en[key]
        ?? key;

    if (params !== undefined) {
        for (const [k, v] of Object.entries(params)) {
            text = text.replaceAll(`{${k}}`, String(v));
        }
    }

    return text;
}
