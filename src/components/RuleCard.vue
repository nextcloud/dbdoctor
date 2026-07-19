<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<article
		ref="root"
		class="rule-card"
		:class="[
			`rule-card--${rule.status}`,
			`rule-card--severity-${rule.severity}`,
		]">
		<header class="rule-card__header">
			<span class="rule-card__dot" :title="rule.severity" />
			<div class="rule-card__title-block">
				<h3 class="rule-card__title">
					{{ rule.name }}
				</h3>
				<span class="rule-card__meta">
					{{ rule.category }}
					<span class="rule-card__sep">·</span>
					{{ severityLabel(rule.severity) }}
					<span class="rule-card__sep">·</span>
					<code class="rule-card__id">{{ rule.id }}</code>
				</span>
			</div>
			<span class="rule-card__status-chip" :class="`rule-card__status-chip--${rule.status}`">
				{{ statusLabel(rule.status) }}
			</span>
		</header>

		<!-- Issue: one-line description of what's wrong.  Only meaningful
			 when the rule is failing. -->
		<p v-if="rule.status === 'fail' && rule.issue" class="rule-card__issue">
			{{ rule.issue }}
		</p>

		<!-- Justification: interpolated detail with the actual value(s)
			 that triggered the rule. -->
		<p v-if="rule.justification" class="rule-card__justification">
			{{ rule.justification }}
		</p>
		<p v-else-if="rule.skipReason" class="rule-card__justification rule-card__justification--skipped">
			{{ rule.skipReason }}
		</p>

		<!-- Recommendation: one-line "how to fix". -->
		<p v-if="rule.status === 'fail' && rule.recommendation" class="rule-card__recommendation">
			{{ rule.recommendation }}
		</p>

		<!-- Per-rule details strip: numeric value and the suggested
			 config target.  Visible whenever the data exists, so the
			 overview is informative for passing rules too. -->
		<dl v-if="hasDetails" class="rule-card__details">
			<template v-if="rule.value !== null && rule.value !== undefined">
				<dt>{{ t('dbdoctor', 'Value') }}</dt>
				<dd>{{ formatValue(rule.value) }}</dd>
			</template>
			<template v-if="rule.apply">
				<dt>{{ t('dbdoctor', 'Setting') }}</dt>
				<dd>
					<code>{{ rule.apply.variable }}</code>
					<span class="rule-card__details-arrow">→</span>
					<code>{{ rule.apply.recommendedValue }}</code>
					<span v-if="rule.apply.runtimeWritable" class="rule-card__details-tag">
						{{ t('dbdoctor', 'runtime') }}
					</span>
					<span v-else class="rule-card__details-tag rule-card__details-tag--restart">
						{{ t('dbdoctor', 'restart required') }}
					</span>
				</dd>
			</template>
			<template v-if="rule.apply?.note">
				<dt>{{ t('dbdoctor', 'Note') }}</dt>
				<dd>{{ rule.apply.note }}</dd>
			</template>
		</dl>

		<footer v-if="rule.status === 'fail' || rule.docUrl" class="rule-card__actions">
			<NcButton
				v-if="rule.status === 'fail' && rule.apply && rule.apply.runtimeWritable"
				variant="primary"
				@click="$emit('apply', rule)">
				<template #icon>
					<IconFlash :size="20" />
				</template>
				{{ t('dbdoctor', 'Apply now') }}
			</NcButton>
			<NcButton
				v-if="rule.status === 'fail' && rule.apply"
				variant="secondary"
				@click="$emit('snippet', rule)">
				<template #icon>
					<IconCodeJson :size="20" />
				</template>
				{{ t('dbdoctor', 'Show config snippet') }}
			</NcButton>
			<a
				v-if="rule.docUrl"
				:href="rule.docUrl"
				class="rule-card__doc-link"
				target="_blank"
				rel="noreferrer noopener">
				<IconOpenInNew :size="16" />
				{{ t('dbdoctor', 'Learn more') }}
			</a>
		</footer>

		<Sparkline
			v-if="series && rule.status === 'fail'"
			class="rule-card__sparkline"
			:series="series" />
	</article>
