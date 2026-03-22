import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
    appId: 'com.smarthabit.app',
    appName: 'SmartHabit',
    webDir: 'build',
    server: {
        // In development, proxy to the Vite dev server
        // Comment out for production builds
        // url: 'http://localhost:5173',
        // cleartext: true,
    },
    plugins: {
        PushNotifications: {
            presentationOptions: ['badge', 'sound', 'alert'],
        },
    },
};

export default config;
