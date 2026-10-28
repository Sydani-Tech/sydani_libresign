// SPDX-License-Identifier: AGPL-3.0-or-later
import {describe,it,expect,vi,beforeEach} from 'vitest'
import {mount,flushPromises} from '@vue/test-utils'
import Reminders from '../../../views/Settings/Reminders.vue'
import {campaignGet,campaignPost} from '../../../services/campaigns'
vi.mock('@nextcloud/l10n',()=>globalThis.mockNextcloudL10n())
vi.mock('../../../services/campaigns',async()=>({...await vi.importActual('../../../services/campaigns'),campaignGet:vi.fn(),campaignPost:vi.fn()}))
vi.mock('@nextcloud/password-confirmation',()=>({confirmPassword:vi.fn()}))
vi.mock('@nextcloud/vue/components/NcSettingsSection',()=>({default:{template:'<section><slot /></section>'}}))
vi.mock('@nextcloud/vue/components/NcButton',()=>({default:{props:['type','disabled'],template:'<button :type="type" :disabled="disabled"><slot /></button>'}}))
const policy={enabled:false,intervalHours:24,firstAfterDays:1,maxReminders:10,stopAfterDays:30,sendTime:'09:00',secondTime:'17:00',until:'',timezone:'Africa/Lagos'}
beforeEach(()=>{vi.clearAllMocks();vi.mocked(campaignGet).mockResolvedValue({...policy});vi.mocked(campaignPost).mockResolvedValue({...policy})})
describe('Administrator reminder settings',()=>{
 it('loads the global policy without enabling reminders',async()=>{const w=mount(Reminders);await flushPromises();expect(campaignGet).toHaveBeenCalledWith('admin/reminder-schedule');expect(w.find('input[type=checkbox]').element.checked).toBe(false);expect(campaignPost).not.toHaveBeenCalled();w.unmount()})
 it('shows a second WAT send time for twice daily',async()=>{const w=mount(Reminders);await flushPromises();await w.find('select').setValue('12');expect(w.findAll('input[type=time]')).toHaveLength(2);expect(w.text()).toContain('Second send time (WAT)');w.unmount()})
 it('saves limits, duration and an explicit stop date through the admin endpoint',async()=>{const w=mount(Reminders);await flushPromises();await w.find('input[type=date]').setValue('2026-12-31');await w.find('form').trigger('submit');await flushPromises();expect(campaignPost).toHaveBeenCalledWith('admin/reminder-schedule',{policy:expect.objectContaining({until:'2026-12-31',maxReminders:10,stopAfterDays:30,timezone:'Africa/Lagos'})});expect(w.text()).toContain('Reminder settings saved.');w.unmount()})
 it('makes saving failures visible',async()=>{vi.mocked(campaignPost).mockRejectedValue({response:{data:{message:'Invalid reminder interval'}}});const w=mount(Reminders);await flushPromises();await w.find('form').trigger('submit');await flushPromises();expect(w.find('[role=alert]').text()).toContain('Invalid reminder interval');w.unmount()})
})
