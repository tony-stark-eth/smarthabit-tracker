# Notifications & Push
## Notification System

### Push Architecture (no Firebase)

Three push channels, all without US services:

**1. Web Push (PWA)** — W3C Standard (RFC 8030), via `minishlink/web-push`:
- Generate VAPID key pair: `openssl ecparam -genkey -name prime256v1 -out private.pem` (one-time)
- Public key in the frontend for `PushManager.subscribe()`, private key in the backend for `minishlink/web-push`
- Keys as environment variables: `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` (not in the repo!)
- Browser asks user for permission → generates PushSubscription (endpoint + keys)
- Frontend sends subscription to `POST /api/v1/user/push-subscription`
- Backend sends encrypted payloads directly to the browser endpoint (VAPID auth)
- Works on Chrome, Firefox, Edge, Safari 16.4+ — without Firebase SDK
- Browsers internally route through their own push services (Google/Mozilla/Apple), but that is browser infrastructure, not Firebase

**2. ntfy (Native Android)** — self-hosted on the Hetzner VPS:
- Lightweight open-source push server (~10MB binary, minimal RAM)
- App subscribes to a user-specific ntfy topic (e.g. `smarthabit-user-{uuid}`)
- Backend publishes via simple HTTP POST: `POST https://ntfy.smarthabit.de/{topic}`
- Supports the UnifiedPush protocol (open standard, no Google)
- Docker container alongside the app on the same VPS

**3. APNs (Native iOS)** — Apple Push Notification service directly:
- Only way for iOS push, but no Firebase needed in between
- `symfony/notifier` has a native APNs transport
- Direct HTTP/2 connection to api.push.apple.com with JWT auth

### Cron-Based Notification Check

A cron job dispatches a `CheckHabitsMessage` every 15 minutes:

```
Cron (every 15min)
  → bin/console app:check-habits
    → Load all active habits with household + users (JOIN)
    → Per habit, per user in the household:
      → Calculate user local time: UTC now → User.timezone
      → Is user local time within Habit.time_window_start/end?
      → Is today an active day? (check frequency + frequency_days)
      → Was it already completed today (in user local time!)? (HabitLog check)
      → Was a notification already sent to this user today?
      → If no: dispatch NotifyHabitMessage to Messenger
        → Handler: push to all subscriptions of this user
          → Web Push subscriptions → minishlink/web-push
          → ntfy subscriptions → HTTP POST to ntfy server
          → APNs subscriptions → Symfony Notifier APNs transport
```

Through per-user checking, multi-timezone works correctly: Lisa in Berlin gets the notification at 07:00 CET, Tom in New York at 07:00 EST — even though it is the same habit.

### NotifyHandler (Multi-Transport)

```php
// Simplified logic
foreach ($user->getPushSubscriptions() as $subscription) {
    match ($subscription['type']) {
        'web_push' => $this->webPushService->send($subscription, $payload),
        'ntfy'     => $this->ntfyClient->publish($subscription['topic'], $payload),
        'apns'     => $this->apnsTransport->send($subscription['device_token'], $payload),
    };
}
```

Each transport has its own error handling. Failed subscriptions are logged in the NotificationLog with `error_reason` and `push_type`.

### Auto Time Windows (Learning)

A nightly command (`app:learn-timewindows`) analyzes the logs of the last 21 days:

```
1. Collect all logged_at (UTC) times for a habit
2. Per log: convert UTC → User.timezone, then minutes since midnight
3. Calculate median = usual local time
4. MAD (Median Absolute Deviation) = more robust than stddev against outliers
5. Window = Median +/- (1.5 x MAD), at least 30min wide
6. Weekday/weekend split if pattern detected (>= 7 data points per group)
7. Update Habit.time_window_start/end (as local time), time_window_mode → auto
```

Why MAD instead of standard deviation: A single outlier (dog let out at 23:00 because guests were over) shifts the stddev massively. MAD is robust against this.

User can switch back to manual at any time.

### ntfy Docker Setup

Runs as an additional service on the Hetzner VPS, separate compose or in the app stack:

```yaml
ntfy:
  image: binwiederhier/ntfy:latest
  command: serve
  restart: unless-stopped
  ports: ["127.0.0.1:2586:80"]  # internal only, Caddy proxies
  volumes:
    - ntfy-cache:/var/cache/ntfy
    - ./ntfy/server.yml:/etc/ntfy/server.yml
  environment:
    TZ: UTC
```

Caddy route: `ntfy.smarthabit.de` → `ntfy:80` with auto-HTTPS. Auth via token-based access control — only the app server may publish, users subscribe via their topics.

## Push Subscription Lifecycle

Push subscriptions are short-lived and differ per platform. Three mechanisms keep the list clean:

**1. Registration** — Frontend/app sends subscription on every start via `POST /api/v1/user/push-subscription`. Idempotent: existing subscription is updated (last_seen), new one is added.

```
push_subscriptions: [
  {
    "type": "web_push",
    "endpoint": "https://updates.push.services.mozilla.com/wpush/v2/...",
    "keys": { "p256dh": "...", "auth": "..." },
    "device_name": "Chrome Desktop",
    "last_seen": "2026-03-21T10:00:00Z"
  },
  {
    "type": "ntfy",
    "topic": "smarthabit-user-abc123",
    "device_name": "Android Lisa",
    "last_seen": "2026-03-20T08:00:00Z"
  },
  {
    "type": "apns",
    "device_token": "a1b2c3...",
    "device_name": "iPhone Lisa",
    "last_seen": "2026-03-21T09:00:00Z"
  }
]
```

**2. Error Handling in the NotifyHandler**:
- Web Push: HTTP 410 (Gone) → remove subscription. HTTP 429 → retry with backoff.
- ntfy: HTTP 5xx → retry. Topic does not exist → remove subscription.
- APNs: Status 410 (Unregistered) → remove subscription. Status 429 → retry.

**3. Nightly Cleanup** — `app:cleanup-push-subscriptions` (cron 04:00 UTC):
- Subscriptions with `last_seen` older than 30 days are removed
- Protects against forgotten devices (old phone, uninstalled app)
- Logs cleanup actions for debugging

**Logout** — `DELETE /api/v1/user/push-subscription` explicitly removes the subscription of the current device.
