/*
 * SPDX-FileCopyrightText: 2026 Sydani Technology contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import SigningFieldBox from '../../../components/PdfEditor/SigningFieldBox.vue'
import { useSigningFieldsStore } from '../../../store/signingFields'
import type { VisibleElementRecord } from '../../../types/index'

vi.mock('@nextcloud/l10n', () => globalThis.mockNextcloudL10n())

const field = (type: string): VisibleElementRecord => ({
	elementId: 42,
	fileId: 1,
	signRequestId: 2,
	type,
	metadata: { label: 'Approval', required: true },
	coordinates: { page: 1, top: 12, left: 10, width: 100, height: 30 },
})

describe('SigningFieldBox.vue', () => {
	it('makes an automatic signing date read-only without reading the browser clock', () => {
		setActivePinia(createPinia())
		const date = { ...field('date'), metadata: { label: 'Signed on', required: true, useSigningDate: true } }
		const wrapper = mount(SigningFieldBox, { props: { type: 'date', field: date, signerLabel: 'External signer', editable: true } })
		expect(wrapper.find('input').exists()).toBe(false)
		expect(wrapper.text()).toContain('Will use the signing date')
		expect(useSigningFieldsStore().values[42]).toBeUndefined()
	})
	it('shows the automatic setting in the creator preview independently of Required', () => {
		setActivePinia(createPinia())
		const wrapper = mount(SigningFieldBox, { props: { type: 'date', metadata: { label: 'Signed on', required: false, useSigningDate: true }, signerLabel: 'Ada' } })
		expect(wrapper.text()).toContain('Use signing date')
		expect(wrapper.text()).not.toContain('*')
	})
	beforeEach(() => setActivePinia(createPinia()))

	it('captures typed values against the persisted field ID', async () => {
		const wrapper = mount(SigningFieldBox, { props: { type: 'text', field: field('text'), signerLabel: 'Ada', editable: true } })
		await wrapper.get('input').setValue('Ada Lovelace')
		expect(useSigningFieldsStore().values[42]).toBe('Ada Lovelace')
	})

	it('captures a required checkbox without changing the field definition', async () => {
		const wrapper = mount(SigningFieldBox, { props: { type: 'checkbox', field: field('checkbox'), signerLabel: 'Ada', editable: true } })
		await wrapper.get('input').setValue(true)
		expect(useSigningFieldsStore().values[42]).toBe(true)
		expect(wrapper.get('input').attributes('required')).toBeDefined()
	})

	it('shows the label and assigned signer to the sender', () => {
		const wrapper = mount(SigningFieldBox, { props: { type: 'date', field: field('date'), signerLabel: 'Ada', editable: false } })
		expect(wrapper.find('input').exists()).toBe(false)
		expect(wrapper.text()).toContain('Approval')
		expect(wrapper.text()).toContain('Ada')
	})

	it('does not prefix a text-field label with a redundant T', () => {
		const wrapper = mount(SigningFieldBox, { props: { type: 'text', field: field('text'), signerLabel: 'Ada', editable: false } })
		expect(wrapper.get('.signing-field__label').text()).toBe('Approval')
	})
})
