/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { interpolateL10n } from '../../testHelpers/l10n.js'
import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'

import FilesListTableHeaderActions from '../../../views/FilesList/FilesListTableHeaderActions.vue'

const showErrorMock = vi.fn()
const showSuccessMock = vi.fn()
const loggerErrorMock = vi.fn()

const filesStoreMock = reactive({
	loading: false,
	files: {
		1: { id: 1, loading: null },
		2: { id: 2, loading: null },
	},
	deleteMultiple: vi.fn(),
	canDelete: vi.fn((_file: {id: number}) => true),
})

const selectionStoreMock = reactive({
	selected: [1, 2],
	reset: vi.fn(),
	set: vi.fn(),
})

vi.mock('@nextcloud/l10n', () => globalThis.mockNextcloudL10n({
	t: (_app: string, text: string, params?: Record<string, string>) => interpolateL10n(text, params),
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn((...args: unknown[]) => showErrorMock(...args)),
	showSuccess: vi.fn((...args: unknown[]) => showSuccessMock(...args)),
}))

vi.mock('../../../logger.js', () => ({
	default: {
		error: vi.fn((...args: unknown[]) => loggerErrorMock(...args)),
	},
}))

vi.mock('../../../store/files.js', () => ({
	useFilesStore: vi.fn(() => filesStoreMock),
}))

vi.mock('../../../store/selection.js', () => ({
	useSelectionStore: vi.fn(() => selectionStoreMock),
}))

vi.mock('@nextcloud/vue/components/NcActions', () => ({
	default: {
		name: 'NcActions',
		props: ['disabled'],
		template: '<div class="nc-actions-stub"><slot /></div>',
	},
}))

vi.mock('@nextcloud/vue/components/NcActionButton', () => ({
	default: {
		name: 'NcActionButton',
		emits: ['click'],
		template: '<button class="nc-action-button-stub" @click="$emit(\'click\')"><slot /><slot name="icon" /></button>',
	},
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: {
		name: 'NcButton',
		emits: ['click'],
		template: '<button class="nc-button-stub" @click="$emit(\'click\')"><slot /><slot name="icon" /></button>',
	},
}))

vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({
	default: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<label class="checkbox-radio-switch-stub"><slot /></label>',
	},
}))

vi.mock('@nextcloud/vue/components/NcDialog', () => ({
	default: {
		name: 'NcDialog',
		template: '<div class="nc-dialog-stub"><slot /><slot name="actions" /></div>',
	},
}))

vi.mock('@nextcloud/vue/components/NcIconSvgWrapper', () => ({
	default: {
		name: 'NcIconSvgWrapper',
		template: '<i class="nc-icon-svg-wrapper-stub" />',
	},
}))

vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({
	default: {
		name: 'NcLoadingIcon',
		template: '<span class="nc-loading-icon-stub" />',
	},
}))

