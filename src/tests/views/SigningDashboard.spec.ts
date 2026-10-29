// SPDX-License-Identifier: AGPL-3.0-or-later
import {afterEach,beforeEach,describe,expect,it,vi} from 'vitest'
import {flushPromises,mount,type VueWrapper} from '@vue/test-utils'
import Dashboard from '../../views/SigningDashboard.vue'
import {campaignGet} from '../../services/campaigns'
vi.mock('@nextcloud/l10n',()=>globalThis.mockNextcloudL10n())
vi.mock('@nextcloud/auth',()=>({getCurrentUser:()=>({isAdmin:false})}))
vi.mock('@nextcloud/initial-state',()=>({loadState:()=>true}))
vi.mock('../../services/campaigns',async()=>({...await vi.importActual('../../services/campaigns'),campaignGet:vi.fn()}))
vi.mock('@nextcloud/vue/components/NcButton',()=>({default:{props:['disabled'],template:'<button :disabled="disabled"><slot /></button>'}}))
vi.mock('vue-router',()=>({RouterLink:{name:'RouterLink',props:['to'],template:'<a><slot /></a>'}}))
const data={counts:{ready:2,signed:5,sent:3,completed:4},perPage:10,total:2,documents:[{id:1,uuid:'uuid-one',signingUuid:'signer-one',name:'Handbook copy',status:1,sender:'HR',requestedAt:'2026-10-23 10:00:00',completedAt:null,campaign:'Handbook 2026',deadline:null},{id:2,uuid:'uuid-two',signingUuid:'signer-two',name:'Individual contract',status:1,sender:'HR',requestedAt:'2026-10-23 10:00:00',campaign:null}]}
let w:VueWrapper
describe('Personal signing dashboard',()=>{
 beforeEach(()=>{vi.clearAllMocks();vi.mocked(campaignGet).mockImplementation(async(path)=>path==='campaign/access'?{canCreate:false}:data);w=mount(Dashboard)})
 afterEach(()=>w.unmount())
 it('combines campaign and ordinary signing requests in the personal inbox',async()=>{await flushPromises();expect(w.text()).toContain('Handbook copy');expect(w.text()).toContain('Individual contract');expect(w.text()).toContain('Handbook 2026');expect(w.findAll('tbody a')).toHaveLength(2)})
 it('opens the personal signing request for ready documents, and verification for signed history',async()=>{await flushPromises();const ready=w.findAllComponents({name:'RouterLink'}).filter(c=>(c.attributes('class')||'').includes('dashboard-action'));expect(ready[0].props('to')).toEqual({name:'SignPDF',params:{uuid:'signer-one'}});expect(ready[1].props('to')).toEqual({name:'SignPDF',params:{uuid:'signer-two'}});await w.findAll('.dashboard-metrics button')[1].trigger('click');await flushPromises();expect(w.findAllComponents({name:'RouterLink'}).filter(c=>(c.attributes('class')||'').includes('dashboard-action'))[0].props('to')).toEqual({name:'ValidationFile',params:{uuid:'uuid-one'}})})
 it('does not grant ordinary users campaign management or archive shortcuts',async()=>{await flushPromises();expect(w.text()).not.toContain('Manage campaigns');expect(w.text()).not.toContain('Signed Archive')})
 it('offers permitted actions before refresh and Files access at both ends',async()=>{await flushPromises();expect(w.find('header').text()).toContain('Request Signature');expect(w.find('header').text()).not.toContain('Create Campaign');expect(w.findAll('.dashboard-inbox button').filter(b=>b.text().includes('Open All Documents'))).toHaveLength(2);expect(w.text()).toContain('Awaiting Completion');expect(w.text()).toContain('Showing up to 10 documents per page')})
 it('groups four cards by direction and loads completed outbound requests',async()=>{await flushPromises();expect(w.text()).not.toContain('Waiting for my turn');expect(w.findAll('.dashboard-metrics button')).toHaveLength(4);expect(w.findAll('.dashboard-direction').map(n=>n.text())).toEqual(['Inbound','Inbound','Outbound','Outbound']);await w.findAll('button').find(b=>b.text().includes('Signing Completed'))!.trigger('click');await flushPromises();expect(campaignGet).toHaveBeenCalledWith('dashboard',{view:'completed',page:1})})
 it('refreshes every ten minutes rather than every thirty seconds',async()=>{vi.useFakeTimers();const other=mount(Dashboard);await flushPromises();vi.mocked(campaignGet).mockClear();await vi.advanceTimersByTimeAsync(599999);expect(campaignGet).not.toHaveBeenCalled();await vi.advanceTimersByTimeAsync(1);expect(campaignGet).toHaveBeenCalledWith('dashboard',{view:'ready',page:1});other.unmount();vi.useRealTimers()})
 it('does not misrepresent a failed lookup as a zero-task inbox',async()=>{await flushPromises();vi.mocked(campaignGet).mockRejectedValue({response:{data:{message:'Service unavailable'}}});await w.findAll('button').find(b=>b.text()==='Refresh')!.trigger('click');await flushPromises();expect(w.text()).toContain('Service unavailable');expect(w.text()).not.toContain('You are all caught up')})
})
