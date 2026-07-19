<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="score-card" :class="`score-card--grade-${displayGrade.toLowerCase()}`">
		<DoctorMascot class="score-card__mascot" :state="mascotState" />

		<div class="score-card__main">
			<!-- Radial gauge: a track ring plus a progress arc that fills
				 0→100 with the score, coloured by grade.  The arc offset
				 is driven by the same animated score value as the number,
				 so ring and digits roll in together on every refresh. -->
			<div class="score-card__gauge">
				<svg class="score-card__gauge-svg" viewBox="0 0 120 120" aria-hidden="true">
					<circle class="score-card__gauge-track"
						cx="60"
						cy="60"
						:r="GAUGE_RADIUS" />
					<circle class="score-card__gauge-arc"
						cx="60"
						cy="60"
						:r="GAUGE_RADIUS"
						:style="{
							stroke: gradeColor,
							strokeDasharray: GAUGE_CIRCUMFERENCE,
							strokeDashoffset: gaugeDashOffset,
						}" />
				</svg>

				<!-- One-shot ripple behind the letter on a real change. -->
				<div v-if="rippleKey > 0"
					:key="`ripple-${rippleKey}`"
					class="score-card__ripple"
					:style="{ '--ripple-color': gradeColor } as CSSPropertiesWithVars" />

				<div class="score-card__gauge-center">
					<!-- Grade letter with morph transition. -->
					<Transition name="grade-morph" mode="out-in">
						<div :key="displayGrade"
							class="score-card__letter"
							:style="{ color: gradeColor }"
							aria-hidden="true">
							{{ displayGrade }}
						</div>
					</Transition>
					<div class="score-card__numeric">
						<span class="score-card__score-value">{{ animatedScore }}</span>
						<span class="score-card__score-out-of">/100</span>
					</div>
				</div>

				<!-- Floating signed delta ("+5" / "-3") after a change. -->
				<Transition name="score-delta">
					<span v-if="deltaLabel !== null"
						:key="`delta-${rippleKey}`"
						class="score-card__delta"
						:class="delta !== null && delta > 0 ? 'score-card__delta--up' : 'score-card__delta--down'">
						{{ deltaLabel }}
					</span>
				</Transition>
			</div>

			<div class="score-card__caption">
				<span class="score-card__caption-title">{{ t('dbdoctor', 'Database health') }}</span>
				<span class="score-card__caption-sub">{{ captionSub }}</span>
			</div>
		</div>

		<!-- Screen-reader narration of the same data the visuals show. -->
		<p class="score-card__sr-only">
			{{ srText }}
		</p>
	</section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import type { CSSProperties } from 'vue'
import { translate as t } from '@nextcloud/l10n'

import { mascotFor, readableColorVarFor, type Grade } from '../utils/grade'
import DoctorMascot from './DoctorMascot.vue'

// Allow CSS custom properties on inline `style` typings without
// fighting the strict CSSProperties shape.
type CSSPropertiesWithVars = CSSProperties & Record<`--${string}`, string>

const props = withDefaults(defineProps<{
	score: number | null
	grade: Grade | null
	running: boolean
	counts?: { alert: number; warning: number; notice: number; total: number }
}>(), {
	score: null,
	grade: null,
	running: false,
	counts: () => ({ alert: 0, warning: 0, notice: 0, total: 0 }),
})

// During the very first render before any check has happened, fall
// back to a neutral "C" colour so the card doesn't read as either
// celebration or alarm.
const displayGrade = computed<Grade>(() => props.grade ?? 'C')

// Text elements (letter, score, ripple) use the readable blend; the
// card's backdrop tint keeps the raw grade colour via the
// .score-card--grade-* classes, where low contrast is the point.
const gradeColor = computed(() => readableColorVarFor(displayGrade.value))

const mascotState = computed(() => mascotFor(props.grade, props.running))

// Gauge geometry.  The arc is a circle stroked with a dash the length
// of its full circumference; shifting the dash offset from full (empty)
// to zero (complete) reveals `score`% of the ring.  Rotation to start
// at 12 o'clock is done in CSS.
const GAUGE_RADIUS = 52
const GAUGE_CIRCUMFERENCE = 2 * Math.PI * GAUGE_RADIUS
const gaugeDashOffset = computed<number>(() => {
	const s = Math.max(0, Math.min(100, animatedScore.value))
	return GAUGE_CIRCUMFERENCE * (1 - s / 100)
})

// One-line status under the "Database health" caption.
const captionSub = computed<string>(() => {
	if (props.running) return t('dbdoctor', 'Running check…')
	if (props.score === null) return t('dbdoctor', 'No check run yet')
	const c = props.counts
	const issues = c.alert + c.warning + c.notice
	if (issues === 0) return t('dbdoctor', 'No issues found')
	return t('dbdoctor', '{n} issues to review', { n: issues })
})

