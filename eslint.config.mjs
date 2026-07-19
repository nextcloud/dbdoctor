import { recommended } from '@nextcloud/eslint-config'
import { defineConfig } from 'eslint/config'

export default defineConfig([
	{
		ignores: ['js/**', 'css/**', 'l10n/**', 'vendor/**', 'node_modules/**'],
	},

	...recommended,

	{
		name: 'dbdoctor/app',
		files: ['src/**/*.{ts,vue}'],
		rules: {
			// Idiomatic in this codebase and kept deliberately:
			// `void somePromise()` marks intentionally un-awaited calls
			// (polling, fire-and-forget refreshes).
			'no-void': 'off',
			// One-line guard clauses (`if (x) return`) are used throughout
			// and read more clearly than a wrapped block here.
			'@stylistic/max-statements-per-line': 'off',
			// Extensionless relative imports; Vite + TypeScript resolve them
			// and the rest of the codebase is written that way.
			'import-extensions/extensions': 'off',
			// Single-word component names (Heartbeat, Sparkline, ScoreCard…)
			// are the established convention here.
			'vue/multi-word-component-names': 'off',
			'vue/no-required-prop-with-default': 'off',
			// `<script setup>` frequently references refs/computed declared
			// lower in the file; order is not a correctness concern here.
			'@typescript-eslint/no-use-before-define': 'off',
			// We document at the module/function level where it adds value,
			// and use the `{@see …}` inline tag in prose comments.
			'jsdoc/require-jsdoc': 'off',
			'jsdoc/require-param-description': 'off',
			'jsdoc/check-tag-names': 'off',
		},
	},

	{
		// The logger utility legitimately wraps the console methods.
		name: 'dbdoctor/logger',
		files: ['src/utils/logger.ts'],
		rules: {
			'no-console': 'off',
		},
	},
])
