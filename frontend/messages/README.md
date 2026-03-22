# i18n Messages

Message files for paraglide-sveltekit (de/en).

## Setup (run once after cloning)

```bash
cd frontend
bun add @inlang/paraglide-sveltekit
bunx @inlang/paraglide-sveltekit init
```

Then import messages in Svelte components:
```svelte
<script>
  import * as m from '$lib/paraglide/messages';
</script>

<h1>{m.app_name()}</h1>
```
