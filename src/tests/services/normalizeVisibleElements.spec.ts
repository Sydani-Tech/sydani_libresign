/**
 * SPDX-FileCopyrightText: 2026 Sydani Technology contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import {describe,it,expect} from 'vitest'
import {normalizeVisibleElements} from '../../services/normalizeVisibleElements'
import type {VisibleElementRecord} from '../../types'

const placed = (type:string) => ({type,elementId:'12',fileId:'3',signRequestId:'8',coordinates:{page:'1',left:'10',top:'20',width:'100',height:'24'},metadata:{label:'Signing date',required:true,useSigningDate:true,timezone:'Africa/Lagos'}} as unknown as VisibleElementRecord)

describe('reopened request field normalization',()=>{
 it.each(['signature','initial','text','date','datetime','checkbox'])('retains %s fields and their placement policy',type=>{
  const result=normalizeVisibleElements([placed(type)])
  expect(result).toHaveLength(1)
  expect(result[0]).toMatchObject({type,elementId:12,fileId:3,signRequestId:8,coordinates:{page:1,left:10,top:20,width:100,height:24},metadata:{required:true,useSigningDate:true,timezone:'Africa/Lagos'}})
 })
 it('rejects unsupported fields and invalid coordinates without losing valid ones',()=>{
  const invalid=placed('text'); invalid.coordinates!.left=Number.NaN
  expect(normalizeVisibleElements([placed('unsupported'),invalid,placed('date')])).toHaveLength(1)
 })
})
