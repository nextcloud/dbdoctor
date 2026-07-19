<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		v-if="open"
		:name="t('dbdoctor', 'DB Doctor settings')"
		:open="open"
		size="large"
		@update:open="(v) => $emit('update:open', v)">
		<NcLoadingIcon v-if="settingsStore.loading" :size="32" />

		<div v-else-if="settingsStore.settings" class="settings-dialog">
			<!-- ── Override credentials ──────────────────────────── -->
			<section class="settings-dialog__panel">
				<h2>{{ t('dbdoctor', 'Override database credentials') }}</h2>
				<p class="settings-dialog__panel-help">
					{{ t('dbdoctor', 'Optional. By default DB Doctor uses Nextcloud\'s database connection. If your Nextcloud database user lacks read privileges on certain status views, you can supply a separate read-only account here. The password is encrypted at rest using Nextcloud\'s credentials manager.') }}
				</p>

				<div class="settings-dialog__grid">
					<NcTextField
						v-model="form.host"
						:label="t('dbdoctor', 'Host')"
						placeholder="localhost" />
					<NcTextField
						v-model="form.portText"
						:label="t('dbdoctor', 'Port')"
						placeholder="3306"
						type="number" />
					<NcSelect
						v-model="form.driver"
						:options="driverOptions"
						:inputLabel="t('dbdoctor', 'Driver')" />
					<NcTextField
						v-model="form.user"
						:label="t('dbdoctor', 'User')"
						placeholder="dbdoctor" />
					<NcTextField
						v-model="form.database"
						:label="t('dbdoctor', 'Database')"
						placeholder="nextcloud" />
					<NcPasswordField
						v-model="form.password"
						:label="passwordLabel"
						:placeholder="passwordPlaceholder" />
				</div>

				<div class="settings-dialog__actions">
					<NcButton variant="secondary" :disabled="testing" @click="onTest">
						<template #icon>
							<NcLoadingIcon v-if="testing" :size="20" />
							<IconConnection v-else :size="20" />
						</template>
						{{ t('dbdoctor', 'Test connection') }}
					</NcButton>
					<NcButton variant="primary" :disabled="saving" @click="onSaveOverride">
						<template #icon>
							<NcLoadingIcon v-if="saving" :size="20" />
							<IconCheck v-else :size="20" />
						</template>
						{{ t('dbdoctor', 'Save') }}
					</NcButton>
					<NcButton
						v-if="settingsStore.settings.override.passwordSet"
						variant="tertiary"
						@click="onClearPassword">
						{{ t('dbdoctor', 'Forget stored password') }}
					</NcButton>
				</div>

				<p v-if="testResult" class="settings-dialog__test-result" :class="{ 'settings-dialog__test-result--error': !testResult.ok }">
					{{ testResult.message }}
				</p>
			</section>

			<!-- ── Audit log ─────────────────────────────────────── -->
			<section class="settings-dialog__panel">
				<h2>{{ t('dbdoctor', 'Recent applications') }}</h2>
				<p v-if="settingsStore.audit.length === 0" class="settings-dialog__panel-help">
					{{ t('dbdoctor', 'No changes have been applied yet. When you click "Apply now" on the dashboard, every change is recorded here.') }}
				</p>
				<table v-else class="settings-dialog__table">
					<thead>
						<tr>
							<th>{{ t('dbdoctor', 'When') }}</th>
							<th>{{ t('dbdoctor', 'Who') }}</th>
							<th>{{ t('dbdoctor', 'Variable') }}</th>
							<th>{{ t('dbdoctor', 'Old value') }}</th>
							<th>{{ t('dbdoctor', 'New value') }}</th>
							<th>{{ t('dbdoctor', 'Result') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="a in settingsStore.audit" :key="a.id">
							<td>{{ new Date(a.appliedAt * 1000).toLocaleString() }}</td>
							<td>{{ a.actorUid }}</td>
							<td><code>{{ a.variable }}</code></td>
							<td><code>{{ a.oldValue ?? '—' }}</code></td>
							<td><code>{{ a.newValue ?? '—' }}</code></td>
							<td>
								<span v-if="a.success" class="settings-dialog__chip settings-dialog__chip--ok">✓</span>
								<span v-else class="settings-dialog__chip settings-dialog__chip--error" :title="a.error ?? ''">✗</span>
							</td>
						</tr>
					</tbody>
				</table>
			</section>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="$emit('update:open', false)">
				{{ t('dbdoctor', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { onMounted, reactive, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconCheck from 'vue-material-design-icons/Check.vue'
import IconConnection from 'vue-material-design-icons/LanConnect.vue'
import { testConnection } from '../api/client'
import { useSettingsStore } from '../stores/settings'

import '@nextcloud/password-confirmation/style.css'

const props = defineProps<{ open: boolean }>()

defineEmits<{ 'update:open': [v: boolean] }>()

const settingsStore = useSettingsStore()

const form = reactive({
	host: '',
	portText: '',
	user: '',
	database: '',
	driver: { id: 'pdo_mysql', label: 'MySQL / MariaDB' } as { id: string, label: string },
	password: '',
})

const driverOptions = [
	{ id: 'pdo_mysql', label: 'MySQL / MariaDB' },
	{ id: 'pdo_pgsql', label: 'PostgreSQL' },
]

const passwordLabel = ref('')
const passwordPlaceholder = ref('')

const testing = ref(false)
const saving = ref(false)
const testResult = ref<{ ok: boolean, message: string } | null>(null)

function syncForm(): void {
	const s = settingsStore.settings
	if (!s) { return }
	form.host = s.override.host
	form.portText = s.override.port > 0 ? String(s.override.port) : ''
	form.user = s.override.user
	form.database = s.override.database
	form.driver = driverOptions.find((d) => d.id === s.override.driver) ?? driverOptions[0]
	form.password = ''
	passwordLabel.value = s.override.passwordSet
		? t('dbdoctor', 'Password (leave empty to keep the saved value)')
		: t('dbdoctor', 'Password')
	passwordPlaceholder.value = s.override.passwordSet ? '••••••••' : ''
}

async function onTest(): Promise<void> {
	testing.value = true
	testResult.value = null
	try {
		// The endpoint dials out with the supplied credentials and is
		// guarded by PasswordConfirmationRequired server-side.
		await confirmPassword()
		testResult.value = await testConnection({
			host: form.host,
			port: form.portText === '' ? 0 : Number(form.portText),
			user: form.user,
			password: form.password,
			database: form.database,
			driver: form.driver.id as 'pdo_mysql' | 'pdo_pgsql',
		})
	} catch (e) {
		testResult.value = { ok: false, message: (e as Error).message ?? 'Connection failed.' }
	} finally {
		testing.value = false
	}
}

async function onSaveOverride(): Promise<void> {
	saving.value = true
	try {
		// update() stores connection credentials and is guarded by
		// PasswordConfirmationRequired server-side.
		await confirmPassword()
		await settingsStore.update({
			host: form.host,
			port: form.portText === '' ? 0 : Number(form.portText),
			user: form.user,
			database: form.database,
			driver: form.driver.id as 'pdo_mysql' | 'pdo_pgsql',
			// Only send the password when the user typed something — otherwise
			// the saved value is preserved server-side.
			...(form.password !== '' ? { password: form.password } : {}),
		})
		showSuccess(t('dbdoctor', 'Saved.'))
		syncForm()
	} catch (e) {
		showError((e as Error).message ?? t('dbdoctor', 'Could not save.'))
	} finally {
		saving.value = false
	}
}

async function onClearPassword(): Promise<void> {
	saving.value = true
	try {
		await confirmPassword()
		await settingsStore.update({ clearPassword: true })
		showSuccess(t('dbdoctor', 'Stored password cleared.'))
		syncForm()
	} catch (e) {
		showError((e as Error).message ?? '')
	} finally {
		saving.value = false
	}
}

// Load settings the first time the dialog opens. Re-syncing the form
// when the store finishes loading keeps the inputs populated.
watch(
	() => props.open,
	async (isOpen) => {
		if (!isOpen) { return }
		if (settingsStore.settings === null) {
			await settingsStore.load()
		}
		syncForm()
	},
)

onMounted(() => {
	if (props.open && settingsStore.settings === null) {
		void settingsStore.load().then(syncForm)
	}
})
</script>

<style scoped lang="scss">
.settings-dialog {
	display: flex;
	flex-direction: column;
	gap: 18px;

	&__panel {
		display: flex;
		flex-direction: column;
		gap: 12px;
		padding: 20px 24px;
		background: var(--dbd-card-bg);
		border: 1px solid var(--dbd-card-border);
		border-radius: var(--dbd-card-radius);

		h2 {
			margin: 0;
			font-size: 16px;
			font-weight: 600;
		}
	}

	&__panel-help {
		margin: 0;
		font-size: 13px;
		color: var(--color-text-maxcontrast);
		line-height: 1.5;
	}

	&__grid {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 12px;

		@media (max-width: 720px) {
			grid-template-columns: 1fr;
		}
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		margin-top: 4px;
	}

	&__row {
		display: flex;
		align-items: center;
		gap: 12px;
	}

	&__test-result {
		margin: 0;
		padding: 10px 12px;
		border-radius: var(--border-radius);
		background: color-mix(in srgb, var(--dbd-grade-a) 22%, transparent);
		color: var(--dbd-grade-a-readable);

		&--error {
			background: color-mix(in srgb, var(--dbd-grade-f) 22%, transparent);
			color: var(--dbd-grade-f-readable);
		}
	}

	&__table {
		width: 100%;
		border-collapse: collapse;
		font-size: 13px;

		th, td {
			text-align: start;
			padding: 8px 10px;
			border-bottom: 1px solid var(--color-border);
		}

		th {
			font-weight: 600;
			color: var(--color-text-maxcontrast);
			text-transform: uppercase;
			font-size: 11px;
			letter-spacing: 0.05em;
		}

		code { font-family: var(--font-face-monospace, monospace); }
	}

	&__chip {
		display: inline-block;
		min-width: 20px;
		text-align: center;
		padding: 2px 6px;
		border-radius: var(--border-radius-pill);
		font-weight: 700;
		font-size: 12px;

		&--ok    { background: color-mix(in srgb, var(--dbd-grade-a) 26%, transparent); color: var(--dbd-grade-a-readable); }
		&--error { background: color-mix(in srgb, var(--dbd-grade-f) 26%, transparent); color: var(--dbd-grade-f-readable); }
	}
}
</style>
