<!-- SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
     SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="campaign-recipient-picker">
		<label>{{ t('libresign', 'Find a Nextcloud user') }}<input v-model="query" type="search" :disabled="disabled" maxlength="200" @input="scheduleSearch"></label>
		<ul v-if="users.length" class="campaign-recipient-picker__results">
			<li v-for="user in users" :key="user.uid"><button type="button" :disabled="disabled" @click="choose(user)">{{ user.name }}<small>{{ user.email }}</small></button></li>
		</ul>
		<div class="campaign-recipient-picker__inputs">
			<label>{{ t('libresign', 'Recipient Name') }}<input v-model="name" :disabled="disabled" maxlength="64" autocomplete="off"></label>
			<label>{{ t('libresign', 'Recipient Email') }}<input v-model="email" :disabled="disabled" type="email" maxlength="254" autocomplete="off" @input="uid = null"></label>
			<NcButton :disabled="disabled || resolving || !name.trim() || !email.trim()" @click="add">{{ t('libresign', 'Add recipient') }}</NcButton>
		</div>
		<p class="campaign-recipient-picker__note">{{ t('libresign', 'An email matching an existing Nextcloud account is internal. Otherwise it is an external recipient; external signing must already be enabled in LibreSign.') }}</p>
		<details v-if="allowGroups">
			<summary>{{ t('libresign', 'Add members of a Nextcloud group') }}</summary>
			<label>{{ t('libresign', 'Find a group') }}<input v-model="groupQuery" type="search" maxlength="200" :disabled="disabled" @input="searchGroups"></label>
			<div class="campaign-recipient-picker__inputs"><label>{{ t('libresign', 'Recipient group') }}<select v-model="groupId" :disabled="disabled"><option value="">{{ t('libresign', 'Select group') }}</option><option v-for="group in groups" :key="group.id" :value="group.id">{{ group.displayname }}</option></select></label><NcButton :disabled="disabled || resolving || !groupId" @click="addGroup">{{ t('libresign', 'Add group members') }}</NcButton></div>
			<p>{{ t('libresign', 'Only active accounts with a valid email are included. Review the final recipient list before sending.') }}</p>
		</details>
		<p v-if="error" role="alert">{{ error }}</p>
	</div>
</template>
<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { campaignGet, campaignPost, campaignError, type CampaignRecipient } from '../../services/campaigns'
defineProps<{disabled?: boolean, allowGroups?: boolean}>()
const emit = defineEmits<{(event: 'add', recipient: CampaignRecipient): void, (event: 'add-many', recipients: CampaignRecipient[]): void}>()
const query = ref(''); const name = ref(''); const email = ref(''); const uid = ref<string | null>(null)
const users = ref<CampaignRecipient[]>([]); const groups = ref<Array<{id: string, displayname: string}>>([])
const groupQuery = ref(''); const groupId = ref(''); const resolving = ref(false); const error = ref('')
let timer: ReturnType<typeof setTimeout> | undefined
let generation = 0
function scheduleSearch() {
	clearTimeout(timer)
	const current = ++generation
	timer = setTimeout(async () => {
		try { const result = await campaignGet<CampaignRecipient[]>('campaign/users', {search: query.value}); if (generation === current) users.value = result }
		catch (e) { if (generation === current) error.value = campaignError(e) }
	}, 300)
}
function choose(user: CampaignRecipient) { name.value = user.name; email.value = user.email; uid.value = user.uid ?? null; query.value = ''; users.value = []; generation++ }
async function add() {
	resolving.value = true; error.value = ''
	try { const result = await campaignPost<CampaignRecipient>('campaign/resolve', {recipient: {name: name.value, email: email.value, uid: uid.value}}); emit('add', result); name.value = ''; email.value = ''; uid.value = null }
	catch (e) { error.value = campaignError(e) } finally { resolving.value = false }
}
async function searchGroups() {
	try { groups.value = await campaignGet('campaign/groups', {search: groupQuery.value}) } catch (e) { error.value = campaignError(e) }
}
async function addGroup() {
	resolving.value = true; error.value = ''
	try { const result = await campaignGet<CampaignRecipient[]>(`campaign/groups/${encodeURIComponent(groupId.value)}/users`); emit('add-many', result) }
	catch (e) { error.value = campaignError(e) } finally { resolving.value = false }
}
onBeforeUnmount(() => { generation++; clearTimeout(timer) })
</script>
<style scoped lang="scss">
.campaign-recipient-picker { padding: 16px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); background: var(--color-background-dark); }
label { display: flex; flex-direction: column; gap: 6px; }
input, select { min-height: 44px; width: 100%; box-sizing: border-box; margin: 0; }
.campaign-recipient-picker__inputs { display: flex; flex-wrap: wrap; align-items: end; gap: 12px; margin-block: 12px; }
.campaign-recipient-picker__inputs label { flex: 1 1 220px; }
.campaign-recipient-picker__results { list-style: none; padding: 0; max-height: 240px; overflow-y: auto; }
.campaign-recipient-picker__results button { display: block; width: 100%; text-align: start; height: auto; min-height: 44px; margin-block: 4px; }
small { display: block; color: var(--color-text-maxcontrast); }
.campaign-recipient-picker__note { color: var(--color-text-maxcontrast); }
summary { cursor: pointer; margin-block: 12px; font-weight: bold; }
[role=alert] { color: var(--color-error); margin-top: 12px; }
@media (max-width: 767px) { input { font-size: 16px; } }
</style>
