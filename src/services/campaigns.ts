/* SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

export type CampaignRecipient = {
	id?: number, name: string, email: string, uid?: string | null, kind?: 'internal' | 'external', status?: string,
	fileId?: number | null, fileUuid?: string | null, requestedAt?: string | null, completedAt?: string | null,
	recipientSignedAt?: string | null, error?: string | null, exemptionReason?: string | null,
}
export type CampaignField = { role: 'recipient' | 'counter', type: 'signature' | 'text' | 'date' | 'checkbox', coordinates: {page: number, left: number, top: number, width: number, height: number}, metadata: {label: string, required: boolean} }
export type CampaignDraft = { name: string, version: string, message: string, dueAt: number | null, recipients: CampaignRecipient[], counter: CampaignRecipient | null, fields: CampaignField[], pdf?: string, sourceName: string }
export type Campaign = CampaignDraft & {
	id: number, revision: number, status: 'draft' | 'active' | 'cancelled', sourceHash: string,
	owner: {uid: string, name: string}, createdAt: number, requestedAt: number | null,
	summary: Record<string, number>, events: Array<{id: number, action: string, actor: string, createdAt: number, details: Record<string, unknown>}>,
}
export const campaignUrl = (path: string) => generateOcsUrl('/apps/libresign/api/v1/' + path)
export async function campaignGet<T>(path: string, params?: Record<string, unknown>): Promise<T> {
	const response = await axios.get(campaignUrl(path), { params })
	return response.data.ocs.data as T
}
export async function campaignPost<T>(path: string, payload: Record<string, unknown> = {}): Promise<T> {
	const response = await axios.post(campaignUrl(path), payload)
	return response.data.ocs.data as T
}
export async function campaignPut<T>(path: string, payload: Record<string, unknown>): Promise<T> {
	const response = await axios.put(campaignUrl(path), payload)
	return response.data.ocs.data as T
}
export function campaignError(error: unknown): string {
	const e = error as {response?: {data?: {ocs?: {data?: {message?: string}, meta?: {message?: string}}, message?: string}}}
	return e.response?.data?.ocs?.data?.message || e.response?.data?.message || e.response?.data?.ocs?.meta?.message || 'Unable to complete the campaign operation. Please try again.'
}
export function mergeCampaignRecipients(existing: CampaignRecipient[], added: CampaignRecipient[]) {
	const result = [...existing]
	const recipientKeys = (row: CampaignRecipient) => ['email:' + row.email.trim().toLowerCase(), ...(row.uid ? ['account:' + row.uid] : [])]
	const keys = new Set(existing.flatMap(recipientKeys))
	let skipped = 0
	for (const row of added) {
		const rowKeys = recipientKeys(row)
		if (rowKeys.some(key => keys.has(key))) { skipped++; continue }
		rowKeys.forEach(key => keys.add(key))
		result.push(row)
	}
	return { recipients: result, skipped }
}
export function campaignDate(value: number | string | null | undefined): string {
	if (!value) return '—'
	const normalised = typeof value === 'number' ? value * 1000 : (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value) ? value.replace(' ', 'T') + 'Z' : value)
	const date = new Date(normalised)
	return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('en-GB', {day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'}).format(date)
}
