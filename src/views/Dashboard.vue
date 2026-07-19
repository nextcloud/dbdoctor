<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="dashboard">
		<!-- ── Header row: title + run-now button ─────────────────── -->
		<header class="dashboard__header">
			<div class="dashboard__title">
				<AppIcon :size="40" class="dashboard__title-icon" />
				<div>
					<h1>{{ t('dbdoctor', 'DB Doctor') }}</h1>
					<p class="dashboard__tagline">
						{{ tagline }}
					</p>
				</div>
			</div>
			<div class="dashboard__header-actions">
				<NcButton type="primary"
					:disabled="checks.running || isUnsupported"
					@click="onRun">
					<template #icon>
						<NcLoadingIcon v-if="checks.running" :size="20" />
						<IconHeartPulse v-else :size="20" />
					</template>
					{{ checks.running ? t('dbdoctor', 'Checking…') : t('dbdoctor', 'Run check now') }}
				</NcButton>
				<NcButton type="tertiary"
					:title="t('dbdoctor', 'DB Doctor settings')"
					:aria-label="t('dbdoctor', 'DB Doctor settings')"
					@click="settingsOpen = true">
					<template #icon>
						<IconCog :size="20" />
					</template>
				</NcButton>
			</div>
		</header>

		<!-- ── Unsupported flavour: short-circuit ─────────────────── -->
		<EmptyOrUnsupported v-if="isUnsupported"
			:title="t('dbdoctor', 'Unsupported database')"
			:description="t('dbdoctor', 'DB Doctor only checks MySQL, MariaDB, and PostgreSQL. Your Nextcloud is configured with a different database.')"
			mascot-state="concerned"
			:cta="null" />

		<!-- ── Empty state (no run yet, supported flavour) ────────── -->
		<EmptyOrUnsupported v-else-if="checks.latest === null && !checks.running"
			:title="t('dbdoctor', 'Ready for your first check-up?')"
			:description="t('dbdoctor', 'I will look at your database\'s settings and tell you if anything looks off. You can keep clicking around — this will only take a moment.')"
			mascot-state="idle"
			:cta="t('dbdoctor', 'Start the check')"
			@action="onRun" />

		<template v-else>
			<!-- ── Top row: ScoreCard + quick stats ───────────────── -->
			<div class="dashboard__top-row">
				<ScoreCard :score="checks.latest?.score ?? null"
					:grade="checks.latest?.grade ?? null"
					:running="checks.running"
					:counts="checks.latest?.counts" />
				<aside class="dashboard__stats">
					<dl>
						<div class="dashboard__stat">
							<dt>{{ t('dbdoctor', 'Database') }}</dt>
							<dd>{{ flavourLabel }}</dd>
						</div>
						<div class="dashboard__stat">
							<dt>{{ t('dbdoctor', 'Version') }}</dt>
							<dd>{{ checks.latest?.dbVersion || '—' }}</dd>
						</div>
						<div class="dashboard__stat">
							<dt>{{ t('dbdoctor', 'Size') }}</dt>
							<dd>{{ checks.latest?.dbSize != null ? formatBytes(checks.latest.dbSize) : '—' }}</dd>
						</div>
						<div class="dashboard__stat">
							<dt>{{ t('dbdoctor', 'Last check') }}</dt>
							<dd>{{ checks.latest ? timeAgo(checks.latest.ranAt) : '—' }}</dd>
						</div>
						<div class="dashboard__stat">
							<dt>{{ t('dbdoctor', 'Findings') }}</dt>
							<dd>
								<button type="button"
									class="dashboard__pill dashboard__pill--alert"
									:class="{ 'dashboard__pill--active': severityFilter === 'alert' }"
									:title="t('dbdoctor', 'Show only alerts')"
									@click="toggleSeverity('alert')">
									{{ checks.latest?.counts.alert ?? 0 }} {{ t('dbdoctor', 'alerts') }}
								</button>
								<button type="button"
									class="dashboard__pill dashboard__pill--warning"
									:class="{ 'dashboard__pill--active': severityFilter === 'warning' }"
									:title="t('dbdoctor', 'Show only warnings')"
									@click="toggleSeverity('warning')">
									{{ checks.latest?.counts.warning ?? 0 }} {{ t('dbdoctor', 'warnings') }}
								</button>
								<button type="button"
									class="dashboard__pill dashboard__pill--notice"
									:class="{ 'dashboard__pill--active': severityFilter === 'notice' }"
									:title="t('dbdoctor', 'Show only notices')"
									@click="toggleSeverity('notice')">
									{{ checks.latest?.counts.notice ?? 0 }} {{ t('dbdoctor', 'notices') }}
								</button>
							</dd>
						</div>
					</dl>
				</aside>
			</div>

			<!-- ── Live metric tiles ───────────────────────────────── -->
			<MetricTiles />

			<!-- ── Charts row: score trend + live latency side by side.
				 ScoreTrend self-hides below two stored runs; flex lets
				 the latency chart take the full row in that case, and
				 both wrap to their own line on narrow screens. ──────── -->
			<div class="dashboard__charts">
				<ScoreTrend class="dashboard__chart" />
				<LatencyChart class="dashboard__chart" />
			</div>

			<!-- ── Reverted-fix notice ─────────────────────────────── -->
			<div v-if="revertedFixes.length > 0" class="dashboard__reverted-note">
				<IconRestore :size="20" class="dashboard__reverted-icon" />
				<div class="dashboard__reverted-body">
					<strong>{{ n('dbdoctor',
						'%n applied fix has reverted to a different value on the live server.',
						'%n applied fixes have reverted to different values on the live server.',
						revertedFixes.length) }}</strong>
					<span class="dashboard__reverted-hint">
						{{ t('dbdoctor', 'This usually means the database restarted. Make these permanent in your config file:') }}
					</span>
					<ul class="dashboard__reverted-list">
						<li v-for="r in revertedFixes" :key="r.variable">
							<code>{{ r.variable }}</code>
							{{ t('dbdoctor', 'applied {applied}, now {live}', { applied: r.appliedValue, live: r.liveValue }) }}
						</li>
					</ul>
				</div>
			</div>

			<!-- ── Restart-required notice ─────────────────────────── -->
			<button v-if="restartCount > 0"
				type="button"
				class="dashboard__restart-note"
				:class="{ 'dashboard__restart-note--active': restartOnly }"
				@click="toggleRestartOnly">
				<IconRestart :size="20" class="dashboard__restart-icon" />
				<span>
					{{ n('dbdoctor',
						'%n recommended fix needs a config change and a database restart — it can’t be applied live.',
						'%n recommended fixes need a config change and a database restart — they can’t be applied live.',
						restartCount) }}
				</span>
				<span class="dashboard__restart-action">
					{{ restartOnly ? t('dbdoctor', 'Show all') : t('dbdoctor', 'Show these') }}
				</span>
			</button>

			<!-- ── Status filter strip + search ────────────────────── -->
			<nav v-if="allRules.length > 0" class="dashboard__filters" aria-label="Filter rules by status">
				<button v-for="f in filters"
					:key="f.id"
					type="button"
					class="dashboard__filter"
					:class="[
						`dashboard__filter--${f.id}`,
						{ 'dashboard__filter--active': statusFilter === f.id },
					]"
					@click="onStatusFilter(f.id)">
					{{ f.label }}
					<span class="dashboard__filter-count">{{ f.count }}</span>
				</button>
				<div class="dashboard__search">
					<NcTextField :value.sync="searchQuery"
						type="search"
						:label="t('dbdoctor', 'Search rules')">
						<template #icon>
							<IconMagnify :size="20" />
						</template>
					</NcTextField>
				</div>
			</nav>

			<!-- ── Full overview: every rule, grouped by category ───── -->
			<section v-if="checks.latest" class="dashboard__rules">
				<div v-for="group in groupedRules"
					:key="group.category"
					class="dashboard__category">
					<header class="dashboard__category-header">
						<h2 class="dashboard__category-title">
							{{ group.category }}
						</h2>
						<span class="dashboard__category-meta">
							<span v-if="group.failCount > 0" class="dashboard__category-pill dashboard__category-pill--fail">
								{{ t('dbdoctor', '{n} failing', { n: group.failCount }) }}
							</span>
							<span v-if="group.okCount > 0" class="dashboard__category-pill dashboard__category-pill--ok">
								{{ t('dbdoctor', '{n} passing', { n: group.okCount }) }}
							</span>
							<span v-if="group.skippedCount > 0" class="dashboard__category-pill dashboard__category-pill--skipped">
								{{ t('dbdoctor', '{n} skipped', { n: group.skippedCount }) }}
							</span>
						</span>
					</header>
					<TransitionGroup name="dashboard-fade" tag="div" class="dashboard__rules-list">
						<RuleCard v-for="rule in group.rules"
							:key="rule.id"
							:rule="rule"
							:series="checks.ruleSeries[rule.id]"
							@apply="onApply"
							@snippet="onSnippet"
							@request-series="checks.loadSeries" />
					</TransitionGroup>
				</div>

				<p v-if="filteredRules.length === 0 && allRules.length > 0" class="dashboard__empty">
					{{ searchQuery.trim() !== ''
						? t('dbdoctor', 'No rules match “{query}”.', { query: searchQuery.trim() })
						: t('dbdoctor', 'No rules match the current filter.') }}
				</p>

				<!-- Visible when latest has score/counts but no decoded
					 results (e.g. the stored JSON is missing or empty).
					 Surfacing this beats silently rendering an empty
					 page that looks broken. -->
				<p v-if="allRules.length === 0" class="dashboard__empty">
					{{ t('dbdoctor', 'The last check recorded a score but no rule details. Click "Run check now" to refresh the findings.') }}
				</p>
			</section>
		</template>

		<!-- ── Dialogs ────────────────────────────────────────────── -->
		<ApplyDialog v-if="applyTarget"
			:open="applyOpen"
			:rule="applyTarget"
			@update:open="(v) => (applyOpen = v)"
			@apply="(rule, resolve) => onConfirmApply(rule, resolve)" />
		<SnippetDialog v-if="snippetTarget"
			:open="snippetOpen"
			:rule="snippetTarget"
			@update:open="(v) => (snippetOpen = v)" />
		<SettingsDialog :open="settingsOpen"
			@update:open="(v) => (settingsOpen = v)" />

		<!-- Confetti for grade A — fires once per A run via store flag.
			 Mounted lazily (async chunk) only while a celebration is
			 pending; the component self-fires on mount via its
			 immediate trigger watch. -->
		<ConfettiOverlay v-if="checks.celebrate" :trigger="checks.celebrate" @done="checks.consumeCelebrate" />
	</div>
