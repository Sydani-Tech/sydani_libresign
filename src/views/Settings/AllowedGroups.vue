<!--
  - SPDX-FileCopyrightText: 2021 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcSettingsSection
		:name="signatureRequestAccessTitle"
		:description="signatureRequestAccessDescription"
	>
		<NcCheckboxRadioSwitch v-model="allSignedInUsers"
			type="switch"
			:disabled="savingAccess"
			@update:model-value="saveAllSignedInUsers">
			{{ t('libresign', 'All signed-in users') }}
		</NcCheckboxRadioSwitch>
		<p v-if="allSignedInUsers">
			{{ t('libresign', 'Every signed-in Nextcloud user can upload documents and request signatures. Anonymous visitors cannot.') }}
		</p>
		<p v-else>
			{{ t('libresign', 'Select authorized groups. The admin group is allowed by default.') }}
		</p>
		<NcSelect v-if="!allSignedInUsers"
			:key="idKey"
			v-model="groupsSelected"
			label="displayname"
			:no-wrap="false"
			:aria-label-combobox="allowedGroupsAriaLabel"
			:close-on-select="false"
			:disabled="loadingGroups"
			:loading="isSearching"
			:multiple="true"
			:options="groups"
			:searchable="true"
			:show-no-options="false"
			@search="searchGroup"
			@update:modelValue="saveGroups" />
	</NcSettingsSection>
</template>

<script setup lang="ts">
import axios from '@nextcloud/axios'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { generateOcsUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'
import { onMounted, ref } from 'vue'

import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import type { AdminInitialState } from '../../types'

import logger from '../../logger.js'
import { usePoliciesStore } from '../../store/policies'
import {
	DEFAULT_REQUEST_SIGN_DENY_GROUPS,
	DEFAULT_REQUEST_SIGN_GROUPS,
	resolveDeniedRequestSignGroups,
	resolveRequestSignGroups,
	serializeRequestSignGroups,
} from './PolicyWorkbench/settings/request-sign-groups/model'

import '@nextcloud/password-confirmation/style.css'

defineOptions({
	name: 'AllowedGroups',
})

type GroupRow = {
	id: string
	displayname: string
}

const groupsSelected = ref<Array<GroupRow | string>>([])
const groups = ref<GroupRow[]>([])
const loadingGroups = ref(false)
const isSearching = ref(false)
const idKey = ref(0)
const policiesStore = usePoliciesStore()

// TRANSLATORS Settings section title for configuring which groups may create signature requests.
const signatureRequestAccessTitle = t('libresign', 'Signature request access')
// TRANSLATORS Settings section description explaining that delegated group admins may only authorize groups they manage, while the default admin group always keeps this permission.
const signatureRequestAccessDescription = t('libresign', 'Choose which groups are authorized to create signature requests. Delegated group admins may authorize only groups they manage. The default admin group always has this permission.')
// TRANSLATORS Accessible label for the group selector used to choose which groups may create signature requests.
const allowedGroupsAriaLabel = t('libresign', 'Choose groups authorized to create signature requests. Delegated group admins may authorize only groups they manage.')
const allSignedInUsers = ref(loadState<AdminInitialState['allow_all_signed_in_request_sign']>('libresign', 'allow_all_signed_in_request_sign', true))
const savingAccess = ref(false)

async function saveAllSignedInUsers() {
	savingAccess.value = true
	try {
		await confirmPassword()
		await axios.post(generateOcsUrl('apps/libresign/api/v1/admin/all-signed-in-request-sign/config'), {
			enabled: allSignedInUsers.value,
		})
	} catch (error) {
		allSignedInUsers.value = !allSignedInUsers.value
		logger.error('Could not update request-to-sign access', { error })
	} finally {
		savingAccess.value = false
	}
}

async function getData() {
	loadingGroups.value = true
	await policiesStore.fetchEffectivePolicies()
	const selected = resolveRequestSignGroups(policiesStore.getEffectiveValue('groups_request_sign') ?? {
		allowGroups: DEFAULT_REQUEST_SIGN_GROUPS,
		denyGroups: DEFAULT_REQUEST_SIGN_DENY_GROUPS,
	})
	groupsSelected.value = groups.value.filter((group: GroupRow) => selected.includes(group.id))
	loadingGroups.value = false
}

async function saveGroups(value: Array<GroupRow | string>) {
	if (Array.isArray(value)) {
		groupsSelected.value = value
	}

	await confirmPassword()

	const groupIds = groupsSelected.value.map((g: GroupRow | string) => {
		if (typeof g === 'object') {
			return g.id
		}
		return g
	})
	const existingPolicyValue = policiesStore.getEffectiveValue('groups_request_sign')
	const denyGroupIds = resolveDeniedRequestSignGroups(existingPolicyValue)

	await policiesStore.saveSystemPolicy('groups_request_sign', serializeRequestSignGroups({
		allowGroups: groupIds,
		denyGroups: denyGroupIds,
	}), false)
	idKey.value += 1
}

async function searchGroup(query: string) {
	isSearching.value = true
	try {
		const { data } = await axios.get(generateOcsUrl('cloud/groups/details'), {
			params: {
				search: query,
				limit: 20,
				offset: 0,
			},
		})
		groups.value = data.ocs.data.groups.sort((a: GroupRow, b: GroupRow) => a.displayname.localeCompare(b.displayname))
	} catch (error: unknown) {
		logger.debug('Could not search by groups', { error })
	} finally {
		isSearching.value = false
	}
}

onMounted(async () => {
	await searchGroup('')
	await getData()
})

defineExpose({
	groupsSelected,
	groups,
	loadingGroups,
	isSearching,
	idKey,
	allSignedInUsers,
	savingAccess,
	saveAllSignedInUsers,
	getData,
	saveGroups,
	searchGroup,
})
</script>
