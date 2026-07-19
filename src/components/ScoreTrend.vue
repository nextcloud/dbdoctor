<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- Self-hiding: a trend needs at least two runs to say anything. -->
	<section v-if="series.length >= 2" class="score-trend">
		<header class="score-trend__head">
			<span class="score-trend__label">{{ t('dbdoctor', 'Score trend') }}</span>
			<span class="score-trend__meta">
				<span class="score-trend__delta" :class="deltaClass">{{ deltaText }}</span>
				<span class="score-trend__range">{{ t('dbdoctor', 'last {days} days', { days: DAYS }) }}</span>
			</span>
		</header>
		<div class="score-trend__plot">
			<svg class="score-trend__svg"
				:viewBox="`0 0 ${W} ${H}`"
				preserveAspectRatio="none"
				role="img"
				:aria-label="ariaLabel">
				<!-- Reference lines at 0 / 50 / 100 -->
				<line v-for="mark in [0, 50, 100]"
					:key="mark"
					class="score-trend__grid"
					:x1="0"
					:x2="W"
					:y1="y(mark)"
					:y2="y(mark)" />
				<path class="score-trend__area" :d="areaPath" />
				<path class="score-trend__line" :d="linePath" />
			</svg>
			<span class="score-trend__axis score-trend__axis--top">100</span>
			<span class="score-trend__axis score-trend__axis--mid">50</span>
			<span class="score-trend__axis score-trend__axis--bottom">0</span>
			<!-- Marker + value for the latest point (always at the right edge).
				 Positioned as an overlay so it isn't distorted by the SVG's
				 non-uniform vertical stretch. -->
			<span class="score-trend__now" :style="{ top: `${dotTopPercent}%` }">
				<span class="score-trend__now-dot" />
				<span class="score-trend__now-value">{{ lastScore }}</span>
			</span>
		</div>
		<div class="score-trend__x">
			<span>{{ startLabel }}</span>
			<span class="score-trend__x-count">{{ n('dbdoctor', '%n check', '%n checks', series.length) }}</span>
			<span>{{ endLabel }}</span>
		</div>
	</section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

import * as api from '../api/client'
import type { ScorePoint } from '../api/types'
import { useChecksStore } from '../stores/checks'
import logger from '../utils/logger'

const DAYS = 30
const W = 600
const H = 80
const PAD = 4

const checks = useChecksStore()
const series = ref<ScorePoint[]>([])

async function load(): Promise<void> {
	try {
		series.value = await api.getScoreHistory(DAYS)
	} catch (e) {
		logger.debug('score history load failed', e)
	}
}

onMounted(load)
// A finished run adds (or updates) a history row — refresh the trend.
watch(() => checks.latest?.ranAt, () => { void load() })

function y(score: number): number {
	return H - PAD - (score / 100) * (H - 2 * PAD)
}

// Time-proportional x positions, so an irregular check cadence doesn't
// masquerade as an even one.  Degenerate span (all points in the same
// second) falls back to index spacing.
const xs = computed<number[]>(() => {
	const s = series.value
	const span = s[s.length - 1].ts - s[0].ts
	if (span <= 0) {
		return s.map((_, i) => (i / (s.length - 1)) * W)
	}
	return s.map((p) => ((p.ts - s[0].ts) / span) * W)
})

const linePath = computed<string>(() => series.value
	.map((p, i) => `${i === 0 ? 'M' : 'L'} ${xs.value[i].toFixed(1)} ${y(p.score).toFixed(1)}`)
	.join(' '))

const areaPath = computed<string>(() =>
	`${linePath.value} L ${W} ${H - PAD} L 0 ${H - PAD} Z`)

const delta = computed<number>(() => {
	const s = series.value
	return s[s.length - 1].score - s[0].score
})

const deltaText = computed<string>(() => {
	const d = delta.value
	if (d === 0) return t('dbdoctor', 'stable')
	return d > 0 ? `▲ +${d}` : `▼ ${d}`
})

const deltaClass = computed(() => ({
	'score-trend__delta--up': delta.value > 0,
	'score-trend__delta--down': delta.value < 0,
}))

const ariaLabel = computed<string>(() => t(
	'dbdoctor',
	'Health score over the last {days} days, from {from} to {to}',
	{ days: DAYS, from: series.value[0]?.score ?? 0, to: series.value[series.value.length - 1]?.score ?? 0 },
))

// Latest score + its vertical position, expressed as a percentage of
// the plot height so the overlay marker tracks the SVG regardless of
// how tall the (stretched) card renders.
const lastScore = computed<number>(() => series.value[series.value.length - 1]?.score ?? 0)
const dotTopPercent = computed<number>(() => (y(lastScore.value) / H) * 100)

