import { client } from '$lib/api/client';

export async function registerPushSubscription(): Promise<boolean> {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        return false;
    }

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return false;

    // Get VAPID public key from backend
    const { publicKey } = await client.get<{ publicKey: string }>('/vapid-key');

    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(publicKey),
    });

    const json = subscription.toJSON();

    await client.post('/user/push-subscription', {
        type: 'web_push',
        endpoint: json.endpoint,
        keys: json.keys,
        device_name: navigator.userAgent.slice(0, 50),
    });

    return true;
}

export async function unregisterPushSubscription(): Promise<void> {
    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.getSubscription();
    if (subscription) {
        await client.delete('/user/push-subscription', { endpoint: subscription.endpoint });
        await subscription.unsubscribe();
    }
}

function urlBase64ToUint8Array(base64String: string): Uint8Array<ArrayBuffer> {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);
    const output = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; i++) {
        output[i] = rawData.charCodeAt(i);
    }
    return output;
}
