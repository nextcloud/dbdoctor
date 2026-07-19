/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate, translatePlural } from '@nextcloud/l10n'
import { createPinia } from 'pinia'
import { createApp } from 'vue'

import App from './App.vue'
import router from './router'
import './styles/tokens.scss'

const mount = document.getElementById('dbdoctor-app')
if (mount === null) {
	// Settings page hasn't rendered the mount node yet — bail out
	// quietly.  Re-running the script on a different page (e.g. when
	// Nextcloud admin section caches scripts) wouldn't crash.
	// eslint-disable-next-line no-console
	console.warn('[dbdoctor] mount node #dbdoctor-app not found; skipping app boot.')
} else {
	const app = createApp(App)

	// Make `t` / `n` available on every component without per-component
	// imports — matches the convention in the activity / webtrack apps.
	app.config.globalProperties.t = translate
	app.config.globalProperties.n = translatePlural

	app.use(createPinia())
	app.use(router)
	app.mount(mount)
}
