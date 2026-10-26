/* SPDX-License-Identifier: AGPL-3.0-or-later */
import {beforeEach,describe,expect,it,vi} from 'vitest'
import {flushPromises,mount} from '@vue/test-utils'
import axios from '@nextcloud/axios'
import CampaignDesigner from '../../../components/Request/CampaignDesigner.vue'
const editor=vi.hoisted(()=>({addSigner:vi.fn(),startAddingSigner:vi.fn().mockReturnValue(true),cancelAdding:vi.fn(),pdfElements:{getAllObjects:vi.fn()}}))
vi.mock('@nextcloud/l10n',()=>globalThis.mockNextcloudL10n())
vi.mock('@nextcloud/axios',()=>({default:{get:vi.fn()}}))
vi.mock('@nextcloud/router',()=>({generateOcsUrl:(s:string)=>s}))
vi.mock('@nextcloud/vue/components/NcModal',()=>({default:{template:'<div><slot /></div>'}}))
vi.mock('@nextcloud/vue/components/NcButton',()=>({default:{props:['disabled'],template:'<button :disabled="disabled"><slot /></button>'}}))
vi.mock('../../../components/PdfEditor/PdfEditor.vue',()=>({default:{name:'PdfEditor',template:'<div class="pdf-editor" />',setup:()=>editor}}))
describe('Reusable Campaign field layout',()=>{
 beforeEach(()=>{vi.clearAllMocks();vi.mocked(axios.get).mockResolvedValue({data:new Blob(['%PDF'])});editor.pdfElements.getAllObjects.mockReturnValue([])})
 it('restores recipient/counter fields once, with distinct roles',async()=>{
  const fields=[{role:'recipient',type:'signature',coordinates:{page:1,left:10,top:20,width:100,height:40},metadata:{label:'Sign',required:true}},{role:'counter',type:'date',coordinates:{page:1,left:200,top:20,width:100,height:30},metadata:{label:'Date',required:true,useSigningDate:true,signingDateTimezone:'Africa/Lagos'}}]
  const w=mount(CampaignDesigner,{props:{campaignId:1,sourceName:'test.pdf',fields:fields as never,counter:{name:'HR',email:'hr@example.test'}}});await flushPromises();const pdf=w.findComponent({name:'PdfEditor'});pdf.vm.$emit('pdf-editor:end-init');await flushPromises();pdf.vm.$emit('pdf-editor:end-init');await flushPromises();expect(editor.addSigner).toHaveBeenCalledTimes(2);expect(editor.addSigner.mock.calls[1][0].signRequestId).toBe(2);expect(editor.addSigner.mock.calls[1][1].metadata.useSigningDate).toBe(true);w.unmount()
 })
 it('saves the full field bounds and metadata, not screen pixels or recipient IDs',async()=>{
  editor.pdfElements.getAllObjects.mockReturnValue([{type:'date',pageNumber:2,x:20.123,y:30.456,width:180,height:28,signer:{signRequestId:2},metadata:{label:'Approval',required:false,useSigningDate:true,signingDateTimezone:'Africa/Lagos'}}]);
  const w=mount(CampaignDesigner,{props:{campaignId:1,sourceName:'test.pdf',fields:[],counter:{name:'HR',email:'hr@example.test'}}});await flushPromises();w.findComponent({name:'PdfEditor'}).vm.$emit('pdf-editor:end-init');await flushPromises();await w.findAll('button').find(b=>b.text()==='Save layout')!.trigger('click');expect(w.emitted('save')?.[0][0]).toEqual([{role:'counter',type:'date',coordinates:{page:2,left:20.12,top:30.46,width:180,height:28},metadata:{label:'Approval',required:false,useSigningDate:true,signingDateTimezone:'Africa/Lagos'}}]);w.unmount()
 })
})
