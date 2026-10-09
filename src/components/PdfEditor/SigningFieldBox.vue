<!--
SPDX-FileCopyrightText: 2026 Sydani Technology contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="signing-field" :class="{ 'signing-field--editable': editable }">
		<template v-if="editable && field?.elementId">
			<input v-if="type === 'checkbox'"
				:id="`signing-field-${field.elementId}`"
				v-model="fields.values[field.elementId]"
				type="checkbox"
				:aria-label="label"
				:required="field.metadata?.required !== false"
				@click.stop @pointerdown.stop @mousedown.stop @touchstart.stop @keydown.stop>
			<input v-else
				:id="`signing-field-${field.elementId}`"
				v-model="fields.values[field.elementId]"
				:type="type === 'date' ? 'date' : 'text'"
				:aria-label="label" :placeholder="label" maxlength="500"
				:required="field.metadata?.required !== false"
				@click.stop @pointerdown.stop @mousedown.stop @touchstart.stop @keydown.stop>
		</template>
		<template v-else>
			<span class="signing-field__label">{{ type === 'checkbox' ? '☑ ' : type === 'date' ? '▦ ' : '' }}{{ label }}</span>
			<small>{{ signerLabel }}{{ required ? ' *' : '' }}</small>
		</template>
	</div>
</template>
<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import { useSigningFieldsStore } from '../../store/signingFields'
import type { VisibleElementRecord } from '../../types/index'
const props = defineProps<{
	type: string
	field?: VisibleElementRecord | null
	metadata?: { label?: string, required?: boolean }
	signerLabel: string
	editable?: boolean
}>()
const fields = useSigningFieldsStore()
const label = computed(() => props.metadata?.label || props.field?.metadata?.label
	|| (props.type === 'checkbox' ? t('libresign', 'Checkbox') : props.type === 'date' ? t('libresign', 'Date') : t('libresign', 'Text')))
const required = computed(() => (props.metadata ?? props.field?.metadata)?.required !== false)
</script>
<style scoped lang="scss">
.signing-field {
	box-sizing: border-box;
	width: 100%;
	height: 100%;
	display: flex;
	flex-direction: column;
	justify-content: center;
	padding: 3px 6px;
	border: 1px dashed var(--color-primary-element);
	border-radius: 4px;
	background: var(--color-primary-element-light);
	overflow: hidden;
	color: var(--color-main-text);
	&__label { font-weight: 600; white-space: nowrap; }
	small { font-size: 10px; white-space: nowrap; }
	input:not([type='checkbox']) { width: 100%; min-height: 0; height: 100%; margin: 0; font-size: 12px; }
	input[type='checkbox'] { width: 18px; height: 18px; min-height: 0; margin: auto; appearance: auto; }
	&--editable { padding: 0; border-style: solid; pointer-events: auto; }
}
</style>
