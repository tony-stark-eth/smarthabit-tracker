import js from '@eslint/js';
import svelte from 'eslint-plugin-svelte';
import globals from 'globals';
import ts from 'typescript-eslint';

/** @type {import('eslint').Linter.Config[]} */
export default [
    js.configs.recommended,
    ...ts.configs.recommended,
    ...svelte.configs['flat/recommended'],

    {
        languageOptions: {
            globals: {
                ...globals.browser,
                ...globals.node,
            },
        },
    },

    {
        files: ['**/*.svelte', '**/*.svelte.ts', '**/*.svelte.js'],
        languageOptions: {
            parserOptions: {
                // Required for TypeScript inside <script lang="ts"> in Svelte files
                // and in .svelte.ts rune store files.
                parser: ts.parser,
            },
        },
    },

    {
        // Rules that apply to all files.
        rules: {
            // Enforce explicit return types on functions for clarity.
            '@typescript-eslint/explicit-function-return-type': ['warn', {
                allowExpressions: true,
                allowTypedFunctionExpressions: true,
            }],

            // Disallow use of `any` — keep code fully typed.
            '@typescript-eslint/no-explicit-any': 'error',

            // Disallow unused variables except those prefixed with _.
            '@typescript-eslint/no-unused-vars': ['error', {
                argsIgnorePattern: '^_',
                varsIgnorePattern: '^_',
            }],

            // Prefer const over let where possible.
            'prefer-const': 'error',
        },
    },

    {
        // The app layout iterates resolved hrefs stored in an object; the rule cannot
        // statically trace them through MemberExpression access, so suppress link
        // checks here. The hrefs are correctly produced by resolve() in the script.
        files: ['src/routes/(app)/+layout.svelte'],
        rules: {
            'svelte/no-navigation-without-resolve': ['error', { ignoreLinks: true }],
        },
    },

    {
        // Ignored paths — build output, generated files, and dependencies.
        ignores: [
            '.svelte-kit/',
            'build/',
            'node_modules/',
        ],
    },
];
