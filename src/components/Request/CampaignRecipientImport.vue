<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="recipient-import">
		<p>{{ t('libresign', 'Upload an Excel (.xlsx) or CSV file containing names and email addresses. Review the detected columns before adding recipients. Nothing is sent during import.') }}</p>
		<FilePicker :label="t('libresign', 'Recipient Excel or CSV file')" :filename="filename" :hint="t('libresign', 'Excel or CSV · Up to 5 MB · 2000 recipients')" accept=".xlsx,.csv" :disabled="disabled || busy" @change="upload" />
		<p v-if="busy" role="status">{{ t('libresign', 'Reading and validating recipients…') }}</p>
		<template v-if="sheet">
			<div class="recipient-import__mapping">
				<label v-if="sheet.sheets.length > 1">{{ t('libresign', 'Worksheet') }}<select :value="sheet.sheet" :disabled="disabled || busy" @change="changeSheet"><option v-for="item in sheet.sheets" :key="item">{{ item }}</option></select></label>
				<label>{{ t('libresign', 'Recipient Name column') }}<select v-model.number="nameColumn" :disabled="disabled || busy" @change="resetValidation"><option :value="-1">{{ t('libresign', 'Choose column') }}</option><option v-for="column in columns" :key="column.index" :value="column.index">{{ column.label }}</option></select></label>
				<label>{{ t('libresign', 'Recipient Email column') }}<select v-model.number="emailColumn" :disabled="disabled || busy" @change="resetValidation"><option :value="-1">{{ t('libresign', 'Choose column') }}</option><option v-for="column in columns" :key="column.index" :value="column.index">{{ column.label }}</option></select></label>
			</div>
			<label class="recipient-import__header"><input v-model="header" type="checkbox" :disabled="disabled || busy" @change="resetValidation">{{ t('libresign', 'First row contains column headings') }}</label>
			<p v-if="nameColumn < 0 || emailColumn < 0 || nameColumn === emailColumn" class="recipient-import__warning" role="alert">{{ t('libresign', 'Choose two different columns for recipient name and email.') }}</p>
			<template v-if="preview.length">
				<div class="recipient-import__counts"><strong>{{ preview.length }} {{ t('libresign', 'rows') }}</strong><span>{{ validCount }} {{ t('libresign', 'valid') }}</span><span>{{ preview.length - validCount }} {{ t('libresign', 'need attention or will be skipped') }}</span></div>
				<p>{{ t('libresign', 'Correct names and email addresses below, or remove any row. Email validation checks format, not whether a mailbox exists. Validate again after editing; invalid and duplicate rows are not added.') }}</p>
				<div class="recipient-import__table"><table><thead><tr><th>{{ t('libresign', 'Row') }}</th><th>{{ t('libresign', 'Recipient Name') }}</th><th>{{ t('libresign', 'Recipient Email') }}</th><th>{{ t('libresign', 'Validation') }}</th><th>{{ t('libresign', 'Actions') }}</th></tr></thead><tbody><tr v-for="row in visibleRows" :key="row.row" :class="{'recipient-import__invalid': row.error}"><td>{{ row.row }}</td><td><input :value="edits[row.row]?.name ?? row.name" :aria-label="t('libresign', 'Recipient name, row {row}', {row:row.row})" :disabled="disabled || busy" maxlength="64" @input="editRow(row, 'name', ($event.target as HTMLInputElement).value)"></td><td><input :value="edits[row.row]?.email ?? row.email" type="email" :aria-label="t('libresign', 'Recipient email, row {row}', {row:row.row})" :aria-invalid="!!row.error" :disabled="disabled || busy" maxlength="254" @input="editRow(row, 'email', ($event.target as HTMLInputElement).value)"></td><td><strong v-if="row.error">{{ row.error }}</strong><span v-else>{{ row.recipient ? row.recipient.kind === 'internal' ? t('libresign', 'Internal account') : t('libresign', 'External recipient') : t('libresign', 'Ready for account validation') }}</span></td><td><NcButton :disabled="disabled || busy" :aria-label="t('libresign', 'Remove row {row}', {row:row.row})" @click="removeRow(row.row)">{{ t('libresign', 'Remove') }}</NcButton></td></tr></tbody></table></div>
				<div v-if="preview.length > 50" class="recipient-import__actions"><NcButton :disabled="page === 0" @click="page--">{{ t('libresign', 'Previous') }}</NcButton><span>{{ page + 1 }} / {{ Math.ceil(preview.length / 50) }}</span><NcButton :disabled="(page + 1) * 50 >= preview.length" @click="page++">{{ t('libresign', 'Next') }}</NcButton></div>
				<div class="recipient-import__actions"><NcButton v-if="!validated" variant="primary" :disabled="disabled || busy || !validCount || preview.length > 2000" @click="validate">{{ t('libresign', 'Validate recipients') }}</NcButton><NcButton v-else variant="primary" :disabled="disabled || busy || !ready.length" @click="add">{{ t('libresign', 'Add {count} valid recipients', {count: ready.length}) }}</NcButton></div>
			</template>
		</template>
		<p v-if="error" class="recipient-import__warning" role="alert"><strong>{{ error }}</strong></p>
		<p v-if="notice" role="status">{{ notice }}</p>
	</div>
