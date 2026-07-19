/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
module.exports = {
	root: true,
	extends: ['@nextcloud/eslint-config/typescript'],
	rules: {
		// This codebase deliberately uses `void somePromise()` to mark
		// intentionally un-awaited calls (polling, fire-and-forget
		// refreshes); that's a readability choice, not a bug.
		'no-void': 'off',
		// Not every internal helper needs a JSDoc block; we document at
		// the module/function level where it adds value.
		'jsdoc/require-jsdoc': 'off',
		'jsdoc/require-param-description': 'off',
		// camelCase emit names are valid Vue and match the `defineEmits`
		// type declarations used across these components.
		'vue/custom-event-name-casing': 'off',
	},
}
