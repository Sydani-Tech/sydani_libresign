// SPDX-License-Identifier: AGPL-3.0-or-later
import type { CampaignRecipient } from './campaigns'
export type ImportSheet = {rows: Array<{row: number, cells: string[]}>, sheets: string[], sheet: string}
export type ImportRow = {row: number, name: string, email: string, error: string, recipient?: CampaignRecipient}
const nameHeader = /^(recipient\s*name|full\s*name|name|employee\s*name|staff\s*name)$/i
const emailHeader = /^(recipient\s*e[ -]?mail|e[ -]?mail(?:\s*address)?|work\s*e[ -]?mail)$/i
export function validImportEmail(value: string): boolean {
	// Deliberately conservative UX check. PHP performs authoritative validation.
	return value.length <= 254 && /^[A-Za-z0-9.!#$%&'*+\/=?^_`{|}~-]+@[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)+$/.test(value)
		&& !value.split('@')[0].startsWith('.') && !value.split('@')[0].endsWith('.') && !value.split('@')[0].includes('..')
}
export function detectImportColumns(rows: ImportSheet['rows']) {
	const first = rows[0]?.cells || []
	const emailIndex = first.findIndex(value => emailHeader.test(value.trim()))
	const nameIndex = first.findIndex(value => nameHeader.test(value.trim()))
	const header = emailIndex >= 0 || nameIndex >= 0
	const width = Math.max(0, ...rows.map(row => row.cells.length))
	const sample = rows.slice(header ? 1 : 0, 51)
	const scores = Array.from({length: width}, (_, column) => sample.filter(row => validImportEmail((row.cells[column] || '').trim())).length)
	const best = Math.max(0, ...scores)
	const email = emailIndex >= 0 ? emailIndex : best ? scores.indexOf(best) : -1
	const name = nameIndex >= 0 ? nameIndex : width === 2 && email >= 0 ? 1 - email : -1
	return {header, email, name}
}
export function mapImportRows(sheet: ImportSheet, nameColumn: number, emailColumn: number, header: boolean, existing: CampaignRecipient[] = [], counter?: CampaignRecipient | null): ImportRow[] {
	if (nameColumn < 0 || emailColumn < 0 || nameColumn === emailColumn) return []
	const seen = new Set(existing.map(row => row.email.trim().toLowerCase()))
	return sheet.rows.slice(header ? 1 : 0).map(source => {
		const name = (source.cells[nameColumn] || '').trim(), email = (source.cells[emailColumn] || '').trim().toLowerCase()
		let error = !name ? 'Recipient name is missing.' : new TextEncoder().encode(name).length > 64 ? 'Recipient name must be at most 64 bytes.' : !validImportEmail(email) ? 'Invalid email address.' : email === counter?.email.toLowerCase() ? 'The countersigner cannot also be a campaign recipient.' : seen.has(email) ? 'Duplicate or already selected recipient.' : ''
		if (name.startsWith('[Formula:') || email.startsWith('[formula:')) error = 'Paste formula results as values before importing.'
		if (!error) seen.add(email)
		return {row: source.row, name, email, error}
	})
}
