/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { defineConfig } from 'vitest/config'

export default defineConfig({
	test: {
		// happy-dom is already a dev dependency; it lets component-level
		// tests run later without further config.  Pure-function tests
		// don't need it but it's a harmless default.
		environment: 'happy-dom',
		include: ['src/**/*.spec.ts'],
	},
})
