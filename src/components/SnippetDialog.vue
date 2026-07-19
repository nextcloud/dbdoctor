<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog v-if="open"
		:name="t('dbdoctor', 'Configuration snippet')"
		:open="open"
		size="normal"
		@update:open="(v) => $emit('update:open', v)">
		<div class="snippet-dialog">
			<p class="snippet-dialog__intro">
				{{ t('dbdoctor', 'Add the following line to your {file}, then restart or reload the server.', { file: rule.apply?.configFile ?? '' }) }}
			</p>

			<pre class="snippet-dialog__code"><code>{{ snippet }}</code></pre>

			<NcButton type="primary" @click="copy">
				<template #icon>
					<IconCheck v-if="copied" :size="20" />
					<IconContentCopy v-else :size="20" />
				</template>
				{{ copied ? t('dbdoctor', 'Copied!') : t('dbdoctor', 'Copy to clipboard') }}
			</NcButton>

			<p v-if="rule.apply?.note" class="snippet-dialog__note">
				⚠ {{ rule.apply.note }}
			</p>
		</div>

		<template #actions>
			<NcButton type="tertiary" @click="$emit('update:open', false)">
				{{ t('dbdoctor', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'

import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import IconContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import IconCheck from 'vue-material-design-icons/Check.vue'

import type { RuleResult } from '../api/types'

const props = defineProps<{
	open: boolean
	rule: RuleResult
}>()

defineEmits<{ 'update:open': [v: boolean] }>()

const copied = ref(false)

const snippet = computed<string>(() => {
	const a = props.rule.apply
	if (!a) return ''
	// Postgres uses the `key = value` style under [postgresql];
	// MySQL/MariaDB use `key = value` under [mysqld].  We render
	// the section header so paste-into-config is foolproof.
	if (a.configFile === 'postgresql.conf') {
		return `# ${a.configFile}\n${a.configKey} = ${a.recommendedValue}`
	}
	return `# ${a.configFile}\n[mysqld]\n${a.configKey} = ${a.recommendedValue}`
})

async function copy(): Promise<void> {
	try {
		await navigator.clipboard.writeText(snippet.value)
		copied.value = true
		setTimeout(() => { copied.value = false }, 1800)
	} catch (e) {
		showError(t('dbdoctor', 'Could not copy to clipboard. Select the text manually instead.'))
	}
}
</script>

<style scoped lang="scss">
.snippet-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;

	&__intro {
		margin: 0;
		font-size: 14px;
		color: var(--color-main-text);
	}

	&__code {
		margin: 0;
		padding: 12px 16px;
		background: var(--dbd-surface-sunken);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius);
		font-family: var(--font-face-monospace, monospace);
		font-size: 13px;
		line-height: 1.5;
		white-space: pre;
		overflow-x: auto;
	}

	&__note {
		margin: 0;
		font-size: 13px;
		color: var(--color-text-maxcontrast);
	}
}
</style>
