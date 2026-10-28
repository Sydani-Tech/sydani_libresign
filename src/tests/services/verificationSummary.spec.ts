// SPDX-License-Identifier: AGPL-3.0-or-later
import {describe,it,expect} from 'vitest'
import {verificationSummary,type LongTermValidation} from '../../services/verificationSummary'
const signer={signed:'2026-10-01T10:00:00Z',valid_to:'2026-10-02T10:00:00Z',signature_validation:{id:1},certificate_validation:{id:1}}
const proof:LongTermValidation={checked:true,offline:true,trustScope:'organisation',signatures:[{id:'s1',name:'QA',format:'PAdES_BASELINE_LT',indication:'TOTAL_PASSED',subIndication:'',timestampVerified:true,bestSignatureTime:'2026-10-01T10:00:01Z'}]}
describe('Verification summary',()=>{
 it('does not call expiry a changed document',()=>{const s=verificationSummary([signer],undefined,Date.parse('2026-10-28'));expect(s.expired).toBe(true);expect(s.changed).toBe(false);expect(s.integrity).toBe(true);expect(s.longTerm).toBe(false)})
 it('requires independent evidence, timestamp and successful validation for LT',()=>{expect(verificationSummary([signer],proof).longTerm).toBe(true);expect(verificationSummary([signer],{...proof,checked:false}).longTerm).toBe(false);expect(verificationSummary([signer],{...proof,signatures:proof.signatures.map(s=>({...s,timestampVerified:false}))}).longTerm).toBe(false)})
 it('does not turn a basic timestamped signature into LT',()=>{expect(verificationSummary([signer],{...proof,signatures:proof.signatures.map(s=>({...s,format:'PAdES_BASELINE_T'}))}).longTerm).toBe(false)})
 it('flags tampering even when the issuer is trusted',()=>{expect(verificationSummary([{...signer,signature_validation:{id:2}}],proof).changed).toBe(true);expect(verificationSummary([{...signer,signature_validation:{id:2}}],proof).longTerm).toBe(false)})
 it('does not claim success for incomplete evidence',()=>{expect(verificationSummary([signer],{...proof,signatures:proof.signatures.map(s=>({...s,indication:'INDETERMINATE'}))}).longTerm).toBe(false)})
 it('uses independently verified configured anchors when the native PDF reader has no issuer trust store',()=>{const unknown={...signer,certificate_validation:{id:3}};expect(verificationSummary([unknown],proof).trusted).toBe(true);expect(verificationSummary([unknown],{...proof,signatures:proof.signatures.map(s=>({...s,indication:'INDETERMINATE'}))}).trusted).toBe(false);expect(verificationSummary([unknown],{...proof,checked:false}).trusted).toBe(false)})
 it('keeps pending participants out of signed content checks',()=>{expect(verificationSummary([{signed:null},signer],proof).signedCount).toBe(1)})
})
