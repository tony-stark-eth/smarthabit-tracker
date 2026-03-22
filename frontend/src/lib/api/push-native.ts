/**
 * Native push registration via Capacitor.
 *
 * iOS  — uses APNs via @capacitor/push-notifications, sends device token to backend.
 * Android — generates a per-user ntfy topic, sends to backend, and subscribes to the
 *           ntfy server so the device receives pushes.
 */

import { PushNotifications } from '@capacitor/push-notifications';
import { client } from '$lib/api/client';

// ---------------------------------------------------------------------------
// iOS — APNs
// ---------------------------------------------------------------------------

/**
 * Register for APNs push notifications on iOS.
 * Requests permission, retrieves the device token, and posts it to the backend.
 */
export async function registerApnsPush(): Promise<boolean> {
    const permResult = await PushNotifications.requestPermissions();
    if (permResult.receive !== 'granted') return false;

    return new Promise<boolean>((resolve) => {
        // Registration success — token is ready
        PushNotifications.addListener('registration', async (token) => {
            try {
                await client.post('/user/push-subscription', {
                    type: 'apns',
                    device_token: token.value,
                    device_name: 'iOS Device',
                });
                resolve(true);
            } catch {
                resolve(false);
            }
        });

        // Registration failure
        PushNotifications.addListener('registrationError', () => {
            resolve(false);
        });

        PushNotifications.register();
    });
}

/**
 * Unregister APNs push — removes the subscription from the backend.
 * The device token is not directly retrievable after registration so we
 * call the generic unregister endpoint.
 */
export async function unregisterApnsPush(): Promise<void> {
    await client.delete('/user/push-subscription', { type: 'apns' });
}

// ---------------------------------------------------------------------------
// Android — ntfy
// ---------------------------------------------------------------------------

const NTFY_SERVER = 'https://ntfy.sh';

/**
 * Register for push notifications on Android via ntfy.
 * Creates a per-user topic, posts it to the backend, and opens a subscription
 * to the ntfy server so the device receives pushes via the Capacitor plugin.
 */
export async function registerNtfyPush(userId: string): Promise<boolean> {
    const permResult = await PushNotifications.requestPermissions();
    if (permResult.receive !== 'granted') return false;

    // Derive a deterministic, per-user ntfy topic (not guessable without the userId)
    const topic = `smarthabit-user-${userId}`;

    try {
        // Inform the backend which ntfy topic to publish to
        await client.post('/user/push-subscription', {
            type: 'ntfy',
            topic,
            ntfy_server: NTFY_SERVER,
            device_name: 'Android Device',
        });

        // Subscribe to the ntfy topic on the device via a fetch-based SSE stream.
        // This runs in the background — in a real app you would use a background
        // service or the ntfy Android app for reliable delivery.
        void subscribeNtfyTopic(topic);

        return true;
    } catch {
        return false;
    }
}

/**
 * Subscribe to the ntfy topic using a server-sent event stream.
 * Dispatches a custom DOM event ('ntfy-message') for each incoming notification
 * so the app can react without coupling to a specific state management solution.
 */
async function subscribeNtfyTopic(topic: string): Promise<void> {
    try {
        const response = await fetch(`${NTFY_SERVER}/${topic}/sse`);
        const reader = response.body?.getReader();
        if (reader === undefined) return;

        const decoder = new TextDecoder();
        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            const text = decoder.decode(value);
            const lines = text.split('\n');
            for (const line of lines) {
                if (line.startsWith('data:')) {
                    try {
                        const payload = JSON.parse(line.slice(5).trim()) as unknown;
                        window.dispatchEvent(new CustomEvent('ntfy-message', { detail: payload }));
                    } catch {
                        // Ignore malformed messages
                    }
                }
            }
        }
    } catch {
        // Network error or stream closed — caller decides whether to retry
    }
}

/**
 * Unregister ntfy push — removes the subscription from the backend.
 */
export async function unregisterNtfyPush(userId: string): Promise<void> {
    const topic = `smarthabit-user-${userId}`;
    await client.delete('/user/push-subscription', { type: 'ntfy', topic });
}
