<!--
  - SPDX-FileCopyrightText: 2021 LibreCode coop and LibreCode contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div>
		<SupportProject />
		<CertificateEngine />
		<SignatureEngine />
		<DownloadBinaries />
		<ConfigureCheck />
		<RootCertificateCfssl />
		<RootCertificateOpenSsl />
		<SettingsPolicyWorkbench />
		<AllowedGroups />
		<CampaignGroups />
		<ScheduledReminders />
	</div>
</template>

<script setup lang="ts">
import AllowedGroups from './AllowedGroups.vue'
import CampaignGroups from './CampaignGroups.vue'
import ScheduledReminders from './ScheduledReminders.vue'
import CertificateEngine from './CertificateEngine.vue'
import ConfigureCheck from './ConfigureCheck.vue'
import DownloadBinaries from './DownloadBinaries.vue'
import SettingsPolicyWorkbench from './PolicyWorkbench/Catalog/Catalog.vue'
import RootCertificateCfssl from './RootCertificateCfssl.vue'
import RootCertificateOpenSsl from './RootCertificateOpenSsl.vue'
import SignatureEngine from './SignatureEngine.vue'
import SupportProject from './SupportProject.vue'

defineOptions({
	name: 'Settings',
	components: {
		CertificateEngine,
		ConfigureCheck,
		DownloadBinaries,
		RootCertificateCfssl,
		RootCertificateOpenSsl,
		SettingsPolicyWorkbench,
		SignatureEngine,
		SupportProject,
	},
})
</script>
