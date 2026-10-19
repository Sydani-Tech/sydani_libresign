<!--
  - SPDX-FileCopyrightText: 2024 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcAppSidebar v-if="sidebarStore.activeTab.length > 0"
		ref="rightAppSidebar"
		v-model:open="opened"
		:name="fileName"
		:subtitle="subTitle"
		v-model:active="sidebarStore.activeTab"
		@update:active="handleUpdateActive"
		@close="closeSidebar">
		<NcAppSidebarTab v-if="showSign"
			id="sign-tab"
			:name="fileName">
			<SignTab />
		</NcAppSidebarTab>
		<NcAppSidebarTab v-if="showRequestSignatureTab"
			id="request-signature-tab"
			:name="fileName">
			<RequestSignatureTab />
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'

import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcAppSidebarTab from '@nextcloud/vue/components/NcAppSidebarTab'

import RequestSignatureTab from '../RightSidebar/RequestSignatureTab.vue'
import SignTab from '../RightSidebar/SignTab.vue'

import { useFilesStore } from '../../store/files.js'
import { useSidebarStore } from '../../store/sidebar.js'
import { useSignStore } from '../../store/sign.js'

defineOptions({
	name: 'RightSidebar',
})

type SidebarRef = {
	$refs?: {
		tabs?: {
			activeTab?: string
		}
	}
}

const filesStore = useFilesStore()
const signStore = useSignStore()
const sidebarStore = useSidebarStore()
const route = useRoute()
const rightAppSidebar = ref<SidebarRef | null>(null)

const fileName = computed(() => filesStore.getSelectedFileView()?.name ?? '')
const opened = computed({
	get: () => sidebarStore.isVisible,
	set: (isOpen: boolean) => {
		if (isOpen) {
			sidebarStore.showSidebar()
		} else {
			sidebarStore.hideSidebar()
		}
	},
})
const subTitle = computed(() => {
	if (!opened.value) {
		return t('libresign', 'Enter who will receive the request')
	}
	return filesStore.getSubtitle()
})

const showRequestSignatureTab = computed(() => sidebarStore.activeTab === 'request-signature-tab')
const showSign = computed(() => sidebarStore.activeTab === 'sign-tab' && signStore.document !== undefined)

watch(() => sidebarStore.activeTab, (newValue) => {
	if (rightAppSidebar.value?.$refs?.tabs) {
		rightAppSidebar.value.$refs.tabs.activeTab = newValue
	}
})

watch(() => route.name, (routeName) => {
	sidebarStore.handleRouteChange(routeName)
})

function handleUpdateActive(active: string) {
	sidebarStore.setActiveTab(active)
}

function closeSidebar() {
	sidebarStore.hideSidebar()
}

defineExpose({
	filesStore,
	signStore,
	sidebarStore,
	rightAppSidebar,
	fileName,
	opened,
	subTitle,
	showRequestSignatureTab,
	showSign,
	handleUpdateActive,
	closeSidebar,
})
</script>
<style lang="scss" scoped>
.app-sidebar__tab  {
	box-shadow: none !important;
}

@media (max-width: 512px) {
	.app-sidebar {
		// Keep the scrollport inside the app, below Nextcloud's header. An
		// auto-height, bottom-anchored sidebar can grow above its scroll origin.
		top: 0;
		bottom: 0;
		inset-inline: 0;
		height: 100%;
		max-height: 100%;
		min-height: 0;
		overflow-y: auto;
		overscroll-behavior-y: contain;
		padding-bottom: env(safe-area-inset-bottom, 0px);
		box-sizing: border-box;
		:deep(.app-sidebar-tabs) {
			flex: 0 0 auto;
		}
		:deep(.app-sidebar-tabs__content) {
			height: auto;
			min-height: 0;
		}
		transform: none !important;
	}
}
</style>
