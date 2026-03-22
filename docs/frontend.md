# Frontend & Design
## Frontend (SvelteKit PWA)

### Architecture

SvelteKit in **SPA mode** (`adapter-static`), deployed as static files behind FrankenPHP/Caddy. No SSR needed — the app is fully client-side after the initial load. Build entirely via Bun, no Symfony AssetMapper.

```
frontend/
├── src/
│   ├── lib/
│   │   ├── api/          — Fetch wrapper, JWT handling, offline queue
│   │   ├── stores/       — Svelte 5 Runes ($state, $derived) for global state
│   │   └── components/   — UI components
│   ├── routes/
│   │   ├── +layout.svelte     — Shell: auth check, nav
│   │   ├── (app)/
│   │   │   ├── +page.svelte         — Dashboard (main view)
│   │   │   ├── habits/[id]/+page.svelte  — History + stats
│   │   │   └── settings/+page.svelte     — Household, time windows, account
│   │   └── (auth)/
│   │       ├── login/+page.svelte
│   │       └── register/+page.svelte
│   ├── service-worker.ts     — Web Push handler + offline cache
│   └── app.html
├── static/
│   └── manifest.json         — PWA manifest
├── bun.lock                  — Bun lockfile (instead of package-lock.json)
├── svelte.config.js
└── vite.config.ts
```

### PWA Features

- **Service Worker**: `@vite-pwa/sveltekit` for automatic caching
- **Offline Support**: Logs are stored locally and synced when online
- **Install Prompt**: manifest.json with icons, theme color, start URL
- **Push**: W3C Web Push API in the service worker (no Firebase SDK, VAPID auth)

### UI Principle

Dashboard = a list of large tap targets. Each habit is a card:
- Emoji + name
- Last log: "Today 07:32 by Lisa" or "Not yet completed"
- One tap → POST /log → optimistic UI update → scale-pulse checkmark animation
- Long press → history

No hamburger menu, no overhead. Open → tap → done.

### Design Direction: Neo Utility + Dark Mode

**Core Aesthetic**: Functional, clear, data-driven. The app should feel like a well-built tool — not a wellness app. Information density without chaos, clear hierarchy, everything graspable at a glance.

**Typography**:
- Headlines: `Sora` (sans-serif, geometric, modern) — 700 weight
- Body: `Sora` — 400/600 weight
- Metadata + monospace details: `JetBrains Mono` — timestamps, window times, stats
- No serifs, no playful lettering — this is a utility tool

**Color Palette Light Mode**:
```
--bg-primary:    #F4F5F7     (background)
--bg-card:       #FFFFFF     (cards)
--bg-header:     #FFFFFF     (header)
--border:        #E8EAEE     (dividers, card borders)
--text-primary:  #1A1D24     (headlines, habit names)
--text-secondary:#888D98     (subtitles, metadata)
--text-tertiary: #B0B4BC     (timestamps, inactive nav)
--accent:        #3366FF     (primary action, progress, active nav)
--success:       #22C55E     (completed, checkmarks, done border)
--warning:       #E65100     (overdue time window)
--tag-blue-bg:   #F0F4FF     (time window tag background)
--tag-orange-bg: #FFF3E0     (overdue tag background)
```

**Color Palette Dark Mode** (toggle in settings, respects `prefers-color-scheme`):
```
--bg-primary:    #0A0A0A     (background)
--bg-card:       #141416     (cards)
--bg-header:     #0A0A0A     (header)
--border:        #1E1E22     (dividers)
--text-primary:  #E8E8E8     (headlines)
--text-secondary:#666A74     (subtitles)
--text-tertiary: #444650     (timestamps)
--accent:        #4D88FF     (primary action, slightly brighter than light)
--success:       #5ACA46     (completed)
--warning:       #FF8A50     (overdue)
--tag-blue-bg:   #1A1E2E     (time window tag)
--tag-orange-bg: #2A1A0E     (overdue tag)
```

**Core Elements of the Neo Utility Design**:
- **Progress Bar in Header**: Shows daily progress (2 of 4 = 50%). Visual feedback without needing to read numbers
- **Colored Tags/Badges**: Time windows as chips (`18:00–20:00` on blue background), overdue ones on orange
- **Left Border Indicator**: Completed habits have a 3px green left border, open ones a gray one — scannable without reading text
- **Four Nav Items**: Today, History, Stats, Config — Stats as its own tab instead of hidden in settings
- **Monospace for Data**: All times, dates, stats numbers in JetBrains Mono — technical, precise, easy to read

**Dark Mode Implementation**:
- CSS Custom Properties + `prefers-color-scheme` media query as default
- User can override in settings: Auto / Light / Dark
- Preference is stored in `User.theme: enum(auto, light, dark)`
- Svelte: `$state` store that sets the CSS class on `<body>`
- No separate stylesheet — same components, just variable swap

**Micro-Interactions**:
- Tap on check button: short scale-pulse (0.9 → 1.1 → 1.0) + color transition to green
- Progress bar animates smoothly on update (CSS `transition: width 0.4s ease-out`)
- Cards appear staggered on first load (`animation-delay` per card)
- No confetti, no bouncing elements — the tool stays matter-of-fact

## Internationalization (i18n)

Bilingual from day 1: German + English. Retrofitting i18n is one of the most painful refactors — so we do it right away.

### Frontend: paraglide-sveltekit

Paraglide (by Inlang) is the i18n standard for SvelteKit. Compiles translations at build time, zero runtime overhead, fully type-safe message keys.

```
frontend/
├── messages/
│   ├── de.json      — { "dashboard.greeting": "Guten Morgen 👋", ... }
│   └── en.json      — { "dashboard.greeting": "Good morning 👋", ... }
├── src/
│   ├── lib/i18n/    — paraglide runtime (generated)
│   └── ...
```

Usage in Svelte code:
```svelte
<script>
  import * as m from '$lib/i18n/messages';
</script>
<h1>{m.dashboard_greeting()}</h1>
```

Advantages over runtime i18n libraries: tree-shaking (unused messages are dropped), TypeScript autocomplete for all keys, no JSON parsing at runtime.

**Language Detection**: `Accept-Language` header → browser language as default. User can override in settings. Language preference is stored in the `User` entity (`locale: string`, default `de`).

### Backend: Symfony Translator

Symfony's built-in Translator for everything that comes from the server:

```
translations/
├── messages.de.yaml   — notification_texts, validation_messages, error_messages
└── messages.en.yaml
```

Relevant for: notification texts ("Have you walked the dog today?" / "Warst du heute schon mit dem Hund draußen?"), validation errors (API responses), email templates (if needed later).

The `Habit.notification_message` field becomes a translation key instead of hard-coded text. The NotifyHandler resolves the key against the target user's language.

### User Entity Extension

The fields `locale`, `theme`, `consent_at`, `consent_version`, and `email_verified_at` are already defined in the main data model (see data model section). API responses include `locale` and `theme` in the JWT payload and in the `/api/v1/user/me` response. The frontend sets language and theme based on these.