// Animate the score change as a tween.  Watching `score` for
// transitions and stepping a separate ref means the digits feel
// "rolled in" rather than instant — a small touch that makes the
// card feel alive on every refresh.
const animatedScore = ref(props.score ?? 0)
let tween: number | null = null

// One-shot animation triggers.  rippleKey increments on every score
// change so we can re-key DOM nodes and restart their CSS animations.
// `delta` is the signed score change for the floater label; null
// outside the brief display window.
const rippleKey = ref(0)
const delta = ref<number | null>(null)
let deltaResetTimer: number | null = null

const deltaLabel = computed<string | null>(() => {
	if (delta.value === null || delta.value === 0) return null
	return (delta.value > 0 ? '+' : '') + String(delta.value)
})

watch(() => props.score, (next, prev) => {
	if (tween !== null) cancelAnimationFrame(tween)
	if (next === null) {
		animatedScore.value = 0
		return
	}
	const start = prev ?? 0
	const startedAt = performance.now()
	const duration = 600
	const step = (t: number) => {
		const p = Math.min(1, (t - startedAt) / duration)
		// Ease-out cubic
		const eased = 1 - Math.pow(1 - p, 3)
		animatedScore.value = Math.round(start + (next - start) * eased)
		if (p < 1) {
			tween = requestAnimationFrame(step)
		} else {
			tween = null
		}
	}
	tween = requestAnimationFrame(step)

	// Trigger a one-shot ripple + delta floater on a *real* change
	// between runs.  We skip the very first paint (prev === undefined)
	// because that's just initial load, not a change the user caused.
	if (prev !== undefined && prev !== null && next !== prev) {
		rippleKey.value++
		delta.value = next - prev
		if (deltaResetTimer !== null) window.clearTimeout(deltaResetTimer)
		// Match the CSS `score-delta-leave` duration so the floater is
		// removed from the DOM right after the leave animation ends.
		deltaResetTimer = window.setTimeout(() => {
			delta.value = null
			deltaResetTimer = null
		}, 1800)
	}
}, { immediate: true })

onBeforeUnmount(() => {
	if (tween !== null) cancelAnimationFrame(tween)
	if (deltaResetTimer !== null) window.clearTimeout(deltaResetTimer)
})

const srText = computed(() => {
	if (props.score === null) return 'No check has been run yet.'
	const g = displayGrade.value
	const c = props.counts
	return `Database health grade ${g}. ${props.score} of 100. `
		+ `${c.alert} alerts, ${c.warning} warnings, ${c.notice} notices.`
})
</script>

