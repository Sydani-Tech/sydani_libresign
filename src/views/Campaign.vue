<!-- SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
     SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<section class="campaign-page">
		<header class="campaign-page__header"><div><h2>{{ t('libresign', 'Campaign') }}</h2><p>{{ t('libresign', 'Prepare once. Send individual copies. Track every recipient.') }}</p></div><div class="campaign-actions"><NcButton v-if="selected || creating" :disabled="busy" @click="back">{{ t('libresign', 'All campaigns') }}</NcButton><NcButton v-if="canCreate && !selected && !creating" variant="primary" @click="newCampaign">{{ t('libresign', 'New campaign') }}</NcButton><NcButton :disabled="busy" @click="refresh">{{ t('libresign', 'Refresh') }}</NcButton></div></header>
		<div v-if="error" class="campaign-error campaign-error--banner" role="alert"><strong>{{ t('libresign', 'Please check the following') }}</strong><p>{{ error }}</p></div><p v-if="notice" class="campaign-notice" role="status">{{ notice }}</p>
		<p v-if="!initialised" role="status">{{ t('libresign', 'Loading campaigns…') }}</p>
		<p v-else-if="!canCreate" role="alert">{{ t('libresign', 'You do not have campaign access. An administrator can authorise your group in LibreSign settings.') }}</p>
		<template v-else>
			<template v-if="!selected && !creating">
                <form class="campaign-filters" @submit.prevent="applyFilters"><p class="campaign-filters__scope">{{ organisationScope ? t('libresign', 'Showing campaigns from all senders in the organisation') : t('libresign', 'Showing campaigns you created') }}</p>
                <div class="campaign-filter-grid"><label>{{ t('libresign','Campaign name') }}<input v-model="filters.name" type="search"></label><label>{{ t('libresign','Document version') }}<input v-model="filters.version" type="search"></label><SenderSelect v-model="filters.sender" :options="senders" /><label>{{ t('libresign','Status') }}<select v-model="filters.status"><option value="">{{ t('libresign','All statuses') }}</option><option value="draft">{{ t('libresign','Draft') }}</option><option value="active">{{ t('libresign','Active · incomplete') }}</option><option value="completed">{{ t('libresign','Completed') }}</option><option value="cancelled">{{ t('libresign','Cancelled') }}</option><option value="overdue">{{ t('libresign','Overdue') }}</option></select></label>
                <label>{{ t('libresign','Recipient Name') }}<input v-model="filters.recipientName" type="search"></label><label>{{ t('libresign','Recipient Email') }}<input v-model="filters.recipientEmail" type="search"></label><label>{{ t('libresign','Recipient type') }}<select v-model="filters.kind"><option value="">{{ t('libresign','Internal and external') }}</option><option value="internal">{{ t('libresign','Internal') }}</option><option value="external">{{ t('libresign','External') }}</option></select></label>
                <label>{{ t('libresign','Date filter') }}<select v-model="filters.dateBy"><option value="created">{{ t('libresign','Date Created') }}</option><option value="requested">{{ t('libresign','Date Requested') }}</option><option value="completed">{{ t('libresign','Date Completed') }}</option><option value="deadline">{{ t('libresign','Deadline') }}</option></select></label><label>{{ t('libresign','From') }}<input v-model="filters.from" type="date"></label><label>{{ t('libresign','To') }}<input v-model="filters.to" type="date"></label><label>{{ t('libresign','Sort by') }}<select v-model="filters.sortBy"><option value="created">{{ t('libresign','Date Created') }}</option><option value="requested">{{ t('libresign','Date Requested') }}</option><option value="completed">{{ t('libresign','Date Completed') }}</option><option value="deadline">{{ t('libresign','Deadline') }}</option><option value="name">{{ t('libresign','Campaign name') }}</option></select></label><label>{{ t('libresign','Order') }}<select v-model="filters.direction"><option value="desc">{{ t('libresign','Descending') }}</option><option value="asc">{{ t('libresign','Ascending') }}</option></select></label></div>
                <div class="campaign-actions"><NcButton type="submit" variant="primary" :disabled="busy">{{ t('libresign','Apply filters') }}</NcButton><NcButton :disabled="busy" @click="resetFilters">{{ t('libresign','Reset') }}</NcButton></div></form>
				<div class="campaign-table-scroll"><table><thead><tr><th>{{ t('libresign', 'Campaign') }}</th><th>{{ t('libresign', 'Sender') }}</th><th>{{ t('libresign', 'Status') }}</th><th>{{ t('libresign', 'Completion') }}</th><th>{{ t('libresign', 'Outstanding') }}</th><th>{{ t('libresign', 'Date Requested') }}</th><th>{{ t('libresign', 'Deadline') }}</th></tr></thead><tbody>
					<tr v-for="campaign in campaigns" :key="campaign.id"><td><button class="campaign-link" type="button" @click="open(campaign.id)">{{ campaign.name }}</button><small>{{ t('libresign', 'Version') }} {{ campaign.version }}</small></td><td>{{ campaign.owner.name }}</td><td>{{ campaignState(campaign) }}</td><td><progress :value="campaign.summary.completed" :max="Math.max(1, campaign.summary.required)" :aria-label="t('libresign', 'Completion rate')" /><small>{{ campaign.summary.completed }} / {{ campaign.summary.required }} · {{ campaign.summary.completionRate }}%</small></td><td>{{ campaign.summary.pending }}<small v-if="campaign.summary.overdue">{{ campaign.summary.overdue }} {{ t('libresign', 'overdue') }}</small></td><td>{{ campaignDate(campaign.requestedAt) }}</td><td>{{ campaignDate(campaign.dueAt) }}</td></tr>
					<tr v-if="!campaigns.length"><td colspan="7">{{ t('libresign', 'No campaigns match these filters.') }}</td></tr>
				</tbody></table></div>
				<div class="campaign-pagination"><NcButton :disabled="busy || page <= 1" @click="changePage(-1)">{{ t('libresign', 'Previous') }}</NcButton><span>{{ page }} / {{ Math.max(1, Math.ceil(total / 25)) }}</span><NcButton :disabled="busy || page * 25 >= total" @click="changePage(1)">{{ t('libresign', 'Next') }}</NcButton></div>
			</template>
			<template v-else>
				<div v-if="selected && selected.status !== 'draft'" class="campaign-summary">
					<div><strong>{{ selected.summary.completed }}</strong><span>{{ t('libresign', 'Completed') }}</span></div><div><strong>{{ selected.summary.pending }}</strong><span>{{ t('libresign', 'Outstanding') }}</span></div><div><strong>{{ selected.summary.overdue }}</strong><span>{{ t('libresign', 'Overdue') }}</span></div><div><strong>{{ selected.summary.awaiting_counter }}</strong><span>{{ t('libresign', 'Awaiting countersignature') }}</span></div><div><strong>{{ selected.summary.failed }}</strong><span>{{ t('libresign', 'Failed requests') }}</span></div><div><strong>{{ selected.summary.completionRate }}%</strong><span>{{ t('libresign', 'Completion rate') }}</span></div>
				</div>
				<form v-if="creating || selected?.status === 'draft'" class="campaign-form" @submit.prevent="saveDraft">
					<div class="campaign-form__title"><h3>{{ creating ? t('libresign', 'New campaign') : t('libresign', 'Campaign setup') }}</h3><span class="campaign-pill">{{ t('libresign', 'Draft · no invitations sent') }}</span></div>
					<section class="campaign-step"><div class="campaign-step__heading"><span>1</span><div><h3>{{ t('libresign', 'Document & details') }}</h3><p>{{ t('libresign', 'Choose the document everyone will receive their own copy of.') }}</p></div></div>
					<div class="campaign-setup-grid"><div><div class="campaign-form__grid"><label>{{ t('libresign', 'Campaign name') }}<input v-model="draft.name" required maxlength="200" :disabled="busy" :placeholder="t('libresign', 'e.g. Employee handbook acknowledgement')"></label><label>{{ t('libresign', 'Document version') }}<input v-model="draft.version" required maxlength="80" :disabled="busy"></label><label>{{ t('libresign', 'Deadline (optional)') }}<input v-model="dueDate" type="date" :disabled="busy"></label></div>
					<label>{{ t('libresign', 'Message to signers') }}<textarea v-model="draft.message" maxlength="4000" rows="3" :disabled="busy" :placeholder="t('libresign', 'Tell recipients what they need to review and sign.')" /></label></div>
					<div class="campaign-document"><div class="campaign-document__icon"><NcIconSvgWrapper :path="mdiFilePdfBox" :size="36" /></div><strong>{{ t('libresign', 'PDF document') }}</strong><p class="campaign-document__name">{{ draft.sourceName || t('libresign', 'Attach your document') }}</p><small>{{ readingPdf ? t('libresign', 'Reading PDF…') : draft.sourceName ? t('libresign', 'PDF attached · ready to place fields') : t('libresign', 'PDF only · up to 20 MB') }}</small><input :key="fileInputKey" ref="pdfInput" class="campaign-file-input" type="file" aria-label="PDF document" accept="application/pdf,.pdf" :disabled="busy || readingPdf" @change="upload"><NcButton :disabled="busy || readingPdf" @click="pdfInput?.click()">{{ draft.sourceName ? t('libresign', 'Replace PDF') : t('libresign', 'Choose PDF') }}</NcButton></div></div></section>
					<section class="campaign-step"><div class="campaign-step__heading"><span>2</span><div><h3>{{ t('libresign', 'Recipients') }} <span class="campaign-count">{{ draft.recipients.length }}</span></h3><p>{{ t('libresign', 'Choose how to add people, then review the list below.') }}</p></div></div>
					<CampaignRecipientPicker :disabled="busy" :existing="draft.recipients" :counter="draft.counter" allow-groups @add="addRecipient" @add-many="addMany" />
					<div class="campaign-table-scroll"><table><thead><tr><th>{{ t('libresign', 'Recipient Name') }}</th><th>{{ t('libresign', 'Recipient Email') }}</th><th>{{ t('libresign', 'Type') }}</th><th>{{ t('libresign', 'Actions') }}</th></tr></thead><tbody><tr v-for="(recipient, index) in draft.recipients" :key="recipient.uid || recipient.email"><td>{{ recipient.name }}</td><td>{{ recipient.email }}</td><td>{{ recipient.kind === 'internal' ? t('libresign', 'Internal') : t('libresign', 'External') }}</td><td><NcButton :disabled="busy" @click="draft.recipients.splice(index, 1)">{{ t('libresign', 'Remove') }}</NcButton></td></tr><tr v-if="!draft.recipients.length"><td colspan="4">{{ t('libresign', 'No recipients selected yet.') }}</td></tr></tbody></table></div>
					<p v-if="recipientError" class="campaign-section-error" role="alert"><strong>{{ recipientError }}</strong></p></section>
					<section class="campaign-step"><div class="campaign-step__heading"><span>3</span><div><h3>{{ t('libresign', 'Countersignature') }}</h3><p>{{ t('libresign', 'Optional. Add an HR representative or approver who signs each copy after its recipient.') }}</p></div></div>
					<details class="campaign-counter"><summary>{{ t('libresign', 'Optional countersignature for every copy') }}</summary><p>{{ t('libresign', 'Each recipient signs first. This person then countersigns that recipient’s copy; other recipients do not need to wait.') }}</p><div v-if="draft.counter" class="campaign-counter__selected"><strong>{{ draft.counter.name }}</strong><p>{{ draft.counter.email }}</p><NcButton :disabled="busy" @click="removeCounter">{{ t('libresign', 'Remove countersigner') }}</NcButton></div><CampaignRecipientPicker v-else :disabled="busy" @add="setCounter" /></details><p v-if="counterError" class="campaign-section-error" role="alert"><strong>{{ counterError }}</strong></p></section>
					<section class="campaign-step campaign-layout-note"><div class="campaign-step__heading"><span>4</span><div><h3>{{ t('libresign', 'Reusable field layout') }}</h3><p>{{ t('libresign', 'Place fields once. The same layout is applied to every recipient’s independent copy.') }}</p></div></div><div class="campaign-layout-note__body"><div><strong>{{ draft.fields.length }} {{ t('libresign', 'fields placed') }}</strong><p>{{ t('libresign', 'Include a signature for each signer role. Replacing the PDF clears the layout.') }}</p><small v-if="!draft.pdf && !selected">{{ t('libresign', 'Attach a PDF above to get started.') }}</small></div><NcButton variant="primary" :disabled="busy || readingPdf || (!draft.pdf && !selected)" @click="design">{{ t('libresign', 'Place signature and fillable fields') }}</NcButton></div></section>
					<div class="campaign-actions campaign-form__footer"><p>{{ t('libresign', 'Saving a draft does not send invitations. Review the campaign before sending.') }}</p><NcButton type="submit" :disabled="busy || readingPdf || !draft.name.trim() || !draft.version.trim()">{{ creating ? t('libresign', 'Create draft') : t('libresign', 'Save draft') }}</NcButton><NcButton v-if="selected" variant="primary" :disabled="busy || readingPdf || !draft.recipients.length || !draft.fields.length" @click="prepareSend">{{ t('libresign', 'Review and send') }}</NcButton></div>
				</form>
				<section v-if="selected && selected.status !== 'draft'" class="campaign-details">
					<h3>{{ selected.name }} — {{ t('libresign', 'Version') }} {{ selected.version }}</h3><p>{{ campaignState(selected) }} · {{ t('libresign', 'Date Requested') }}: {{ campaignDate(selected.requestedAt) }} · {{ t('libresign', 'Deadline') }}: {{ campaignDate(selected.dueAt) }}</p>
					<p>{{ selected.message }}</p><p>{{ t('libresign', 'Document and recipient list are frozen. Use Reuse campaign for a new document revision or recipient cohort.') }}</p>
					<div class="campaign-actions"><a :href="campaignUrl(`campaigns/${selected.id}/export`)">{{ t('libresign', 'Export completion register (CSV)') }}</a><NcButton :disabled="busy" @click="duplicate">{{ t('libresign', 'Reuse campaign') }}</NcButton><NcButton v-if="selected.status === 'active'" :disabled="busy || !selected.summary.pending" @click="remind">{{ t('libresign', 'Remind outstanding signers') }}</NcButton><NcButton v-if="selected.status === 'active'" :disabled="busy || !selected.summary.failed" @click="retry">{{ t('libresign', 'Retry failed requests') }}</NcButton></div>
					<div class="campaign-form__grid campaign-recipient-filters"><label>{{ t('libresign', 'Recipient Name') }}<input v-model="recipientName" type="search"></label><label>{{ t('libresign', 'Recipient Email') }}<input v-model="recipientEmail" type="search"></label><label>{{ t('libresign', 'Status') }}<select v-model="recipientStatus"><option value="">{{ t('libresign', 'All statuses') }}</option><option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option></select></label></div>
					<div class="campaign-table-scroll"><table><thead><tr><th>{{ t('libresign', 'Recipient Name') }}</th><th>{{ t('libresign', 'Recipient Email') }}</th><th>{{ t('libresign', 'Type') }}</th><th>{{ t('libresign', 'Status') }}</th><th>{{ t('libresign', 'Date Requested') }}</th><th>{{ t('libresign', 'Date Completed') }}</th><th>{{ t('libresign', 'Actions') }}</th></tr></thead><tbody><tr v-for="recipient in filteredRecipients" :key="recipient.id"><td>{{ recipient.name }}</td><td>{{ recipient.email }}</td><td>{{ recipient.kind === 'internal' ? t('libresign', 'Internal') : t('libresign', 'External') }}</td><td>{{ statusLabel(recipient.status) }}<small v-if="recipient.error" class="campaign-error">{{ recipient.error }}</small><small v-if="recipient.exemptionReason">{{ recipient.exemptionReason }}</small></td><td>{{ campaignDate(recipient.requestedAt) }}</td><td>{{ campaignDate(recipient.completedAt) }}</td><td class="campaign-actions"><a v-if="recipient.fileId" :href="campaignUrl(`campaigns/${selected.id}/recipients/${recipient.id}/file`)" target="_blank" rel="noopener noreferrer">{{ t('libresign', 'View PDF') }}</a><a v-if="recipient.fileUuid && recipient.completedAt" :href="validationUrl(recipient.fileUuid)" target="_blank" rel="noopener noreferrer">{{ t('libresign', 'Verify') }}</a><NcButton v-if="selected.status === 'active' && !['completed', 'awaiting_counter', 'exempt', 'cancelled'].includes(recipient.status || '')" :disabled="busy" @click="exemptRecipient = recipient">{{ t('libresign', 'Exempt') }}</NcButton></td></tr><tr v-if="!filteredRecipients.length"><td colspan="7">{{ t('libresign', 'No recipients match these filters.') }}</td></tr></tbody></table></div>
					<details v-if="selected.status === 'active'" class="campaign-cancel"><summary>{{ t('libresign', 'Cancel campaign') }}</summary><p>{{ t('libresign', 'Stops new signing and reminders. Existing signed documents and audit history will not be deleted.') }}</p><label>{{ t('libresign', 'Reason') }}<input v-model="cancelReason" maxlength="1000"></label><NcButton :disabled="busy || !cancelReason.trim()" @click="cancel">{{ t('libresign', 'Confirm cancellation') }}</NcButton></details>
				</section>
				<section v-if="selected" class="campaign-history"><h3>{{ t('libresign', 'Campaign history') }}</h3><ol><li v-for="event in selected.events" :key="event.id"><strong>{{ eventLabel(event.action) }}</strong> · {{ campaignDate(event.createdAt) }} · {{ event.actor }}<p v-if="event.details.reason">{{ event.details.reason }}</p></li></ol></section>
			</template>
		</template>
		<CampaignDesigner v-if="designer && selected" :campaign-id="selected.id" :source-name="selected.sourceName" :fields="draft.fields" :counter="draft.counter" :saving="busy" @save="saveLayout" @close="designer = false" />
		<NcDialog v-if="confirmSend && selected" :name="t('libresign', 'Send campaign')" @closing="confirmSend = false"><p>{{ t('libresign', 'This will create a separate signing request for each of {count} recipients. Each recipient will receive their own invitation. Review the list before continuing.', {count: draft.recipients.length}) }}</p><p><strong>{{ draft.name }} — {{ draft.version }}</strong></p><template #actions><NcButton :disabled="busy" @click="confirmSend = false">{{ t('libresign', 'Back') }}</NcButton><NcButton variant="primary" :disabled="busy" @click="publish">{{ t('libresign', 'Send campaign') }}</NcButton></template></NcDialog>
		<NcDialog v-if="exemptRecipient" :name="t('libresign', 'Exempt recipient')" @closing="exemptRecipient = null"><p>{{ exemptRecipient.name }} — {{ exemptRecipient.email }}</p><label>{{ t('libresign', 'Reason (required)') }}<textarea v-model="exemptReason" maxlength="1000" /></label><template #actions><NcButton :disabled="busy" @click="exemptRecipient = null">{{ t('libresign', 'Back') }}</NcButton><NcButton variant="primary" :disabled="busy || !exemptReason.trim()" @click="exempt">{{ t('libresign', 'Record exemption') }}</NcButton></template></NcDialog>
	</section>