</template>

<script setup lang="ts">
import { computed, defineAsyncComponent, onMounted, ref } from 'vue'

import { showError } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconHeartPulse from 'vue-material-design-icons/HeartPulse.vue'
import IconCog from 'vue-material-design-icons/Cog.vue'
import IconMagnify from 'vue-material-design-icons/Magnify.vue'
import IconRestart from 'vue-material-design-icons/Restart.vue'
import IconRestore from 'vue-material-design-icons/Restore.vue'

import type { RevertedFix, RuleResult, RuleStatus, Severity } from '../api/types'
import * as api from '../api/client'
import { confirmPassword } from '@nextcloud/password-confirmation'
import '@nextcloud/password-confirmation/style.css'

import AppIcon from '../components/AppIcon.vue'
import EmptyOrUnsupported from '../components/EmptyOrUnsupported.vue'
import LatencyChart from '../components/LatencyChart.vue'
import MetricTiles from '../components/MetricTiles.vue'
import RuleCard from '../components/RuleCard.vue'
import ScoreCard from '../components/ScoreCard.vue'
import ScoreTrend from '../components/ScoreTrend.vue'
import { useChecksStore } from '../stores/checks'
import { formatBytes, timeAgo } from '../utils/formatters'

// The dialogs and the confetti overlay are rarely (or never) shown in a
// typical visit, so they're split out of the entry chunk and fetched on
// first render instead.
const ApplyDialog = defineAsyncComponent(() => import('../components/ApplyDialog.vue'))
const ConfettiOverlay = defineAsyncComponent(() => import('../components/ConfettiOverlay.vue'))
const SettingsDialog = defineAsyncComponent(() => import('../components/SettingsDialog.vue'))
const SnippetDialog = defineAsyncComponent(() => import('../components/SnippetDialog.vue'))

