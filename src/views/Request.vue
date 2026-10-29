<!--
  - SPDX-FileCopyrightText: 2024 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="container">
		<div id="container-request">
			<header>
				<h1>{{ t('libresign', 'Request Signatures') }}</h1>
				<p v-if="!sidebarStore.isVisible">
					{{ t('libresign', 'Choose the file to request signatures.') }}
				</p>
			</header>
			<div class="content-request">
				<File v-show="!!filesStore.selectedFileId"
					status="0"
					status-text="none" />
				<aside v-if="!filesStore.selectedFileId && !sidebarStore.isVisible" class="letterhead-shortcut">
 <p>{{ t('libresign', 'Need a letterhead? Start with a shared letterhead before requesting signatures.') }}</p>
 <NcButton :to="{name:'letterheads',query:{new:'1'}}" variant="secondary">{{ t('libresign', 'Use a Letterhead') }}</NcButton>
</aside>
<ReqestPicker v-if="!sidebarStore.isVisible"
					:inline="true" />
			</div>
		</div>
	</div>
</template>
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { onBeforeUnmount, onMounted } from 'vue'

import NcButton from '@nextcloud/vue/components/NcButton'

import File from '../components/File/File.vue'
import ReqestPicker from '../components/Request/RequestPicker.vue'

import { useFilesStore } from '../store/files.js'
import { useSidebarStore } from '../store/sidebar.js'

defineOptions({
	name: 'Request',
})

type FilesStore = {
	selectedFileId: number
	disableIdentifySigner: () => void
	selectFile: () => void
}

type SidebarStore = {
	isVisible: boolean
}

const filesStore = useFilesStore() as FilesStore
const sidebarStore = useSidebarStore() as SidebarStore

onMounted(() => {
	filesStore.disableIdentifySigner()
})

onBeforeUnmount(() => {
	filesStore.selectFile()
})

defineExpose({
	filesStore,
	sidebarStore,
})
</script>

<style lang="scss" scoped>
.letterhead-shortcut { margin-bottom:24px; padding:18px; border:1px solid var(--color-border); border-radius:12px; background:var(--color-primary-element-light); }
.letterhead-shortcut p { margin-bottom:12px; line-height:1.6; }
.container{
	display: flex;
	flex-direction: row;
	justify-content: center;
	align-items: center;
	width: 100%;
	height: 100%;
}

#container-request {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	width: 500px;
	max-width: 100%;
	text-align: center;

	header {
		margin-bottom: 2.5rem;

		h1 {
			font-size: 45px;
			margin-bottom: 1rem;
		}

		p {
			font-size: 15px;
		}
	}

	.content-request{
		display: flex;
		gap: 12px; flex: 1;
		flex-direction: column;
	}
}
</style>
