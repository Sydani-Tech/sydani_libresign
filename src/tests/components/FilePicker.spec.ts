// SPDX-License-Identifier: AGPL-3.0-or-later
import {describe,it,expect,vi} from 'vitest'
import {mount} from '@vue/test-utils'
import FilePicker from '../../components/FilePicker.vue'
vi.mock('@nextcloud/l10n',()=>globalThis.mockNextcloudL10n())
describe('Accessible upload control',()=>{
 it('keeps native file validation, file-type filtering and keyboard access behind the styled surface',async()=>{const w=mount(FilePicker,{props:{label:'Upload Document',accept:'.pdf',required:true}});const input=w.get('input');expect(input.attributes()).toMatchObject({'aria-label':'Upload Document',accept:'.pdf',required:''});Object.defineProperty(input.element,'files',{value:[new File(['qa'],'QA.pdf',{type:'application/pdf'})],configurable:true});await input.trigger('change');expect(w.emitted('change')?.[0][0]).toBeInstanceOf(Event);await w.setProps({filename:'QA.pdf'});expect(w.text()).toContain('QA.pdf');expect(w.text()).toContain('Replace file');await w.setProps({disabled:true});expect(input.attributes('disabled')).toBeDefined();w.unmount()})
})
