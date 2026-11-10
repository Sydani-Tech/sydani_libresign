/*
 * SPDX-FileCopyrightText: 2026 Sydani Technology contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { getSigningFieldIssue } from '../../store/signingFields'

describe('signing field completion', () => {
	it.each([undefined, '', '1999-12-31', 'malicious client value'])('does not ask the signer to complete a server-controlled date (%s)', value => {
		expect(getSigningFieldIssue({ type: 'date', metadata: { required: true, useSigningDate: true } }, value)).toBeNull()
	})
	it('does not apply the signing-date option to a text field', () => {
		expect(getSigningFieldIssue({ type: 'text', metadata: { useSigningDate: true } }, '')).toBe('required')
	})
	it.each([undefined, null, '', '   '])('requires a value for text and date (%s)', value => {
		for (const type of ['text', 'date']) expect(getSigningFieldIssue({ type }, value)).toBe('required')
	})
	it.each([false, undefined, 'true'])('requires explicit checkbox consent (%s)', value => {
		expect(getSigningFieldIssue({ type: 'checkbox' }, value)).toBe('required')
	})
	it('accepts completed text and checkbox values', () => {
		expect(getSigningFieldIssue({ type: 'text' }, 'Ada Lovelace')).toBeNull()
		expect(getSigningFieldIssue({ type: 'checkbox' }, true)).toBeNull()
	})
	it.each(['text', 'date', 'checkbox'])('allows an empty optional %s', type => {
		expect(getSigningFieldIssue({ type, metadata: { required: false } }, undefined)).toBeNull()
	})
	it.each(['2026-10-19', '2028-02-29'])('accepts a real date (%s)', value => {
		expect(getSigningFieldIssue({ type: 'date' }, value)).toBeNull()
	})
	it.each(['2026-02-29', '2026-13-01', '2026-04-31', '19/10/2026', 'not a date'])('rejects an invalid date (%s)', value => {
		expect(getSigningFieldIssue({ type: 'date' }, value)).toBe('invalid')
	})
	it('validates optional values when supplied', () => {
		expect(getSigningFieldIssue({ type: 'date', metadata: { required: false } }, 'wrong')).toBe('invalid')
	})
	it.each(['x'.repeat(501), 'line\nbreak', true])('rejects invalid text values', value => {
		expect(getSigningFieldIssue({ type: 'text' }, value)).toBe('invalid')
	})
})
