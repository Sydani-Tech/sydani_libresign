<!-- SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
     SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcModal :name="t('libresign', 'Campaign field layout')" size="full" @close="emit('close')">
		<div class="campaign-designer">
			<aside class="campaign-designer__toolbar">
				<h2>{{ t('libresign', 'Place fields once') }}</h2>
				<p>{{ t('libresign', 'This layout is copied to every recipient’s own document.') }}</p>
				<label>{{ t('libresign', 'Assign fields to') }}
					<select v-model="selectedRole" @change="cancelAdding"><option value="recipient">{{ t('libresign', 'Recipient') }}</option><option v-if="counter" value="counter">{{ t('libresign', 'Countersigner') }} — {{ counter.name }}</option></select>
				</label>
				<div class="campaign-designer__palette">
					<button v-for="field in palette" :key="field.type" type="button" draggable="true" :disabled="!ready || saving" :aria-pressed="adding === field.type"
						@click="add(field.type)" @dragstart="drag($event, field.type)">{{ field.label }}</button>
				</div>
				<p class="campaign-designer__hint">{{ t('libresign', 'Choose a field, then tap or click the document. Move and resize it with its handles. Select text, date or checkbox fields to change their label and Required setting.') }}</p>
				<NcButton v-if="adding" @click="cancelAdding">{{ t('libresign', 'Cancel placement') }}</NcButton>
				<p v-if="error" role="alert">{{ error }}</p>
				<div class="campaign-designer__actions"><NcButton variant="primary" :disabled="!ready || saving || !!adding" @click="save">{{ t('libresign', 'Save layout') }}</NcButton><NcButton :disabled="saving" @click="emit('close')">{{ t('libresign', 'Close') }}</NcButton></div>
			</aside>
			<div v-if="!pdf" class="campaign-designer__loading" role="status">{{ t('libresign', 'Loading PDF…') }}</div>
			<PdfEditor v-else ref="editor" :files="[pdf]" :file-names="[sourceName]" :signers="roles" @pdf-editor:end-init="initialiseFields" @pdf-editor:adding-ended="adding = ''" />
		</div>
	</NcModal>
</template>
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import PdfEditor from '../PdfEditor/PdfEditor.vue'
import { campaignUrl, campaignError, type CampaignField, type CampaignRecipient } from '../../services/campaigns'
import type { SignerSummaryRecord, VisibleElementRecord } from '../../types/index'

const props = defineProps<{campaignId: number, sourceName: string, fields: CampaignField[], counter: CampaignRecipient | null, saving?: boolean}>()
const emit = defineEmits<{(event: 'save', fields: CampaignField[]): void, (event: 'close'): void}>()
type PdfObject = {type: CampaignField['type'], pageNumber: number, x: number, y: number, width: number, height: number, signer?: {signRequestId: number}, metadata?: CampaignField['metadata']}
type EditorApi = {startAddingSigner: (signer: SignerSummaryRecord, size: {width: number, height: number, type: string}) => boolean, cancelAdding: () => void,
	addSigner: (signer: SignerSummaryRecord, field: VisibleElementRecord) => Promise<void>, pdfElements?: {getAllObjects: (index: number) => PdfObject[]}}
const editor = ref<EditorApi | null>(null)
const pdf = ref<Blob | null>(null)
const error = ref('')
const ready = ref(false)
const adding = ref('')
const selectedRole = ref<'recipient' | 'counter'>('recipient')
let initialising = false
const palette = [ {type: 'signature', label: t('libresign', 'Signature')}, {type: 'text', label: t('libresign', 'Text')}, {type: 'date', label: t('libresign', 'Date')}, {type: 'checkbox', label: t('libresign', 'Checkbox')} ]
const roles = computed(() => [ {signRequestId: 1, displayName: t('libresign', 'Recipient')}, ...(props.counter ? [{signRequestId: 2, displayName: t('libresign', 'Countersigner')}] : []) ] as SignerSummaryRecord[])
async function initialiseFields() {
	if (ready.value || initialising) return
	initialising = true
	try {
		for (const [index, field] of props.fields.entries()) {
			const signer = roles.value[field.role === 'counter' ? 1 : 0]
			if (!signer) continue
			await editor.value?.addSigner(signer, {elementId: -index - 1, fileId: 0, signRequestId: signer.signRequestId, type: field.type, coordinates: field.coordinates, metadata: field.metadata} as VisibleElementRecord)
		}
		ready.value = true
	} catch { error.value = t('libresign', 'Unable to restore this layout. Close and reopen the editor.'); }
	finally { initialising = false }
}
function add(type: string) {
	const sizes = type === 'signature' ? {width: 180, height: 60} : type === 'checkbox' ? {width: 20, height: 20} : {width: type === 'date' ? 120 : 180, height: 28}
	if (editor.value?.startAddingSigner(roles.value[selectedRole.value === 'counter' ? 1 : 0], {...sizes, type})) adding.value = type
}
function cancelAdding() { editor.value?.cancelAdding(); adding.value = '' }
function drag(event: DragEvent, type: string) { add(type); event.dataTransfer?.setData('application/x-libresign-field', type) }
function save() {
	const fields = (editor.value?.pdfElements?.getAllObjects(0) || []).map(object => ({
		role: object.signer?.signRequestId === 2 ? 'counter' : 'recipient', type: object.type,
		coordinates: {page: object.pageNumber, left: Math.round(object.x * 100) / 100, top: Math.round(object.y * 100) / 100, width: Math.round(object.width * 100) / 100, height: Math.round(object.height * 100) / 100},
		metadata: object.metadata ?? {label: '', required: true},
	})) as CampaignField[]
	emit('save', fields)
}
onMounted(async () => {
	try { const response = await axios.get(campaignUrl(`campaigns/${props.campaignId}/source`), {responseType: 'blob'}); pdf.value = response.data }
	catch (e) { error.value = campaignError(e) }
})
</script>
<style scoped lang="scss">
.campaign-designer { display: flex; height: 100%; min-height: 0; overflow: hidden; }
.campaign-designer__toolbar { width: 280px; flex: 0 0 280px; padding: 16px; overflow-y: auto; box-sizing: border-box; }
.campaign-designer__toolbar h2 { margin-bottom: 8px; }
.campaign-designer__toolbar label { display: flex; flex-direction: column; gap: 6px; margin-block: 12px; }
.campaign-designer__toolbar select { width: 100%; min-height: 44px; }
.campaign-designer__palette { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.campaign-designer__palette button { min-height: 44px; margin: 0; background: var(--color-primary-element-light); color: var(--color-main-text); border: 1px solid var(--color-border); }
.campaign-designer__palette button[aria-pressed=true] { outline: 2px solid var(--color-primary-element); }
.campaign-designer__hint { margin-block: 12px; color: var(--color-text-maxcontrast); }
.campaign-designer__actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
.campaign-designer > :deep(.pdf-editor) { flex: 1 1 0; min-height: 0; min-width: 0; height: auto; }
.campaign-designer__loading { padding: 24px; }
@media (max-width: 767px) {
	.campaign-designer { flex-direction: column; }
	.campaign-designer__toolbar { width: 100%; flex: 0 0 auto; padding: 8px 12px; max-height: 45%; }
	.campaign-designer__toolbar h2, .campaign-designer__toolbar > p:not([role=alert]), .campaign-designer__hint { display: none; }
	.campaign-designer__toolbar label { margin-block: 4px; }
	.campaign-designer__palette { grid-template-columns: repeat(4, 1fr); gap: 4px; }
	.campaign-designer__actions { margin-top: 6px; }
}
</style>