</template>
<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { mdiFilePdfBox } from '@mdi/js'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import SenderSelect from '../components/DocumentFilters/SenderSelect.vue'
import CampaignDesigner from '../components/Request/CampaignDesigner.vue'
import CampaignRecipientPicker from '../components/Request/CampaignRecipientPicker.vue'
import { campaignGet, campaignPost, campaignPut, campaignUrl, campaignDate, campaignError, mergeCampaignRecipients, type Campaign, type CampaignDraft, type CampaignField, type CampaignRecipient } from '../services/campaigns'

const initialised = ref(false); const canCreate = ref(false); const busy = ref(false); const error = ref(''); const notice = ref('')
const recipientError = ref(''); const counterError = ref(''); const readingPdf = ref(false); const pdfInput = ref<HTMLInputElement | null>(null)
let uploadGeneration = 0
function reportError(message: string) { error.value = message; showError(message) }
const campaigns = ref<Campaign[]>([]); const total = ref(0); const page = ref(1)
const defaultFilters=()=>({name:'',version:'',sender:'',recipientName:'',recipientEmail:'',kind:'',status:'',dateBy:'created',from:'',to:'',sortBy:'created',direction:'desc'})
const filters=reactive(defaultFilters()),appliedFilters=ref(defaultFilters()),senders=ref<Array<{id:string,name:string}>>([]),organisationScope=ref(false)
async function applyFilters(){page.value=1;appliedFilters.value={...filters};await perform(loadList)}
async function resetFilters(){Object.assign(filters,defaultFilters());await applyFilters()}
const selected = ref<Campaign | null>(null); const creating = ref(false); const designer = ref(false); const confirmSend = ref(false)
const dueDate = ref(''); const fileInputKey = ref(0); const recipientName = ref(''); const recipientEmail = ref(''); const recipientStatus = ref(''); const cancelReason = ref('')
const exemptRecipient = ref<CampaignRecipient | null>(null); const exemptReason = ref('')
const blank = (): CampaignDraft => ({name: '', version: '1', message: '', dueAt: null, sourceName: '', pdf: undefined, recipients: [], counter: null, fields: []})
const draft = reactive<CampaignDraft>(blank())
const statuses: Record<string, string> = {draft: t('libresign', 'Not sent'), queued: t('libresign', 'Queued'), notification_pending: t('libresign', 'Invitation queued'), pending: t('libresign', 'Awaiting recipient'), awaiting_counter: t('libresign', 'Awaiting countersignature'), completed: t('libresign', 'Completed'), failed: t('libresign', 'Request or notification failed'), exempt: t('libresign', 'Exempt'), cancelled: t('libresign', 'Cancelled')}
const filteredRecipients = computed(() => (selected.value?.recipients || []).filter(row => row.name.toLowerCase().includes(recipientName.value.toLowerCase()) && row.email.toLowerCase().includes(recipientEmail.value.toLowerCase()) && (!recipientStatus.value || row.status === recipientStatus.value)))
const statusLabel = (status?: string) => statuses[status || ''] || status || '—'
function campaignState(c: Campaign) { return c.status === 'draft' ? t('libresign', 'Draft') : c.status === 'cancelled' ? t('libresign', 'Cancelled') : c.summary.required > 0 && c.summary.completed === c.summary.required ? t('libresign', 'Completed') : t('libresign', 'Active') }
function eventLabel(action: string) { return ({created: t('libresign', 'Created'), draft_updated: t('libresign', 'Draft updated'), published: t('libresign', 'Published'), request_created: t('libresign', 'Individual request created'), reminder_queued: t('libresign', 'Reminders queued'), retry_queued: t('libresign', 'Retries queued'), cancelled: t('libresign', 'Cancelled'), exempted: t('libresign', 'Exemption recorded')} as Record<string, string>)[action] || action }
function validationUrl(uuid: string) { return generateUrl('/apps/libresign/validation/{uuid}', {uuid}) }
function setSelected(c: Campaign) {
	selected.value = c; creating.value = false
	Object.assign(draft, {name: c.name, version: c.version, message: c.message, dueAt: c.dueAt, sourceName: c.sourceName, fields: c.fields.map(field => ({...field, coordinates: {...field.coordinates}, metadata: {...field.metadata}})), counter: c.counter ? {...c.counter} : null, recipients: c.recipients.map(row => ({name: row.name, email: row.email, uid: row.uid, kind: row.kind})), pdf: undefined})
	const d = c.dueAt ? new Date(c.dueAt * 1000) : null
	dueDate.value = d ? `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}` : ''
	fileInputKey.value++
}
async function perform(action: () => Promise<void>) {
	if (busy.value) return false
	busy.value = true; error.value = ''; notice.value = ''
	try { await action(); return true } catch (e) { reportError(campaignError(e)); return false } finally { busy.value = false }
}
async function loadList() { const data = await campaignGet<{data: Campaign[], total: number, senders?:Array<{id:string,name:string}>,scope?:string}>('campaigns', {page: page.value,filters:appliedFilters.value}); campaigns.value = data.data; total.value = data.total;senders.value=data.senders||[];organisationScope.value=data.scope==='organisation' }
async function open(id: number) { await perform(async () => { setSelected(await campaignGet<Campaign>(`campaigns/${id}`)); recipientName.value = ''; recipientEmail.value = ''; recipientStatus.value = '' }) }
async function refresh() { await perform(async () => { if (selected.value && selected.value.status !== 'draft') selected.value = await campaignGet<Campaign>(`campaigns/${selected.value.id}`); else if (!selected.value && !creating.value) await loadList() }) }
function newCampaign() { uploadGeneration++; readingPdf.value = false; creating.value = true; selected.value = null; Object.assign(draft, blank()); dueDate.value = ''; error.value = ''; recipientError.value = ''; counterError.value = ''; notice.value = ''; fileInputKey.value++ }
async function back() { selected.value = null; creating.value = false; designer.value = false; await perform(loadList) }
async function changePage(delta: number) { page.value += delta; await perform(loadList) }
function addMany(rows: CampaignRecipient[]) {
	const merged = mergeCampaignRecipients(draft.recipients, rows)
	if (merged.recipients.length > 2000) { recipientError.value = t('libresign', 'A campaign supports up to 2000 recipients.'); reportError(recipientError.value); return }
	if (draft.counter && merged.recipients.some(row => row.email.toLowerCase() === draft.counter?.email.toLowerCase())) { recipientError.value = t('libresign', 'The countersigner cannot also be a campaign recipient. Choose a different recipient or remove the countersigner.'); reportError(recipientError.value); return }
	draft.recipients = merged.recipients; notice.value = merged.skipped ? t('libresign', '{count} duplicate recipients skipped.', {count: merged.skipped}) : ''; error.value = ''; recipientError.value = ''
}
function addRecipient(row: CampaignRecipient) { addMany([row]) }
function setCounter(row: CampaignRecipient) { if (draft.recipients.some(r => r.email.toLowerCase() === row.email.toLowerCase())) { counterError.value = t('libresign', 'The countersigner cannot also be a campaign recipient. Choose someone who is not in the recipient list.'); reportError(counterError.value); return } draft.counter = row; counterError.value = ''; error.value = '' }
function removeCounter() { draft.counter = null; draft.fields = draft.fields.filter(field => field.role !== 'counter'); counterError.value = ''; recipientError.value = ''; error.value = '' }
async function upload(event: Event) {
	const file = (event.target as HTMLInputElement).files?.[0]
	if (!file) return
	if (file.size > 20 * 1024 * 1024 || !file.name.toLowerCase().endsWith('.pdf')) { (event.target as HTMLInputElement).value = ''; reportError(t('libresign', 'Choose a PDF no larger than 20 MB. The previous saved document has not been changed.')); return }
	const current = ++uploadGeneration; readingPdf.value = true
	try { const content = await new Promise<string>((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(String(reader.result).split(',')[1]); reader.onerror = reject; reader.readAsDataURL(file) }); if (current !== uploadGeneration) return; draft.pdf = content; draft.sourceName = file.name; draft.fields = []; error.value = '' }
	catch { if (current === uploadGeneration) reportError(t('libresign', 'Unable to read the selected PDF.')) } finally { if (current === uploadGeneration) readingPdf.value = false }
}
async function persistDraft() {
	if (readingPdf.value) throw new Error('PDF still loading')
	if (!draft.name.trim() || !draft.version.trim()) { throw {response:{data:{message:t('libresign', 'Enter a campaign name and document version before placing fields or saving.')}}} }
	draft.dueAt = dueDate.value ? Math.floor(new Date(dueDate.value + 'T23:59:59').getTime() / 1000) : null
	const payload = {...draft, recipients: draft.recipients.map(row => ({name: row.name, email: row.email, uid: row.uid ?? null}))}
	const c = selected.value ? await campaignPut<Campaign>(`campaigns/${selected.value.id}`, {revision: selected.value.revision, campaign: payload}) : await campaignPost<Campaign>('campaigns', {campaign: payload})
	setSelected(c)
}
async function saveDraft() { await perform(async () => { await persistDraft(); notice.value = t('libresign', 'Campaign draft saved. No invitations have been sent.') }) }
async function design() { if (await perform(persistDraft)) designer.value = true }
async function saveLayout(fields: CampaignField[]) { draft.fields = fields; if (await perform(persistDraft)) { designer.value = false; notice.value = t('libresign', 'Reusable field layout saved.') } }
async function prepareSend() { if (await perform(persistDraft)) confirmSend.value = true }
async function publish() { await perform(async () => { if (!selected.value) return; setSelected(await campaignPost<Campaign>(`campaigns/${selected.value.id}/publish`, {revision: selected.value.revision})); confirmSend.value = false; notice.value = t('libresign', 'Campaign published. Individual invitations are queued; completion is tracked from actual signatures.') }) }
async function remind() { await perform(async () => { if (!selected.value) return; const data = await campaignPost<{queued: number}>(`campaigns/${selected.value.id}/remind`); notice.value = t('libresign', '{count} reminders queued. Recent reminders and completed requests are skipped.', {count: data.queued}) }) }
async function retry() { await perform(async () => { if (!selected.value) return; const data = await campaignPost<{queued: number}>(`campaigns/${selected.value.id}/retry`); selected.value = await campaignGet<Campaign>(`campaigns/${selected.value.id}`); notice.value = t('libresign', '{count} failed requests queued for retry.', {count: data.queued}) }) }
async function duplicate() { await perform(async () => { if (selected.value) { setSelected(await campaignPost<Campaign>(`campaigns/${selected.value.id}/duplicate`)); notice.value = t('libresign', 'New draft created using the same PDF and layout. Choose recipients and review the version before sending.') } }) }
async function cancel() { await perform(async () => { if (selected.value) { selected.value = await campaignPost<Campaign>(`campaigns/${selected.value.id}/cancel`, {reason: cancelReason.value}); cancelReason.value = '' } }) }
async function exempt() { await perform(async () => { if (selected.value && exemptRecipient.value) { selected.value = await campaignPost<Campaign>(`campaigns/${selected.value.id}/recipients/${exemptRecipient.value.id}/exempt`, {reason: exemptReason.value}); exemptRecipient.value = null; exemptReason.value = '' } }) }
let timer: ReturnType<typeof setInterval> | undefined
let ticking = false
let disposed = false
onMounted(async () => {
	await perform(async () => { const access = await campaignGet<{canCreate: boolean}>('campaign/access'); canCreate.value = access.canCreate; if (access.canCreate) await loadList() })
	if (disposed) return
	initialised.value = true
	timer = setInterval(async () => {
		const c = selected.value
		if (!c || c.status !== 'active' || busy.value || ticking) return
		ticking = true
		try { if (c.summary.queued + c.summary.notification_pending > 0) await campaignPost(`campaigns/${c.id}/process`); if (disposed) return; const fresh = await campaignGet<Campaign>(`campaigns/${c.id}`); if (!disposed && selected.value?.id === c.id && !busy.value) selected.value = fresh }
		catch (e) { if (!disposed) error.value = campaignError(e) } finally { ticking = false }
	}, 10000)
})
onBeforeUnmount(() => { disposed = true; uploadGeneration++; clearInterval(timer) })
</script>
<style scoped lang="scss">
.campaign-page { padding: 32px clamp(16px,3vw,48px); width: 100%; box-sizing: border-box; overflow-y: auto; background: var(--color-main-background); }
.campaign-page__header { display: flex; justify-content: space-between; gap: 20px; align-items: start; margin-bottom: 24px; flex-wrap: wrap; }
h2 { font-size: 26px; margin-bottom: 8px; } h3 { font-size: 20px; margin-block: 20px 12px; }
.campaign-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.campaign-actions a { color: var(--color-primary-element); text-decoration: underline; padding: 8px; }
.campaign-error { color: var(--color-main-text); overflow-wrap: anywhere; }
.campaign-error--banner, .campaign-section-error { padding:16px 18px; border:1px solid var(--color-border-maxcontrast); border-inline-start:5px solid var(--color-error); border-radius:10px; background:var(--color-background-dark); margin-block:16px; font-size:15px; line-height:1.5; }
.campaign-error--banner strong { display:block; font-size:16px; margin-bottom:4px; }
.campaign-notice { padding: 12px; border-inline-start: 4px solid var(--color-primary-element); background: var(--color-primary-element-light); margin-bottom: 16px; }
.campaign-table-scroll { overflow-x: auto; margin-block: 16px; }
table { width: 100%; border-collapse: collapse; } th, td { padding: 12px; border-bottom: 1px solid var(--color-border); text-align: start; vertical-align: top; } th { background: var(--color-background-dark); white-space: nowrap; }
small { display: block; margin-top: 6px; color: var(--color-text-maxcontrast); }
.campaign-link { background: none; border: 0; margin: 0; padding: 4px 0; min-height: 34px; color: var(--color-primary-element); text-align: start; font-weight: bold; }
progress { width: 140px; accent-color: var(--color-primary-element); }
.campaign-filters{padding:20px;border:1px solid var(--color-border);border-radius:14px;margin-bottom:20px;background:var(--color-background-hover);}.campaign-filters__scope{margin-bottom:16px;color:var(--color-text-maxcontrast);}.campaign-filter-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;}.campaign-pagination { display: flex; justify-content: space-between; align-items: center; }
.campaign-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(145px, 1fr)); gap: 12px; margin-block: 20px; }
.campaign-summary > div { padding: 18px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); background: var(--color-background-dark); }
.campaign-summary strong { display: block; font-size: 28px; margin-bottom: 4px; color: var(--color-primary-element); }
.campaign-form { max-width: 1440px; }
.campaign-form__title { display:flex; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:20px; }.campaign-form__title h3 { margin:0; font-size:22px; }
.campaign-pill { border:1px solid var(--color-border); border-radius:24px; padding:6px 12px; font-size:13px; color:var(--color-text-maxcontrast); background:var(--color-background-hover); }
.campaign-step { border:1px solid var(--color-border); border-radius:16px; padding:24px; margin-bottom:20px; background:var(--color-main-background); box-shadow:0 3px 12px rgb(0 0 0 / 3%); }
.campaign-step__heading { display:flex; gap:14px; align-items:flex-start; margin-bottom:22px; }.campaign-step__heading > span { display:flex; align-items:center; justify-content:center; width:34px; height:34px; flex:0 0 34px; border-radius:50%; background:var(--color-primary-element-light); color:var(--color-main-text); font-weight:700; }
.campaign-step__heading h3 { margin:0 0 5px; font-size:19px; font-weight:650; }.campaign-step__heading p { color:var(--color-text-maxcontrast); line-height:1.5; }
.campaign-setup-grid { display:grid; grid-template-columns:minmax(0,1fr) 280px; gap:28px; align-items:stretch; }
.campaign-document { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px; padding:22px; border:2px dashed var(--color-border-maxcontrast); border-radius:12px; background:var(--color-background-hover); text-align:center; position:relative; }
.campaign-document__icon { color:var(--color-primary-element); margin-bottom:4px; }.campaign-document__name { overflow-wrap:anywhere; word-break:break-word; max-width:100%; font-weight:550; }.campaign-document small { margin:0 0 8px; }
.campaign-file-input { position:absolute; width:1px !important; height:1px; min-height:0; opacity:0; pointer-events:none; }
.campaign-count { display:inline-flex; align-items:center; justify-content:center; min-width:26px; padding:2px 8px; border-radius:20px; background:var(--color-primary-element-light); font-size:14px; margin-inline-start:8px; }
.campaign-layout-note__body { display:flex; align-items:center; justify-content:space-between; gap:20px; flex-wrap:wrap; }.campaign-layout-note__body strong { font-size:18px; }.campaign-layout-note__body p { color:var(--color-text-maxcontrast); margin-top:6px; }
.campaign-form__footer { padding:8px 0 20px; justify-content:flex-end; }.campaign-form__footer > p { margin-inline-end:auto; max-width:600px; color:var(--color-text-maxcontrast); }
.campaign-counter__selected { padding:16px; background:var(--color-background-hover); border-radius:10px; margin-top:14px; }
.campaign-form__grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-block: 0 16px; }
label { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
input, select, textarea { width: 100%; box-sizing: border-box; min-height: 46px; margin: 0; border-radius:8px; }label { font-weight:550; }textarea { resize:vertical; }
summary { cursor: pointer; font-weight: bold; }
.campaign-counter { margin:0; }.campaign-counter > p { margin-block:14px; color:var(--color-text-maxcontrast); }.campaign-cancel { border:1px solid var(--color-border); padding:16px; margin-block:20px; border-radius:12px; }
.campaign-history { margin-top: 28px; } .campaign-history li { padding: 10px 0; border-bottom: 1px solid var(--color-border); }
.campaign-recipient-filters { margin-top: 24px; }
@media(max-width:1100px){.campaign-setup-grid{grid-template-columns:1fr;}.campaign-document{min-height:180px;}}
@media (max-width: 767px) { .campaign-page { padding: 16px 10px; }.campaign-step{padding:16px 12px;border-radius:12px;}.campaign-form__grid{grid-template-columns:1fr;gap:10px;}.campaign-step__heading{gap:10px;margin-bottom:16px;}.campaign-step__heading h3{font-size:18px;}.campaign-form__footer{justify-content:stretch;}.campaign-form__footer > p{flex-basis:100%;}input, select, textarea { font-size: 16px; } .campaign-summary { grid-template-columns: repeat(2, 1fr); } .campaign-page__header { gap: 12px; } }
</style>
