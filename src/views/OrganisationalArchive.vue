<!--
  SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcAppContent :page-heading="t('libresign', 'Signed Archive')">
		<section class="signed-archive">
			<h2>{{ t('libresign', 'Signed Archive') }}</h2>
			<p>{{ t('libresign', 'Read-only organisational archive. Signed documents and signing history are protected; original source files remain deletable in Nextcloud Files.') }}</p>
			<p v-if="!isAdmin" role="alert">{{ t('libresign', 'Administrator access required.') }}</p>
			<template v-else>
				<DocumentFilters :model-value="filters" :senders="senders" archive :loading="loading" @apply="applyFilters" />
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
								<td>{{ document.sender.name }}</td>
								<td><div v-for="(recipient, index) in document.recipients" :key="index">{{ recipient.name }}</div></td>
								<td>{{ formatDate(document.requestedAt) }}</td>
								<td>{{ document.completedAt ? formatDate(document.completedAt) : t('libresign', 'Not completed') }}</td>
								<td>{{ document.statusText }}</td>
								<td class="archive-row-actions">
									<a :href="fileUrl(document.id)" target="_blank" rel="noopener noreferrer">{{ t('libresign', 'View PDF') }}</a>
									<a :href="fileUrl(document.id, true)">{{ t('libresign', 'Download') }}</a>
									<a :href="validationUrl(document.uuid)" target="_blank" rel="noopener noreferrer">{{ t('libresign', 'Verify') }}</a>
									<NcButton @click="showHistory(document.id)">{{ t('libresign', 'Signing history') }}</NcButton>
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
				<NcDialog v-if="historyOpen" :open="true" :name="historyTitle" size="large" @closing="closeHistory">
                    <div class="archive-history">
                        <div v-if="historyLoading" role="status" aria-live="polite"><NcLoadingIcon />{{ t('libresign', 'Loading signing history…') }}</div>
                        <p v-else-if="historyError" role="alert"><strong>{{ historyError }}</strong></p>
                        <div v-else-if="history" class="archive-history__content" tabindex="0" :aria-label="t('libresign', 'Signing history details')">
					<p>{{ t('libresign', 'Signed document SHA-256') }}: <code>{{ history.hash }}</code></p>
					<ol>
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
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import DocumentFilters from '../components/DocumentFilters/DocumentFilters.vue'
import { documentFilterDefaults, documentFilterParams, type DocumentFilters as FilterState } from '../services/documentFilters'

type Recipient = { name: string, order: number, statusText: string, signedAt: string | null, ipAddress: string | null, identifiers: Array<{type: string, value: string}>, fields: Array<{label: string, type: string, value: unknown}> }
type ArchivedDocument = { id: number, uuid: string, name: string, sender: {id: string, name: string}, recipients: Recipient[], requestedAt: string | null, completedAt: string | null, statusText: string, sourceDeleted: boolean, hash: string }
const filters = ref(documentFilterDefaults(true))
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
	if (!isAdmin.value || loading.value) return
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
		error.value = t('libresign', 'Unable to load the archive. Check your administrator access and try again.')
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
    historyError.value = ''
    historyLoading.value = false
}
async function showHistory(id: number) {
    const request = ++historyRequest
    historyName.value = documents.value.find(document => document.id === id)?.name || ''
    history.value = null
    historyError.value = ''
    historyLoading.value = true
    historyOpen.value = true
    try {
        const response = await axios.get(generateUrl('/apps/libresign/archive/history/{id}', { id }))
        if (request === historyRequest) history.value = response.data
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
