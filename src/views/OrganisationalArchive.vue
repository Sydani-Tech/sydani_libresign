<!--
  SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcAppContent :page-heading="t('libresign', 'Signed Archive')">
		<section class="signed-archive">
			<h2>{{ t('libresign', 'Signed Archive') }}</h2>
			<p>{{ isAdmin ? t('libresign', 'Protected archive for completed signing records and externally signed PDFs.') : t('libresign', 'Archive externally signed PDFs and view your own imports here.') }}</p>
			<p v-if="!signedIn" role="alert">{{ t('libresign', 'Sign in to use Signed Archive.') }}</p>
			<template v-else>
				<NcButton variant="primary" @click="importOpen = true">{{ t('libresign', 'Archive a signed PDF') }}</NcButton>
				<DocumentFilters v-if="isAdmin" :model-value="filters" :senders="senders" archive compact :loading="loading" @apply="applyFilters" />
				<p v-if="error" role="alert" class="archive-error">{{ error }}</p>
				<div v-if="loading" role="status" aria-live="polite"><NcLoadingIcon />{{ t('libresign', 'Loading archive…') }}</div>
				<div class="archive-table-scroll" :aria-busy="loading">
					<table>
						<thead><tr>
							<th scope="col">{{ t('libresign', 'Document') }}</th>
							<th scope="col">{{ t('libresign', 'Sender') }}</th>
							<th scope="col">{{ t('libresign', 'Recipients') }}</th>
							<th scope="col">{{ t('libresign', 'Date Requested') }}</th>
							<th scope="col">{{ t('libresign', 'Date Completed') }}</th>
							<th scope="col">{{ t('libresign', 'Status') }}</th>
							<th scope="col">{{ t('libresign', 'Actions') }}</th>
						</tr></thead>
						<tbody>
							<tr v-for="document in documents" :key="document.id">
								<td>{{ document.name }}<small v-if="document.sourceDeleted">{{ t('libresign', 'Source deleted; signed record preserved') }}</small></td>
								<td>{{ document.sender.name || t('libresign', 'Not recorded') }}</td>
								<td><div v-for="(recipient, index) in document.recipients" :key="index">{{ recipient.name }}</div></td>
								<td>{{ formatDate(document.requestedAt) }}</td>
								<td>{{ document.external ? (document.external.completedDate || t('libresign', 'Not recorded')) : (document.completedAt ? formatDate(document.completedAt) : t('libresign', 'Not completed')) }}</td>
								<td>{{ document.external ? t('libresign', 'Signed externally') : document.statusText }}</td>
								<td class="archive-row-actions">
									<a :href="fileUrl(document.id)" target="_blank" rel="noopener noreferrer">{{ t('libresign', 'View PDF') }}</a>
									<a :href="fileUrl(document.id, true)">{{ t('libresign', 'Download') }}</a>
									<NcButton v-if="document.external" @click="showHistory(document.id, true)">{{ t('libresign', 'Validate') }}</NcButton>
									<a v-else :href="validationUrl(document.uuid)" target="_blank" rel="noopener noreferrer">{{ t('libresign', 'Verify') }}</a>
									<NcButton v-if="!document.external" @click="showHistory(document.id)">{{ t('libresign', 'Signing history') }}</NcButton>
								</td>
							</tr>
							<tr v-if="!loading && !documents.length"><td colspan="7">{{ t('libresign', 'No signed documents match these filters.') }}</td></tr>
						</tbody>
					</table>
				</div>
				<div class="archive-pagination">
					<NcButton :disabled="loading || page <= 1" @click="changePage(-1)">{{ t('libresign', 'Previous') }}</NcButton>
					<span>{{ t('libresign', 'Page {page} of {pages} · {total} documents', { page, pages: Math.max(1, Math.ceil(total / length)), total }) }}</span>
					<NcButton :disabled="loading || page * length >= total" @click="changePage(1)">{{ t('libresign', 'Next') }}</NcButton>
				</div>
				<NcDialog v-if="importOpen" :name="t('libresign', 'Archive an externally signed PDF')" size="large" @closing="closeImport">
					<form id="external-archive-import" class="archive-import" @submit.prevent="importDocument">
						<p>{{ t('libresign', 'Upload the completed PDF as downloaded from the signing service. Enter any details you know from the invitation or audit report.') }}</p>
						<div class="archive-import__uploads">
							<FilePicker :label="t('libresign', 'Completed PDF')"
								:filename="importPdf?.name" :hint="t('libresign', 'Signed PDF · Required')"
								accept="application/pdf,.pdf" :disabled="importing || inspecting" required
								@change="selectPdf($event, false)" />
							<FilePicker :label="t('libresign', 'Audit report or certificate of completion')"
								:filename="auditPdf?.name" :hint="t('libresign', 'PDF · Optional')"
								accept="application/pdf,.pdf" :disabled="importing"
								@change="selectPdf($event, true)" />
						</div>
						<div class="archive-import__metadata">
							<label>{{ t('libresign', 'Sender name') }}<input v-model.trim="details.senderName" maxlength="250" :disabled="importing"></label>
							<label>{{ t('libresign', 'Sender email') }}<input v-model.trim="details.senderEmail" type="email" maxlength="250" :disabled="importing"></label>
							<label>{{ t('libresign', 'Signing provider') }}<input v-model.trim="details.provider" maxlength="250" :disabled="importing"></label>
							<label>{{ t('libresign', 'Envelope or agreement ID') }}<input v-model.trim="details.reference" maxlength="250" :disabled="importing"></label>
							<label>{{ t('libresign', 'Date completed') }}<input v-model="details.completedDate" type="date" :disabled="importing"></label>
						</div>
						<NcButton :disabled="!importPdf || importing || inspecting" @click="inspectPdf">{{ t('libresign', 'Check signatures') }}</NcButton>
						<div v-if="inspection" role="status">
							<p v-if="!inspection.checked">{{ t('libresign', 'Signature verification is unavailable.') }}</p>
							<p v-else-if="!inspection.signatures.length">{{ t('libresign', 'No embedded digital signature detected. Attach the provider’s audit report if available.') }}</p>
							<ul v-else>
								<li v-for="signature in inspection.signatures" :key="signature.id">
									{{ signature.name || t('libresign', 'Certificate name unavailable') }} ·
									{{ signature.cryptographicIntegrity ? t('libresign', 'Signature integrity verified') : t('libresign', 'Signature integrity could not be verified') }} ·
									{{ signature.issuerTrusted ? t('libresign', 'Issuer trusted by this installation') : t('libresign', 'Issuer trust not established') }}
								</li>
							</ul>
						</div>
						<label class="archive-import__confirmation"><input v-model="confirmed" type="checkbox" required :disabled="importing">{{ t('libresign', 'I confirm this PDF was completed outside LibreSign.') }}</label>
						<p v-if="importError" role="alert" class="archive-error">{{ importError }}</p>
					</form>
					<template #actions>
						<NcButton :disabled="importing" @click="closeImport">{{ t('libresign', 'Cancel') }}</NcButton>
						<NcButton form="external-archive-import" type="submit" variant="primary" :disabled="importing || inspecting || !importPdf || !confirmed">{{ t('libresign', 'Save to Signed Archive') }}</NcButton>
					</template>
				</NcDialog>
				<NcDialog v-if="historyOpen" :open="true" :name="historyTitle" size="large" @closing="closeHistory">
                    <div class="archive-history">
                        <div v-if="historyLoading" role="status" aria-live="polite"><NcLoadingIcon />{{ t('libresign', 'Loading signing history…') }}</div>
                        <p v-else-if="historyError" role="alert"><strong>{{ historyError }}</strong></p>
                        <div v-else-if="history" class="archive-history__content" tabindex="0" :aria-label="t('libresign', 'Signing history details')">
					<p>{{ t('libresign', 'Signed document SHA-256') }}: <code>{{ history.hash }}</code></p>
							<template v-if="history.external">
								<p>{{ t('libresign', 'Imported by {user} on {date}', { user: history.external.importedBy, date: formatDate(history.external.importedAt) }) }}</p>
								<h3>{{ t('libresign', 'Details supplied at import') }}</h3>
								<p v-if="history.external.senderName">{{ t('libresign', 'Sender name') }} · {{ history.external.senderName }}</p>
								<p v-if="history.external.senderEmail">{{ t('libresign', 'Sender email') }} · {{ history.external.senderEmail }}</p>
								<p v-if="history.external.provider">{{ t('libresign', 'Signing provider') }} · {{ history.external.provider }}</p>
								<p v-if="history.external.reference">{{ t('libresign', 'Envelope or agreement ID') }} · {{ history.external.reference }}</p>
								<a v-if="history.external.auditNodeId" :href="auditUrl(history.id)">{{ t('libresign', 'Download provider audit report') }}</a>
								<h3>{{ t('libresign', 'PDF verification') }}</h3>
								<p v-if="verification">
									{{ verification.fileMatch ? t('libresign', 'The PDF matches the archived fingerprint.') : t('libresign', 'The PDF differs from the archived fingerprint.') }}
								</p>
								<p v-if="verification && !verification.longTermValidation.checked">{{ t('libresign', 'Signature verification is unavailable.') }}</p>
								<p v-else-if="verification && !verification.longTermValidation.signatures.length">{{ t('libresign', 'No embedded digital signature detected.') }}</p>
								<ul v-else-if="verification">
									<li v-for="signature in verification.longTermValidation.signatures" :key="signature.id">
										<strong>{{ signature.name || t('libresign', 'Certificate name unavailable') }}</strong>
										<p>{{ signature.cryptographicIntegrity ? t('libresign', 'Signature integrity verified') : t('libresign', 'Signature integrity could not be verified') }}</p>
										<p>{{ signature.issuerTrusted ? t('libresign', 'Issuer trusted by this installation') : t('libresign', 'Issuer trust not established') }}</p>
										<p>{{ signature.timestampVerified ? t('libresign', 'Timestamp verified') : t('libresign', 'Timestamp not verified') }}</p>
										<p>{{ hasLongTermEvidence(signature) ? t('libresign', 'Long-term evidence verified') : t('libresign', 'Long-term evidence not established') }}</p>
										<p v-if="signature.indication === 'TOTAL_FAILED'" role="alert">{{ t('libresign', 'Signature validation failed') }} · {{ signature.subIndication }}</p>
									</li>
								</ul>
							</template>
					<ol v-else>
						<li v-for="(recipient, index) in history.recipients" :key="index">
							<strong>{{ recipient.name }}</strong> — {{ recipient.statusText }}
							<p v-for="identifier in recipient.identifiers" :key="identifier.type + identifier.value">{{ identifier.type }}: {{ identifier.value }}</p>
							<p>{{ t('libresign', 'Signing order') }}: {{ recipient.order }}</p>
							<p v-if="recipient.signedAt">{{ t('libresign', 'Signed') }}: {{ formatDate(recipient.signedAt) }}</p>
							<p v-if="recipient.ipAddress">{{ t('libresign', 'IP address') }}: {{ recipient.ipAddress }}</p>
							<p v-for="(field, fieldIndex) in recipient.fields" :key="fieldIndex">{{ field.label || field.type }}: {{ String(field.value) }}</p>
						</li>
						</ol>
                        </div>
                    </div>
                    <template #actions><NcButton @click="closeHistory">{{ t('libresign', 'Close') }}</NcButton></template>
                </NcDialog>
			</template>
		</section>
	</NcAppContent>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { getCurrentUser } from '@nextcloud/auth'
