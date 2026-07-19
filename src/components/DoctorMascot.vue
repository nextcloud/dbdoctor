<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="mascot" :class="`mascot--${state}`" aria-hidden="true">
		<svg class="mascot__svg"
			viewBox="0 0 96 96"
			xmlns="http://www.w3.org/2000/svg">
			<!-- Soft underglow -->
			<circle cx="48"
				cy="52"
				r="34"
				class="mascot__halo" />

			<!-- Coat: a friendly white doctor's coat with collar V -->
			<path d="M14 90 Q14 60 32 56 L48 64 L64 56 Q82 60 82 90 Z"
				class="mascot__coat" />
			<path d="M48 64 L40 90" class="mascot__coat-line" />
			<path d="M48 64 L56 90" class="mascot__coat-line" />

			<!-- Hair, back layer: shoulder-length, flowing onto the
				 coat on both sides of the face. -->
			<path d="M48 13 Q28 13 26 33 Q25 45 29 58 Q31 63 36 62 Q34 50 35 40 Q36 32 41 27 L55 27 Q60 32 61 40 Q62 50 60 62 Q65 63 67 58 Q71 45 70 33 Q68 13 48 13 Z"
				class="mascot__hair" />

			<!-- Head -->
			<circle cx="48"
				cy="38"
				r="18"
				class="mascot__face" />

			<!-- Hair, front layer: side-swept bangs over the crown. -->
			<path d="M31 33 Q32 19 48 18 Q64 19 65 33 Q58 26 46 26 Q37 27 33 31 Q31.5 32 31 33 Z"
				class="mascot__hair" />

			<!-- Eyes -->
			<circle :cx="leftEye.x"
				:cy="leftEye.y"
				r="2.4"
				class="mascot__eye" />
			<circle :cx="rightEye.x"
				:cy="rightEye.y"
				r="2.4"
				class="mascot__eye" />

			<!-- Mouth: morph for state -->
			<path :d="mouthPath" class="mascot__mouth" />

			<!-- Stethoscope: chest piece + tubing.  The chest piece swings
				 gently in the "checking" state via CSS animation. -->
			<g class="mascot__stetho">
				<path d="M40 64 Q42 78 52 80 Q62 82 64 70" class="mascot__tube" />
				<circle cx="64"
					cy="70"
					r="5"
					class="mascot__bell" />
				<circle cx="64"
					cy="70"
					r="2"
					class="mascot__bell-inner" />
			</g>

			<!-- Cheeks (only in happy state) -->
			<g v-if="state === 'happy'" class="mascot__cheeks">
				<circle cx="34" cy="42" r="3" />
				<circle cx="62" cy="42" r="3" />
			</g>
		</svg>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

import type { MascotState } from '../utils/grade'

const props = defineProps<{ state: MascotState }>()

// Eye position shifts a hair down for "concerned" so the face reads
// as a sympathetic frown rather than a glare.
const leftEye = computed(() => ({
	x: 41,
	y: props.state === 'concerned' ? 39 : 37,
}))
const rightEye = computed(() => ({
	x: 55,
	y: props.state === 'concerned' ? 39 : 37,
}))

// Mouth shape per state.  Each path traces from the left mouth-corner
// to the right; the curvature carries the emotion.
const mouthPath = computed(() => {
	switch (props.state) {
	case 'happy':
		return 'M40 47 Q48 54 56 47'
	case 'concerned':
		return 'M40 49 Q48 44 56 49'
	case 'checking':
		// A little circle: "hmm let me listen…"
		return 'M44 47 Q48 50 52 47 Q48 44 44 47 Z'
	case 'idle':
	default:
		return 'M42 47 Q48 49 54 47'
	}
})
</script>

<style scoped lang="scss">
.mascot {
	display: inline-block;
	width: 96px;
	height: 96px;
	flex-shrink: 0;

	&__svg {
		width: 100%;
		height: 100%;
		// Idle and happy mascots breathe gently.
		animation: dbd-breathing 5.6s ease-in-out infinite;
		transform-origin: 48px 64px;
	}

	&--checking &__svg {
		// Stop breathing while "listening" so the stethoscope swing
		// reads as the only motion.
		animation: none;
	}

	&__halo {
		fill: color-mix(in srgb, var(--color-primary-element) 12%, transparent);
	}

	&__coat {
		fill: var(--color-main-background);
		stroke: var(--color-border);
		stroke-width: 1.5;
	}

	&__coat-line {
		stroke: var(--color-border);
		stroke-width: 1;
		fill: none;
	}

	&__face {
		fill: #f3d6b5;
		stroke: color-mix(in srgb, #b07a4d 50%, transparent);
		stroke-width: 1;
	}

	&__hair {
		fill: #6b3f1d;
	}

	&__eye {
		fill: #2c2c2c;
	}

	&__mouth {
		fill: none;
		stroke: #62392a;
		stroke-width: 1.6;
		stroke-linecap: round;
	}

	&__cheeks circle {
		fill: color-mix(in srgb, #ff8aa1 70%, transparent);
	}

	&__tube {
		fill: none;
		stroke: var(--color-primary-element, #0082c9);
		stroke-width: 1.6;
		stroke-linecap: round;
	}

	&__bell {
		fill: var(--color-primary-element, #0082c9);
	}

	&__bell-inner {
		fill: var(--color-main-background);
	}

	// Stethoscope swings while we're "checking".
	&--checking &__stetho {
		transform-origin: 48px 64px;
		animation: dbd-stetho-swing 1.6s ease-in-out infinite;
	}
}

@keyframes dbd-stetho-swing {
	0%, 100% { transform: rotate(-6deg); }
	50%      { transform: rotate(6deg);  }
}
</style>
