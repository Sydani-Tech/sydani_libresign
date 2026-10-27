<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
 <form class="document-filters" @submit.prevent="apply">
  <label>{{ t('libresign', 'Document name') }}<input v-model="draft.name" type="search" maxlength="200"></label>
  <div class="document-filters__sender"><SenderSelect v-model="draft.sender" :options="senders" /></div>
  <label>{{ t('libresign', 'Recipient Name') }}<input v-model="draft.recipientName" type="search" maxlength="200"></label>
  <label>{{ t('libresign', 'Recipient Email') }}<input v-model="draft.recipientEmail" type="search" maxlength="200"></label>
  <label>{{ t('libresign', 'Status') }}<select v-model="draft.status">
   <option value="all">{{ archive ? t('libresign', 'All protected documents') : t('libresign', 'All statuses') }}</option>
   <option v-if="!archive" value="draft">{{ t('libresign', 'Draft') }}</option>
   <option v-if="!archive" value="pending">{{ t('libresign', 'Awaiting signatures') }}</option>
   <option value="partial">{{ t('libresign', 'Partially signed') }}</option>
   <option value="completed">{{ t('libresign', 'Completed') }}</option>
   <option v-if="!archive" value="signing">{{ t('libresign', 'Signing in progress') }}</option>
  </select></label>
  <label>{{ t('libresign', 'Date filter') }}<select v-model="draft.dateBy"><option value="requested">{{ t('libresign', 'Date Requested') }}</option><option value="completed">{{ t('libresign', 'Date Completed') }}</option><option v-if="!archive" value="created">{{ t('libresign', 'Date Created') }}</option></select></label>
  <label>{{ t('libresign', 'From') }}<input v-model="draft.fromDate" type="date"></label>
  <label>{{ t('libresign', 'To') }}<input v-model="draft.toDate" type="date"></label>
  <label>{{ t('libresign', 'Sort by') }}<select v-model="draft.sortBy"><option value="requested">{{ t('libresign', 'Date Requested') }}</option><option value="completed">{{ t('libresign', 'Date Completed') }}</option><option v-if="!archive" value="created">{{ t('libresign', 'Date Created') }}</option><option value="name">{{ t('libresign', 'Document name') }}</option><option value="sender">{{ t('libresign', 'Sender username') }}</option><option v-if="!archive" value="status">{{ t('libresign', 'Status') }}</option><option v-if="!archive" value="signers">{{ t('libresign', 'Number of signers') }}</option></select></label>
  <label>{{ t('libresign', 'Order') }}<select v-model="draft.direction"><option value="desc">{{ t('libresign', 'Descending') }}</option><option value="asc">{{ t('libresign', 'Ascending') }}</option></select></label>
  <div class="document-filters__actions"><NcButton type="submit" variant="primary" :disabled="loading">{{ t('libresign', 'Apply filters') }}</NcButton><NcButton :disabled="loading" @click="reset">{{ t('libresign', 'Reset') }}</NcButton></div>
  <p v-if="error" class="document-filters__error" role="alert"><strong>{{ error }}</strong></p>
 </form>
</template>
<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import SenderSelect from './SenderSelect.vue'
import { documentFilterDefaults, type DocumentFilters, type SenderOption } from '../../services/documentFilters'
const props = defineProps<{ modelValue: DocumentFilters, senders: SenderOption[], archive?: boolean, loading?: boolean }>()
const emit = defineEmits<{ apply: [filters: DocumentFilters] }>()
const draft = reactive({ ...props.modelValue }), error = ref('')
watch(() => props.modelValue, value => Object.assign(draft, value), { deep: true })
function apply() {
 error.value = ''
 if (draft.fromDate && draft.toDate && draft.fromDate > draft.toDate) { error.value = t('libresign', 'The From date must not be later than the To date.'); return }
 emit('apply', { ...draft })
}
function reset() { Object.assign(draft, documentFilterDefaults(props.archive)); apply() }
</script>
<style scoped lang="scss">
.document-filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin-block:24px 16px;}
.document-filters label{display:flex;flex-direction:column;gap:6px;min-width:0;}
.document-filters input,.document-filters select{width:100%;min-height:44px;box-sizing:border-box;margin:0;}
.document-filters__sender{min-width:0;align-self:end;}
.document-filters__actions{display:flex;align-items:end;gap:8px;flex-wrap:wrap;grid-column:1/-1;}
.document-filters__error{grid-column:1/-1;padding:12px;border-inline-start:4px solid var(--color-error);background:var(--color-background-hover);}
@media(max-width:600px){.document-filters{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}.document-filters__sender{grid-column:span 1;}}
@media(max-width:360px){.document-filters{grid-template-columns:1fr;}}
</style>