<style scoped lang="scss">
.score-card {
	position: relative;
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 8px 20px;
	align-items: center;
	padding: 12px 20px;
	border-radius: var(--dbd-card-radius);
	background:
		radial-gradient(circle at top right, color-mix(in srgb, currentColor 12%, transparent) 0%, transparent 70%),
		var(--dbd-card-bg);
	border: 1px solid var(--dbd-card-border);
	box-shadow: var(--dbd-card-shadow);
	overflow: hidden;
	color: var(--color-main-text);

	&__mascot {
		grid-row: 1;
		grid-column: 1;
		width: 72px;
		height: 72px;
	}

	&__main {
		grid-row: 1;
		grid-column: 2;
		position: relative;
		display: flex;
		align-items: center;
		gap: 18px;
		padding-block: 4px;
	}

	&__gauge {
		position: relative;
		width: 112px;
		height: 112px;
		flex-shrink: 0;
	}

	&__gauge-svg {
		width: 100%;
		height: 100%;
		// Start the arc at 12 o'clock and sweep clockwise.
		transform: rotate(-90deg);
	}

	&__gauge-track {
		fill: none;
		stroke: color-mix(in srgb, var(--color-main-text) 12%, transparent);
		stroke-width: 9;
	}

	&__gauge-arc {
		fill: none;
		stroke-width: 9;
		stroke-linecap: round;
		// The offset is bound inline and updates every tween frame; a
		// short transition smooths the gap between frames and the very
		// first paint.
		transition: stroke-dashoffset 120ms linear;
	}

	&__gauge-center {
		position: absolute;
		inset: 0;
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 2px;
	}

	&__letter {
		font-size: 40px;
		font-weight: 800;
		line-height: 1;
		letter-spacing: -0.02em;
		font-feature-settings: 'tnum';
		will-change: transform, opacity;
	}

	// One-shot ripple emitted from behind the grade letter when the
	// score changes between runs.  The element is mounted via Vue's
	// `v-if` keyed on rippleKey, so each re-key restarts the keyframe.
	&__ripple {
		position: absolute;
		inset: 50% auto auto 50%;
		width: 96px;
		height: 96px;
		margin: -48px 0 0 -48px;
		border-radius: 50%;
		background: radial-gradient(
			circle,
			color-mix(in srgb, var(--ripple-color, currentColor) 45%, transparent) 0%,
			transparent 68%
		);
		pointer-events: none;
		opacity: 0;
		animation: score-ripple 1100ms cubic-bezier(0.22, 0.8, 0.3, 1) forwards;
		z-index: 0;
	}

	// Floating signed delta ("+5" / "-3") that drifts upward and fades
	// after a real score change.  Anchored to the top-right of the ring.
	&__delta {
		position: absolute;
		top: 4px;
		left: 100%;
		transform-origin: left top;
		padding: 1px 8px;
		border-radius: var(--border-radius-pill);
		font-size: 12px;
		font-weight: 700;
		font-variant-numeric: tabular-nums;
		line-height: 1.4;
		pointer-events: none;
		white-space: nowrap;
		will-change: transform, opacity;

		&--up {
			background: color-mix(in srgb, var(--dbd-grade-a) 22%, transparent);
			color: var(--dbd-grade-a-readable);
		}

		&--down {
			background: color-mix(in srgb, var(--dbd-grade-f) 22%, transparent);
			color: var(--dbd-grade-f-readable);
		}
	}

	&__numeric {
		display: inline-flex;
		align-items: baseline;
		gap: 1px;
		color: var(--color-text-maxcontrast);
	}

	&__score-value {
		font-size: 17px;
		font-weight: 700;
		font-variant-numeric: tabular-nums;
		color: var(--color-main-text);
	}

	&__score-out-of {
		font-size: 12px;
	}

	&__caption {
		display: flex;
		flex-direction: column;
		gap: 2px;
		min-width: 0;
	}

	&__caption-title {
		font-size: 16px;
		font-weight: 600;
		color: var(--color-main-text);
	}

	&__caption-sub {
		font-size: 13px;
		color: var(--color-text-maxcontrast);
	}

	&__sr-only {
		position: absolute;
		width: 1px;
		height: 1px;
		padding: 0;
		margin: -1px;
		overflow: hidden;
		clip: rect(0, 0, 0, 0);
		white-space: nowrap;
		border: 0;
	}
}

// Per-grade backdrop tint.  A bit of colour without overwhelming the
// rest of the card — pulled from currentColor via the letter rule.
.score-card--grade-a { color: var(--dbd-grade-a); }
.score-card--grade-b { color: var(--dbd-grade-b); }
.score-card--grade-c { color: var(--dbd-grade-c); }
.score-card--grade-d { color: var(--dbd-grade-d); }
.score-card--grade-f { color: var(--dbd-grade-f); }

// ── Grade morph transition ────────────────────────────────────
//
// Triggered by re-keying the .score-card__letter element on grade
// change (Vue swaps the node, running enter + leave).  The leaving
// letter scales down and fades; the entering one scales up from
// slightly small with a soft fade.  Combined with `mode="out-in"`
// they hand off cleanly without overlapping.
.grade-morph-enter-active,
.grade-morph-leave-active {
	transition: transform 320ms cubic-bezier(0.22, 1.0, 0.36, 1.0), opacity 280ms ease-out;
}
.grade-morph-enter-from {
	transform: scale(0.7) rotate(-6deg);
	opacity: 0;
}
.grade-morph-leave-to {
	transform: scale(1.25) rotate(4deg);
	opacity: 0;
}

// ── Score delta floater ────────────────────────────────────────
.score-delta-enter-active {
	transition: transform 360ms cubic-bezier(0.22, 1.0, 0.36, 1.0), opacity 220ms ease-out;
}
.score-delta-leave-active {
	transition: transform 1200ms ease-out, opacity 1200ms ease-out;
}
.score-delta-enter-from {
	transform: translateY(8px) scale(0.85);
	opacity: 0;
}
.score-delta-leave-to {
	transform: translateY(-22px);
	opacity: 0;
}

@keyframes score-ripple {
	0%   { transform: scale(0.6); opacity: 0.7; }
	100% { transform: scale(2.4); opacity: 0; }
}

@media (prefers-reduced-motion: reduce) {
	.grade-morph-enter-active,
	.grade-morph-leave-active,
	.score-delta-enter-active,
	.score-delta-leave-active {
		transition: opacity 100ms linear;
	}
	.score-card__ripple {
		animation: none;
		display: none;
	}
}
</style>
