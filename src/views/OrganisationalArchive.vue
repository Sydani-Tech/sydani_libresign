<!--
  SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcAppContent :page-heading="t('libresign', 'Signed archive')">
		<section class="signed-archive">
			<h2>{{ t('libresign', 'Signed archive') }}</h2>
			<p>{{ t('libresign', 'Read-only organisational archive. Signed documents and signing history are protected; original source files remain deletable in Nextcloud Files.') }}</p>
			<p v-if="!isAdmin" role="alert">{{ t('libresign', 'Administrator access required.') }}</p>
			<template v-else>
				<form class="archive-filters" @submit.prevent="search">
					<label>{{ t('libresign', 'Document name') }}<input v-model="filters.name" type="search" maxlength="200"></label>
					<label>{{ t('libresign', 'Sender') }}
						<select v-model="filters.sender">
							<option value="">{{ t('libresign', 'All senders') }}</option>
							<option v-for="sender in senders" :key="sender.id" :value="sender.id">{{ sender.name }}</option>
						</select>
					</label>
					<label>{{ t('libresign', 'Recipient Name') }}<input v-model="filters.recipientName" type="search" maxlength="200"></label>
					<label>{{ t('libresign', 'Recipient Email') }}<input v-model="filters.recipientEmail" type="search" maxlength="200"></label>
					<label>{{ t('libresign', 'Status') }}
						<select v-model="filters.status">
							<option value="completed">{{ t('libresign', 'Completed') }}</option>
							<option value="partial">{{ t('libresign', 'Partially signed') }}</option>
							<option value="all">{{ t('libresign', 'All protected documents') }}</option>
						</select>
					</label>
					<label>{{ t('libresign', 'Date filter') }}
						<select v-model="filters.dateBy">
							<option value="completed">{{ t('libresign', 'Date Completed') }}</option>
							<option value="requested">{{ t('libresign', 'Date Requested') }}</option>
						</select>
					</label>
					<label>{{ t('libresign', 'From') }}<input v-model="fromDate" type="date"></label>
					<label>{{ t('libresign', 'To') }}<input v-model="toDate" type="date"></label>
					<label>{{ t('libresign', 'Sort by') }}
						<select v-model="filters.sortBy">
							<option value="completed">{{ t('libresign', 'Date Completed') }}</option>
							<option value="requested">{{ t('libresign', 'Date Requested') }}</option>
							<option value="name">{{ t('libresign', 'Document name') }}</option>
							<option value="sender">{{ t('libresign', 'Sender username') }}</option>
						</select>
					</label>
					<label>{{ t('libresign', 'Order') }}
						<select v-model="filters.direction">
							<option value="desc">{{ t('libresign', 'Descending') }}</option>
							<option value="asc">{{ t('libresign', 'Ascending') }}</option>
						</select>
					</label>
					<div class="archive-filter-actions">
						<NcButton type="submit" variant="primary" :disabled="loading">{{ t('libresign', 'Apply filters') }}</NcButton>
						<NcButton :disabled="loading" @click="reset">{{ t('libresign', 'Reset') }}</NcButton>
					</div>
				</form>
				<p class="archive-date-note">{{ t('libresign', 'Older requests may not have a recorded Date Requested. These are shown as Not recorded, never replaced with the upload date.') }}</p>
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
				<section v-if="history" class="archive-history" aria-live="polite">
					<header><h3>{{ t('libresign', 'Signing history') }} — {{ history.name }}</h3><NcButton @click="history = null">{{ t('libresign', 'Close') }}</NcButton></header>
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
				</section>
			</template>
		</section>
	</NcAppContent>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

type Recipient = { name: string, order: number, statusText: string, signedAt: string | null, ipAddress: string | null, identifiers: Array<{type: string, value: string}>, fields: Array<{label: string, type: string, value: unknown}> }
type ArchivedDocument = { id: number, uuid: string, name: string, sender: {id: string, name: string}, recipients: Recipient[], requestedAt: string | null, completedAt: string | null, statusText: string, sourceDeleted: boolean, hash: string }
const defaults = { name: '', sender: '', recipientName: '', recipientEmail: '', status: 'completed', dateBy: 'completed', sortBy: 'completed', direction: 'desc' }
const filters = reactive({ ...defaults })
const isAdmin = computed(() => getCurrentUser()?.isAdmin ?? false)
const documents = ref<ArchivedDocument[]>([])
const senders = ref<Array<{id: string, name: string}>>([])
const history = ref<ArchivedDocument | null>(null)
const fromDate = ref('')
const toDate = ref('')
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
	if (fromDate.value && toDate.value && fromDate.value > toDate.value) {
		error.value = t('libresign', 'The From date must not be later than the To date.')
		return
	}
	loading.value = true
	try {
		const response = await axios.get(generateUrl('/apps/libresign/archive/list'), { params: {
			...filters, page: page.value, length,
			from: fromDate.value ? Math.floor(new Date(fromDate.value + 'T00:00:00').getTime() / 1000) : undefined,
			to: toDate.value ? Math.floor(new Date(toDate.value + 'T23:59:59').getTime() / 1000) : undefined,
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
function search() { page.value = 1; history.value = null; return load() }
function reset() { Object.assign(filters, defaults); fromDate.value = ''; toDate.value = ''; return search() }
function changePage(delta: number) { page.value += delta; return load() }
async function showHistory(id: number) {
	try {
		history.value = (await axios.get(generateUrl('/apps/libresign/archive/history/{id}', { id }))).data
	} catch {
		error.value = t('libresign', 'Unable to load signing history.')
	}
}
onMounted(load)
</script>

<style scoped lang="scss">
.signed-archive { padding: 24px; max-width: 1800px; }
.signed-archive h2 { margin-bottom: 8px; }
.archive-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-block: 24px 12px; }
.archive-filters label { display: flex; flex-direction: column; gap: 6px; }
.archive-filters input, .archive-filters select { width: 100%; min-height: 44px; }
.archive-filter-actions { display: flex; align-items: end; gap: 8px; flex-wrap: wrap; }
.archive-date-note { color: var(--color-text-maxcontrast); margin-block: 12px 20px; }
.archive-error { color: var(--color-error); }
.archive-table-scroll { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 14px 10px; text-align: start; vertical-align: top; border-bottom: 1px solid var(--color-border); }
th { background: var(--color-background-dark); white-space: nowrap; }
td small { display: block; margin-top: 6px; color: var(--color-text-maxcontrast); }
.archive-row-actions a { display: inline-block; margin-inline-end: 14px; margin-block: 6px; color: var(--color-primary-element); text-decoration: underline; }
.archive-pagination { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-block: 20px; }
.archive-history { padding: 20px; background: var(--color-background-dark); border-radius: var(--border-radius-large); }
.archive-history header { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.archive-history code { overflow-wrap: anywhere; }
.archive-history li { margin: 18px 0 18px 24px; list-style: decimal; }
</style>
