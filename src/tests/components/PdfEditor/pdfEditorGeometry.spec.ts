/* SPDX-License-Identifier: AGPL-3.0-or-later */
import { describe, expect, it } from 'vitest'
import { pointerToPdfPlacement } from '../../../components/PdfEditor/pdfEditorGeometry'
describe('PDF placement uses the displayed page rectangle', () => {
 for (const scale of [0.5, 1, 1.75]) {
  for (const [type, width, height] of [['signature',180,60],['text',180,28],['date',120,28],['checkbox',20,20]] as const) {
   it(type + ' stays under the pointer at zoom ' + scale, () => {
    const rect={left:310,top:-240,width:600*scale,height:800*scale}
    const result=pointerToPdfPlacement({rect,page:{width:600,height:800},field:{width,height},clientX:310+300*scale,clientY:-240+400*scale})!
    expect(result.x+width/2).toBeCloseTo(300);expect(result.y+height/2).toBeCloseTo(400)
    expect(result.scaleX).toBe(scale);expect(result.scaleY).toBe(scale)
   })
  }
 }
 it('uses current scroll/layout bounds instead of cached canvas positions', () => {
  const result=pointerToPdfPlacement({rect:{left:90,top:-600,width:300,height:400},page:{width:600,height:800},field:{width:120,height:28},clientX:240,clientY:-300})!
  expect(result.x).toBe(240);expect(result.y).toBe(586)
 })
 it('keeps fields within every page edge', () => {
  const args={rect:{left:100,top:20,width:300,height:400},page:{width:600,height:800},field:{width:180,height:60}}
  expect(pointerToPdfPlacement({...args,clientX:100,clientY:20})).toMatchObject({x:0,y:0})
  expect(pointerToPdfPlacement({...args,clientX:400,clientY:420})).toMatchObject({x:420,y:740})
 })
 it('rejects points outside the PDF and unrendered/invalid pages', () => {
  const args={rect:{left:100,top:20,width:300,height:400},page:{width:600,height:800},field:{width:180,height:60},clientX:200,clientY:200}
  expect(pointerToPdfPlacement({...args,clientX:99})).toBeNull()
  expect(pointerToPdfPlacement({...args,rect:{...args.rect,width:0}})).toBeNull()
  expect(pointerToPdfPlacement({...args,field:{width:700,height:60}})).toBeNull()
 })
})
