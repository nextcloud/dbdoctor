<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="dbdoctor-app">
		<RouterView />
	</div>
</template>

<script setup lang="ts">
import { RouterView } from 'vue-router'
</script>

<style lang="scss">
// Top-level layout.  Scoped styles live on each view; here we just
// give the shell some breathing room and ensure the embedded admin
// panel looks intentional.
.dbdoctor-app {
	display: flex;
	flex-direction: column;
	gap: 24px;
	max-width: 1100px;
	margin: 0 auto;
	padding: 16px;
	// The settings page floats its navigation toggle over the top-left
	// corner of the content area (a clickable-area-sized button plus
	// the navigation padding).  Clear it vertically — the same way
	// native settings sections do via their heading margins — so the
	// DBDoctor header never sits underneath the button.
	padding-block-start: calc(var(--default-clickable-area, 44px) + var(--app-navigation-padding, 4px) * 2);
}
</style>
