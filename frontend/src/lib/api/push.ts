/**
 * Platform-aware push notification dispatcher.
 *
 * Delegates to the appropriate implementation based on the runtime platform:
 *   web     → Web Push (VAPID) via push-web.ts
 *   ios     → APNs via Capacitor (push-native.ts)
 *   android → ntfy via Capacitor (push-native.ts)
 *
 * Public API: registerPush(userId?) and unregisterPush()
 */

import { getPlatform } from '$lib/platform';
import {
    registerWebPushSubscription,
    unregisterWebPushSubscription,
} from '$lib/api/push-web';
import {
    registerApnsPush,
    unregisterApnsPush,
    registerNtfyPush,
    unregisterNtfyPush,
} from '$lib/api/push-native';

/**
 * Register for push notifications on the current platform.
 *
 * @param userId - Required for Android (ntfy topic derivation). Ignored on web/iOS.
 * @returns true if registration succeeded, false otherwise.
 */
export async function registerPush(userId?: string): Promise<boolean> {
    const platform = getPlatform();

    switch (platform) {
        case 'ios':
            return registerApnsPush();

        case 'android':
            if (userId === undefined) {
                console.warn('[push] registerPush: userId is required on Android');
                return false;
            }
            return registerNtfyPush(userId);

        default:
            return registerWebPushSubscription();
    }
}

/**
 * Unregister push notifications on the current platform.
 *
 * @param userId - Required for Android to derive the ntfy topic. Ignored on web/iOS.
 */
export async function unregisterPush(userId?: string): Promise<void> {
    const platform = getPlatform();

    switch (platform) {
        case 'ios':
            return unregisterApnsPush();

        case 'android':
            if (userId === undefined) {
                console.warn('[push] unregisterPush: userId is required on Android');
                return;
            }
            return unregisterNtfyPush(userId);

        default:
            return unregisterWebPushSubscription();
    }
}

// ---------------------------------------------------------------------------
// Legacy named exports — kept for backwards compatibility with existing callers
// that import { registerPushSubscription } or { unregisterPushSubscription }.
// ---------------------------------------------------------------------------

/** @deprecated Use registerPush() instead. */
export const registerPushSubscription = (): Promise<boolean> => registerPush();

/** @deprecated Use unregisterPush() instead. */
export const unregisterPushSubscription = (): Promise<void> => unregisterPush();
