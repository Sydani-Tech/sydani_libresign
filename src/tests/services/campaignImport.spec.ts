// SPDX-License-Identifier: AGPL-3.0-or-later
import {describe, expect, it} from 'vitest'
import {detectImportColumns, mapImportRows, validImportEmail, type ImportSheet} from '../../services/campaignImport'
const sheet = (rows:string[][]):ImportSheet => ({rows:rows.map((cells,index)=>({row:index+1,cells})),sheets:[],sheet:''})
describe('Recipient import mapping and validation',()=>{
	it('detects reversed named columns',()=>expect(detectImportColumns(sheet([['Email Address','Full Name'],['ada@example.test','Ada']]).rows)).toEqual({header:true,email:0,name:1}))
	it('detects headerless reversed data',()=>expect(detectImportColumns(sheet([['ada@example.test','Ada'],['bob@example.test','Bob']]).rows)).toEqual({header:false,email:0,name:1}))
	it('requires explicit mapping when no email can be identified',()=>expect(detectImportColumns(sheet([['Unknown','Other'],['Ada','broken']]).rows)).toEqual({header:false,email:-1,name:-1}))
	it('does not guess a name from several unlabelled columns',()=>expect(detectImportColumns(sheet([['42','Ada','ada@example.test']]).rows).name).toBe(-1))
	it('allows manual column choices and preserves source row numbers',()=>expect(mapImportRows(sheet([['Ignore','Name','Email'],['42','Ada','ADA@example.test']]),1,2,true)).toEqual([{row:2,name:'Ada',email:'ada@example.test',error:''}]))
	it('flags malformed emails without treating them as valid rows',()=>{for(const value of ['a@@example.com','a b@example.com','a@example','a..b@example.com','.a@example.com','a@-example.com','a@example..com'])expect(validImportEmail(value),value).toBe(false)})
	it('accepts plus addresses and subdomains',()=>expect(validImportEmail('ada+hr@staff.example.org')).toBe(true))
	it('flags missing and oversized names',()=>{const rows=mapImportRows(sheet([['','a@example.test'],['😀'.repeat(17),'b@example.test']]),0,1,false);expect(rows.every(r=>r.error)).toBe(true)})
	it('skips duplicates and existing recipients case-insensitively',()=>{const rows=mapImportRows(sheet([['Ada','A@example.test'],['Again','a@example.test'],['Existing','x@example.test']]),0,1,false,[{name:'X',email:'X@example.test'}]);expect(rows.map(r=>Boolean(r.error))).toEqual([false,true,true])})
	it('flags the countersigner rather than adding them to the cohort',()=>expect(mapImportRows(sheet([['HR','hr@example.test']]),0,1,false,[],{name:'HR',email:'HR@example.test'})[0].error).toContain('countersigner'))
	it('rejects identical mapping and formula cells',()=>{expect(mapImportRows(sheet([['A','a@example.test']]),0,0,false)).toEqual([]);expect(mapImportRows(sheet([['[Formula: paste values first]','a@example.test']]),0,1,false)[0].error).toContain('formula')})
})
