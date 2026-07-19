<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="category-tabs" role="tablist">
		<button
			v-for="cat in categories"
			:key="cat.id"
			type="button"
			role="tab"
			class="category-tabs__tab"
			:class="{ 'category-tabs__tab--active': cat.id === modelValue }"
			:aria-selected="cat.id === modelValue ? 'true' : 'false'"
			@click="$emit('update:modelValue', cat.id)">
			<span class="category-tabs__label">{{ cat.label }}</span>
			<span
				v-if="cat.failCount > 0"
				class="category-tabs__badge"
				:title="t('dbdoctor', '{n} failing', { n: cat.failCount })">
				{{ cat.failCount }}
			</span>
		</button>
	</div>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'

defineProps<{
	modelValue: string
	categories: { id: string, label: string, failCount: number }[]
}>()

defineEmits<{
	'update:modelValue': [id: string]
}>()
</script>

<style scoped lang="scss">
.category-tabs {
	display: flex;
	gap: 4px;
	flex-wrap: wrap;
	padding: 4px;
	background: var(--dbd-surface-sunken);
	border-radius: var(--border-radius-large);

	&__tab {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		padding: 6px 14px;
		border: none;
		background: transparent;
		border-radius: var(--border-radius);
		color: var(--color-text-maxcontrast);
		font-size: 14px;
		font-weight: 500;
		cursor: pointer;
		transition: background-color 120ms ease, color 120ms ease;

		&:hover, &:focus-visible {
			background: var(--color-main-background);
			color: var(--color-main-text);
		}

		&--active {
			background: var(--color-main-background);
			color: var(--color-main-text);
			box-shadow: var(--dbd-card-shadow);
		}
	}

	&__badge {
		min-width: 20px;
		height: 20px;
		padding: 0 6px;
		border-radius: var(--border-radius-pill);
		background: var(--dbd-grade-f);
		color: var(--color-primary-element-text, #fff);
		font-size: 11px;
		font-weight: 700;
		display: inline-flex;
		align-items: center;
		justify-content: center;
	}

	&__label {
		white-space: nowrap;
	}
}
</style>
