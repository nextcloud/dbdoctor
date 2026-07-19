<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div v-if="visible" class="confetti" aria-hidden="true">
		<span v-for="(p, i) in pieces"
			:key="i"
			class="confetti__piece"
			:style="p.style" />
	</div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'

const props = defineProps<{ trigger: boolean }>()
const emit = defineEmits<{ done: [] }>()

interface Piece { style: Record<string, string> }

const visible = ref(false)
let timer: number | null = null

// Build a fixed pool of confetti pieces with randomized x / hue / delay
// custom properties.  CSS handles the actual flight via @keyframes.
const PALETTE = ['#46ba61', '#0082c9', '#f4a261', '#e9322d', '#7bbf3a', '#8e44ad']
const pieces = computed<Piece[]>(() => {
	const out: Piece[] = []
	const count = 80
	for (let i = 0; i < count; i++) {
		const x = Math.random() * 100
		const delay = Math.random() * 0.4
		const duration = 2.4 + Math.random() * 1.4
		const rot = Math.random() * 360
		const sway = (Math.random() - 0.5) * 60
		const color = PALETTE[i % PALETTE.length]
		out.push({
			style: {
				'--confetti-x': x + '%',
				'--confetti-delay': delay + 's',
				'--confetti-duration': duration + 's',
				'--confetti-rot': rot + 'deg',
				'--confetti-sway': sway + 'px',
				background: color,
			},
		})
	}
	return out
})

// `immediate` so the burst also fires when the component is mounted
// lazily with trigger already true (the dashboard only mounts the
// overlay while a celebration is pending).
watch(() => props.trigger, (next) => {
	if (!next) return
	// Respect reduced-motion: emit `done` immediately so the parent
	// can clear the trigger flag, but don't render flying pieces.
	const reduce = typeof window !== 'undefined'
		&& window.matchMedia
		&& window.matchMedia('(prefers-reduced-motion: reduce)').matches
	if (reduce) {
		emit('done')
		return
	}
	visible.value = true
	if (timer !== null) clearTimeout(timer)
	timer = window.setTimeout(() => {
		visible.value = false
		emit('done')
	}, 3200)
}, { immediate: true })
</script>

<style scoped lang="scss">
.confetti {
	position: fixed;
	inset: 0;
	pointer-events: none;
	overflow: hidden;
	z-index: 9999;

	&__piece {
		position: absolute;
		top: -10vh;
		left: var(--confetti-x);
		width: 8px;
		height: 14px;
		opacity: 0.9;
		border-radius: 1px;
		animation: dbd-confetti-fall var(--confetti-duration, 3s) cubic-bezier(0.25, 0.46, 0.45, 0.94) forwards;
		animation-delay: var(--confetti-delay, 0s);
		transform-origin: center;
	}
}

@keyframes dbd-confetti-fall {
	0%   {
		transform: translate(0, 0) rotate(0deg);
		opacity: 0;
	}
	10%  { opacity: 0.95; }
	100% {
		transform: translate(var(--confetti-sway, 0), 110vh) rotate(var(--confetti-rot, 360deg));
		opacity: 0;
	}
}
</style>
