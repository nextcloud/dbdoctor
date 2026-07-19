<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcEmptyContent
		class="dbd-empty"
		:name="title"
		:description="description">
		<template #icon>
			<DoctorMascot :state="mascotState" />
		</template>
		<template v-if="cta" #action>
			<NcButton variant="primary" @click="$emit('action')">
				<template #icon>
					<IconHeartPulse :size="20" />
				</template>
				{{ cta }}
			</NcButton>
		</template>
	</NcEmptyContent>
</template>

<script setup lang="ts">
import type { MascotState } from '../utils/grade'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import IconHeartPulse from 'vue-material-design-icons/HeartPulse.vue'
import DoctorMascot from './DoctorMascot.vue'

defineProps<{
	title: string
	description: string
	mascotState?: MascotState
	cta?: string | null
}>()

defineEmits<{ action: [] }>()
</script>

<style scoped lang="scss">
.dbd-empty {
	margin: 24px 0;
}
</style>
