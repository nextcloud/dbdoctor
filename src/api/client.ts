/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type {
	AuditRow,
	LiveMetrics,
	RevertedFix,
	RunResult,
	ScorePoint,
	SeriesPoint,
	Settings,
} from './types'

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

interface OcsEnvelope<T> {
	ocs: { meta: unknown, data: T }
}

function url(path: string): string {
	// Force JSON: OCS endpoints default to XML when no format is set
	// and @nextcloud/axios doesn't always negotiate it via Accept.
	const base = generateOcsUrl('apps/dbdoctor/api/v1' + path)
	return base + (base.includes('?') ? '&' : '?') + 'format=json'
}

// Some installs serve OCS responses with a content type axios doesn't
// auto-parse as JSON.  Forcing the Accept header and a manual JSON
// transform on the response side keeps `res.data` an object even when
// the server's Content-Type is text/plain or text/xml.
const REQUEST_OPTS = {
	headers: { Accept: 'application/json' },
	responseType: 'json' as const,
	transformResponse: [
		(data: unknown): unknown => {
			if (typeof data === 'string') {
				try {
					return JSON.parse(data)
				} catch {
					return data
				}
			}
			return data
		},
	],
}

// Surfaces a useful message when the backend returns something that
// isn't an OCS envelope (PHP fatal, HTML 500 page, redirect to login).
// Without this, callers see a cryptic "undefined is not an object"
// when they try to read `.ocs.data` on a non-OCS body.
function unwrap<T>(body: unknown, label: string): T {
	if (body === null || typeof body !== 'object') {
		const preview = typeof body === 'string'
			? body.slice(0, 200)
			: String(body)
		throw new Error(`${label}: server returned a non-JSON response (got: ${preview || 'empty body'}). Check the server log.`)
	}
	const ocs = (body as { ocs?: { data?: T } }).ocs
	if (!ocs || !('data' in ocs)) {
		throw new Error(`${label}: response is missing the OCS envelope. The endpoint may have errored before reaching the controller.`)
	}
	return ocs.data as T
}

export async function getLatest(): Promise<RunResult | null> {
	const res = await axios.get<OcsEnvelope<RunResult | null>>(url('/check/latest'), REQUEST_OPTS)
	// 204 No Content gives axios an empty body; treat as "no run yet".
	if (res.status === 204 || res.data === '' || res.data === null) {
		return null
	}
	return unwrap<RunResult | null>(res.data, 'getLatest')
}

export async function runCheck(): Promise<RunResult> {
	const res = await axios.post<OcsEnvelope<RunResult>>(url('/check/run'), null, REQUEST_OPTS)
	return unwrap<RunResult>(res.data, 'runCheck')
}

export interface PingResult {
	elapsedMs: number
	ok: boolean
	error?: string
}

// 1 Hz poll target.  We pass a per-request timeout shorter than the
// poll interval so a stalled DB doesn't queue up backed-up requests
// behind each other; the chart shows the timeout as a sentinel point.
const PING_TIMEOUT_MS = 800

export async function pingDatabase(): Promise<PingResult> {
	const res = await axios.get<OcsEnvelope<PingResult>>(
		url('/check/ping'),
		{ ...REQUEST_OPTS, timeout: PING_TIMEOUT_MS },
	)
	return unwrap<PingResult>(res.data, 'pingDatabase')
}

export async function getHistory(ruleId: string, days: number = 30): Promise<SeriesPoint[]> {
	const res = await axios.get<OcsEnvelope<{ ruleId: string, days: number, series: SeriesPoint[] }>>(
		url('/check/history'),
		{ ...REQUEST_OPTS, params: { ruleId, days } },
	)
	return unwrap<{ series: SeriesPoint[] }>(res.data, 'getHistory').series
}

export async function getScoreHistory(days: number = 30): Promise<ScorePoint[]> {
	const res = await axios.get<OcsEnvelope<{ days: number, series: ScorePoint[] }>>(
		url('/check/score-history'),
		{ ...REQUEST_OPTS, params: { days } },
	)
	return unwrap<{ series: ScorePoint[] }>(res.data, 'getScoreHistory').series
}

