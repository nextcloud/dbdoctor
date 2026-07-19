/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import * as api from '../api/client'
import type { RunResult, SeriesPoint } from '../api/types'
import logger from '../utils/logger'

/**
 * Store for the dashboard's main check data.
 *
 * Holds the most-recent RunResult, a per-rule history series cache
 * (lazily filled when a card expands), and a "celebrate" flag the
 * confetti overlay listens to.  We deliberately keep this store
 * narrow — settings live in their own store.
 */
export const useChecksStore = defineStore('dbdoctor/checks', () => {
	const latest = ref<RunResult | null>(null)
	const running = ref(false)
	const error = ref<string | null>(null)
	const ruleSeries = ref<Record<string, SeriesPoint[]>>({})
	// Toggled true for one frame after a fresh A grade is achieved
	// so ConfettiOverlay can mount and immediately consume it.
	const celebrate = ref(false)

	const grade = computed(() => latest.value?.grade ?? null)

	async function fetchLatest(): Promise<void> {
		try {
			latest.value = await api.getLatest()
			error.value = null
		} catch (e) {
			error.value = (e as Error).message ?? 'Could not load latest run.'
			logger.error('fetchLatest failed', e)
		}
	}

	async function runNow(): Promise<void> {
		if (running.value) return
		running.value = true
		error.value = null
		const previousGrade = latest.value?.grade ?? null
		try {
			const next = await api.runCheck()
			latest.value = next
			// Celebrate when the user just earned an A — but not
			// every load on a server that's already at A.
			if (next.grade === 'A' && previousGrade !== 'A') {
				celebrate.value = true
			}
		} catch (e) {
			error.value = (e as Error).message ?? 'Check run failed.'
			logger.error('runNow failed', e)
		} finally {
			running.value = false
		}
	}

	function consumeCelebrate(): void {
		celebrate.value = false
	}

	async function loadSeries(ruleId: string, days: number = 30): Promise<void> {
		try {
			ruleSeries.value = {
				...ruleSeries.value,
				[ruleId]: await api.getHistory(ruleId, days),
			}
		} catch (e) {
			logger.warn(`history load failed for ${ruleId}`, e)
		}
	}

	async function applyAndRefresh(
		ruleId: string,
		variable: string,
		value: string,
	): Promise<{ success: boolean; oldValue: string | null; newValue: string | null; error?: string }> {
		const result = await api.applyChange(ruleId, variable, value)
		if (result.success) {
			// Re-run so the score reflects the change.  We don't await
			// this aggressively — the dialog has already shown the
			// outcome by the time this resolves.
			void runNow()
		}
		return result
	}

	return {
		// state
		latest,
		running,
		error,
		ruleSeries,
		celebrate,
		// derived
		grade,
		// actions
		fetchLatest,
		runNow,
		loadSeries,
		applyAndRefresh,
		consumeCelebrate,
	}
})
