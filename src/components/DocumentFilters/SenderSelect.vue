<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
 <div class="sender-filter"><span>{{ t('libresign', 'Sender') }}</span><NcSelect :model-value="selected" :options="options" label="name" track-by="id" :searchable="true" :clearable="true" :placeholder="t('libresign', 'All Senders')" :aria-label-combobox="t('libresign', 'Sender')" label-outside append-to-body @update:model-value="select" /></div>
</template>
<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import type { SenderOption } from '../../services/documentFilters'
const props = defineProps<{ modelValue: string, options: SenderOption[] }>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
const selected = computed(() => props.options.find(option => option.id === props.modelValue) ?? null)
function select(option: SenderOption | null) { emit('update:modelValue', option?.id ?? '') }
</script>
<style scoped>.sender-filter{display:flex;flex-direction:column;gap:6px;min-width:0;}.sender-filter :deep(.v-select){width:100% !important;min-width:0 !important;max-width:100%;}</style>