import axios, { isAxiosError } from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import { hasLongTermEvidence, type LongTermValidation } from '../services/verificationSummary'
import FilePicker from '../components/FilePicker.vue'
import DocumentFilters from '../components/DocumentFilters/DocumentFilters.vue'
import { documentFilterDefaults, documentFilterParams, type DocumentFilters as FilterState } from '../services/documentFilters'

type Recipient = { name: string, order: number, statusText: string, signedAt: string | null, ipAddress: string | null, identifiers: Array<{type: string, value: string}>, fields: Array<{label: string, type: string, value: unknown}> }
type ArchivedDocument = { external?: ExternalDetails | null, id: number, uuid: string, name: string, sender: {id: string, name: string}, recipients: Recipient[], requestedAt: string | null, completedAt: string | null, statusText: string, sourceDeleted: boolean, hash: string }
type ExternalDetails = {
	senderName: string, senderEmail: string, provider: string, reference: string, completedDate: string,
	importedBy: string, importedAt: string, auditNodeId: number | null,
}
type ExternalVerification = { fileMatch: boolean, longTermValidation: LongTermValidation }
const importOpen = ref(false)
const importing = ref(false)
const importError = ref('')
const confirmed = ref(false)
const inspection = ref<LongTermValidation | null>(null)
const inspecting = ref(false)
const importPdf = ref<File | null>(null)
const auditPdf = ref<File | null>(null)
const details = reactive({ senderName: '', senderEmail: '', provider: '', reference: '', completedDate: '' })
const verification = ref<ExternalVerification | null>(null)

