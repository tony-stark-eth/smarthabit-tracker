/**
 * Offline queue — persists failed POST requests to localStorage so they can be
 * replayed when the network comes back online.
 *
 * Uses localStorage (not IndexedDB) for simplicity. Suitable for small queues
 * of habit log requests.
 */

export interface QueuedRequest {
    id: string;
    url: string;
    method: string;
    body: string;
    timestamp: number;
}

const QUEUE_KEY = 'smarthabit_offline_queue';

export function getQueue(): QueuedRequest[] {
    if (typeof localStorage === 'undefined') return [];
    const raw = localStorage.getItem(QUEUE_KEY);
    if (raw === null) return [];
    try {
        return JSON.parse(raw) as QueuedRequest[];
    } catch {
        return [];
    }
}

export function addToQueue(url: string, method: string, body: unknown): void {
    const queue = getQueue();
    queue.push({
        id: crypto.randomUUID(),
        url,
        method,
        body: JSON.stringify(body),
        timestamp: Date.now(),
    });
    localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
}

export function clearQueue(): void {
    localStorage.removeItem(QUEUE_KEY);
}

export async function flushQueue(fetchFn: typeof fetch): Promise<void> {
    const queue = getQueue();
    if (queue.length === 0) return;

    const failed: QueuedRequest[] = [];

    for (const item of queue) {
        try {
            const token =
                typeof localStorage !== 'undefined'
                    ? localStorage.getItem('access_token')
                    : null;

            await fetchFn(item.url, {
                method: item.method,
                headers: {
                    'Content-Type': 'application/json',
                    ...(token !== null ? { Authorization: `Bearer ${token}` } : {}),
                },
                body: item.body,
            });
        } catch {
            failed.push(item);
        }
    }

    if (failed.length > 0) {
        localStorage.setItem(QUEUE_KEY, JSON.stringify(failed));
    } else {
        clearQueue();
    }
}
