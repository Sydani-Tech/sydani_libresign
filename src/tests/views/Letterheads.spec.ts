// SPDX-License-Identifier: AGPL-3.0-or-later
import {describe,it,expect,vi,beforeEach,afterEach} from 'vitest'
import {mount,flushPromises,type VueWrapper} from '@vue/test-utils'
import Letterheads from '../../views/Letterheads.vue'
import {campaignGet,campaignPost} from '../../services/campaigns'
const state=vi.hoisted(()=>({uid:'owner',query:{} as Record<string,string>,push:vi.fn(),selectFileByUuid:vi.fn()}))
vi.mock('@nextcloud/l10n',()=>globalThis.mockNextcloudL10n())
vi.mock('@nextcloud/auth',()=>({getCurrentUser:()=>({uid:state.uid})}))
vi.mock('vue-router',()=>({useRoute:()=>({query:state.query}),useRouter:()=>({push:state.push})}))
vi.mock('@nextcloud/password-confirmation',()=>({confirmPassword:vi.fn()}))
vi.mock('../../store/files.js',()=>({useFilesStore:()=>({selectFileByUuid:state.selectFileByUuid})}))
vi.mock('../../services/campaigns',async()=>({...await vi.importActual('../../services/campaigns'),campaignGet:vi.fn(),campaignPost:vi.fn()}))
vi.mock('@nextcloud/vue/components/NcButton',()=>({default:{props:['type','disabled'],template:'<button :type="type||`button`" :disabled="disabled"><slot /></button>'}}))
vi.mock('@nextcloud/vue/components/NcSelect',()=>({default:{template:'<div class="approver-picker" />'}}))
vi.mock('../../components/Request/CampaignRecipientPicker.vue',()=>({default:{emits:['add'],template:'<button class="external" type="button" @click="$emit(`add`,{name:`Guest`,email:`guest@example.test`,uid:null,kind:`external`})">Add external test signer</button>'}}))
const doc=(status='pending')=>({id:1,name:'Letterhead contract',state:status,revision:3,owner:{uid:'owner',name:'Owner'},approver:{uid:'approver',name:'Approver'},createdAt:1793000000,approvedAt:status==='approved'?1793000100:null,recipients:[{name:'Guest',email:'guest@example.test',uid:null}],pages:2,reason:'',events:[]})
let w:VueWrapper|undefined
const click=async(label:string)=>{const button=w!.findAll('button').find(b=>b.text()===label);expect(button).toBeDefined();await button!.trigger('click');await flushPromises()}
beforeEach(()=>{vi.clearAllMocks();state.uid='owner';state.query={};vi.mocked(campaignGet).mockImplementation(async(path)=>path==='letterhead/documents'?{data:[doc()],total:1,isAdmin:false,canApprove:state.uid==='approver',canCreate:true}:path==='letterheads'?[{id:1,name:'Shared letterhead',active:1}]:path==='letterhead/users'?[]:doc());vi.mocked(campaignPost).mockResolvedValue(doc('approved'))})
afterEach(()=>w?.unmount())
describe('Letterhead document approval UI',()=>{
 it('lists staff documents without sending any invitation',async()=>{w=mount(Letterheads);await flushPromises();expect(w.text()).toContain('Letterhead contract');expect(campaignPost).not.toHaveBeenCalled()})
 it('shows image previews and keeps PDF download and signing locked while approval is pending',async()=>{w=mount(Letterheads);await flushPromises();await click('Letterhead contract');expect(w.find('.preview img').attributes('src')).toContain('letterhead/documents/1/preview/1');expect(w.text()).toContain('PDF download and signing are locked');expect(w.text()).not.toContain('Download approved PDF');expect(w.text()).not.toContain('Approve this document');await click('Next page');expect(w.find('.preview img').attributes('src')).toContain('/preview/2')})
 it('asks for an approver when an external signer is added',async()=>{w=mount(Letterheads);await flushPromises();await click('New document');await w.find('.external').trigger('click');expect(w.text()).toContain('External signing requires approval');expect(w.find('.approver-picker').exists()).toBe(true);expect(w.findAll('button').find(b=>b.text()==='Create preview')!.attributes('disabled')).toBeDefined()})
 it('allows the selected role holder to record a decision',async()=>{state.uid='approver';w=mount(Letterheads);await flushPromises();await click('Letterhead contract');await click('Approve this document');expect(campaignPost).toHaveBeenCalledWith('letterhead/documents/1/decision',{revision:3,approve:true,reason:''})})
 it('opens an approval email deep link and hands an approved request to the existing field editor',async()=>{state.query={document:'1'};vi.mocked(campaignGet).mockImplementation(async(path)=>path==='letterhead/documents'?{data:[],total:0,isAdmin:false,canApprove:false,canCreate:true}:path==='letterheads'?[]:doc('approved'));vi.mocked(campaignPost).mockResolvedValue({id:10,uuid:'approved-uuid'});w=mount(Letterheads);await flushPromises();expect(w.text()).toContain('Download approved PDF');await click('Place fields and request signatures');expect(state.selectFileByUuid).toHaveBeenCalledWith('approved-uuid');expect(state.push).toHaveBeenCalledWith({name:'requestFiles'})})
})
