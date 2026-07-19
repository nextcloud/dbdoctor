/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { LiveMetrics } from '../api/types'

import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { getLiveMetrics } from '../api/client'
import logger from '../utils/logger'

/**
 * Live server metrics for the dashboard tiles: connection saturation,
 * cache-hit ratio, and throughput.  Throughput is derived from a
 * cumulative counter (Questions / transactions) by differencing
 * successive polls, so we keep the previous sample to compute a rate.
 *
 * Refcounted + visibility-aware like the latency store: polling only
 * runs while a consumer is mounted and the tab is visible.  A slower
 * cadence than the 1 Hz latency ping is plenty for these gauges.
 */
export const useMetricsStore = defineStore('dbdoctor/metrics', () => {
	const POLL_INTERVAL_MS = 3000
	const MAX_QPS_SAMPLES = 40

	const latest = ref<LiveMetrics | null>(null)
	// Rolling per-second throughput samples for the mini chart.
	const qps = ref<number[]>([])
	const throughputLabel = ref('Queries/s')

	const subscribers = ref(0)
	let timer: number | null = null
	let inflight = false
	let visibilityBound = false
	// Previous counter + timestamp for rate differencing.
	let prevCounter: number | null = null
	let prevAt: number | null = null

	const connectionRatio = computed<number | null>(() => {
		const m = latest.value
		if (m === null || m.connections.max <= 0) { return null }
		return Math.min(1, m.connections.used / m.connections.max)
	})
	const cacheHitRatio = computed<number | null>(() => latest.value?.cacheHitRatio ?? null)
	const currentQps = computed<number | null>(() => (qps.value.length ? qps.value[qps.value.length - 1] : null))

	async function tick(): Promise<void> {
		if (inflight) { return }
		inflight = true
		try {
			const m = await getLiveMetrics()
			latest.value = m
			throughputLabel.value = m.throughput.label

			// Convert the cumulative counter into a per-second rate.
			const now = Date.now()
			if (prevCounter !== null && prevAt !== null) {
				const dt = (now - prevAt) / 1000
				const dCount = m.throughput.counter - prevCounter
				// Guard against counter resets (server restart) and gaps.
				const rate = dt > 0 && dCount >= 0 ? dCount / dt : 0
				qps.value.push(Math.round(rate))
				while (qps.value.length > MAX_QPS_SAMPLES) { qps.value.shift() }
			}
			prevCounter = m.throughput.counter
			prevAt = now
		} catch (e) {
			logger.debug('metrics poll failed', e)
		} finally {
			inflight = false
		}
	}

	function start(): void {
		if (timer !== null) { return }
		void tick()
		timer = window.setInterval(() => { void tick() }, POLL_INTERVAL_MS)
	}

	function stop(): void {
		if (timer !== null) {
			window.clearInterval(timer)
			timer = null
		}
	}

	function onVisibility(): void {
		if (subscribers.value === 0) { return }
		if (document.hidden) { stop() } else { start() }
	}

	function subscribe(): void {
		subscribers.value++
		if (subscribers.value === 1) {
			if (!visibilityBound) {
				document.addEventListener('visibilitychange', onVisibility)
				visibilityBound = true
			}
			if (!document.hidden) { start() }
		}
	}

	function unsubscribe(): void {
		subscribers.value = Math.max(0, subscribers.value - 1)
		if (subscribers.value === 0) {
			stop()
			if (visibilityBound) {
				document.removeEventListener('visibilitychange', onVisibility)
				visibilityBound = false
			}
			// Reset rolling state so a future mount starts clean.
			latest.value = null
			qps.value = []
			prevCounter = null
			prevAt = null
		}
	}

	return {
		latest,
		qps,
		throughputLabel,
		connectionRatio,
		cacheHitRatio,
		currentQps,
		subscribe,
		unsubscribe,
	}
})
