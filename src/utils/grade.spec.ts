/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import { bpmForLatency, gradeFor, mascotFor } from './grade'

describe('gradeFor', () => {
	it('maps scores to the same boundaries as the PHP Score service', () => {
		expect(gradeFor(100)).toBe('A')
		expect(gradeFor(90)).toBe('A')
		expect(gradeFor(89)).toBe('B')
		expect(gradeFor(80)).toBe('B')
		expect(gradeFor(70)).toBe('C')
		expect(gradeFor(60)).toBe('D')
		expect(gradeFor(59)).toBe('F')
		expect(gradeFor(0)).toBe('F')
	})
})

describe('bpmForLatency', () => {
	it('is calm at zero latency and rises with latency', () => {
		expect(bpmForLatency(0)).toBe(50)
		expect(bpmForLatency(10)).toBeGreaterThan(bpmForLatency(1))
		expect(bpmForLatency(100)).toBeGreaterThan(bpmForLatency(10))
	})

	it('clamps to the [50, 160] range and tolerates bad input', () => {
		expect(bpmForLatency(1_000_000)).toBeLessThanOrEqual(160)
		expect(bpmForLatency(-5)).toBe(60)
		expect(bpmForLatency(Number.NaN)).toBe(60)
	})
})

describe('mascotFor', () => {
	it('always shows checking while a run is in progress', () => {
		expect(mascotFor('F', true)).toBe('checking')
	})

	it('reflects the grade when idle', () => {
		expect(mascotFor(null, false)).toBe('idle')
		expect(mascotFor('A', false)).toBe('happy')
		expect(mascotFor('B', false)).toBe('happy')
		expect(mascotFor('F', false)).toBe('concerned')
	})
})
