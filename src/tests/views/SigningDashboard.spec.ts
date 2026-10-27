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
vi.mock('vue-router',()=>({RouterLink:{props:['to'],template:'<a><slot /></a>'}}))
const data={counts:{ready:2,waiting:1,signed:5,sent:3},perPage:10,total:2,documents:[{id:1,uuid:'uuid-one',name:'Handbook copy',status:1,sender:'HR',requestedAt:'2026-10-23 10:00:00',completedAt:null,campaign:'Handbook 2026',deadline:null},{id:2,uuid:'uuid-two',name:'Individual contract',status:1,sender:'HR',requestedAt:'2026-10-23 10:00:00',campaign:null}]}
let w:VueWrapper
describe('Personal signing dashboard',()=>{
 beforeEach(()=>{vi.clearAllMocks();vi.mocked(campaignGet).mockImplementation(async(path)=>path==='campaign/access'?{canCreate:false}:data);w=mount(Dashboard)})
 afterEach(()=>w.unmount())
 it('combines campaign and ordinary signing requests in the personal inbox',async()=>{await flushPromises();expect(w.text()).toContain('Handbook copy');expect(w.text()).toContain('Individual contract');expect(w.text()).toContain('Handbook 2026');expect(w.findAll('tbody a')).toHaveLength(2)})
 it('does not grant ordinary users campaign management or archive shortcuts',async()=>{await flushPromises();expect(w.text()).not.toContain('Manage campaigns');expect(w.text()).not.toContain('Signed Archive')})
 it('offers permitted actions before refresh and Files access at both ends',async()=>{await flushPromises();expect(w.find('header').text()).toContain('Request Signature');expect(w.find('header').text()).not.toContain('Create Campaign');expect(w.findAll('.dashboard-inbox button').filter(b=>b.text().includes('Open All Files'))).toHaveLength(2);expect(w.text()).toContain('Sent by me, awaiting completion');expect(w.text()).toContain('Showing up to 10 documents per page')})
 it('loads waiting-turn records separately and never offers premature signing',async()=>{await flushPromises();await w.findAll('button').find(b=>b.text().includes('Waiting for my turn'))!.trigger('click');await flushPromises();expect(campaignGet).toHaveBeenCalledWith('dashboard',{view:'waiting',page:1});expect(w.text()).toContain('An earlier participant must sign');expect(w.findAll('tbody a')).toHaveLength(0);expect(w.text()).toContain('Available when it is your turn')})
 it('does not misrepresent a failed lookup as a zero-task inbox',async()=>{await flushPromises();vi.mocked(campaignGet).mockRejectedValue({response:{data:{message:'Service unavailable'}}});await w.findAll('button').find(b=>b.text()==='Refresh')!.trigger('click');await flushPromises();expect(w.text()).toContain('Service unavailable');expect(w.text()).not.toContain('You are all caught up')})
})
