/**
 * Lightweight i18n — reactive locale + translation function.
 *
 * Usage:
 *   import { t, setLocale } from '$lib/i18n';
 *   t('dashboard_good_morning')           → "Good morning" / "Guten Morgen"
 *   t('dashboard_progress', { done: 3, total: 5 }) → "3 of 5 done"
 */

import { en } from './en';
import { de } from './de';

type Locale = 'en' | 'de';
type Translations = Record<string, string>;

const translations: Record<Locale, Translations> = { en, de };

// ---------------------------------------------------------------------------
// Reactive locale state — set once after login from user.locale
// ---------------------------------------------------------------------------

let currentLocale: Locale = 'en';

export function getLocale(): Locale {
    return currentLocale;
}

export function setLocale(locale: string): void {
    currentLocale = locale === 'de' ? 'de' : 'en';
}

// ---------------------------------------------------------------------------
// Translation function
// ---------------------------------------------------------------------------

/**
 * Look up a translation key and interpolate `{placeholder}` tokens.
 * Falls back to English, then to the raw key.
 */
export function t(key: string, params?: Record<string, string | number>): string {
    let text = translations[currentLocale][key]
        ?? translations.en[key]
        ?? key;

    if (params !== undefined) {
        for (const [k, v] of Object.entries(params)) {
            text = text.replaceAll(`{${k}}`, String(v));
        }
    }

    return text;
}
