/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { pingDatabase } from '../api/client'
import logger from '../utils/logger'

/**
 * Live database-latency telemetry, shared between the LatencyChart and
 * the score-card Heartbeat.
 *
 * Two consumers needed a single source: putting the polling here keeps
 * them in lockstep (the heartbeat's BPM matches the chart's last point
 * to the millisecond) and lets us refcount subscribers — polling only
 * runs when at least one consumer is mounted, and pauses when the tab
 * is hidden.
 *
 * Smoothing: we expose both the raw last reading (`currentMs`) for the
 * chart's "now" readout and an exponentially-weighted moving average
 * (`smoothedMs`) for the heartbeat — a single 200ms spike shouldn't
 * make the mascot's heart race for 30 seconds.
 */
export const useLatencyStore = defineStore('dbdoctor/latency', () => {
	const MAX_SAMPLES = 60
	const POLL_INTERVAL_MS = 1000
	// EMA weight on the newest sample.  0.25 trades roughly 4 samples
	// of inertia for a still-responsive smoothed trace.
	const EMA_ALPHA = 0.25

	// NaN entries denote a failed / timed-out ping; consumers render
	// them as a gap rather than smoothing them away.
	const samples = ref<number[]>([])
	const ema = ref<number | null>(null)

	const subscribers = ref(0)
	let timer: number | null = null
	let inflight = false
	let visibilityBound = false

	const currentMs = computed<number | null>(() => {
		const v = samples.value[samples.value.length - 1]
		return Number.isFinite(v) ? v : null
	})
	const smoothedMs = computed<number | null>(() => ema.value)

	async function tick(): Promise<void> {
		// Don't pile up requests if the previous one is still in flight
		// — better to drop a sample than queue them.
		if (inflight) { return }
		inflight = true
		try {
			const r = await pingDatabase()
			const v = r.ok ? r.elapsedMs : Number.NaN
			samples.value.push(v)
			if (Number.isFinite(v)) {
				ema.value = ema.value === null
					? v
					: EMA_ALPHA * v + (1 - EMA_ALPHA) * ema.value
			}
		} catch (e) {
			samples.value.push(Number.NaN)
			logger.debug('latency ping failed', e)
		} finally {
			while (samples.value.length > MAX_SAMPLES) { samples.value.shift() }
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
		if (document.hidden) {
			stop()
		} else {
			start()
		}
	}

	function bindVisibility(): void {
		if (visibilityBound) { return }
		document.addEventListener('visibilitychange', onVisibility)
		visibilityBound = true
	}

	function unbindVisibility(): void {
		if (!visibilityBound) { return }
		document.removeEventListener('visibilitychange', onVisibility)
		visibilityBound = false
	}

	/**
	 * Refcounted lifecycle.  The first subscriber starts polling; the
	 * last unsubscribe stops it.  Components should call subscribe()
	 * in onMounted and unsubscribe() in onBeforeUnmount.
	 */
	function subscribe(): void {
		subscribers.value++
		if (subscribers.value === 1) {
			bindVisibility()
			if (!document.hidden) { start() }
		}
	}

	function unsubscribe(): void {
		subscribers.value = Math.max(0, subscribers.value - 1)
		if (subscribers.value === 0) {
			stop()
			unbindVisibility()
			// Reset the rolling state so a future mount starts cleanly.
			samples.value = []
			ema.value = null
		}
	}

	return {
		// state
		samples,
		// derived
		currentMs,
		smoothedMs,
		// actions
		subscribe,
		unsubscribe,
	}
})