export async function getRevertedFixes(): Promise<RevertedFix[]> {
	const res = await axios.get<OcsEnvelope<{ reverted: RevertedFix[] }>>(
		url('/check/reverted-fixes'),
		REQUEST_OPTS,
	)
	return unwrap<{ reverted: RevertedFix[] }>(res.data, 'getRevertedFixes').reverted
}

export async function applyChange(ruleId: string, variable: string, value: string): Promise<{
	success: boolean
	oldValue: string | null
	newValue: string | null
	error?: string
}> {
	try {
		const res = await axios.post<OcsEnvelope<{
			success: boolean
			oldValue: string | null
			newValue: string | null
			error?: string
		}>>(url('/apply'), { ruleId, variable, value }, REQUEST_OPTS)
		return unwrap(res.data, 'applyChange')
	} catch (e) {
		// A non-2xx (bad value → 400, or an unexpected server fault) still
		// carries a useful message in the OCS body.  Surface it as a
		// failed result the dialog can render, rather than letting axios's
		// generic "Request failed with status code NNN" bubble up.
		const body = (e as { response?: { data?: unknown } })?.response?.data
		const data = (body as { ocs?: { data?: { error?: string } } })?.ocs?.data
		const meta = (body as { ocs?: { meta?: { message?: string } } })?.ocs?.meta
		const message = data?.error || meta?.message || (e instanceof Error ? e.message : String(e))
		return { success: false, oldValue: null, newValue: null, error: message }
	}
}

export async function getSettings(): Promise<Settings> {
	const res = await axios.get<OcsEnvelope<Settings>>(url('/settings'), REQUEST_OPTS)
	return unwrap<Settings>(res.data, 'getSettings')
}

export async function updateSettings(patch: Partial<{
	host: string
	port: number
	user: string
	database: string
	driver: 'pdo_mysql' | 'pdo_pgsql'
	password: string
	clearPassword: boolean
}>): Promise<Settings> {
	const res = await axios.put<OcsEnvelope<Settings>>(url('/settings'), patch, REQUEST_OPTS)
	return unwrap<Settings>(res.data, 'updateSettings')
}

export async function testConnection(payload: {
	host: string
	port: number
	user: string
	password: string
	database: string
	driver: 'pdo_mysql' | 'pdo_pgsql'
}): Promise<{ ok: boolean, message: string }> {
	const res = await axios.post<OcsEnvelope<{ ok: boolean, message: string }>>(
		url('/settings/test-connection'),
		payload,
		REQUEST_OPTS,
	)
	return unwrap(res.data, 'testConnection')
}

export async function getAudit(limit: number = 50): Promise<AuditRow[]> {
	const res = await axios.get<OcsEnvelope<{ entries: AuditRow[] }>>(url('/audit'), { ...REQUEST_OPTS, params: { limit } })
	return unwrap<{ entries: AuditRow[] }>(res.data, 'getAudit').entries
}

// ── Live metrics (dashboard tiles) ──────────────────────────────────

// Axios reports HTTP failures as a generic "Request failed with status
// code NNN".  When the body is an OCS error envelope the real cause
// (e.g. the SQL error the controller wrapped) is in ocs.meta.message —
// surface that instead.
function toReadableError(e: unknown, label: string): Error {
	const body = (e as { response?: { data?: unknown } })?.response?.data
	const message = (body as { ocs?: { meta?: { message?: string } } })?.ocs?.meta?.message
	if (typeof message === 'string' && message !== '') {
		return new Error(`${label}: ${message}`)
	}
	return e instanceof Error ? e : new Error(String(e))
}

async function ocsGet<T>(label: string, path: string, opts: object = {}): Promise<T> {
	try {
		const res = await axios.get<OcsEnvelope<T>>(url(path), { ...REQUEST_OPTS, ...opts })
		return unwrap<T>(res.data, label)
	} catch (e) {
		throw toReadableError(e, label)
	}
}

export async function getLiveMetrics(): Promise<LiveMetrics> {
	return ocsGet<LiveMetrics>('getLiveMetrics', '/insights/metrics', { timeout: 4000 })
}
