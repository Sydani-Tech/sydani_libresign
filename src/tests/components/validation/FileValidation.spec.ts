// SPDX-License-Identifier: AGPL-3.0-or-later
import {describe,it,expect,vi} from 'vitest'
import {mount} from '@vue/test-utils'
import FileValidation from '../../../components/validation/FileValidation.vue'
vi.mock('@nextcloud/l10n',()=>globalThis.mockNextcloudL10n())
const signer={signed:'2026-10-01T09:00:00Z',valid_to:'2026-10-02T09:00:00Z',signature_validation:{id:1},certificate_validation:{id:1}}
const wrapper=(document:Record<string,unknown>,extra={})=>mount(FileValidation,{props:{document:document as never,...extra},global:{stubs:{DocumentValidationDetails:{name:'DocumentValidationDetails',props:['document','legalInformation'],template:'<div />'},NcIconSvgWrapper:true,NcNoteCard:{name:'NcNoteCard',props:['type'],template:'<div><slot /></div>'}}}})
describe('File verification information',()=>{
 it('does not congratulate a signer while verification reports a problem',()=>{const w=wrapper({signers:[{...signer,modification_validation:{status:3}}]},{isAfterSigned:true});expect(w.text()).toContain('Verification needs review');expect(w.text()).not.toContain('signed content passed verification.');expect(w.text()).toContain('Document changes need review')})
 it('does not turn a generic success message into evidence of a valid signature',()=>{const w=wrapper({signers:[]},{documentValidMessage:'Everything is valid'});expect(w.text()).not.toContain('Everything is valid');expect(w.find('.verification-summary').exists()).toBe(false)})
 it('shows content integrity separately from certificate expiry',()=>{const w=wrapper({signers:[signer]});expect(w.text()).toContain('Signed content integrity verified');expect(w.text()).toContain('A signing certificate has expired.');expect(w.text()).toContain('Long-term validity has not been established')})
 it('only claims long-term validation when independent evidence passed',()=>{const w=wrapper({signers:[signer],longTermValidation:{checked:true,offline:true,trustScope:'organisation',signatures:[{indication:'TOTAL_PASSED',format:'PAdES_BASELINE_LT',timestampVerified:true}]}});expect(w.text()).toContain('PAdES-LT evidence verified from the PDF')})
 it('makes tampering visible even for a trusted issuer',()=>{const w=wrapper({signers:[{...signer,signature_validation:{id:2}}]});expect(w.find('.verification-summary--error').exists()).toBe(true);expect(w.text()).toContain('Document integrity check failed')})
 it('retains the completed-signing confirmation and detailed document information',()=>{const document={name:'Contract',signers:[signer]};const w=wrapper(document,{isAfterSigned:true,legalInformation:'Terms'});expect(w.text()).toContain('Your signature was recorded');expect(w.findComponent({name:'DocumentValidationDetails'}).props()).toMatchObject({document,legalInformation:'Terms'})})
})
