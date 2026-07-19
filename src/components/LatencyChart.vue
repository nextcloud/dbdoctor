<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="latency-chart" :class="{ 'latency-chart--paused': paused }">
		<header class="latency-chart__header">
			<div class="latency-chart__title-block">
				<h3 class="latency-chart__title">
					{{ t('dbdoctor', 'Live database latency') }}
				</h3>
				<p class="latency-chart__subtitle">
					{{ t('dbdoctor', 'SHOW TABLES every second — last 60 s') }}
				</p>
			</div>
			<div class="latency-chart__readout">
				<span class="latency-chart__current" :class="bandClass">
					{{ currentLabel }}
				</span>
				<dl class="latency-chart__stats">
					<div class="latency-chart__stat">
						<dt>{{ t('dbdoctor', 'avg') }}</dt>
						<dd>{{ formatMs(avg) }}</dd>
					</div>
					<div class="latency-chart__stat">
						<dt>{{ t('dbdoctor', 'min') }}</dt>
						<dd>{{ formatMs(minVal) }}</dd>
					</div>
					<div class="latency-chart__stat">
						<dt>{{ t('dbdoctor', 'max') }}</dt>
						<dd>{{ formatMs(maxVal) }}</dd>
					</div>
				</dl>
			</div>
		</header>

		<!-- The svg stretches to the container width via
			 preserveAspectRatio="none", which would distort any text
			 inside it — so the axis labels and the empty-state hint are
			 HTML overlays positioned over the plot instead. -->
		<div class="latency-chart__plot">
			<svg
				class="latency-chart__svg"
				:viewBox="`0 0 ${VIEW_W} ${VIEW_H}`"
				preserveAspectRatio="none"
				role="img"
				:aria-label="t('dbdoctor', 'Database latency over the last 60 seconds')">
				<defs>
					<linearGradient
						id="dbd-latency-fill"
						x1="0"
						y1="0"
						x2="0"
						y2="1">
						<stop offset="0" class="latency-chart__fill-top" />
						<stop offset="1" class="latency-chart__fill-bottom" />
					</linearGradient>
				</defs>

				<!-- Horizontal grid lines (3 quartiles of the current
					 y-domain).  Re-rendered when the y-scale shifts so
					 the axis never lies about the data. -->
				<g class="latency-chart__grid">
					<line
						v-for="g in gridLines"
						:key="g.value"
						:x1="0"
						:x2="VIEW_W"
						:y1="g.y"
						:y2="g.y" />
				</g>

				<!-- Filled area under the curve.  Goes to the floor at the
					 last data X so the area "scrolls" with the line. -->
				<path
					v-if="areaPath"
					class="latency-chart__area"
					:d="areaPath" />

				<!-- The line itself.  CSS animates the d attribute, giving
					 the appearance of smooth scroll-by-one each tick. -->
				<path
					v-if="linePath"
					class="latency-chart__line"
					:d="linePath" />

				<!-- Static dot marking the newest sample. -->
				<circle
					v-if="lastPoint"
					class="latency-chart__dot"
					:cx="lastPoint.x"
					:cy="lastPoint.y"
					r="3" />
			</svg>

			<span
				v-for="g in gridLines"
				:key="g.value"
				class="latency-chart__grid-label"
				:style="{ top: `${(g.y / VIEW_H) * 100}%` }">
				{{ formatMs(g.value) }}
			</span>

			<span v-if="samples.length === 0" class="latency-chart__empty">
				{{ t('dbdoctor', 'Sampling…') }}
			</span>
		</div>
	</section>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useLatencyStore } from '../stores/latency'

// ── Sampling source ────────────────────────────────────────────────

// Polling lives in the Pinia store so the score-card heartbeat can
// share the exact same trace.  We just subscribe / unsubscribe to
// drive its refcount.
const latency = useLatencyStore()
const samples = computed<number[]>(() => latency.samples)
const paused = ref(false)
const MAX_SAMPLES = 60

function onVisibility(): void {
	paused.value = document.hidden
}

onMounted(() => {
	latency.subscribe()
	document.addEventListener('visibilitychange', onVisibility)
})

onBeforeUnmount(() => {
	latency.unsubscribe()
	document.removeEventListener('visibilitychange', onVisibility)
})

// ── Chart geometry ─────────────────────────────────────────────────

// The viewBox is fixed; CSS scales the SVG to whatever container
// width is available.  preserveAspectRatio="none" lets the chart
// stretch horizontally without distorting line stroke width
// (vector-effect: non-scaling-stroke is applied in CSS for that).
const VIEW_W = 600
const VIEW_H = 140
const PAD_TOP = 8
const PAD_BOTTOM = 18

// Y-domain: floor at 0; ceiling at max(samples) * 1.25, clamped to a
// sensible minimum so a flatline at 0.5 ms doesn't make every wiggle
// look like a spike.  Re-derived on every render.
const yMax = computed<number>(() => {
	const finite = samples.value.filter((v) => Number.isFinite(v))
	if (finite.length === 0) { return 50 }
	const top = Math.max(...finite) * 1.25
	return Math.max(20, top)
})

