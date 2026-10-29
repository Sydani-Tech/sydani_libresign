// SPDX-License-Identifier: AGPL-3.0-or-later
import {describe,it,expect,vi,beforeEach} from 'vitest'
import {mount,flushPromises} from '@vue/test-utils'
import {reactive} from 'vue'
import LetterheadSigning from '../../../components/Letterheads/LetterheadSigning.vue'
const state=vi.hoisted(()=>({post:vi.fn(),select:vi.fn(),detail:vi.fn(),clear:vi.fn(),sidebar:vi.fn(),disable:vi.fn(),files:null as any}))
vi.mock('@nextcloud/l10n',()=>globalThis.mockNextcloudL10n())
vi.mock('../../../services/campaigns',()=>({campaignPost:(...args:unknown[])=>state.post(...args),campaignError:()=> 'Request unavailable'}))
vi.mock('../../../store/files.js',()=>({useFilesStore:()=>state.files}))
vi.mock('../../../store/sidebar.js',()=>({useSidebarStore:()=>({setActiveTab:state.sidebar})}))
vi.mock('../../../components/RightSidebar/RequestSignatureTab.vue',()=>({default:{props:['useModal','fixedRecipients'],template:'<div class="native-signing" :data-modal="useModal" :data-fixed="fixedRecipients">Place fields / Request signatures</div>'}}))
vi.mock('@nextcloud/vue/components/NcButton',()=>({default:{template:'<button><slot /></button>'}}))
beforeEach(()=>{vi.clearAllMocks();state.post.mockResolvedValue({id:10,uuid:'frozen-doc'});state.select.mockResolvedValue(10);state.detail.mockResolvedValue({id:'10'});state.files=reactive({selectedFileId:10,selectFileByUuid:state.select,fetchFileDetail:state.detail,selectFile:state.clear,disableIdentifySigner:state.disable,getFile:()=>({statusText:'Draft'})})})
describe('Letterhead signing workspace',()=>{
 it('loads the approved request and its complete fields before exposing the native editor',async()=>{let resolve!:(r:unknown)=>void;state.detail.mockImplementation(()=>new Promise(r=>resolve=r));const w=mount(LetterheadSigning,{props:{documentId:6}});await flushPromises();expect(state.post).toHaveBeenCalledWith('letterhead/documents/6/request');expect(state.select).not.toHaveBeenCalled();expect(state.detail).toHaveBeenCalledWith({fileId:10,uuid:'frozen-doc',force:true});expect(w.find('.native-signing').exists()).toBe(false);resolve({id:'10'});await flushPromises();expect(w.find('.native-signing').attributes()).toMatchObject({'data-modal':'true','data-fixed':'true'});expect(state.sidebar).toHaveBeenCalledWith('');expect(w.emitted('prepared')?.[0]).toEqual([10]);w.unmount();expect(state.clear).toHaveBeenCalled()})
 it('shows a recoverable failure without an upload-from-scratch screen',async()=>{state.post.mockRejectedValue(new Error('Approval is required'));const w=mount(LetterheadSigning,{props:{documentId:6}});await flushPromises();expect(w.get('[role=alert]').text()).toContain('Approval is required');expect(w.find('.native-signing').exists()).toBe(false);expect(w.find('input[type=file]').exists()).toBe(false);w.unmount()})
 it('does not select a late request after leaving the document',async()=>{let resolve!:(r:unknown)=>void;state.post.mockImplementation(()=>new Promise(r=>resolve=r));const w=mount(LetterheadSigning,{props:{documentId:6}});w.unmount();resolve({id:10,uuid:'late'});await flushPromises();expect(state.select).not.toHaveBeenCalled()})
 it('does not clear another request selected after this panel closes',async()=>{const w=mount(LetterheadSigning,{props:{documentId:6}});await flushPromises();state.clear.mockClear();state.files.selectedFileId=99;w.unmount();expect(state.clear).not.toHaveBeenCalled()})
})
