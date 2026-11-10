/**
 * SPDX-FileCopyrightText: 2026 Sydani Technology contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { VisibleElementRecord } from '../types/index'

export const normalizeVisibleElements = (elements: VisibleElementRecord[]): VisibleElementRecord[] =>
	elements.flatMap((element) => {
		if (!['signature', 'initial', 'text', 'date', 'datetime', 'checkbox'].includes(element.type)) {
			return []
		}

		if (!element.coordinates) {
			return []
		}

		const page = Number(element.coordinates.page)
		const left = Number(element.coordinates.left)
		const top = Number(element.coordinates.top)
		const normalizedFileId = Number(element.fileId)
		const normalizedSignRequestId = Number(element.signRequestId)
		const normalizedElementId = Number(element.elementId)
		const rawWidth = element.coordinates.width
		const rawHeight = element.coordinates.height
		const width = rawWidth === undefined ? undefined : Number(rawWidth)
		const height = rawHeight === undefined ? undefined : Number(rawHeight)

		if (![page, left, top].every(Number.isFinite)) {
			return []
		}

		if (!Number.isFinite(normalizedFileId) || !Number.isFinite(normalizedSignRequestId) || !Number.isFinite(normalizedElementId)) {
			return []
		}

		if ((width !== undefined && !Number.isFinite(width)) || (height !== undefined && !Number.isFinite(height))) {
			return []
		}

		return [{
			...element,
			type: element.type,
			elementId: normalizedElementId,
			fileId: normalizedFileId,
			signRequestId: normalizedSignRequestId,
			coordinates: {
				page,
				left,
				top,
				...(width !== undefined ? { width } : {}),
				...(height !== undefined ? { height } : {}),
			},
		} satisfies VisibleElementRecord]
	})