const checks = useChecksStore()

// Initial-state values delivered by the PHP Settings\Admin form.  We
// use them only to render the unsupported card on first paint without
// a network round-trip.  loadState throws if the key isn't present
// (rare — only happens if the page is loaded outside the admin flow),
// in which case we fall back to an empty string and the Dashboard
// just trusts the API response.
let initialFlavour = ''
try {
	initialFlavour = loadState<string>('dbdoctor', 'flavour', '')
} catch {
	initialFlavour = ''
}

const isUnsupported = computed(() => {
	const f = checks.latest?.dbFlavour ?? initialFlavour
	return f === 'unsupported'
})

const flavourLabel = computed(() => {
	const f = checks.latest?.dbFlavour ?? initialFlavour
	return ({
		mysql: 'MySQL',
		mariadb: 'MariaDB',
		pgsql: 'PostgreSQL',
	} as Record<string, string>)[f] ?? f ?? '—'
})

const tagline = computed(() => {
	if (checks.latest === null) return t('dbdoctor', 'Your database, but with a regular check-up.')
	if (checks.running) return t('dbdoctor', 'Listening to your database…')
	return ({
		A: t('dbdoctor', 'Healthy! Keep doing what you\'re doing.'),
		B: t('dbdoctor', 'Looking good — small tweaks could make it better.'),
		C: t('dbdoctor', 'Some things to address — let\'s tune.'),
		D: t('dbdoctor', 'Several issues — start with the alerts below.'),
		F: t('dbdoctor', 'Critical issues — please address the alerts now.'),
	} as Record<string, string>)[checks.latest.grade] ?? ''
})

