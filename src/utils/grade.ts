/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export type Grade = 'A' | 'B' | 'C' | 'D' | 'F'

export type MascotState = 'happy' | 'idle' | 'concerned' | 'checking'

/**
 * Derive a grade letter from a numeric 0-100 score.  Mirrors the
 * server-side {@see \OCA\DBDoctor\Service\Score::gradeFor} so
 * client-side fallbacks and server responses agree.
 *
 * @param score
 */
export function gradeFor(score: number): Grade {
	if (score >= 90) { return 'A' }
	if (score >= 80) { return 'B' }
	if (score >= 70) { return 'C' }
	if (score >= 60) { return 'D' }
	return 'F'
}

/**
 * The CSS custom-property used to colour the score / heartbeat for
 * a given grade.  Indirection through CSS variables keeps the
 * grade colours editable in tokens.scss.
 *
 * @param grade
 */
export function colorVarFor(grade: Grade): string {
	return {
		A: 'var(--dbd-grade-a)',
		B: 'var(--dbd-grade-b)',
		C: 'var(--dbd-grade-c)',
		D: 'var(--dbd-grade-d)',
		F: 'var(--dbd-grade-f)',
	}[grade]
}

/**
 * Readable variant of the grade colour.  The raw palette is tuned for
 * accents (tints, strokes, dots); as actual text on card surfaces it
 * can fall below AA — most visibly the dark-red F on a dark theme.
 * These variants are blended with the theme's main text colour in
 * tokens.scss so they stay legible on both light and dark surfaces.
 *
 * @param grade
 */
export function readableColorVarFor(grade: Grade): string {
	return {
		A: 'var(--dbd-grade-a-readable)',
		B: 'var(--dbd-grade-b-readable)',
		C: 'var(--dbd-grade-c-readable)',
		D: 'var(--dbd-grade-d-readable)',
		F: 'var(--dbd-grade-f-readable)',
	}[grade]
}

/**
 * Beats per minute used by the Heartbeat component.  The worse the
 * grade, the faster (and more alarming) the heartbeat — a soft visual
 * metaphor that doesn't fight the data.
 *
 * @param grade
 */
export function bpmFor(grade: Grade): number {
	return { A: 60, B: 75, C: 90, D: 110, F: 130 }[grade]
}

/**
 * Beats per minute as a function of *live database latency*.  Used
 * by the Heartbeat when a smoothed latency reading is available — a
 * snappy database makes the mascot's heart slow + calm, a struggling
 * one makes it race.
 *
 * Curve:
 *   - 0   ms → 50 BPM (resting calm)
 *   - 1   ms → 60 BPM
 *   - 10  ms → 80 BPM (alert)
 *   - 100 ms → 110 BPM (stressed)
 *   - 1000 ms → 140 BPM (alarmed)
 *
 * The shape is `50 + 30 * log10(ms + 1)` clamped to [50, 160].  log10
 * keeps the response sane across the multi-decade range we care about
 * (sub-millisecond local DBs through hundreds-of-ms remote).
 *
 * @param latencyMs
 */
export function bpmForLatency(latencyMs: number): number {
	if (!Number.isFinite(latencyMs) || latencyMs < 0) { return 60 }
	const bpm = 50 + 30 * Math.log10(latencyMs + 1)
	return Math.max(50, Math.min(160, bpm))
}

/**
 * Mascot expression for a given grade + running state.  When a check
 * is running we always show the "checking" mascot so the user gets
 * immediate feedback that something's happening.
 *
 * @param grade
 * @param running
 */
export function mascotFor(grade: Grade | null, running: boolean): MascotState {
	if (running) { return 'checking' }
	if (grade === null) { return 'idle' }
	if (grade === 'A' || grade === 'B') { return 'happy' }
	if (grade === 'F') { return 'concerned' }
	return 'idle'
}
