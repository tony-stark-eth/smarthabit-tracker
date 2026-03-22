export type Platform = 'web' | 'ios' | 'android';

export function getPlatform(): Platform {
    // Capacitor injects window.Capacitor when running in a native shell
    if (typeof window !== 'undefined' && 'Capacitor' in window) {
        const cap = (window as { Capacitor?: { isNativePlatform?: () => boolean; getPlatform?: () => string } }).Capacitor;
        if (cap?.isNativePlatform?.()) {
            const platform = cap.getPlatform?.();
            if (platform === 'ios') return 'ios';
            if (platform === 'android') return 'android';
        }
    }
    return 'web';
}

export function isNative(): boolean {
    return getPlatform() !== 'web';
}