// ── Full overview: status filter + grouping ──────────────────

const allRules = computed<RuleResult[]>(() => checks.latest?.results ?? [])

type FilterId = 'all' | 'fail' | 'ok' | 'skipped'
const statusFilter = ref<FilterId>('all')
// Refinements that AND with the status tab.  `severityFilter` narrows
// failing rules to one severity (driven by the Findings pills);
// `restartOnly` keeps only fixes that need a restart; `searchQuery`
// matches name / id / category.
const severityFilter = ref<Severity | null>(null)
const restartOnly = ref(false)
const searchQuery = ref('')

const filters = computed<{ id: FilterId; label: string; count: number }[]>(() => {
	const c = (s: RuleStatus) => allRules.value.filter((r) => r.status === s).length
	return [
		{ id: 'all', label: t('dbdoctor', 'All'), count: allRules.value.length },
		{ id: 'fail', label: t('dbdoctor', 'Failing'), count: c('fail') },
		{ id: 'ok', label: t('dbdoctor', 'Passing'), count: c('ok') },
		{ id: 'skipped', label: t('dbdoctor', 'Skipped'), count: c('skipped') },
	]
})

// A failing rule whose fix exists but can't be applied at runtime —
// it needs a config-file edit and a database restart.
function needsRestart(r: RuleResult): boolean {
	return r.status === 'fail' && r.apply !== null && !r.apply.runtimeWritable
}

const restartCount = computed<number>(() => allRules.value.filter(needsRestart).length)

const filteredRules = computed<RuleResult[]>(() => {
	let rules = allRules.value
	if (statusFilter.value !== 'all') {
		rules = rules.filter((r) => r.status === statusFilter.value)
	}
	if (severityFilter.value !== null) {
		rules = rules.filter((r) => r.status === 'fail' && r.severity === severityFilter.value)
	}
	if (restartOnly.value) {
		rules = rules.filter(needsRestart)
	}
	const q = searchQuery.value.trim().toLowerCase()
	if (q !== '') {
		rules = rules.filter((r) =>
			r.name.toLowerCase().includes(q)
			|| r.id.toLowerCase().includes(q)
			|| r.category.toLowerCase().includes(q))
	}
	return rules
})

// Picking a status tab clears the fail-only refinements so the tab
// does what its label says.
function onStatusFilter(id: FilterId): void {
	statusFilter.value = id
	severityFilter.value = null
	restartOnly.value = false
}

// Findings pills toggle a severity lens (and imply "failing").
function toggleSeverity(sev: Severity): void {
	if (severityFilter.value === sev) {
		severityFilter.value = null
		return
	}
	severityFilter.value = sev
	restartOnly.value = false
	statusFilter.value = 'fail'
}

function toggleRestartOnly(): void {
	restartOnly.value = !restartOnly.value
	if (restartOnly.value) {
		severityFilter.value = null
		statusFilter.value = 'fail'
	}
}

