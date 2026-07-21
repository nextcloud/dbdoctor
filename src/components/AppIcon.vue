<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- The app mark ("patient monitor": bedside monitor with a
		 heartbeat trace on screen), same drawing as img/app.svg so the
		 navigation icon and the in-app branding stay in sync.  Tinted
		 through currentColor like the material-design icon it replaced. -->
	<span class="app-icon" role="img" aria-hidden="true">
		<svg
			:width="size"
			:height="size"
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			stroke-width="1.8"
			stroke-linecap="round"
			stroke-linejoin="round">
			<rect
				x="3.5"
				y="5"
				width="17"
				height="12"
				rx="2" />
			<path d="M9.5 20.5h5M12 17v3.5" />
			<polyline points="6.5 11 8.3 11 9.6 8.6 11.6 13.6 13 10.2 13.9 11 17.5 11" />
		</svg>
	</span>
</template>

<script setup lang="ts">
withDefaults(defineProps<{ size?: number }>(), { size: 24 })
</script>

<style scoped>
.app-icon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
}
</style>
