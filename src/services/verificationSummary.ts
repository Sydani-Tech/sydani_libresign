// SPDX-License-Identifier: AGPL-3.0-or-later
export type LongTermSignature={id:string,name:string,format:string,indication:string,subIndication:string,timestampVerified:boolean,bestSignatureTime:string}
export type LongTermValidation={checked:boolean,offline:boolean,trustScope:string,checkedAt?:string,signatures:LongTermSignature[],reason?:string}
type Signer={signed?:string|null,valid_to?:string|number,signature_validation?:{id?:number},certificate_validation?:{id?:number},modification_validation?:{status?:number},crl_validation?:string}
export function verificationSummary(signers:Signer[],ltv?:LongTermValidation,now=Date.now()) {
 const signed=signers.filter(s=>s.signed),proof=ltv?.checked?ltv.signatures:[],allProof=proof.length===signed.length&&proof.length>0&&proof.every(s=>s.indication==='TOTAL_PASSED'),failedProof=proof.some(s=>s.indication==='TOTAL_FAILED')
 const changed=signed.some(s=>[2,3,4].includes(s.signature_validation?.id??0))||proof.some(s=>['HASH_FAILURE','SIG_CRYPTO_FAILURE'].includes(s.subIndication))
 const modificationIssue=signed.some(s=>s.modification_validation?.status===3)
 const integrity=signed.length>0&&signed.every(s=>s.signature_validation?.id===1)&&!changed&&!modificationIssue
 const longTerm=!changed&&!modificationIssue&&allProof&&proof.every(s=>['PAdES_BASELINE_LT','PAdES_BASELINE_LTA'].includes(s.format)&&s.timestampVerified)
 return {signedCount:signed.length,integrity,changed,modificationIssue,allProof,failedProof,longTerm,timestampVerified:proof.length>0&&proof.every(s=>s.timestampVerified),trusted:signed.length>0&&((allProof&&ltv?.trustScope==='organisation')||signed.every(s=>s.certificate_validation?.id===1)),expired:signed.some(s=>s.valid_to&&new Date(s.valid_to).getTime()<now)}
}