// Sort within a category: fail (alert→warning→notice) → ok → skipped,
// then alphabetical by name as the tiebreaker.
const statusOrder: Record<RuleStatus, number> = { fail: 0, ok: 1, skipped: 2 }
const sevWeight: Record<string, number> = { alert: 0, warning: 1, notice: 2 }
function ruleSort(a: RuleResult, b: RuleResult): number {
	const ord = statusOrder[a.status] - statusOrder[b.status]
	if (ord !== 0) return ord
	if (a.status === 'fail' && b.status === 'fail') {
		const sev = (sevWeight[a.severity] ?? 9) - (sevWeight[b.severity] ?? 9)
		if (sev !== 0) return sev
	}
	return a.name.localeCompare(b.name)
}

interface CategoryGroup {
	category: string
	rules: RuleResult[]
	failCount: number
	okCount: number
	skippedCount: number
}

const groupedRules = computed<CategoryGroup[]>(() => {
	const groups: Record<string, RuleResult[]> = {}
	for (const r of filteredRules.value) {
		(groups[r.category] ||= []).push(r)
	}
	return Object.keys(groups)
		.sort()
		.map((category) => {
			const rules = groups[category].slice().sort(ruleSort)
			return {
				category,
				rules,
				failCount: rules.filter((r) => r.status === 'fail').length,
				okCount: rules.filter((r) => r.status === 'ok').length,
				skippedCount: rules.filter((r) => r.status === 'skipped').length,
			}
		})
})

// ── Dialog wiring ─────────────────────────────────────────────

const applyOpen = ref(false)
const applyTarget = ref<RuleResult | null>(null)
const snippetOpen = ref(false)
const snippetTarget = ref<RuleResult | null>(null)
const settingsOpen = ref(false)

function onApply(rule: RuleResult): void {
	applyTarget.value = rule
	applyOpen.value = true
}

function onSnippet(rule: RuleResult): void {
	snippetTarget.value = rule
	snippetOpen.value = true
}

async function onConfirmApply(
	rule: RuleResult,
	resolve: (r: { success: boolean; oldValue: string | null; newValue: string | null; error?: string }) => void,
): Promise<void> {
	if (!rule.apply) {
		resolve({ success: false, oldValue: null, newValue: null, error: 'No apply descriptor for rule.' })
		return
	}
	try {
		// The apply endpoint changes live database variables and is
		// guarded by PasswordConfirmationRequired server-side — ask for
		// the password up front or the request bounces with a 403.
		await confirmPassword()
		const result = await checks.applyAndRefresh(rule.id, rule.apply.variable, rule.apply.recommendedValue)
		resolve(result)
	} catch (e) {
		resolve({ success: false, oldValue: null, newValue: null, error: (e as Error).message ?? 'Apply failed.' })
	}
}

const revertedFixes = ref<RevertedFix[]>([])
async function loadRevertedFixes(): Promise<void> {
	try {
		revertedFixes.value = await api.getRevertedFixes()
	} catch {
		// Non-critical banner; a failure just means we don't show it.
		revertedFixes.value = []
	}
}

async function onRun(): Promise<void> {
	await checks.runNow()
	if (checks.error !== null) {
		showError(checks.error)
	}
	// An apply-then-refresh may have changed what's reverted.
	void loadRevertedFixes()
}

onMounted(() => {
	void checks.fetchLatest()
	void loadRevertedFixes()
})
</script>

