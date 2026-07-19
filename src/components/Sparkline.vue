<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="sparkline" :title="title">
		<svg v-if="series.length > 0"
			class="sparkline__svg"
			:viewBox="`0 0 ${days} 1`"
			preserveAspectRatio="none">
			<rect v-for="(cell, i) in cells"
				:key="i"
				class="sparkline__cell"
				:class="`sparkline__cell--${cell.status}`"
				:x="i"
				y="0"
				width="0.95"
				height="1">
				<title>{{ cell.tooltip }}</title>
			</rect>
		</svg>
		<span v-else class="sparkline__empty">{{ t('dbdoctor', 'No history yet') }}</span>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

import { translate as t } from '@nextcloud/l10n'

import type { SeriesPoint } from '../api/types'

const props = withDefaults(defineProps<{
	series: SeriesPoint[]
	days?: number
}>(), {
	days: 30,
})

interface Cell {
	status: 'ok' | 'fail' | 'skipped' | 'empty'
	tooltip: string
}

// Build one cell per day, scanning the most recent N days.  When
// multiple snapshots exist for the same day (rate-limit allows up
// to one per hour), the latest wins — matches what users would
// expect from a single dot per day.
const cells = computed<Cell[]>(() => {
	const dayMs = 86400 * 1000
	const now = Date.now()
	const buckets: Record<string, SeriesPoint> = {}
	for (const p of props.series) {
		const key = String(Math.floor(p.ts / 86400))
		const cur = buckets[key]
		if (!cur || p.ts > cur.ts) {
			buckets[key] = p
		}
	}
	const out: Cell[] = []
	for (let i = props.days - 1; i >= 0; i--) {
		const date = new Date(now - i * dayMs)
		const key = String(Math.floor(date.getTime() / 1000 / 86400))
		const p = buckets[key]
		const tooltip = date.toLocaleDateString() + ' — '
			+ (p ? p.status : t('dbdoctor', 'no data'))
		out.push({
			status: (p ? p.status : 'empty') as Cell['status'],
			tooltip,
		})
	}
	return out
})

const title = computed(() => t('dbdoctor', 'Last {n} days', { n: props.days }))
</script>

<style scoped lang="scss">
.sparkline {
	display: block;
	width: 100%;
	height: 14px;

	&__svg {
		width: 100%;
		height: 100%;
		display: block;
	}

	&__cell {
		transition: opacity 120ms ease;

		&--ok      { fill: var(--dbd-grade-a); }
		&--fail    { fill: var(--dbd-grade-f); }
		&--skipped { fill: var(--dbd-surface-sunken); }
		&--empty   { fill: var(--dbd-surface-sunken); opacity: 0.4; }

		&:hover {
			opacity: 0.7;
		}
	}

	&__empty {
		font-size: 11px;
		color: var(--color-text-maxcontrast);
	}
}
</style>
