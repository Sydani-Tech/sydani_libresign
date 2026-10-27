// SPDX-License-Identifier: AGPL-3.0-or-later
export type SenderOption = { id: string, name: string }
export type DocumentFilters = { name: string, sender: string, recipientName: string, recipientEmail: string, status: string, dateBy: string, fromDate: string, toDate: string, sortBy: string, direction: string }
export function documentFilterDefaults(archive = false): DocumentFilters {
 return { name: '', sender: '', recipientName: '', recipientEmail: '', status: archive ? 'completed' : 'all', dateBy: archive ? 'completed' : 'requested', fromDate: '', toDate: '', sortBy: archive ? 'completed' : 'requested', direction: 'desc' }
}
export function documentFilterParams(filters: DocumentFilters) {
 const { fromDate, toDate, ...rest } = filters
 return { ...rest, from: fromDate ? Math.floor(new Date(fromDate + 'T00:00:00').getTime() / 1000) : undefined, to: toDate ? Math.floor(new Date(toDate + 'T23:59:59').getTime() / 1000) : undefined }
}
