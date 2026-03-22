# Native App & Widgets
## Native App & Widgets (Gradual)

### Strategy: PWA-First, Gradual Native Features

Three stages from simple to complex. Each stage delivers standalone value — you don't need to reach stage 3 for the effort to be worthwhile.

### Stage 1 — PWA Shortcuts (Zero Native Code, from Phase 2)

No Capacitor needed. The PWA manifest gets `shortcuts` entries:

```json
{
  "shortcuts": [
    {
      "name": "Log dog walk",
      "short_name": "Dog",
      "url": "/log/dog?source=shortcut",
      "icons": [{ "src": "/icons/dog-96.png", "sizes": "96x96" }]
    },
    {
      "name": "Log dishwasher",
      "short_name": "Dishes",
      "url": "/log/dishwasher?source=shortcut",
      "icons": [{ "src": "/icons/dish-96.png", "sizes": "96x96" }]
    }
  ]
}
```

Result: Long-press on the SmartHabit icon shows quick actions. Tap opens the app directly on the log route for the habit. Works on Android (Chrome) and iOS (Safari 16.4+). No App Store needed, no native code — 30 minutes of work.

Dynamic shortcuts (based on the user's actual habits) are not possible as a PWA — manifest shortcuts are static. But the top 3 habits as fixed shortcuts cover 80% of the need.

### Stage 2 — Capacitor App + Store Deployment (No Widget Code)

The same SvelteKit build (`adapter-static`) in a native WebView:

```bash
bun add @capacitor/core @capacitor/cli
bunx cap init SmartHabit com.smarthabit.app --web-dir frontend/build
bunx cap add ios
bunx cap add android
```

Build flow:
```
bun run build          → frontend/build/ (static files)
bunx cap sync          → copies build into native projects
bunx cap open ios      → opens Xcode
bunx cap open android  → opens Android Studio
```

The code stays identical — Capacitor adds native projects (`ios/`, `android/`). What this gives you: App Store / Play Store presence, native push via APNs (iOS) and ntfy (Android), and the foundation for stage 3.

**App Store Deployment**:
- iOS: Xcode build → TestFlight → App Store Connect
- Android: Android Studio build → Play Console → Internal Testing → Production
- Push notifications: APNs directly for iOS, ntfy for Android — no Firebase, no `@capacitor/push-notifications` needed

### Stage 3 — Native Widgets via `capacitor-widget-bridge` (Minimal Native Code)

The community plugin `capacitor-widget-bridge` handles the complete data bridge between app and widget. No custom Capacitor plugin needed.

**How it works:**

```
┌─────────────────────────────────────────────┐
│  SvelteKit App (JS)                         │
│                                             │
│  // Write habit data to SharedStorage       │
│  WidgetBridge.setItem({                     │
│    group: 'group.com.smarthabit',           │
│    key: 'habits',                           │
│    value: JSON.stringify(todayHabits)       │
│  });                                        │
│  WidgetBridge.reloadAllTimelines();         │
│                                             │
└──────────────────┬──────────────────────────┘
                   │ SharedDefaults (iOS)
                   │ SharedPreferences (Android)
                   ▼
┌─────────────────────────────────────────────┐
│  Native Widget                              │
│                                             │
│  iOS:  ~50-80 lines SwiftUI                │
│        → reads UserDefaults(suiteName:)    │
│        → shows habit list + tap buttons    │
│                                             │
│  Android: ~80-100 lines Kotlin + XML       │
│        → reads SharedPreferences            │
│        → RemoteViews layout                │
│                                             │
└─────────────────────────────────────────────┘
```

**What you need to write yourself** (the plugin takes care of the rest):
- iOS: a SwiftUI Widget Extension (~50-80 lines) — reads JSON from UserDefaults, renders as a list
- Android: an AppWidgetProvider (~80-100 lines Kotlin) + an XML layout — reads JSON from SharedPreferences

**What the plugin handles**:
- `setItem()` / `getItem()` / `removeItem()` — share data between JS and the native widget
- `reloadAllTimelines()` / `reloadTimelines()` — trigger widget refresh programmatically
- Works on both platforms with the same JS API

**Data flow**:
1. App opens → `GET /api/v1/dashboard` → write habit data to SharedStorage via `WidgetBridge.setItem()`
2. Widget reads cached data directly from SharedStorage (no API call from the widget)
3. Tap on "Done" in the widget → opens app with deep link → app logs via API
4. After logging: `WidgetBridge.reloadAllTimelines()` → widget refreshes

Tap-to-log directly from the widget without opening the app is technically possible (via App Intents on iOS, PendingIntent on Android), but one iteration more complex. For v1, this is sufficient: widget shows status, tap opens the app at the right habit.

**Widget Sizes**:
- iOS: Small (1-2 habits, status only), Medium (4 habits with tap targets), Large (all habits)
- Android: 2x1 (compact), 4x2 (full)

### Optional: iOS Live Activities

Not prioritized, but interesting for the future: `capacitor-live-activity` plugin for temporary lock screen displays. Example: "Dog has been outside for 45min" as a timer on the lock screen + Dynamic Island. Cool feature, but niche — only after stage 3 when the foundation is solid.