// When every run in the window lands on the same calendar day, plain
// date labels read as a broken "Jul 19 … Jul 19".  Fall back to clock
// times in that case (keeping the day on the first label for context).
const sameDay = computed<boolean>(() => {
	const a = new Date(series.value[0].ts * 1000)
	const b = new Date(series.value[series.value.length - 1].ts * 1000)
	return a.toDateString() === b.toDateString()
})

function fmtDate(ts: number): string {
	return new Date(ts * 1000).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}
function fmtTime(ts: number): string {
	return new Date(ts * 1000).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
}

const startLabel = computed<string>(() => {
	const ts = series.value[0].ts
	return sameDay.value ? `${fmtDate(ts)} ${fmtTime(ts)}` : fmtDate(ts)
})
const endLabel = computed<string>(() => {
	const ts = series.value[series.value.length - 1].ts
	return sameDay.value ? fmtTime(ts) : fmtDate(ts)
})
</script>

<style scoped lang="scss">
.score-trend {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px 14px;
	background: var(--dbd-card-bg);
	border: 1px solid var(--dbd-card-border);
	border-radius: var(--dbd-card-radius);

	&__head {
		display: flex;
		align-items: baseline;
		justify-content: space-between;
		gap: 8px;
	}

	&__label {
		font-size: 11px;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: 0.05em;
		color: var(--color-text-maxcontrast);
	}

	&__meta {
		display: inline-flex;
		align-items: baseline;
		gap: 10px;
	}

	&__delta {
		font-size: 13px;
		font-weight: 700;
		font-variant-numeric: tabular-nums;
		color: var(--color-text-maxcontrast);

		&--up { color: var(--dbd-grade-a-readable); }
		&--down { color: var(--dbd-grade-f-readable); }
	}

	&__range {
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}

	// Grow to consume the card's full height.  When this card sits in a
	// flex row next to the taller latency chart, equal-height stretch
	// would otherwise leave dead space below the fixed-height plot; a
	// growing plot fills it and gives the trend line more vertical room.
	&__plot {
		position: relative;
		flex: 1 1 auto;
		min-height: 104px;
	}

	&__svg {
		display: block;
		width: 100%;
		height: 100%;
	}

	&__grid {
		stroke: var(--dbd-card-border);
		stroke-width: 1;
		stroke-dasharray: 3 4;
		vector-effect: non-scaling-stroke;
	}

	&__line {
		fill: none;
		stroke: var(--color-primary-element);
		stroke-width: 2;
		stroke-linejoin: round;
		stroke-linecap: round;
		vector-effect: non-scaling-stroke;
	}

	&__area {
		fill: var(--color-primary-element);
		fill-opacity: 0.1;
	}

	// Y-axis scale markers on the left, clear of the end-value marker.
	&__axis {
		position: absolute;
		inset-inline-start: 2px;
		font-size: 10px;
		font-variant-numeric: tabular-nums;
		color: var(--color-text-maxcontrast);
		background: color-mix(in srgb, var(--dbd-card-bg) 78%, transparent);
		padding-inline: 2px;
		border-radius: 2px;
		pointer-events: none;

		&--top { inset-block-start: 0; }
		&--mid { inset-block-start: 50%; transform: translateY(-50%); }
		&--bottom { inset-block-end: 0; }
	}

	// Marker + value for the latest score, riding the right edge at the
	// height of the last data point.
	&__now {
		position: absolute;
		inset-inline-end: 0;
		display: inline-flex;
		flex-direction: row-reverse;
		align-items: center;
		gap: 5px;
		transform: translateY(-50%);
		pointer-events: none;
	}

	&__now-dot {
		width: 9px;
		height: 9px;
		border-radius: 50%;
		background: var(--color-primary-element);
		border: 2px solid var(--dbd-card-bg);
		box-shadow: 0 0 0 1px var(--color-primary-element);
	}

	&__now-value {
		font-size: 13px;
		font-weight: 700;
		font-variant-numeric: tabular-nums;
		color: var(--color-primary-element);
		background: color-mix(in srgb, var(--dbd-card-bg) 78%, transparent);
		padding-inline: 3px;
		border-radius: 3px;
	}

	&__x {
		display: flex;
		align-items: baseline;
		justify-content: space-between;
		gap: 8px;
		font-size: 11px;
		color: var(--color-text-maxcontrast);
	}

	&__x-count {
		font-weight: 600;
		color: var(--color-text-maxcontrast);
	}
}
</style>
