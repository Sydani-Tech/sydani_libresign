<!-- SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
     SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcSettingsSection :name="t('libresign', 'Campaign')" :description="t('libresign', 'Choose which groups may create signing campaigns and track their own recipients. Administrators are always allowed and can manage all campaigns.')">
		<p>{{ t('libresign', 'This permission is separate from ordinary signature requests. No selected groups means administrators only.') }}</p>
		<NcSelect v-model="selected" label="displayname" :multiple="true" :close-on-select="false" :options="options" :loading="loading" :disabled="saving" :aria-label-combobox="t('libresign', 'Groups allowed to create campaigns')" @search="search" />
		<NcButton variant="primary" :disabled="loading || saving" @click="save">{{ t('libresign', 'Save campaign groups') }}</NcButton>
		<p v-if="error" role="alert">{{ error }}</p><p v-if="message" role="status">{{ message }}</p>
	</NcSettingsSection>
</template>
<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { confirmPassword } from '@nextcloud/password-confirmation'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcButton from '@nextcloud/vue/components/NcButton'
import { campaignGet, campaignPost, campaignError } from '../../services/campaigns'
type Group = {id: string, displayname: string}
const selected = ref<Group[]>([])
const options = ref<Group[]>([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const message = ref('')
async function search(query = '') {
	loading.value = true
	try { const found = await campaignGet<Group[]>('campaign/groups', {search: query}); options.value = [...selected.value, ...found.filter(row => !selected.value.some(chosen => chosen.id === row.id))] }
	catch (e) { error.value = campaignError(e) } finally { loading.value = false }
}
async function save() {
	saving.value = true; error.value = ''; message.value = ''
	try { await confirmPassword(); const data = await campaignPost<{groups: Group[]}>('campaign-settings', {groups: selected.value.map(row => row.id)}); selected.value = data.groups; message.value = t('libresign', 'Campaign permissions saved. Refresh LibreSign to update its navigation.') }
	catch (e) { error.value = campaignError(e) } finally { saving.value = false }
}
onMounted(async () => {
	try { const data = await campaignGet<{groups: Group[]}>('campaign-settings'); selected.value = data.groups; await search() }
	catch (e) { error.value = campaignError(e) }
})
</script>
