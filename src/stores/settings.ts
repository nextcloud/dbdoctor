/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { AuditRow, Settings } from '../api/types'

import { defineStore } from 'pinia'
import { ref } from 'vue'
import * as api from '../api/client'
import logger from '../utils/logger'

export const useSettingsStore = defineStore('dbdoctor/settings', () => {
	const settings = ref<Settings | null>(null)
	const audit = ref<AuditRow[]>([])
	const loading = ref(false)
	const error = ref<string | null>(null)

	async function load(): Promise<void> {
		loading.value = true
		try {
			settings.value = await api.getSettings()
			audit.value = await api.getAudit(50)
			error.value = null
		} catch (e) {
			error.value = (e as Error).message ?? 'Could not load settings.'
			logger.error('settings load failed', e)
		} finally {
			loading.value = false
		}
	}

	async function update(patch: Parameters<typeof api.updateSettings>[0]): Promise<void> {
		settings.value = await api.updateSettings(patch)
	}

	async function refreshAudit(): Promise<void> {
		audit.value = await api.getAudit(50)
	}

	return { settings, audit, loading, error, load, update, refreshAudit }
})