function selectPdf(event: Event, audit: boolean) {
	const selected = (event.target as HTMLInputElement).files?.[0] ?? null
	if (audit) auditPdf.value = selected
	else {
		importPdf.value = selected
		inspection.value = null
		confirmed.value = false
	}
}

function closeImport() {
	if (importing.value || inspecting.value) return
	importOpen.value = false
	importError.value = ''
	importPdf.value = null
	inspection.value = null
	confirmed.value = false
	auditPdf.value = null
	Object.assign(details, { senderName: '', senderEmail: '', provider: '', reference: '', completedDate: '' })
}


async function inspectPdf() {
	if (!importPdf.value || inspecting.value) return
	const payload = new FormData()
	payload.append('pdf', importPdf.value)
	inspecting.value = true
	importError.value = ''
	try {
		const response = await axios.post<LongTermValidation>(generateUrl('/apps/libresign/archive/inspect'), payload)
		inspection.value = response.data
	} catch (cause) {
		importError.value = archiveError(cause, t('libresign', 'Unable to inspect this PDF.'))
	} finally {
		inspecting.value = false
	}
}

function archiveError(cause: unknown, fallback: string) {
	return isAxiosError<{message?: unknown}>(cause) && typeof cause.response?.data?.message === 'string'
		? cause.response.data.message
		: fallback
}

