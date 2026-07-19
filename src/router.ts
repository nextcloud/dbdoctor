/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { RouteRecordRaw } from 'vue-router'

import { createRouter, createWebHashHistory } from 'vue-router'
import Dashboard from './views/Dashboard.vue'

const routes: RouteRecordRaw[] = [
	{ path: '/', name: 'dashboard', component: Dashboard },
]

// Hash history keeps the SPA from clashing with the surrounding
// admin-settings page's own routing.
export default createRouter({
	history: createWebHashHistory(),
	routes,
})
