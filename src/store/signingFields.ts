/**
 * SPDX-FileCopyrightText: 2026 Sydani Technology contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { defineStore } from 'pinia'
import { ref } from 'vue'

export const isSigningField = (type: unknown): boolean => ['text', 'date', 'checkbox'].includes(String(type))

export const useSigningFieldsStore = defineStore('signingFields', () => {
	const values = ref<Record<number, string | boolean>>({})
	const reset = () => { values.value = {} }
	return { values, reset }
})