</template>

<script setup lang="ts">
import type { RuleResult, RuleStatus, SeriesPoint, Severity } from '../api/types'

import { translate as t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import IconCodeJson from 'vue-material-design-icons/CodeJson.vue'
import IconFlash from 'vue-material-design-icons/Flash.vue'
import IconOpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Sparkline from './Sparkline.vue'
import { formatValue } from '../utils/formatters'

const props = defineProps<{
	rule: RuleResult
	series?: SeriesPoint[]
}>()

const emit = defineEmits<{
	apply: [rule: RuleResult]
	snippet: [rule: RuleResult]
	// Fired once, when a failing card first scrolls into view, so the
	// parent can lazily fetch this rule's history for the sparkline.
	// Lazy + one-shot keeps a 70-rule check from firing 70 requests at
	// mount time.
	requestSeries: [ruleId: string]
}>()

const root = ref<HTMLElement | null>(null)
let observer: IntersectionObserver | null = null
let requested = false

// Only failing cards render a sparkline, so only they need history.
// Emit once the card is on screen (or immediately when the browser
// has no IntersectionObserver), and never emit twice.  Lazy +
// one-shot keeps a 70-rule check from firing 70 requests at once.
function maybeObserveSeries(): void {
	if (requested || observer !== null) {
		return
	}
	if (props.rule.status !== 'fail' || props.series !== undefined) {
		return
	}
	const request = () => {
		requested = true
		emit('requestSeries', props.rule.id)
	}

	if (typeof IntersectionObserver === 'undefined' || root.value === null) {
		request()
		return
	}
	observer = new IntersectionObserver((entries) => {
		if (entries.some((e) => e.isIntersecting)) {
			request()
			observer?.disconnect()
			observer = null
		}
	}, { rootMargin: '100px' })
	observer.observe(root.value)
}

onMounted(maybeObserveSeries)

// The card component persists across re-runs (keyed by rule id), so a
// rule that only starts failing on a later run wouldn't re-trigger
// onMounted — watch the status so it still gets its sparkline.
watch(() => props.rule.status, maybeObserveSeries)

onBeforeUnmount(() => {
	observer?.disconnect()
	observer = null
})

const hasDetails = computed(() => {
	const r = props.rule
	return (r.value !== null && r.value !== undefined) || r.apply !== null
})

function severityLabel(s: Severity): string {
	return { alert: t('dbdoctor', 'Alert'), warning: t('dbdoctor', 'Warning'), notice: t('dbdoctor', 'Notice') }[s]
}

function statusLabel(s: RuleStatus): string {
	return {
		ok: t('dbdoctor', 'OK'),
		fail: t('dbdoctor', 'Action needed'),
		skipped: t('dbdoctor', 'Skipped'),
	}[s]
}
</script>

<style scoped lang="scss">
.rule-card {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 14px 16px;
	background: var(--dbd-card-bg);
	border: 1px solid var(--dbd-card-border);
	border-radius: var(--dbd-card-radius);
	transition: border-color 150ms ease, box-shadow 150ms ease;
	animation: dbd-fade-in 280ms ease-out both;

	&:hover {
		border-color: color-mix(in srgb, var(--color-primary-element) 35%, var(--dbd-card-border));
		box-shadow: var(--dbd-card-shadow);
	}

	// Failing rules get an emphasised left edge in their severity colour.
	&--fail.rule-card--severity-alert {
		border-inline-start: 4px solid var(--dbd-severity-alert);
		padding-inline-start: 12px;
	}
	&--fail.rule-card--severity-warning {
		border-inline-start: 4px solid var(--dbd-severity-warning);
		padding-inline-start: 12px;
	}
	&--fail.rule-card--severity-notice {
		border-inline-start: 4px solid var(--dbd-severity-notice);
		padding-inline-start: 12px;
	}

	// Passing and skipped rules are dimmer so failing ones stand out
	// at a glance.
	&--ok      { opacity: 0.7; }
	&--skipped { opacity: 0.55; }

	&__header {
		display: flex;
		align-items: center;
		gap: 12px;
	}

	&__dot {
		flex-shrink: 0;
		width: 10px;
		height: 10px;
		border-radius: 50%;
	}

	&--severity-alert &__dot   { background: var(--dbd-severity-alert); }
	&--severity-warning &__dot { background: var(--dbd-severity-warning); }
	&--severity-notice &__dot  { background: var(--dbd-severity-notice); }

	&__title-block {
		display: flex;
		flex-direction: column;
		gap: 2px;
		flex-grow: 1;
		min-width: 0;
	}

	&__title {
		margin: 0;
		font-size: 15px;
		font-weight: 600;
		line-height: 1.3;
	}

	&__meta {
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}

	&__sep {
		margin-inline: 4px;
	}

	&__status-chip {
		flex-shrink: 0;
		padding: 2px 10px;
		border-radius: var(--border-radius-pill);
		font-size: 12px;
		font-weight: 600;

		&--ok      { background: color-mix(in srgb, var(--dbd-grade-a) 26%, transparent); color: var(--dbd-grade-a-readable); }
		&--fail    { background: color-mix(in srgb, var(--dbd-grade-f) 26%, transparent); color: var(--dbd-grade-f-readable); }
		&--skipped { background: var(--dbd-surface-sunken); color: var(--color-main-text); }
	}

	&__id {
		font-family: var(--font-monospace, monospace);
		font-size: 11px;
		opacity: 0.85;
	}

	&__issue {
		margin: 0;
		font-size: 14px;
		font-weight: 600;
		color: var(--color-main-text);
	}

	&__justification {
		margin: 0;
		font-size: 14px;
		line-height: 1.45;
		color: var(--color-main-text);

		&--skipped {
			color: var(--color-text-maxcontrast);
			font-style: italic;
		}
	}

	&__recommendation {
		margin: 0;
		font-size: 13px;
		color: var(--color-text-maxcontrast);
		font-style: italic;
	}

	&__details {
		display: grid;
		grid-template-columns: max-content 1fr;
		gap: 4px 12px;
		margin: 4px 0 0;
		padding: 8px 10px;
		background: var(--dbd-surface-sunken);
		border-radius: var(--dbd-card-radius);
		font-size: 13px;

		dt {
			color: var(--color-text-maxcontrast);
			font-weight: 600;
			text-transform: uppercase;
			letter-spacing: 0.04em;
			font-size: 11px;
			align-self: center;
		}

		dd {
			margin: 0;
			color: var(--color-main-text);
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			gap: 6px;
			min-width: 0;
			word-break: break-word;
		}

		code {
			font-family: var(--font-monospace, monospace);
			padding: 1px 6px;
			background: var(--dbd-surface-sunken);
			border-radius: 4px;
			font-size: 12px;
		}
	}

	&__details-arrow {
		color: var(--color-text-maxcontrast);
	}

	&__details-tag {
		display: inline-block;
		padding: 1px 8px;
		border-radius: var(--border-radius-pill);
		font-size: 11px;
		font-weight: 600;
		background: color-mix(in srgb, var(--dbd-grade-a) 26%, transparent);
		color: var(--dbd-grade-a-readable);

		&--restart {
			background: color-mix(in srgb, var(--dbd-severity-warning) 26%, transparent);
			color: var(--dbd-severity-warning-readable);
		}
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
		margin-top: 4px;
	}

	&__doc-link {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		margin-inline-start: auto;
		padding: 4px 8px;
		font-size: 13px;
		font-weight: 600;
		color: var(--color-primary-element);
		text-decoration: none;
		border-radius: var(--border-radius);

		&:hover,
		&:focus-visible { text-decoration: underline; }
	}

	&__sparkline {
		margin-top: 4px;
	}
}
</style>
