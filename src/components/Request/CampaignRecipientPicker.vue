<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="campaign-recipient-picker">
		<div class="recipient-methods" role="group" :aria-label="t('libresign', 'Add recipients')">
			<button v-for="item in methods" :key="item.id" type="button" :aria-pressed="method === item.id" :disabled="disabled || resolving" @click="selectMethod(item.id)"><NcIconSvgWrapper :path="item.icon" :size="22" /><span>{{ item.label }}</span></button>
		</div>
		<div class="recipient-method-panel">
			<template v-if="method === 'internal'">
				<h4>{{ t('libresign', 'Add Internal Users') }}</h4><p>{{ t('libresign', 'Search active Nextcloud accounts by name or email, then add the people you need.') }}</p>
				<label>{{ t('libresign', 'Find a Nextcloud user') }}<input v-model="query" type="search" :disabled="disabled || resolving" maxlength="200" :placeholder="t('libresign', 'Search by name or email')" @input="scheduleSearch"></label>
				<p v-if="searching" role="status">{{ t('libresign', 'Searching…') }}</p>
				<ul v-if="users.length" class="recipient-results"><li v-for="user in users" :key="user.uid"><div><strong>{{ user.name }}</strong><small>{{ user.email }}</small></div><NcButton :disabled="disabled || resolving || alreadySelected(user)" @click="choose(user)">{{ alreadySelected(user) ? t('libresign', 'Added') : t('libresign', 'Add user') }}</NcButton></li></ul>
				<p v-else-if="searched && !searching">{{ t('libresign', 'No active accounts found. Use Add an External User if this person has no Nextcloud account.') }}</p>
			</template>
			<template v-else-if="method === 'group'">
				<h4>{{ t('libresign', 'Add from a Group') }}</h4><p>{{ t('libresign', 'Add a snapshot of active group members. You can remove individuals from the review list before sending.') }}</p>
				<div class="recipient-inputs"><label>{{ t('libresign', 'Find a group') }}<input v-model="groupQuery" type="search" maxlength="200" :disabled="disabled || resolving" @input="searchGroups"></label><label>{{ t('libresign', 'Recipient group') }}<select v-model="groupId" :disabled="disabled || resolving"><option value="">{{ t('libresign', 'Select group') }}</option><option v-for="group in groups" :key="group.id" :value="group.id">{{ group.displayname }}</option></select></label></div>
				<NcButton variant="primary" :disabled="disabled || resolving || !groupId" @click="addGroup">{{ t('libresign', 'Add group members') }}</NcButton>
			</template>
			<template v-else-if="method === 'external'">
				<h4>{{ t('libresign', 'Add an External User') }}</h4><p>{{ t('libresign', 'Enter the recipient’s name and email. If the email belongs to an existing account, they will be recognised as an internal user.') }}</p>
				<div class="recipient-inputs"><label>{{ t('libresign', 'Recipient Name') }}<input v-model="name" :disabled="disabled || resolving" maxlength="64" autocomplete="off" :placeholder="t('libresign', 'Full name')"></label><label>{{ t('libresign', 'Recipient Email') }}<input v-model="email" :disabled="disabled || resolving" type="email" maxlength="254" autocomplete="off" placeholder="name@example.com"></label></div>
				<NcButton variant="primary" :disabled="disabled || resolving || !name.trim() || !email.trim()" @click="add">{{ t('libresign', 'Add recipient') }}</NcButton>
			</template>
			<CampaignRecipientImport v-else :disabled="disabled || resolving" :existing="existing" :counter="counter" @add-many="emit('add-many', $event)" />
			<p v-if="error" class="recipient-error" role="alert"><strong>{{ error }}</strong></p>
		</div>
	</div>
