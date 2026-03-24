#!/usr/bin/env bun
/**
 * Generate frontend i18n TypeScript files from Symfony translation YAML.
 *
 * Single source of truth: backend/translations/messages.{locale}.yaml
 * The `ui:` top-level key is extracted and flattened into underscore-separated
 * keys (e.g. ui.nav.today → nav_today).
 *
 * Usage: bun run scripts/generate-i18n.ts
 * Called automatically by `bun run build` and `bun run check` via package.json.
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { parse as parseYaml } from 'yaml';

const ROOT = resolve(dirname(new URL(import.meta.url).pathname), '../..');
const LOCALES = ['en', 'de'] as const;
const OUTPUT_DIR = resolve(ROOT, 'frontend/src/lib/i18n');

/** Flatten a nested object into dot-separated keys: { a: { b: 'x' } } → { 'a_b': 'x' } */
function flatten(obj: Record<string, unknown>, prefix = ''): Record<string, string> {
    const result: Record<string, string> = {};
    for (const [key, value] of Object.entries(obj)) {
        const flatKey = prefix !== '' ? `${prefix}_${key}` : key;
        if (typeof value === 'string') {
            result[flatKey] = value;
        } else if (typeof value === 'object' && value !== null) {
            Object.assign(result, flatten(value as Record<string, unknown>, flatKey));
        }
    }
    return result;
}

for (const locale of LOCALES) {
    const yamlPath = resolve(ROOT, `backend/translations/messages.${locale}.yaml`);
    const yamlContent = readFileSync(yamlPath, 'utf-8');
    const parsed = parseYaml(yamlContent) as Record<string, unknown>;

    const uiSection = parsed['ui'];
    if (typeof uiSection !== 'object' || uiSection === null) {
        console.error(`No "ui:" section found in ${yamlPath}`);
        process.exit(1);
    }

    const flat = flatten(uiSection as Record<string, unknown>);

    // Sort keys for stable output
    const sorted = Object.keys(flat).sort();

    const lines = sorted.map((key) => {
        const escaped = flat[key]!.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
        return `    ${key}: '${escaped}',`;
    });

    const tsContent = [
        '/**',
        ` * ${locale.toUpperCase()} translations — auto-generated from backend/translations/messages.${locale}.yaml`,
        ' * Do not edit manually. Run: bun run i18n:generate',
        ' */',
        `export const ${locale}: Record<string, string> = {`,
        ...lines,
        '};\n',
    ].join('\n');

    const outPath = resolve(OUTPUT_DIR, `${locale}.ts`);
    writeFileSync(outPath, tsContent);
    console.log(`Generated ${outPath} (${sorted.length} keys)`);
}
