/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export type Severity = 'alert' | 'warning' | 'notice'
export type RuleStatus = 'ok' | 'fail' | 'skipped'

export interface ApplyDescriptor {
	variable: string
	configKey: string
	configFile: string
	runtimeWritable: boolean
	recommendedValue: string
	note: string | null
}

export interface RuleResult {
	id: string
	name: string
	category: string
	severity: Severity
	status: RuleStatus
	issue: string
	recommendation: string
	justification: string | null
	value: number | null
	skipReason: string | null
	apply: ApplyDescriptor | null
	docUrl: string | null
}

export interface CheckCounts {
	alert: number
	warning: number
	notice: number
	total: number
}

export interface RunResult {
	ranAt: number
	dbFlavour: string
	dbVersion: string
	/** Live database size in bytes; null when it could not be probed. */
	dbSize: number | null
	score: number
	grade: 'A' | 'B' | 'C' | 'D' | 'F'
	counts: CheckCounts
	results: RuleResult[]
}

export interface SeriesPoint {
	ts: number
	status: RuleStatus
}

export interface ScorePoint {
	ts: number
	score: number
	grade: string
}

export interface RevertedFix {
	ruleId: string
	variable: string
	appliedValue: string
	liveValue: string
	appliedAt: number
}

export interface Settings {
	override: {
		host: string
		port: number
		user: string
		database: string
		driver: 'pdo_mysql' | 'pdo_pgsql'
		passwordSet: boolean
	}
}

// ── Live metrics (dashboard tiles) ──────────────────────────────────

export interface LiveMetrics {
	connections: { used: number; max: number }
	cacheHitRatio: number | null
	throughput: { counter: number; label: string }
	threadsRunning: number
}

export interface AuditRow {
	id: number
	appliedAt: number
	actorUid: string
	ruleId: string
	variable: string
	oldValue: string | null
	newValue: string | null
	success: boolean
	error: string | null
}