async function importDocument() {
	if (!importPdf.value || !confirmed.value || importing.value || inspecting.value) return
	const payload = new FormData()
	payload.append('pdf', importPdf.value)
	payload.append('confirmed', 'yes')
	if (auditPdf.value) payload.append('audit', auditPdf.value)
	for (const [key, value] of Object.entries(details)) payload.append(key, value)
	importError.value = ''
	importing.value = true
	try {
		const response = await axios.post<ArchivedDocument>(generateUrl('/apps/libresign/archive/import'), payload)
		importing.value = false
		closeImport()
		page.value = 1
		await load()
		await showHistory(response.data.id, true, response.data.name)
	} catch (cause) {
		importError.value = archiveError(cause, t('libresign', 'Unable to archive the PDF. Try again.'))
	} finally {
		importing.value = false
	}
}

function auditUrl(id: number) {
	return generateUrl('/apps/libresign/archive/audit/{id}', { id })
}

const filters = ref(documentFilterDefaults(true))
const signedIn = computed(() => Boolean(getCurrentUser()?.uid))
const isAdmin = computed(() => getCurrentUser()?.isAdmin ?? false)
const documents = ref<ArchivedDocument[]>([])
const senders = ref<Array<{id: string, name: string}>>([])
const history = ref<ArchivedDocument | null>(null)
const historyOpen = ref(false)
const historyLoading = ref(false)
const historyError = ref('')
const historyName = ref('')
const historyTitle = computed(() => historyName.value ? t('libresign', 'Signing history — {name}', {name: historyName.value}) : t('libresign', 'Signing history'))
let historyRequest = 0
const page = ref(1)
const length = 25
const total = ref(0)
const loading = ref(false)
const error = ref('')