// Map (index, value) → (x, y) in viewBox coords.  Index 0 is the
// oldest sample, plotted at the left edge; the line grows to the
// right as samples arrive and reaches the right edge once the buffer
// holds MAX_SAMPLES of them.
function pointFor(i: number, n: number, value: number): { x: number, y: number } {
	const x = VIEW_W * (i / (MAX_SAMPLES - 1))
	const range = VIEW_H - PAD_TOP - PAD_BOTTOM
	const y = PAD_TOP + range - (value / yMax.value) * range
	return { x, y: Math.max(PAD_TOP, Math.min(VIEW_H - PAD_BOTTOM, y)) }
}

// Build the line path.  Failed samples (NaN) break the path into
// segments, so a connection blip shows up as a literal gap rather
// than being smoothed away.
const linePath = computed<string>(() => {
	const n = samples.value.length
	if (n === 0) { return '' }
	let d = ''
	let pendingMove = true
	for (let i = 0; i < n; i++) {
		const v = samples.value[i]
		if (!Number.isFinite(v)) {
			pendingMove = true
			continue
		}
		const { x, y } = pointFor(i, n, v)
		d += (pendingMove ? `M ${x.toFixed(1)} ${y.toFixed(1)}` : ` L ${x.toFixed(1)} ${y.toFixed(1)}`)
		pendingMove = false
	}
	return d
})

const areaPath = computed<string>(() => {
	const n = samples.value.length
	if (n === 0) { return '' }
	const floor = VIEW_H - PAD_BOTTOM
	const segments: { x: number, y: number }[][] = []
	let current: { x: number, y: number }[] = []
	for (let i = 0; i < n; i++) {
		const v = samples.value[i]
		if (!Number.isFinite(v)) {
			if (current.length) { segments.push(current) }
			current = []
			continue
		}
		current.push(pointFor(i, n, v))
	}
	if (current.length) { segments.push(current) }
	if (segments.length === 0) { return '' }
	return segments.map((seg) => {
		if (seg.length === 0) { return '' }
		const first = seg[0]
		const last = seg[seg.length - 1]
		const head = `M ${first.x.toFixed(1)} ${floor.toFixed(1)} L ${first.x.toFixed(1)} ${first.y.toFixed(1)}`
		const mid = seg.slice(1).map((p) => `L ${p.x.toFixed(1)} ${p.y.toFixed(1)}`).join(' ')
		const tail = `L ${last.x.toFixed(1)} ${floor.toFixed(1)} Z`
		return `${head} ${mid} ${tail}`
	}).join(' ')
})

const lastPoint = computed<{ x: number, y: number } | null>(() => {
	const n = samples.value.length
	if (n === 0) { return null }
	const v = samples.value[n - 1]
	if (!Number.isFinite(v)) { return null }
	return pointFor(n - 1, n, v)
})

// Three quartile gridlines (25 / 50 / 75 % of the y-domain).  Plus
// labels rendered against the right edge so they don't fight the
// curve in the centre.
interface GridLine { y: number, value: number }
const gridLines = computed<GridLine[]>(() => {
	const range = VIEW_H - PAD_TOP - PAD_BOTTOM
	const max = yMax.value
	return [0.25, 0.5, 0.75].map((frac) => ({
		value: max * (1 - frac),
		y: PAD_TOP + range * frac,
	}))
})

// ── Readout ────────────────────────────────────────────────────────

const finiteSamples = computed<number[]>(() => samples.value.filter((v) => Number.isFinite(v)))
const current = computed<number | null>(() => {
	const n = samples.value.length
	if (n === 0) { return null }
	const v = samples.value[n - 1]
	return Number.isFinite(v) ? v : null
})
const avg = computed<number | null>(() => {
	const f = finiteSamples.value
	if (f.length === 0) { return null }
	return f.reduce((s, v) => s + v, 0) / f.length
})
const minVal = computed<number | null>(() => {
	const f = finiteSamples.value
	return f.length === 0 ? null : Math.min(...f)
})
const maxVal = computed<number | null>(() => {
	const f = finiteSamples.value
	return f.length === 0 ? null : Math.max(...f)
})

function formatMs(v: number | null): string {
	if (v === null || !Number.isFinite(v)) { return '—' }
	if (v < 1) { return v.toFixed(2) + ' ms' }
	if (v < 10) { return v.toFixed(1) + ' ms' }
	return Math.round(v) + ' ms'
}

const currentLabel = computed<string>(() => {
	if (current.value === null) {
		// Still sampling, or last sample failed.
		return samples.value.length === 0 ? '—' : t('dbdoctor', 'no response')
	}
	return formatMs(current.value)
})

