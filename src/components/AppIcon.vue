<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- The app mark ("temperature taken": database cylinder with a
		 thermometer), same drawing as img/app.svg so the navigation
		 icon and the in-app branding stay in sync.  Tinted through
		 currentColor like the material-design icon it replaced. -->
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
			<ellipse
				cx="9.5"
				cy="6.8"
				rx="5"
				ry="2.1" />
			<path d="M4.5 6.8V17c0 1.16 2.24 2.1 5 2.1s5-.94 5-2.1V6.8" />
			<path d="M18.2 13.4V4.6a1.6 1.6 0 0 1 3.2 0v8.8a3.1 3.1 0 1 1-3.2 0z" />
			<path d="M19.8 8.5v5" stroke-width="1.4" />
			<circle
				cx="19.8"
				cy="16.1"
				r="1.2"
				fill="currentColor"
				stroke="none" />
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
