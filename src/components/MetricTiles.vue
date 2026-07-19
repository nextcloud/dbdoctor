<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="metric-tiles">
		<!-- Cache hit ratio -->
		<div class="metric-tiles__tile">
			<span class="metric-tiles__label">{{ t('dbdoctor', 'Cache hit ratio') }}</span>
			<span class="metric-tiles__value" :class="cacheClass">
				{{ metrics.cacheHitRatio !== null ? formatPercent(metrics.cacheHitRatio * 100) : '—' }}
			</span>
			<div class="metric-tiles__bar">
				<div
					class="metric-tiles__bar-fill"
					:class="cacheClass"
					:style="{ width: `${(metrics.cacheHitRatio ?? 0) * 100}%` }" />
			</div>
		</div>

		<!-- Connection saturation -->
		<div class="metric-tiles__tile">
			<span class="metric-tiles__label">{{ t('dbdoctor', 'Connections') }}</span>
			<span class="metric-tiles__value" :class="connClass">
				{{ metrics.latest ? `${metrics.latest.connections.used} / ${metrics.latest.connections.max}` : '—' }}
			</span>
			<div class="metric-tiles__bar">
				<div
					class="metric-tiles__bar-fill"
					:class="connClass"
					:style="{ width: `${(metrics.connectionRatio ?? 0) * 100}%` }" />
			</div>
		</div>

		<!-- Throughput -->
		<div class="metric-tiles__tile metric-tiles__tile--wide">
			<div class="metric-tiles__throughput-head">
				<span class="metric-tiles__label">{{ metrics.throughputLabel }}</span>
				<span class="metric-tiles__value metric-tiles__value--small">
					{{ metrics.currentQps !== null ? metrics.currentQps.toLocaleString() : '—' }}
				</span>
			</div>
			<svg
				class="metric-tiles__spark"
				:viewBox="`0 0 ${SPARK_W} ${SPARK_H}`"
				preserveAspectRatio="none"
				role="img"
				:aria-label="t('dbdoctor', 'Recent throughput')">
				<path v-if="areaPath" class="metric-tiles__spark-area" :d="areaPath" />
				<path v-if="linePath" class="metric-tiles__spark-line" :d="linePath" />
			</svg>
		</div>
	</div>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { useMetricsStore } from '../stores/metrics'
import { formatPercent } from '../utils/formatters'

const metrics = useMetricsStore()

onMounted(() => metrics.subscribe())
onBeforeUnmount(() => metrics.unsubscribe())

// Cache hit: green when healthy, warning mid, alert when poor.
const cacheClass = computed(() => {
	const r = metrics.cacheHitRatio
	if (r === null) { return 'metric-tiles__value--muted' }
	if (r >= 0.99) { return 'metric-tiles__tone--good' }
	if (r >= 0.95) { return 'metric-tiles__tone--warn' }
	return 'metric-tiles__tone--bad'
})

// Connections: alert as we approach the ceiling.
const connClass = computed(() => {
	const r = metrics.connectionRatio
	if (r === null) { return 'metric-tiles__value--muted' }
	if (r < 0.7) { return 'metric-tiles__tone--good' }
	if (r < 0.9) { return 'metric-tiles__tone--warn' }
	return 'metric-tiles__tone--bad'
})

// ── Throughput sparkline ────────────────────────────────────────────
const SPARK_W = 200
const SPARK_H = 40

const linePath = computed<string>(() => {
	const s = metrics.qps
	if (s.length < 2) { return '' }
	const max = Math.max(1, ...s)
	const stepX = SPARK_W / (s.length - 1)
	return s.map((v, i) => {
		const x = i * stepX
		const y = SPARK_H - (v / max) * (SPARK_H - 2) - 1
		return `${i === 0 ? 'M' : 'L'} ${x.toFixed(1)} ${y.toFixed(1)}`
	}).join(' ')
})

const areaPath = computed<string>(() => {
	if (linePath.value === '') { return '' }
	return `${linePath.value} L ${SPARK_W} ${SPARK_H} L 0 ${SPARK_H} Z`
})
</script>

<style scoped lang="scss">
.metric-tiles {
	display: grid;
	grid-template-columns: 1fr 1fr 1.4fr;
	gap: 12px;

	@media (max-width: 640px) {
		grid-template-columns: 1fr;
	}

	&__tile {
		display: flex;
		flex-direction: column;
		gap: 6px;
		padding: 12px 14px;
		background: var(--dbd-card-bg);
		border: 1px solid var(--dbd-card-border);
		border-radius: var(--dbd-card-radius);
		min-width: 0;
	}

	&__label {
		font-size: 11px;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: 0.05em;
		color: var(--color-text-maxcontrast);
	}

	&__value {
		font-size: 22px;
		font-weight: 700;
		font-variant-numeric: tabular-nums;
		line-height: 1;
		color: var(--color-main-text);

		&--small { font-size: 18px; }
		&--muted { color: var(--color-text-maxcontrast); }
	}

	&__tone {
		&--good { color: var(--dbd-grade-a-readable); }
		&--warn { color: var(--dbd-severity-warning-readable); }
		&--bad  { color: var(--dbd-grade-f-readable); }
	}

	&__bar {
		height: 6px;
		border-radius: 3px;
		background: var(--dbd-surface-sunken);
		overflow: hidden;
	}

	&__bar-fill {
		height: 100%;
		border-radius: 3px;
		transition: width 400ms ease;
		background: var(--color-text-maxcontrast);

		&.metric-tiles__tone--good { background: var(--dbd-grade-a); }
		&.metric-tiles__tone--warn { background: var(--dbd-severity-warning); }
		&.metric-tiles__tone--bad  { background: var(--dbd-grade-f); }
	}

	&__throughput-head {
		display: flex;
		align-items: baseline;
		justify-content: space-between;
		gap: 8px;
	}

	&__spark {
		display: block;
		width: 100%;
		height: 40px;
	}

	&__spark-line {
		fill: none;
		stroke: var(--dbd-severity-notice);
		stroke-width: 1.6;
		stroke-linejoin: round;
		stroke-linecap: round;
		vector-effect: non-scaling-stroke;
	}

	&__spark-area {
		fill: var(--dbd-severity-notice);
		fill-opacity: 0.14;
	}
}
</style>