</template>
<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import {t} from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import FilePicker from '../FilePicker.vue'
import {campaignPost, campaignError, type CampaignRecipient} from '../../services/campaigns'
import {detectImportColumns, mapImportRows, type ImportSheet, type ImportRow} from '../../services/campaignImport'
const props = defineProps<{disabled?: boolean, existing?: CampaignRecipient[], counter?: CampaignRecipient | null}>()
const emit = defineEmits<{(event: 'add-many', recipients: CampaignRecipient[]): void}>()
const sheet = ref<ImportSheet | null>(null), filename = ref(''), encoded = ref(''), busy = ref(false), error = ref(''), notice = ref('')
const nameColumn = ref(-1), emailColumn = ref(-1), header = ref(true), validated = ref(false), checked = ref<ImportRow[]>([]), page = ref(0)
const edits = ref<Record<number, {name:string, email:string}>>({}), removed = ref<number[]>([])
const columns = computed(() => Array.from({length: Math.max(0, ...(sheet.value?.rows.map(row => row.cells.length) || []))}, (_, index) => ({index, label: `${index + 1}: ${sheet.value?.rows[0]?.cells[index] || t('libresign', 'Unnamed column')}`})))
const mappedSheet = computed(() => sheet.value ? {...sheet.value, rows:sheet.value.rows.filter((source,index) => (header.value && index === 0) || !removed.value.includes(source.row)).map(source => {
	const cells = [...source.cells], patch = edits.value[source.row]
	if(patch && nameColumn.value >= 0 && emailColumn.value >= 0) {cells[nameColumn.value]=patch.name;cells[emailColumn.value]=patch.email}
	return {...source,cells}
})} : null)
const preview = computed(() => validated.value ? checked.value : mappedSheet.value ? mapImportRows(mappedSheet.value, nameColumn.value, emailColumn.value, header.value, props.existing, props.counter) : [])
const visibleRows = computed(() => preview.value.slice(page.value * 50, (page.value + 1) * 50))
const validCount = computed(() => preview.value.filter(row => !row.error).length)
const ready = computed(() => checked.value.filter(row => !row.error && row.recipient).map(row => row.recipient!))
function resetValidation(keepPage: unknown = false) { validated.value = false; checked.value = []; error.value = ''; notice.value = ''; if(keepPage !== true) page.value = 0 }
function editRow(row:ImportRow, field:'name'|'email', value:string) {edits.value[row.row]={...(edits.value[row.row] || {name:row.name,email:row.email}),[field]:value};resetValidation(true)}
function removeRow(row:number) {removed.value.push(row);resetValidation(true);page.value=Math.min(page.value,Math.max(0,Math.ceil(preview.value.length/50)-1))}
watch([() => props.existing, () => props.counter], resetValidation, {deep:true})
async function read(sheetName = '') {
	busy.value = true; error.value = ''; resetValidation(); edits.value={};removed.value=[]
	try { sheet.value = await campaignPost<ImportSheet>('campaign/import', {file: encoded.value, filename: filename.value, sheet: sheetName}); const mapping = detectImportColumns(sheet.value.rows); nameColumn.value = mapping.name; emailColumn.value = mapping.email; header.value = mapping.header }
	catch (e) { sheet.value = null; error.value = campaignError(e) } finally { busy.value = false }
}
async function upload(event: Event) {
	const input = event.target as HTMLInputElement, file = input.files?.[0]
	if (!file) return
	sheet.value = null; resetValidation(); encoded.value = ''; filename.value = ''
	if (file.size > 5 * 1024 * 1024 || !/\.(xlsx|csv)$/i.test(file.name)) { error.value = t('libresign', 'Choose an .xlsx or .csv file no larger than 5 MB.'); input.value = ''; return }
	busy.value = true
	try { encoded.value = await new Promise<string>((resolve, reject) => {const reader = new FileReader(); reader.onload = () => resolve(String(reader.result).split(',')[1]); reader.onerror = reject; reader.readAsDataURL(file)}); filename.value = file.name; await read() }
	catch { error.value = t('libresign', 'Unable to read the selected recipient file.') } finally { busy.value = false; input.value = '' }
}
async function changeSheet(event: Event) { await read((event.target as HTMLSelectElement).value) }
async function validate() {
	if (busy.value) return
	busy.value = true; error.value = ''
	const rows = preview.value.map(row => ({...row})), valid = rows.filter(row => !row.error)
	try {
		const result = await campaignPost<Array<{index: number, recipient: CampaignRecipient | null, error: string | null}>>('campaign/resolve-import', {recipients: valid.map(row => ({name: row.name, email: row.email}))})
		for (const item of result) { const row = valid[item.index]; if (!row) continue; row.error = item.error || ''; row.recipient = item.recipient || undefined }
		checked.value = rows; validated.value = true
	} catch (e) { error.value = campaignError(e) } finally { busy.value = false }
}
function add() { emit('add-many', ready.value); notice.value = t('libresign', 'Valid recipients added to the review list. No invitations have been sent.'); sheet.value = null; encoded.value = ''; filename.value = ''; checked.value = []; validated.value = false }
</script>
<style scoped lang="scss">
.recipient-import > p { margin-bottom: 14px; color: var(--color-text-maxcontrast); }
.recipient-import__upload { display: flex; flex-direction: column; gap: 6px; border: 2px dashed var(--color-border-maxcontrast); border-radius: 12px; padding: 20px; cursor: pointer; background: var(--color-main-background); }
.recipient-import__upload span { font-weight: 650; }.recipient-import__upload input { width: 100%; margin-top: 8px; }
.recipient-import__mapping { display: grid; grid-template-columns: repeat(auto-fit,minmax(180px,1fr)); gap: 14px; margin-top: 20px; }
label { display: flex; flex-direction: column; gap: 6px; }select { width: 100%; margin: 0; min-height: 44px; }
.recipient-import__header { flex-direction: row; align-items: center; margin-block: 14px; gap: 8px; }.recipient-import__header input { width: 20px; height: 20px; margin: 0; }
.recipient-import__counts, .recipient-import__actions { display: flex; flex-wrap: wrap; align-items: center; gap: 14px; margin-block: 16px; }
.recipient-import__table { max-height: 350px; overflow: auto; border: 1px solid var(--color-border); border-radius: 10px; }table { width: 100%; border-collapse: collapse; }th,td { padding: 12px; text-align: start; border-bottom: 1px solid var(--color-border); overflow-wrap: anywhere; }th { position: sticky; top: 0; background: var(--color-background-dark); }
.recipient-import__table input { width:100%; min-width:170px; min-height:42px; margin:0; box-sizing:border-box; }.recipient-import__table input[type=email] {min-width:230px;}.recipient-import__table input[aria-invalid=true] {border-color:var(--color-error);}
.recipient-import__invalid td:last-child { color: var(--color-main-text); background: var(--color-background-dark); border-inline-start: 4px solid var(--color-error); }
.recipient-import__warning { padding: 14px; border: 1px solid var(--color-error); border-inline-start: 5px solid var(--color-error); border-radius: 8px; color: var(--color-main-text) !important; background: var(--color-main-background); }
@media(max-width:767px) { select { font-size:16px; } }
</style>