function formatDate(value: string | null) {
	return value ? new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : t('libresign', 'Not recorded')
}
function fileUrl(id: number, download = false) {
	return generateUrl('/apps/libresign/archive/file/{id}', { id }) + (download ? '?download=true' : '')
}
function validationUrl(uuid: string) {
	return generateUrl('/apps/libresign/validation/{uuid}', { uuid })
}
async function load() {
	if (!signedIn.value || loading.value) return
	error.value = ''

	loading.value = true
	try {
		const response = await axios.get(generateUrl('/apps/libresign/archive/list'), { params: {
			...documentFilterParams(filters.value), page: page.value, length,
		} })
		documents.value = response.data.data
		total.value = response.data.total
		senders.value = response.data.senders
	} catch {
		documents.value = []
		total.value = 0
		error.value = t('libresign', 'Unable to load the archive. Try again.')
	} finally {
		loading.value = false
	}
}
function search() { page.value = 1; closeHistory(); return load() }
function applyFilters(value: FilterState) { filters.value = value; return search() }
function changePage(delta: number) { page.value += delta; return load() }
function closeHistory() {
    historyRequest++
    historyOpen.value = false
    history.value = null
    verification.value = null
    historyError.value = ''
    historyLoading.value = false
}
async function showHistory(id: number, external = false, name = '') {
    const request = ++historyRequest
    historyName.value = name || documents.value.find(document => document.id === id)?.name || ''
    history.value = null
    historyError.value = ''
    historyLoading.value = true
    historyOpen.value = true
    try {
        const [response, proof] = await Promise.all([
            axios.get(generateUrl('/apps/libresign/archive/history/{id}', { id })),
            external ? axios.get<ExternalVerification>(generateUrl('/apps/libresign/archive/verify/{id}', { id })) : null,
        ])
        if (request === historyRequest) {
            history.value = response.data
            verification.value = proof?.data ?? null
        }
    } catch {
        if (request === historyRequest) historyError.value = t('libresign', 'Unable to load signing history. Close this window and try again.')
    } finally {
        if (request === historyRequest) historyLoading.value = false
    }
}
onBeforeUnmount(() => { historyRequest++ })
onMounted(load)
</script>

<style scoped lang="scss">
.signed-archive { padding: 24px; max-width: 1800px; }
.signed-archive h2 { margin-bottom: 8px; }
.archive-import { display: grid; gap: 14px; padding-block: 8px; }
.archive-import label { display: grid; gap: 6px; min-width: 0; }
.archive-import .archive-import__confirmation { display: flex; align-items: center; }
.archive-import__uploads { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
@media (max-width: 620px) { .archive-import__uploads { grid-template-columns: minmax(0, 1fr); } }
.archive-import__metadata { display: grid; grid-template-columns: repeat(auto-fit, minmax(175px, 1fr)); gap: 12px; }
.archive-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-block: 24px 12px; }
.archive-filters label { display: flex; flex-direction: column; gap: 6px; }
.archive-filters input, .archive-filters select { width: 100%; min-height: 44px; }
.archive-filter-actions { display: flex; align-items: end; gap: 8px; flex-wrap: wrap; }
.archive-error { color: var(--color-error); }
.archive-table-scroll { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 14px 10px; text-align: start; vertical-align: top; border-bottom: 1px solid var(--color-border); }
th { background: var(--color-background-dark); white-space: nowrap; }
td small { display: block; margin-top: 6px; color: var(--color-text-maxcontrast); }
.archive-row-actions a { display: inline-block; margin-inline-end: 14px; margin-block: 6px; color: var(--color-primary-element); text-decoration: underline; }
.archive-pagination { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-block: 20px; }
.archive-history { min-width: 0; }
.archive-history__content { max-height: 60vh; overflow-y: auto; overscroll-behavior: contain; padding: 4px 12px 4px 4px; }
.archive-history p, .archive-history code { overflow-wrap: anywhere; white-space: normal; }
.archive-history p { line-height: 1.6; }
.archive-history li { margin: 18px 0 18px 24px; list-style: decimal; }
</style>
