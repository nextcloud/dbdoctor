<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		v-if="open"
		:name="t('dbdoctor', 'Apply database change?')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="apply-dialog">
			<p class="apply-dialog__rule">
				<strong>{{ rule.name }}</strong>
				<span class="apply-dialog__category">{{ rule.category }}</span>
			</p>

			<div class="apply-dialog__values">
				<div class="apply-dialog__col">
					<span class="apply-dialog__label">{{ t('dbdoctor', 'Variable') }}</span>
					<code class="apply-dialog__code">{{ rule.apply?.variable }}</code>
				</div>
				<div class="apply-dialog__col">
					<span class="apply-dialog__label">{{ t('dbdoctor', 'New value') }}</span>
					<code class="apply-dialog__code apply-dialog__code--accent">{{ rule.apply?.recommendedValue }}</code>
				</div>
			</div>

			<p v-if="rule.apply?.note" class="apply-dialog__note">
				⚠ {{ rule.apply.note }}
			</p>

			<p class="apply-dialog__caveat">
				{{ t('dbdoctor', 'This will issue SET GLOBAL / ALTER SYSTEM against the live server. The change is reversible by re-applying the previous value, but please verify your assumptions before proceeding.') }}
			</p>

			<div v-if="result" class="apply-dialog__result" :class="{ 'apply-dialog__result--error': !result.success }">
				<p v-if="result.success">
					✅ {{ t('dbdoctor', 'Applied. Old value: {old}. New value: {new}.', { old: result.oldValue ?? '?', new: result.newValue ?? '?' }) }}
				</p>
				<p v-else>
					❌ {{ result.error ?? t('dbdoctor', 'Apply failed.') }}
				</p>
			</div>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="onUpdateOpen(false)">
				{{ result?.success ? t('dbdoctor', 'Close') : t('dbdoctor', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!result?.success"
				variant="primary"
				:disabled="busy"
				@click="onApply">
				<template #icon>
					<NcLoadingIcon v-if="busy" :size="20" />
					<IconFlash v-else :size="20" />
				</template>
				{{ busy ? t('dbdoctor', 'Applying…') : t('dbdoctor', 'Apply') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup lang="ts">
import type { RuleResult } from '../api/types'

import { translate as t } from '@nextcloud/l10n'
import { ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import IconFlash from 'vue-material-design-icons/Flash.vue'

const props = defineProps<{
	open: boolean
	rule: RuleResult
}>()

const emit = defineEmits<{
	'update:open': [v: boolean]
	apply: [
		rule: RuleResult,
		// resolver lets us await the result inline so the dialog stays
		// open and renders the outcome instead of dismissing on click.
		resolve: (r: { success: boolean, oldValue: string | null, newValue: string | null, error?: string }) => void,
	]
}>()

const busy = ref(false)
const result = ref<{ success: boolean, oldValue: string | null, newValue: string | null, error?: string } | null>(null)

// Reset state when the dialog re-opens for a different rule.
watch(() => props.open, (next) => {
	if (next) {
		busy.value = false
		result.value = null
	}
})

function onApply(): void {
	busy.value = true
	emit('apply', props.rule, (r) => {
		busy.value = false
		result.value = r
	})
}

function onUpdateOpen(v: boolean): void {
	emit('update:open', v)
}
</script>

<style scoped lang="scss">
.apply-dialog {
	display: flex;
	flex-direction: column;
	gap: 14px;
	padding: 4px 0;

	&__rule {
		margin: 0;
		display: flex;
		align-items: baseline;
		gap: 12px;
		font-size: 16px;
	}

	&__category {
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}

	&__values {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 12px;
	}

	&__col {
		display: flex;
		flex-direction: column;
		gap: 4px;
		padding: 10px 12px;
		background: var(--dbd-surface-sunken);
		border-radius: var(--border-radius);
	}

	&__label {
		font-size: 11px;
		text-transform: uppercase;
		letter-spacing: 0.05em;
		color: var(--color-text-maxcontrast);
	}

	&__code {
		font-family: var(--font-face-monospace, monospace);
		font-size: 13px;
		font-weight: 600;

		&--accent {
			color: var(--color-primary-element);
		}
	}

	&__note {
		margin: 0;
		padding: 8px 12px;
		background: color-mix(in srgb, var(--dbd-severity-warning) 22%, transparent);
		border-radius: var(--border-radius);
		color: var(--color-main-text);
		font-size: 13px;
	}

	&__caveat {
		margin: 0;
		font-size: 13px;
		color: var(--color-text-maxcontrast);
	}

	&__result {
		padding: 10px 12px;
		background: color-mix(in srgb, var(--dbd-grade-a) 22%, transparent);
		color: var(--dbd-grade-a-readable);
		border-radius: var(--border-radius);

		&--error {
			background: color-mix(in srgb, var(--dbd-grade-f) 22%, transparent);
			color: var(--dbd-grade-f-readable);
		}

		p { margin: 0; }
	}
}
</style>
