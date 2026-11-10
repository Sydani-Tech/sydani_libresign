/* SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
type Size = { width: number, height: number }
/** Convert viewport coordinates to PDF points, independently of render/zoom caches. */
export function pointerToPdfPlacement({rect,page,field,clientX,clientY}: {
 rect: Size & {left:number,top:number}, page:Size, field:Size, clientX:number, clientY:number,
}): {x:number,y:number,scaleX:number,scaleY:number} | null {
 if (![rect.left,rect.top,rect.width,rect.height,page.width,page.height,field.width,field.height,clientX,clientY].every(Number.isFinite)
  || Math.min(rect.width,rect.height,page.width,page.height,field.width,field.height)<=0
  || field.width>page.width || field.height>page.height
  || clientX<rect.left || clientX>rect.left+rect.width || clientY<rect.top || clientY>rect.top+rect.height) return null
 const scaleX=rect.width/page.width, scaleY=rect.height/page.height
 return {x:Math.max(0,Math.min(page.width-field.width,(clientX-rect.left)/scaleX-field.width/2)),
  y:Math.max(0,Math.min(page.height-field.height,(clientY-rect.top)/scaleY-field.height/2)),scaleX,scaleY}
}
