<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template><NcNoteCard :type="verified ? 'success' : 'warning'">{{ verified ? t('libresign','Your signature was recorded and the signed content passed verification.') : t('libresign','Your signature was recorded. Verification needs review; check the results before relying on this document.') }}</NcNoteCard></template>
<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { verificationSummary, type LongTermValidation } from '../../services/verificationSummary'
const props=defineProps<{document:{signers?:Parameters<typeof verificationSummary>[0],longTermValidation?:LongTermValidation,files?:Array<{signers?:Parameters<typeof verificationSummary>[0],longTermValidation?:LongTermValidation}>}}>()
const verified=computed(()=>{const documents=props.document.files?.length?props.document.files:[props.document];return documents.every(document=>{const s=verificationSummary(document.signers||[],document.longTermValidation);return s.integrity&&!s.changed&&!s.modificationIssue&&!s.failedProof})})
</script>
