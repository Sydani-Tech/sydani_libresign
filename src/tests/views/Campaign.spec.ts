/* SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
import {afterEach,beforeEach,describe,expect,it,vi} from 'vitest'
import {flushPromises,mount,type VueWrapper} from '@vue/test-utils'
import Campaign from '../../views/Campaign.vue'
import {campaignGet,campaignPost,campaignPut} from '../../services/campaigns'
vi.mock('@nextcloud/l10n',()=>globalThis.mockNextcloudL10n())
vi.mock('@nextcloud/router',()=>({generateUrl:(s:string)=>s,generateOcsUrl:(s:string)=>s}))
vi.mock('@nextcloud/dialogs',()=>({showError:vi.fn()}))
vi.mock('../../services/campaigns',async()=>({...await vi.importActual('../../services/campaigns'),campaignGet:vi.fn(),campaignPost:vi.fn(),campaignPut:vi.fn()}))
vi.mock('../../components/Request/CampaignDesigner.vue',()=>({default:{template:'<div />'}}))
vi.mock('../../components/Request/CampaignRecipientPicker.vue',()=>({default:{template:'<div />'}}))
vi.mock('@nextcloud/vue/components/NcButton',()=>({default:{props:['disabled','type'],template:'<button :disabled="disabled" :type="type || `button`"><slot /></button>'}}))
vi.mock('@nextcloud/vue/components/NcDialog',()=>({default:{template:'<aside><slot /><slot name="actions" /></aside>'}}))
const summary={total:2,required:2,completed:1,pending:1,overdue:1,awaiting_counter:0,failed:0,queued:0,notification_pending:0,completionRate:50}
const record=()=>({id:1,name:'Handbook',version:'2026',message:'Please review',status:'active',revision:2,sourceHash:'hash',sourceName:'handbook.pdf',owner:{uid:'hr',name:'HR'},createdAt:1792670400,requestedAt:1792670400,dueAt:null,fields:[{role:'recipient',type:'signature',coordinates:{page:1,left:10,top:20,width:100,height:40},metadata:{label:'Signature',required:true}}],counter:null,summary:{...summary},events:[],recipients:[{id:10,name:'Alice',email:'alice@example.test',uid:'alice',kind:'internal',status:'completed',completedAt:'2026-10-22 10:00:00',fileId:100},{id:11,name:'Bob',email:'bob@external.test',uid:null,kind:'external',status:'pending',fileId:101}]})
let wrapper:VueWrapper|undefined
const click=async(label:string)=>{const button=wrapper!.findAll('button').find(b=>b.text()===label);expect(button).toBeDefined();await button!.trigger('click');await flushPromises()}
describe('Campaign workflow',()=>{
 beforeEach(()=>{vi.clearAllMocks();vi.mocked(campaignGet).mockImplementation(async(path)=>path==='campaign/access'?{canCreate:true}:path==='campaigns'?{data:[record()],total:1}:record())})
 afterEach(()=>{wrapper?.unmount();wrapper=undefined})
 it('denies the page without loading campaign records',async()=>{vi.mocked(campaignGet).mockResolvedValue({canCreate:false});wrapper=mount(Campaign);await flushPromises();expect(wrapper.text()).toContain('You do not have campaign access.');expect(campaignGet).toHaveBeenCalledTimes(1)})
 it('shows independent completion and name/email filters',async()=>{wrapper=mount(Campaign);await flushPromises();await click('Handbook');expect(wrapper.text()).toContain('50%');expect(wrapper.text()).toContain('alice@example.test');const searches=wrapper.findAll('input[type=search]');await searches[0].setValue('Bob');expect(wrapper.text()).not.toContain('alice@example.test');expect(wrapper.text()).toContain('bob@external.test');await searches[1].setValue('alice');expect(wrapper.text()).toContain('No recipients match these filters.')})
 it('opening a published campaign does not send or sign anything',async()=>{wrapper=mount(Campaign);await flushPromises();await click('Handbook');expect(campaignPost).not.toHaveBeenCalled();expect(campaignPut).not.toHaveBeenCalled()})
 it('reuses a campaign as a draft, not a new published batch',async()=>{vi.mocked(campaignPost).mockResolvedValue({...record(),id:2,status:'draft',recipients:[]});wrapper=mount(Campaign);await flushPromises();await click('Handbook');await click('Reuse campaign');expect(campaignPost).toHaveBeenCalledWith('campaigns/1/duplicate');expect(wrapper.text()).toContain('Campaign setup');expect(wrapper.text()).toContain('No recipients selected yet.')})
 it('requires explicit review confirmation before publication',async()=>{vi.mocked(campaignGet).mockImplementation(async(path)=>path==='campaign/access'?{canCreate:true}:path==='campaigns'?{data:[record()],total:1}:{...record(),status:'draft'});vi.mocked(campaignPut).mockResolvedValue({...record(),status:'draft',revision:3});wrapper=mount(Campaign);await flushPromises();await click('Handbook');await click('Review and send');expect(wrapper.text()).toContain('Each recipient will receive their own invitation.');expect(campaignPost).not.toHaveBeenCalled()})
 it('attaching a PDF enables placement on a brand-new campaign and creates only a draft',async()=>{
  wrapper=mount(Campaign);await flushPromises();await click('New campaign');
  const name=wrapper.findAll('input').find(i=>i.attributes('maxlength')==='200')!;await name.setValue('New PDF QA');
  const reader={result:'data:application/pdf;base64,JVBERi0x',onload:null as null|(()=>void),onerror:null,readAsDataURL(){this.onload?.()}};
  vi.stubGlobal('FileReader',class{constructor(){return reader}});
  try{const input=wrapper.find('input[type=file]');Object.defineProperty(input.element,'files',{value:[new File(['%PDF-1'],'new.pdf',{type:'application/pdf'})]});await input.trigger('change');await flushPromises();
   const place=wrapper.findAll('button').find(b=>b.text()==='Place signature and fillable fields')!;expect(place.attributes('disabled')).toBeUndefined();
   vi.mocked(campaignPost).mockResolvedValue({...record(),name:'New PDF QA',status:'draft',recipients:[],fields:[],sourceName:'new.pdf'});await click('Place signature and fillable fields');
   expect(campaignPost).toHaveBeenCalledWith('campaigns',expect.objectContaining({campaign:expect.objectContaining({pdf:'JVBERi0x',sourceName:'new.pdf'})}));expect(campaignPost).not.toHaveBeenCalledWith(expect.stringContaining('publish'),expect.anything());
  }finally{vi.unstubAllGlobals()}
 })
 it('shows countersigner conflicts in a bold section alert and a Nextcloud popup',async()=>{
  const {showError}=await import('@nextcloud/dialogs');vi.mocked(campaignGet).mockImplementation(async(path)=>path==='campaign/access'?{canCreate:true}:path==='campaigns'?{data:[record()],total:1}:{...record(),status:'draft'});
  wrapper=mount(Campaign);await flushPromises();await click('Handbook');
  const pickers=wrapper.findAllComponents({name:'CampaignRecipientPicker'});pickers[1].vm.$emit('add',{name:'Alice',email:'alice@example.test'});await flushPromises();
  expect(wrapper.find('.campaign-section-error strong').text()).toContain('countersigner cannot');expect(showError).toHaveBeenCalled();
 })
})
