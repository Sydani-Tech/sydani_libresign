/**
 * SPDX-FileCopyrightText: 2026 Sydani Technology contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { defineStore } from 'pinia'
import { ref } from 'vue'

export const isSigningField = (type: unknown): boolean => ['text', 'date', 'checkbox'].includes(String(type))

type SigningFieldDefinition = { type?: string, metadata?: { required?: boolean } | null }
export function getSigningFieldIssue(field: SigningFieldDefinition, value: unknown): 'required' | 'invalid' | null {
	const required = field.metadata?.required !== false
	if (field.type === 'checkbox') return required && value !== true ? 'required' : null
	if (value === undefined || value === null || (typeof value === 'string' && !value.trim())) return required ? 'required' : null
	if (typeof value !== 'string' || value.length > 500 || /[\x00-\x1F\x7F]/u.test(value)) return 'invalid'
	if (field.type === 'date') {
		const date = /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T00:00:00Z`) : null
		if (!date || !Number.isFinite(date.getTime()) || date.toISOString().slice(0, 10) !== value) return 'invalid'
	}
	return null
}

export const useSigningFieldsStore = defineStore('signingFields', () => {
	const values = ref<Record<number, string | boolean>>({})
	const reset = () => { values.value = {} }
	return { values, reset }
})
