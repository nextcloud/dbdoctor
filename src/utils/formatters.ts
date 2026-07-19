/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const SI_BYTE_UNITS = ['B', 'KiB', 'MiB', 'GiB', 'TiB'] as const

/**
 * Render a byte count in IEC units with up to one decimal of
 * precision.  Used to display sizes in rule justifications when the
 * raw `SHOW VARIABLES` value comes back in bytes.
 *
 * @param bytes
 */
export function formatBytes(bytes: number): string {
	if (!Number.isFinite(bytes) || bytes < 0) { return '?' }
	let n = bytes
	let u = 0
	while (n >= 1024 && u < SI_BYTE_UNITS.length - 1) {
		n /= 1024
		u++
	}
	return (u === 0 ? n.toString() : n.toFixed(1)) + ' ' + SI_BYTE_UNITS[u]
}

/**
 * Render a UNIX timestamp as a relative phrase (e.g. "2 minutes ago"),
 * falling back to a localized date string for older points.
 *
 * @param ts
 * @param now
 */
export function timeAgo(ts: number, now: number = Date.now() / 1000): string {
	const seconds = Math.max(0, Math.floor(now - ts))
	if (seconds < 5) { return 'just now' }
	if (seconds < 60) { return `${seconds}s ago` }
	if (seconds < 3600) { return `${Math.floor(seconds / 60)}m ago` }
	if (seconds < 86400) { return `${Math.floor(seconds / 3600)}h ago` }
	if (seconds < 86400 * 14) { return `${Math.floor(seconds / 86400)}d ago` }
	return new Date(ts * 1000).toLocaleDateString()
}

/**
 * Pretty-print a percentage with at most one decimal.
 *
 * @param p
 */
export function formatPercent(p: number): string {
	if (!Number.isFinite(p)) { return '?' }
	if (Math.abs(p - Math.round(p)) < 0.05) { return Math.round(p) + '%' }
	return p.toFixed(1) + '%'
}

/**
 * Format a rule's primary value for compact display in the overview.
 *
 * The advisor evaluator collapses everything into a float, but the
 * meaningful presentation depends on magnitude:
 *  - integer-valued numbers print without decimals;
 *  - small fractional numbers keep up to four decimals (advisor ratios
 *    like cache_hit_ratio = 0.9842 are common);
 *  - large integers get thousands separators for readability.
 *
 * @param v
 */
export function formatValue(v: number | null | undefined): string {
	if (v === null || v === undefined || !Number.isFinite(v)) { return '—' }
	if (Number.isInteger(v)) { return v.toLocaleString() }
	if (Math.abs(v) >= 100) { return Math.round(v).toLocaleString() }
	return parseFloat(v.toFixed(4)).toString()
}