<style scoped lang="scss">
.dashboard {
	display: flex;
	flex-direction: column;
	gap: 20px;

	&__header {
		display: flex;
		flex-wrap: wrap;
		gap: 12px;
		align-items: center;
		justify-content: space-between;
	}

	&__title {
		display: flex;
		align-items: center;
		gap: 12px;

		h1 {
			margin: 0;
			font-size: 24px;
			font-weight: 700;
		}
	}

	&__title-icon {
		color: var(--color-primary-element);
		flex-shrink: 0;
	}

	&__tagline {
		margin: 0;
		font-size: 13px;
		color: var(--color-text-maxcontrast);
	}

	&__header-actions {
		display: flex;
		gap: 8px;
		align-items: center;
	}

	&__top-row {
		display: grid;
		grid-template-columns: minmax(280px, 380px) 1fr;
		gap: 20px;

		@media (max-width: 720px) {
			grid-template-columns: 1fr;
		}
	}

	&__charts {
		display: flex;
		flex-wrap: wrap;
		gap: 20px;
	}

	&__chart {
		// Grow to share the row evenly; wrap to a full-width line when
		// the viewport can't fit two charts of readable width.
		flex: 1 1 340px;
		min-width: 0;
	}

	&__stats {
		padding: 12px 16px;
		background: var(--dbd-card-bg);
		border: 1px solid var(--dbd-card-border);
		border-radius: var(--dbd-card-radius);

		dl {
			display: grid;
			gap: 6px;
			margin: 0;
		}
	}

	&__stat {
		display: grid;
		grid-template-columns: 110px 1fr;
		gap: 12px;
		align-items: baseline;

		dt {
			font-size: 12px;
			text-transform: uppercase;
			letter-spacing: 0.05em;
			color: var(--color-text-maxcontrast);
		}

		dd {
			margin: 0;
			font-weight: 600;
			color: var(--color-main-text);
		}
	}

	&__pill {
		display: inline-block;
		padding: 2px 10px;
		margin-inline-end: 6px;
		border: 1px solid transparent;
		border-radius: var(--border-radius-pill);
		font-size: 12px;
		font-weight: 600;
		font-family: inherit;
		cursor: pointer;
		transition: box-shadow 120ms ease, border-color 120ms ease, filter 120ms ease;

		&:hover { filter: brightness(1.05); }

		&:focus-visible {
			outline: none;
			box-shadow: 0 0 0 2px var(--color-primary-element);
		}

		&--alert   { background: color-mix(in srgb, var(--dbd-severity-alert)   24%, transparent); color: var(--dbd-severity-alert-readable); }
		&--warning { background: color-mix(in srgb, var(--dbd-severity-warning) 24%, transparent); color: var(--dbd-severity-warning-readable); }
		&--notice  { background: color-mix(in srgb, var(--dbd-severity-notice)  24%, transparent); color: var(--dbd-severity-notice-readable); }

		// Selected pill gets a solid ring in its own severity colour.
		&--active { border-color: currentColor; }
		&--alert.dashboard__pill--active   { box-shadow: 0 0 0 1px var(--dbd-severity-alert); }
		&--warning.dashboard__pill--active { box-shadow: 0 0 0 1px var(--dbd-severity-warning); }
		&--notice.dashboard__pill--active  { box-shadow: 0 0 0 1px var(--dbd-severity-notice); }
	}

	&__reverted-note {
		display: flex;
		gap: 10px;
		padding: 12px 14px;
		border: 1px solid color-mix(in srgb, var(--dbd-grade-f) 40%, var(--dbd-card-border));
		border-radius: var(--dbd-card-radius);
		background: color-mix(in srgb, var(--dbd-grade-f) 10%, var(--dbd-card-bg));
		font-size: 13px;
		color: var(--color-main-text);
	}

	&__reverted-icon {
		flex-shrink: 0;
		color: var(--dbd-grade-f-readable);
	}

	&__reverted-body {
		display: flex;
		flex-direction: column;
		gap: 4px;
		min-width: 0;
	}

	&__reverted-hint {
		color: var(--color-text-maxcontrast);
	}

	&__reverted-list {
		margin: 4px 0 0;
		padding-inline-start: 18px;
		display: flex;
		flex-direction: column;
		gap: 2px;

		code {
			font-family: var(--font-monospace, monospace);
			font-weight: 600;
		}
	}

	&__restart-note {
		display: flex;
		align-items: center;
		gap: 10px;
		width: 100%;
		text-align: start;
		padding: 10px 14px;
		border: 1px solid color-mix(in srgb, var(--dbd-severity-warning) 40%, var(--dbd-card-border));
		border-radius: var(--dbd-card-radius);
		background: color-mix(in srgb, var(--dbd-severity-warning) 12%, var(--dbd-card-bg));
		color: var(--color-main-text);
		font-size: 13px;
		font-family: inherit;
		cursor: pointer;
		transition: background 120ms ease, border-color 120ms ease;

		&:hover { background: color-mix(in srgb, var(--dbd-severity-warning) 18%, var(--dbd-card-bg)); }

		&:focus-visible {
			outline: none;
			box-shadow: 0 0 0 2px var(--color-primary-element);
		}

		&--active {
			border-color: var(--dbd-severity-warning);
			background: color-mix(in srgb, var(--dbd-severity-warning) 20%, var(--dbd-card-bg));
		}
	}

	&__restart-icon {
		flex-shrink: 0;
		color: var(--dbd-severity-warning-readable);
	}

	&__restart-action {
		margin-inline-start: auto;
		flex-shrink: 0;
		font-weight: 600;
		color: var(--color-primary-element);
		white-space: nowrap;
	}

	&__filters {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
		padding: 6px;
		background: var(--dbd-card-bg);
		border: 1px solid var(--dbd-card-border);
		border-radius: var(--dbd-card-radius);
	}

	&__filter {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		padding: 6px 12px;
		background: transparent;
		border: 1px solid transparent;
		border-radius: var(--border-radius-pill);
		font-size: 13px;
		font-weight: 600;
		color: var(--color-text-maxcontrast);
		cursor: pointer;
		transition: background 120ms ease, color 120ms ease, border-color 120ms ease;

		&:hover {
			background: var(--dbd-surface-sunken);
			color: var(--color-main-text);
		}

		&--active {
			background: var(--color-primary-element);
			color: var(--color-primary-element-text);
			border-color: var(--color-primary-element);
		}

		&--active:hover {
			background: var(--color-primary-element-hover, var(--color-primary-element));
			color: var(--color-primary-element-text);
		}
	}

	&__filter-count {
		display: inline-flex;
		min-width: 22px;
		justify-content: center;
		padding: 0 6px;
		background: color-mix(in srgb, currentColor 14%, transparent);
		border-radius: var(--border-radius-pill);
		font-size: 12px;
		font-weight: 600;
	}

	&__search {
		// Fixed-width wrapper: NcTextField's root fills 100%, so we
		// constrain it here and push the whole thing to the end of the
		// filter row.  flex: none keeps it from growing and wrapping to
		// its own line.
		flex: 0 0 200px;
		max-width: 100%;
		margin-inline-start: auto;

		// Trim NcTextField's default clickable-area height so it lines up
		// with the shorter filter buttons instead of towering over them.
		:deep(.input-field__input) {
			min-height: 34px;
			height: 34px;
		}

		:deep(.input-field__main-wrapper),
		:deep(.input-field__icon) {
			height: 34px;
			min-height: 34px;
		}
	}

	&__rules {
		display: flex;
		flex-direction: column;
		gap: 24px;
	}

	&__category {
		display: flex;
		flex-direction: column;
		gap: 10px;
	}

	&__category-header {
		display: flex;
		flex-wrap: wrap;
		align-items: baseline;
		justify-content: space-between;
		gap: 10px;
		padding-bottom: 4px;
		border-bottom: 1px solid var(--color-border);
	}

	&__category-title {
		margin: 0;
		font-size: 16px;
		font-weight: 700;
		color: var(--color-main-text);
	}

	&__category-meta {
		display: inline-flex;
		flex-wrap: wrap;
		gap: 6px;
	}

	&__category-pill {
		display: inline-block;
		padding: 2px 10px;
		border-radius: var(--border-radius-pill);
		font-size: 12px;
		font-weight: 600;

		&--fail    { background: color-mix(in srgb, var(--dbd-grade-f) 24%, transparent); color: var(--dbd-grade-f-readable); }
		&--ok      { background: color-mix(in srgb, var(--dbd-grade-a) 24%, transparent); color: var(--dbd-grade-a-readable); }
		&--skipped { background: var(--dbd-surface-sunken); color: var(--color-main-text); }
	}

	&__rules-list {
		display: flex;
		flex-direction: column;
		gap: 12px;
	}

	&__empty {
		margin: 16px 0 0;
		padding: 16px;
		text-align: center;
		color: var(--color-text-maxcontrast);
		font-size: 14px;
		background: var(--dbd-surface-sunken);
		border-radius: var(--dbd-card-radius);
	}
}

// Smooth fade for rule cards as they reshuffle on category change.
.dashboard-fade-enter-active,
.dashboard-fade-leave-active {
	transition: opacity 220ms ease, transform 220ms ease;
}
.dashboard-fade-enter-from,
.dashboard-fade-leave-to {
	opacity: 0;
	transform: translateY(-4px);
}
</style>
