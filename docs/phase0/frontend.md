# Frontend — SvelteKit Setup

#### `package.json`

```json
{
    "name": "frontend",
    "private": true,
    "type": "module",
    "scripts": {
        "dev": "vite dev",
        "build": "vite build",
        "preview": "vite preview",
        "check": "svelte-kit sync && svelte-check --tsconfig ./tsconfig.json",
        "check:watch": "svelte-kit sync && svelte-check --tsconfig ./tsconfig.json --watch",
        "lint": "eslint .",
        "format": "prettier --write ."
    },
    "devDependencies": {
        "@sveltejs/adapter-static": "latest",
        "@sveltejs/kit": "^2",
        "@sveltejs/vite-plugin-svelte": "latest",
        "svelte": "^5",
        "svelte-check": "latest",
        "typescript": "^5.7",
        "vite": "^6",
        "eslint": "latest",
        "eslint-plugin-svelte": "latest",
        "prettier": "latest",
        "prettier-plugin-svelte": "latest",
        "tailwindcss": "^4",
        "@tailwindcss/vite": "latest"
    }
}
```

**`adapter-static`** = SPA mode, no SSR. The right choice for apps behind login (like SmartHabit), but with implications: no SEO for public pages (landing, privacy), no server-side auth check (everything client-side), no progressive enhancement with JS disabled. Anyone forking the template who needs public pages with SEO must switch to `adapter-node` or `adapter-auto`.

#### `tsconfig.json`

```json
{
    "compilerOptions": {
        "strict": true,
        "noUncheckedIndexedAccess": true,
        "noImplicitOverride": true,
        "exactOptionalPropertyTypes": true,
        "noFallthroughCasesInSwitch": true
    }
}
```

#### `vite.config.ts` — API Proxy (no CORS needed)

Frontend and backend run on **the same origin** — no CORS, no `nelmio/cors-bundle`:

- **Prod**: Caddy serves static frontend + PHP API under one domain
- **Dev**: Vite proxy forwards `/api/*` to the FrankenPHP container

```typescript
import { sveltekit } from '@sveltejs/kit/vite';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [tailwindcss(), sveltekit()],
    server: {
        proxy: {
            '/api': {
                target: 'https://localhost',  // FrankenPHP container
                changeOrigin: true,
                secure: false,                // Self-signed cert in dev
            },
            '/.well-known/mercure': {
                target: 'https://localhost',  // Mercure Hub (in Caddy)
                changeOrigin: true,
                secure: false,
                ws: true,                     // WebSocket for SSE fallback
            },
        },
    },
});
```

The browser sees only `localhost:5173` (Vite), all API calls go transparently to Symfony. In prod, Caddy serves everything under one domain — the proxy does not exist there.

#### `src/lib/api/client.ts`

Generic fetch wrapper:
- Base URL: `/api/v1` (relative — works in dev via proxy and in prod under the same domain)
- Automatically adds access token from auth store as `Authorization: Bearer` header
- 401 → refresh token flow → retry
- Structured error response type
- Generic `get<T>()`, `post<T>()`, `put<T>()`, `delete<T>()` methods

#### `src/routes/+page.svelte`

Minimal landing page that demonstrates SvelteKit + Tailwind + dark mode are working. Contains:
- Dark mode toggle (system + manual via CSS Custom Properties)
- Link to the health endpoint (`/api/v1/health` — goes through the Vite proxy)
- Template branding that gets replaced when forking
