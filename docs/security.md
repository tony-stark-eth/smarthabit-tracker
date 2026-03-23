# Security, GDPR & Email
## GDPR & Privacy

Mandatory for the EU market. The app stores personal data: email, behavioral patterns (when is what completed), household composition, device tokens. Must be considered from the start — retrofitting is a nightmare.

### API Endpoints

```
GET    /api/v1/user/export       — Data export (Art. 20 GDPR): all user data as JSON download
DELETE /api/v1/user/me           — Account deletion (Art. 17 GDPR): cascade to all logs, notifications, push subscriptions
GET    /api/v1/privacy           — Current privacy policy as JSON (version + text)
```

### Deletion Cascade

Account deletion removes: User entity, all HabitLogs of the user, all NotificationLogs of the user, all push subscriptions. Habits remain (they belong to the household, not the user). If the last user of a household is deleted → cascade-delete household + all habits + all logs.

### Retention Periods

- **HabitLogs**: 1 year, then automatically anonymized (user_id → null) or deleted. Nightly command `app:cleanup-old-logs`.
- **NotificationLogs**: 90 days, then deleted (purely for debugging).
- **Account data**: until deletion by the user.
- Configurable via environment variables: `LOG_RETENTION_DAYS=365`, `NOTIFICATION_LOG_RETENTION_DAYS=90`.

### Consent

- At registration: Checkbox "I have read and accept the privacy policy" (required, stored with timestamp + privacy policy version).
- Push notifications: separate consent in frontend (browser permission dialog + explicit opt-in in app flow).
- Cookie banner: not needed — PWA uses no third-party cookies, only first-party JWT + localStorage.

### Privacy Policy

Must contain: data controller, purpose of processing (habit tracking, notifications), legal basis (consent Art. 6(1)(a) GDPR), recipients (Resend for email — US-based, DPA available; ntfy self-hosted for push), third-country transfer (Apple APNs for iOS push — USA, Standard Contractual Clauses; Resend — USA; browser push endpoints for Web Push), retention period, rights (access, deletion, export, withdrawal), contact details. Versioned as Markdown in the repo, retrievable via API. Advantage of the self-hosted approach: push data for Android never leaves the own server (ntfy on Hetzner DE).

## Security Hardening

### Rate Limiting

Symfony Rate Limiter on auth endpoints — without this the login endpoint is a brute-force target.

```php
// config/packages/rate_limiter.php
'login' => [
    'policy' => 'sliding_window',
    'limit' => 5,
    'interval' => '1 minute',
],
'register' => [
    'policy' => 'fixed_window',
    'limit' => 3,
    'interval' => '15 minutes',
],
'api_general' => [
    'policy' => 'sliding_window',
    'limit' => 60,
    'interval' => '1 minute',
],
```

Additionally: Caddy as the **first rate-limit layer** before PHP — faster because no PHP bootstrapping required:

```
# Caddyfile — Rate limiting at infrastructure level
route /api/v1/login {
    rate_limit {remote.ip} 5r/m 429
}
route /api/v1/register {
    rate_limit {remote.ip} 3r/15m 429
}
route /api/v1/password/* {
    rate_limit {remote.ip} 3r/15m 429
}
```

Two layers: Caddy blocks obvious brute-force before PHP is even touched, Symfony Rate Limiter handles the finer logic (per user, with sliding window).

### Extended Auth Flows

- **Email Verification**: After registration → verification email with token link. Account is restricted until verified (can log, but cannot invite). Symfony has `SymfonyCasts\Bundle\VerifyEmail\VerifyEmailBundle`.
- **Password Reset**: `POST /api/v1/password/forgot` → token via email → `POST /api/v1/password/reset` with token + new password. Token is valid for 1h, single-use.
- **Password Change**: `PUT /api/v1/user/password` with old + new password (for logged-in users).

### Additional Measures

- **Same-origin architecture**: Frontend and API run under the same domain (Caddy in prod, Vite proxy in dev) — no CORS needed, no `nelmio/cors-bundle`.
- **CSP Headers**: Caddy config, `Content-Security-Policy` with strict `script-src`.
- **Input Validation**: Symfony Validator with attributes on all DTOs. No raw user input in queries.
- **Household Isolation**: Middleware/Voter that verifies every API access only touches data of the user's own household. Unit-tested.
- **JWT Blacklist**: On password change/reset all existing refresh tokens are invalidated.

## Email (Symfony Mailer + Mailpit / Resend)

### Dev: Mailpit

[Mailpit](https://github.com/axllent/mailpit) is a lightweight, self-hosted email testing tool. All outgoing email is caught and displayed in a web UI at `http://localhost:8025`. No external accounts needed.

```
# compose.yaml (already configured)
MAILER_DSN=smtp://mailpit:1025
```

### Prod: Resend

[Resend](https://resend.com) provides a free tier (3,000 emails/month) — more than enough for a personal household app. Uses standard SMTP, no Symfony bridge package required.

```
# .env.local on production server
MAILER_DSN=smtp://resend:re_YOUR_API_KEY@smtp.resend.com:465
```

Setup:
1. Create a free account at https://resend.com
2. Verify your domain (add DNS records: SPF, DKIM, DMARC)
3. Generate an API key
4. Set `MAILER_DSN` in `.env.local` on the production server

### Email Types

- **Verification email**: After registration, token link for confirmation
- **Password reset**: Token link, valid for 1h
- **Household invitation**: Optional — invite via email in addition to invite code
- **Welcome email**: After successful verification

### Async via Messenger

All emails go through Symfony Messenger (async). The Mailer is already Messenger-aware — `MAILER_DSN` + `messenger.yaml` config is sufficient. No custom handler needed. Emails never block the request.

### Templates

Symfony Mailer + Twig for email templates (yes, Twig — only for emails, not for the app). Bilingual via Symfony Translator: template uses `{{ 'email.verification.subject'|trans({}, 'messages', user.locale) }}`.