</template>
<script setup lang="ts">
import {computed, onBeforeUnmount, ref} from 'vue'
import {t} from '@nextcloud/l10n'
import {showError} from '@nextcloud/dialogs'
import {mdiAccountMultipleOutline, mdiAccountGroupOutline, mdiAccountPlusOutline, mdiFileExcelOutline} from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import CampaignRecipientImport from './CampaignRecipientImport.vue'
import {campaignGet, campaignPost, campaignError, type CampaignRecipient} from '../../services/campaigns'
const props = defineProps<{apiBase?: string, disabled?: boolean, allowGroups?: boolean, existing?: CampaignRecipient[], counter?: CampaignRecipient | null}>()
const emit = defineEmits<{(event: 'add', recipient: CampaignRecipient): void, (event: 'add-many', recipients: CampaignRecipient[]): void}>()
const methods = computed(() => [{id:'internal', label:t('libresign','Add Internal Users'), icon:mdiAccountMultipleOutline}, ...(props.allowGroups ? [{id:'group', label:t('libresign','Add from a Group'), icon:mdiAccountGroupOutline}] : []), {id:'external', label:t('libresign','Add an External User'), icon:mdiAccountPlusOutline}, ...(props.allowGroups ? [{id:'import', label:t('libresign','Add from an Excel File'), icon:mdiFileExcelOutline}] : [])])
const method = ref('internal'), query = ref(''), name = ref(''), email = ref('')
const users = ref<CampaignRecipient[]>([]), groups = ref<Array<{id:string,displayname:string}>>([])
const groupQuery = ref(''), groupId = ref(''), resolving = ref(false), error = ref(''), searching = ref(false), searched = ref(false)
let timer: ReturnType<typeof setTimeout> | undefined, generation = 0, groupGeneration = 0
function fail(e: unknown) { error.value = campaignError(e); showError(error.value) }
function alreadySelected(user: CampaignRecipient) { return props.existing?.some(row => Boolean(row.uid && row.uid === user.uid) || row.email.toLowerCase() === user.email.toLowerCase()) }
function selectMethod(value: string) { method.value = value; error.value = ''; if (value === 'group') void searchGroups() }
function scheduleSearch() {
	clearTimeout(timer); const current = ++generation; users.value = []; searched.value = false
	if (!query.value.trim()) { searching.value = false; return }
	searching.value = true
	timer = setTimeout(async () => {
		try {const result = await campaignGet<CampaignRecipient[]>((props.apiBase || 'campaign')+'/users', {search:query.value}); if (generation === current) {users.value=result; searched.value=true}}
		catch(e) {if(generation === current) fail(e)} finally {if(generation === current) searching.value=false}
	},300)
}
async function resolve(recipient: CampaignRecipient) {
	resolving.value=true;error.value=''
	try {const result=await campaignPost<CampaignRecipient>((props.apiBase || 'campaign')+'/resolve',{recipient});emit('add',result);name.value='';email.value=''} catch(e){fail(e)} finally{resolving.value=false}
}
async function choose(user: CampaignRecipient) {await resolve(user)}
async function add() {await resolve({name:name.value,email:email.value})}
async function searchGroups(){const current=++groupGeneration;try{const result=await campaignGet<Array<{id:string,displayname:string}>>('campaign/groups',{search:groupQuery.value});if(current===groupGeneration) groups.value=result}catch(e){if(current===groupGeneration)fail(e)}}
async function addGroup(){resolving.value=true;error.value='';try{emit('add-many',await campaignGet<CampaignRecipient[]>(`campaign/groups/${encodeURIComponent(groupId.value)}/users`))}catch(e){fail(e)}finally{resolving.value=false}}
onBeforeUnmount(()=>{generation++;groupGeneration++;clearTimeout(timer)})
</script>
<style scoped lang="scss">
.campaign-recipient-picker { border:1px solid var(--color-border); border-radius:14px; overflow:hidden; }
.recipient-methods { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:8px; padding:12px; background:var(--color-background-hover); }
.recipient-methods button { display:flex; align-items:center; justify-content:flex-start; gap:10px; white-space:normal; text-align:start; min-height:68px; height:auto; margin:0; padding:12px; border:1px solid var(--color-border); border-radius:10px; background:var(--color-main-background); color:var(--color-main-text); font-weight:600; }
.recipient-methods button[aria-pressed=true] { border-color:var(--color-primary-element); box-shadow:inset 0 0 0 1px var(--color-primary-element); background:var(--color-primary-element-light); }
.recipient-method-panel { padding:22px; background:var(--color-main-background); }.recipient-method-panel h4 { font-size:17px; font-weight:650; margin-bottom:6px; }.recipient-method-panel p { color:var(--color-text-maxcontrast); margin-bottom:14px; line-height:1.5; }
label { display:flex; flex-direction:column; gap:6px; font-weight:550; }input,select { min-height:46px; width:100%; box-sizing:border-box; margin:0; }
.recipient-inputs { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; margin-block:16px; }
.recipient-results { list-style:none; padding:0; max-height:280px; overflow-y:auto; margin-top:12px; }.recipient-results li { display:flex; justify-content:space-between; gap:12px; align-items:center; padding:12px; border-bottom:1px solid var(--color-border); }.recipient-results small { display:block; color:var(--color-text-maxcontrast); overflow-wrap:anywhere; }
.recipient-method-panel .recipient-error { color:var(--color-main-text); background:var(--color-background-dark); border-inline-start:5px solid var(--color-error); border-radius:8px; padding:14px; margin-top:16px; }
@media(max-width:1000px){.recipient-methods{grid-template-columns:repeat(2,minmax(0,1fr));}}
@media(max-width:767px){.recipient-method-panel{padding:14px;}.recipient-methods{gap:6px;padding:8px;}.recipient-methods button{font-size:13px;min-height:62px;padding:8px;}.recipient-inputs{grid-template-columns:1fr;}input,select{font-size:16px;}}
</style>
