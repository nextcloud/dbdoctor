/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const APP = 'dbdoctor'

// Lightweight logger.  We keep this thin (no external deps) so the
// bundle stays small.  Output goes through `console` which Nextcloud
// already wires to its log surfaces in dev builds.
export default {
	debug(msg: string, data?: unknown): void {
		if (typeof console !== 'undefined') console.debug(`[${APP}] ${msg}`, data ?? '')
	},
	info(msg: string, data?: unknown): void {
		if (typeof console !== 'undefined') console.info(`[${APP}] ${msg}`, data ?? '')
	},
	warn(msg: string, data?: unknown): void {
		if (typeof console !== 'undefined') console.warn(`[${APP}] ${msg}`, data ?? '')
	},
	error(msg: string, data?: unknown): void {
		if (typeof console !== 'undefined') console.error(`[${APP}] ${msg}`, data ?? '')
	},
}