describe('FilesListTableHeaderActions.vue', () => {
	const createWrapper = () => mount(FilesListTableHeaderActions)

	beforeEach(() => {
		filesStoreMock.loading = false
		filesStoreMock.files = {
			1: { id: 1, loading: null },
			2: { id: 2, loading: null },
		}
		filesStoreMock.deleteMultiple.mockReset()
        filesStoreMock.canDelete.mockReset().mockReturnValue(true)
		selectionStoreMock.selected = [1, 2]
		selectionStoreMock.reset.mockReset()
		selectionStoreMock.set.mockReset()
		showErrorMock.mockReset()
		showSuccessMock.mockReset()
		loggerErrorMock.mockReset()
	})

	it('registers the delete batch action on mount', async () => {
		const wrapper = createWrapper()
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.enabledMenuActions).toHaveLength(1)
		expect(wrapper.vm.enabledMenuActions[0].id).toBe('delete')
		expect(wrapper.find('.nc-action-button-stub').text()).toContain('Delete')
	})

    it.each([[1],[1,2]])('hides Delete for protected and mixed selections %j without displaying an error',async(id1,id2)=>{
        filesStoreMock.canDelete.mockImplementation(file=>file.id!==1)
        selectionStoreMock.selected=id2 ? [id1,id2] : [id1]
        const wrapper=createWrapper();await flushPromises()
        expect(wrapper.find('.files-list__row-actions-batch-delete').exists()).toBe(false)
        expect(showErrorMock).not.toHaveBeenCalled()
        expect(filesStoreMock.deleteMultiple).not.toHaveBeenCalled();wrapper.unmount()
    })
    it('updates visibility when selecting drafts, protected records, or an unknown record',async()=>{
        filesStoreMock.canDelete.mockImplementation(file=>file.id!==1)
        selectionStoreMock.selected=[2]
        const wrapper=createWrapper();await flushPromises()
        expect(wrapper.find('.files-list__row-actions-batch-delete').exists()).toBe(true)
        selectionStoreMock.selected=[1,2];await wrapper.vm.$nextTick()
        expect(wrapper.find('.files-list__row-actions-batch-delete').exists()).toBe(false)
        selectionStoreMock.selected=[999];await wrapper.vm.$nextTick()
        expect(wrapper.find('.files-list__row-actions-batch-delete').exists()).toBe(false)
        selectionStoreMock.selected=[2];await wrapper.vm.$nextTick()
        expect(wrapper.find('.files-list__row-actions-batch-delete').exists()).toBe(true);wrapper.unmount()
    })
    it('does not delete queued records that become protected before confirmation',async()=>{
        const wrapper=createWrapper();await flushPromises()
        wrapper.vm.toDelete=[1,2];wrapper.vm.confirmDelete=true
        filesStoreMock.canDelete.mockReturnValue(false)
        wrapper.vm.doDelete();await flushPromises()
        expect(filesStoreMock.deleteMultiple).not.toHaveBeenCalled()
        expect(wrapper.vm.confirmDelete).toBe(false);wrapper.unmount()
    })
    it('shows success and resets selection after a successful batch action', async () => {
		const wrapper = createWrapper()
		const action = {
			id: 'archive',
			displayName: vi.fn(() => 'Archive'),
			iconSvgInline: vi.fn(() => '<svg />'),
			execBatch: vi.fn(async () => [true, true]),
		}

		await wrapper.vm.onActionClick(action)

		expect(filesStoreMock.files[1].loading).toBeUndefined()
		expect(filesStoreMock.files[2].loading).toBeUndefined()
		expect(showSuccessMock).toHaveBeenCalledWith('"Archive" batch action executed successfully')
		expect(selectionStoreMock.reset).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.loading).toBeNull()
	})

	it('keeps only failed sources selected when a batch action partially fails', async () => {
		const wrapper = createWrapper()
		const action = {
			id: 'archive',
			displayName: vi.fn(() => 'Archive'),
			iconSvgInline: vi.fn(() => '<svg />'),
			execBatch: vi.fn(async () => [false, true]),
		}

		await wrapper.vm.onActionClick(action)

		expect(selectionStoreMock.set).toHaveBeenCalledWith([1])
		expect(showErrorMock).toHaveBeenCalledWith('"Archive" failed on some elements ')
		expect(selectionStoreMock.reset).not.toHaveBeenCalled()
	})

	it('deletes the queued files and clears selection after confirmation', async () => {
		filesStoreMock.deleteMultiple.mockResolvedValue(undefined)
		const wrapper = createWrapper()

		wrapper.vm.toDelete = [1, 2]
		wrapper.vm.deleteFile = false
		wrapper.vm.doDelete()
		await flushPromises()

		expect(filesStoreMock.deleteMultiple).toHaveBeenCalledWith([1, 2], false)
		expect(selectionStoreMock.reset).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.toDelete).toEqual([])
		expect(wrapper.vm.deleting).toBe(false)
	})
})