// Colour the current readout (and the line, indirectly via CSS scope)
// by latency band: < 5 ms = healthy, < 25 ms = ok, < 100 ms = slow,
// rest = alarming.  Numbers chosen for an interactive web stack on a
// local network; remote DBs will frequently sit in the "ok" band.
const bandClass = computed<string>(() => {
	const v = current.value
	if (v === null) { return 'latency-chart__current--unknown' }
	if (v < 5) { return 'latency-chart__current--good' }
	if (v < 25) { return 'latency-chart__current--ok' }
	if (v < 100) { return 'latency-chart__current--slow' }
	return 'latency-chart__current--bad'
})
</script>

<style scoped lang="scss">
.latency-chart {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 12px 16px;
	background: var(--dbd-card-bg);
	border: 1px solid var(--dbd-card-border);
	border-radius: var(--dbd-card-radius);

	&--paused {
		opacity: 0.55;
	}

	&__header {
		display: flex;
		flex-wrap: wrap;
		align-items: flex-end;
		justify-content: space-between;
		gap: 8px 16px;
	}

	&__title-block {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}

	&__title {
		margin: 0;
		font-size: 15px;
		font-weight: 600;
	}

	&__subtitle {
		margin: 0;
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}

	// A two-row grid keeps every stat label exactly above its value
	// and bottom-aligns the whole block with the big current readout —
	// flex columns can't guarantee that cross-alignment.  The dl / div
	// wrappers stay for semantics but are flattened via
	// display: contents so dt / dd participate in the grid directly.
	&__readout {
		display: grid;
		grid-auto-flow: column;
		grid-template-rows: auto auto;
		align-items: end;
		column-gap: 16px;
		row-gap: 1px;
	}

	&__current {
		grid-row: 1 / span 2;
		margin-inline-end: 4px;
		font-variant-numeric: tabular-nums;
		font-size: 22px;
		font-weight: 700;
		line-height: 1;
		transition: color 220ms ease;

		&--good { color: var(--dbd-grade-a-readable); }
		&--ok   { color: var(--dbd-severity-notice-readable); }
		&--slow { color: var(--dbd-severity-warning-readable); }
		&--bad  { color: var(--dbd-grade-f-readable); }
		&--unknown { color: var(--color-text-maxcontrast); }
	}

	&__stats {
		display: contents;
	}

	&__stat {
		display: contents;

		dt {
			grid-row: 1;
			justify-self: end;
			margin: 0;
			font-size: 10px;
			line-height: 1.2;
			text-transform: uppercase;
			letter-spacing: 0.06em;
			color: var(--color-text-maxcontrast);
		}

		dd {
			grid-row: 2;
			justify-self: end;
			margin: 0;
			font-variant-numeric: tabular-nums;
			font-size: 13px;
			font-weight: 600;
			line-height: 1.2;
			color: var(--color-main-text);
		}
	}

	&__plot {
		position: relative;
	}

	&__svg {
		display: block;
		width: 100%;
		height: 96px;
	}

	&__grid-label {
		position: absolute;
		inset-inline-end: 0;
		transform: translateY(calc(-100% - 2px));
		font-size: 10px;
		line-height: 1.2;
		font-variant-numeric: tabular-nums;
		color: var(--color-text-maxcontrast);
		// Data scrolls in underneath — a faint pill of card colour keeps
		// the label readable when the line passes behind it.
		background: color-mix(in srgb, var(--dbd-card-bg) 75%, transparent);
		border-radius: 4px;
		padding-inline: 4px;
		pointer-events: none;
	}

	&__grid line {
		stroke: var(--color-border);
		stroke-width: 1;
		stroke-dasharray: 2 4;
		vector-effect: non-scaling-stroke;
		opacity: 0.7;
	}

	&__fill-top {
		stop-color: var(--dbd-severity-notice);
		stop-opacity: 0.26;
	}

	&__fill-bottom {
		stop-color: var(--dbd-severity-notice);
		stop-opacity: 0.02;
	}

	&__area {
		fill: url(#dbd-latency-fill);
		// Animating `d` is supported on path elements in modern Safari /
		// Chrome / Firefox — degrades gracefully to a hard cut on older
		// engines, which is fine since the cut happens at 1 Hz.
		transition: d 950ms linear;
	}

	&__line {
		fill: none;
		stroke: var(--dbd-severity-notice);
		stroke-width: 2;
		stroke-linecap: round;
		stroke-linejoin: round;
		vector-effect: non-scaling-stroke;
		transition: d 950ms linear;
	}

	&__dot {
		fill: var(--dbd-severity-notice);
		stroke: var(--color-main-background);
		stroke-width: 2;
		vector-effect: non-scaling-stroke;
	}

	&__empty {
		position: absolute;
		inset: 0;
		display: flex;
		align-items: center;
		justify-content: center;
		color: var(--color-text-maxcontrast);
		font-size: 14px;
		pointer-events: none;
	}
}

@media (prefers-reduced-motion: reduce) {
	.latency-chart__area,
	.latency-chart__line {
		transition: none;
	}
}
</style>
