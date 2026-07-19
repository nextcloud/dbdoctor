<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="heartbeat" :style="cssVars" aria-hidden="true">
		<svg
			class="heartbeat__svg"
			viewBox="0 0 220 60"
			preserveAspectRatio="none">
			<!-- The ECG line is a single repeating polyline.  We draw it
				 twice and translate the second copy by the width of the
				 viewBox so the animation loops without a visible seam. -->
			<g class="heartbeat__scroller">
				<polyline
					class="heartbeat__line"
					:points="ecgPoints" />
				<polyline
					class="heartbeat__line"
					:points="ecgPoints"
					transform="translate(220 0)" />
			</g>
		</svg>
	</div>
</template>

<script setup lang="ts">
import type { Grade } from '../utils/grade'

import { computed } from 'vue'
import { bpmFor, bpmForLatency, colorVarFor } from '../utils/grade'

const props = withDefaults(defineProps<{
	grade: Grade | null
	// When provided (and finite), drives the BPM live from real DB
	// latency.  When null/undefined, we fall back to the grade-based
	// BPM so the heartbeat still moves before the latency store has
	// produced its first sample.
	latencyMs?: number | null
}>(), {
	grade: null,
	latencyMs: null,
})

// One full ECG cycle — the classic P-Q-R-S-T peaks + flatline.
// Tuned to fit a 220×60 viewBox so the polyline reads cleanly at
// any width in a flex layout.  Hand-tuned (not data) — this is a
// decorative pulse, not a real cardiogram.
const ecgPoints = '0,30 30,30 40,28 50,30 70,30 78,16 84,52 90,12 96,38 105,30 130,30 140,32 150,28 165,30 200,30 220,30'

const cssVars = computed(() => {
	const grade = props.grade ?? 'C'
	// Prefer live latency when available — the heartbeat then becomes
	// a real ECG of the database, not a static grade metaphor.
	const bpm = (props.latencyMs !== null && props.latencyMs !== undefined && Number.isFinite(props.latencyMs))
		? bpmForLatency(props.latencyMs)
		: bpmFor(grade)
	// A full cycle should equal one beat — 60 seconds / bpm.
	const seconds = (60 / bpm).toFixed(2) + 's'
	return {
		'--heartbeat-stroke': colorVarFor(grade),
		'--heartbeat-duration': seconds,
	} as Record<string, string>
})
</script>

<style scoped lang="scss">
.heartbeat {
	width: 100%;
	height: 60px;
	overflow: hidden;
	position: relative;

	&__svg {
		display: block;
		width: 100%;
		height: 100%;
	}

	&__scroller {
		// The two copies of the polyline share this group, which
		// scrolls left.  When the second copy reaches the position
		// of the first, the loop resets — invisibly because the
		// shapes are identical.
		animation: heartbeat-scroll var(--heartbeat-duration) linear infinite;
	}

	&__line {
		fill: none;
		stroke: var(--heartbeat-stroke);
		stroke-width: 2;
		stroke-linecap: round;
		stroke-linejoin: round;
		// Subtle glow that takes on the grade's tint.
		filter: drop-shadow(0 0 2px var(--heartbeat-stroke));
	}
}

@keyframes heartbeat-scroll {
	from { transform: translateX(0); }
	to   { transform: translateX(-220px); }
}
</style>
