/**
 * SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import OrganisationalArchive from '../../views/OrganisationalArchive.vue'

const auth = vi.hoisted(() => ({ admin: true }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'testuser', isAdmin: auth.admin }) }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/l10n', () => globalThis.mockNextcloudL10n())
vi.mock('@nextcloud/router', () => ({ generateUrl: (path: string, params: Record<string, unknown> = {}) => path.replace(/\{(\w+)\}/g, (_, key) => String(params[key])) }))
vi.mock('@nextcloud/vue/components/NcAppContent', () => ({ default: { template: '<main><slot /></main>' } }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { template: '<span />' } }))

vi.mock('@nextcloud/vue/components/NcDialog', () => ({ default: { name:'NcDialog', props:['name','open'], emits:['closing'], template:'<section role="dialog" :aria-label="name"><h2>{{ name }}</h2><button aria-label="Close dialog" @click="$emit(\'closing\')">×</button><slot /><footer><slot name="actions" /></footer></section>' } }))

const record = {
	id: 7, uuid: 'document-uuid', name: 'Archive QA', sender: { id: 'alice', name: 'Alice' },
	recipients: [{ name: 'Bob', order: 1, statusText: 'Signed', signedAt: '2026-10-15T10:00:00Z', ipAddress: null, identifiers: [], fields: [] }],
	requestedAt: null, completedAt: '2026-10-15T10:00:00Z', statusText: 'Signed', sourceDeleted: true, hash: 'abc',
}
describe('Organisational archive', () => {
	beforeEach(() => {
		auth.admin = true
		vi.mocked(axios.get).mockReset().mockResolvedValue({ data: { data: [record], total: 1, senders: [{ id: 'alice', name: 'Alice' }] } })
	})
	it('shows protected documents, independent source deletion, and honest legacy dates', async () => {
		const wrapper = mount(OrganisationalArchive)
		await flushPromises()
		expect(wrapper.text()).toContain('Archive QA')
		expect(wrapper.text()).toContain('Not recorded')
		expect(wrapper.text()).toContain('Source deleted; signed record preserved')
		expect(wrapper.find('a[href="/apps/libresign/archive/file/7"]').exists()).toBe(true)
		expect(wrapper.findAll('button').some(button => button.text() === 'Delete')).toBe(false)
		wrapper.unmount()
	})
	it('shows history in a dialog and preserves the archive filters and listing when closed',async()=>{
        const wrapper=mount(OrganisationalArchive);await flushPromises()
        await wrapper.find('input[type=search]').setValue('Archive')
        vi.mocked(axios.get).mockResolvedValueOnce({data:record})
        await wrapper.findAll('button').find(b=>b.text()==='Signing history')!.trigger('click');await flushPromises()
        expect(wrapper.find('[role=dialog]').attributes('aria-label')).toBe('Signing history — Archive QA')
        expect(wrapper.find('[role=dialog]').text()).toContain('Signed document SHA-256')
        expect(wrapper.find('[role=dialog] ol').text()).toContain('Bob')
        expect(wrapper.findAll('.signed-archive > .archive-history')).toHaveLength(0)
        await wrapper.find('[role=dialog] footer button').trigger('click')
        expect(wrapper.find('[role=dialog]').exists()).toBe(false)
        expect((wrapper.find('input[type=search]').element as HTMLInputElement).value).toBe('Archive')
        expect(wrapper.find('tbody').text()).toContain('Archive QA')
        expect(axios.get).toHaveBeenCalledTimes(2);wrapper.unmount()
    })
    it('opens immediately while loading and ignores a late response after closing',async()=>{
        const wrapper=mount(OrganisationalArchive);await flushPromises()
        let finish!:(value:unknown)=>void
        vi.mocked(axios.get).mockReturnValueOnce(new Promise(resolve=>{finish=resolve}))
        await wrapper.findAll('button').find(b=>b.text()==='Signing history')!.trigger('click')
        expect(wrapper.find('[role=dialog] [role=status]').text()).toContain('Loading signing history')
        await wrapper.find('[aria-label="Close dialog"]').trigger('click')
        finish({data:record});await flushPromises()
        expect(wrapper.find('[role=dialog]').exists()).toBe(false);wrapper.unmount()
    })
    it('keeps a history lookup failure inside the dialog without stale details',async()=>{
        const wrapper=mount(OrganisationalArchive);await flushPromises()
        vi.mocked(axios.get).mockRejectedValueOnce(new Error('Forbidden'))
        await wrapper.findAll('button').find(b=>b.text()==='Signing history')!.trigger('click');await flushPromises()
        expect(wrapper.find('[role=dialog] [role=alert]').text()).toContain('Unable to load signing history')
        expect(wrapper.find('[role=dialog] ol').exists()).toBe(false)
        expect(wrapper.find('.archive-error').exists()).toBe(false);wrapper.unmount()
    })
    it('does not let an older response replace another document history',async()=>{
        const second={...record,id:8,name:'Second document',recipients:[{...record.recipients[0],name:'Carol'}]}
        vi.mocked(axios.get).mockResolvedValueOnce({data:{data:[record,second],total:2,senders:[]}})
        const wrapper=mount(OrganisationalArchive);await flushPromises()
        let finish!:(value:unknown)=>void
        vi.mocked(axios.get).mockReturnValueOnce(new Promise(resolve=>{finish=resolve}))
        const buttons=wrapper.findAll('button').filter(b=>b.text()==='Signing history')
        await buttons[0].trigger('click');await wrapper.find('[aria-label="Close dialog"]').trigger('click')
        vi.mocked(axios.get).mockResolvedValueOnce({data:second})
        await buttons[1].trigger('click');await flushPromises();finish({data:record});await flushPromises()
        expect(wrapper.find('[role=dialog]').text()).toContain('Second document')
        expect(wrapper.find('[role=dialog] ol').text()).toContain('Carol')
        expect(wrapper.find('[role=dialog] ol').text()).not.toContain('Bob');wrapper.unmount()
    })
    it('does not fetch or expose archive records for ordinary users', async () => {
		auth.admin = false
		const wrapper = mount(OrganisationalArchive)
		await flushPromises()
		expect(axios.get).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('Administrator access required.')
		expect(wrapper.text()).not.toContain('Archive QA')
		wrapper.unmount()
	})
	it('sends distinct requested-date filters with sender and recipient', async () => {
		const wrapper = mount(OrganisationalArchive)
		await flushPromises()
		wrapper.findComponent({ name: 'SenderSelect' }).vm.$emit('update:modelValue', 'alice')
		const dateFilter = wrapper.findAll('label').find(label => label.text().startsWith('Date filter'))!.find('select')
		await dateFilter.setValue('requested')
		await wrapper.findAll('input[type="search"]')[1].setValue('Bob')
		await wrapper.findAll('input[type="search"]')[2].setValue('bob@example.test')
		const dates = wrapper.findAll('input[type="date"]')
		await dates[0].setValue('2026-10-01')
		await dates[1].setValue('2026-10-15')
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(axios.get).toHaveBeenLastCalledWith('/apps/libresign/archive/list', expect.objectContaining({ params: expect.objectContaining({
			sender: 'alice', recipientName: 'Bob', recipientEmail: 'bob@example.test', dateBy: 'requested',
			from: Math.floor(new Date('2026-10-01T00:00:00').getTime() / 1000),
			to: Math.floor(new Date('2026-10-15T23:59:59').getTime() / 1000),
		}) }))
		wrapper.unmount()
	})
	it('does not retain a stale listing after access fails', async () => {
		const wrapper = mount(OrganisationalArchive)
		await flushPromises()
		vi.mocked(axios.get).mockRejectedValueOnce(new Error('Forbidden'))
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(wrapper.text()).not.toContain('Archive QA')
		expect(wrapper.find('[role="alert"]').exists()).toBe(true)
		wrapper.unmount()
	})
})
