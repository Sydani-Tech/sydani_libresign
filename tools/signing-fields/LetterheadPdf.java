/* SPDX-License-Identifier: AGPL-3.0-or-later */
package coop.libresign;
import com.lowagie.text.Rectangle;
import com.lowagie.text.pdf.*;
import java.io.*;
/** Composition happens before signing; signed inputs are deliberately rejected. */
public final class LetterheadPdf {
 public static void main(String[] args) throws Exception {
  if(args.length!=4) throw new IllegalArgumentException("Expected source, letterhead, output and layer");
  PdfReader source=new PdfReader(args[0]), template=new PdfReader(args[1]);
  if(source.isEncrypted()||template.isEncrypted()||!source.getAcroFields().getSignatureNames().isEmpty()||!template.getAcroFields().getSignatureNames().isEmpty()) throw new IllegalArgumentException("Upload unsigned, unencrypted PDFs");
  if(template.getNumberOfPages()!=1||source.getNumberOfPages()<1||source.getNumberOfPages()>100) throw new IllegalArgumentException("Use a one-page letterhead and a document of up to 100 pages");
  boolean foreground="front".equals(args[3]);if(!foreground&&!"behind".equals(args[3])) throw new IllegalArgumentException("Invalid letterhead layer");
  try(OutputStream out=new FileOutputStream(args[2])) {
   PdfStamper stamp=new PdfStamper(source,out);PdfImportedPage head=stamp.getImportedPage(template,1);Rectangle t=template.getPageSizeWithRotation(1);
   for(int page=1;page<=source.getNumberOfPages();page++) {
    Rectangle r=source.getPageSizeWithRotation(page);float scale=Math.min(r.getWidth()/t.getWidth(),r.getHeight()/t.getHeight());
    PdfContentByte canvas=foreground?stamp.getOverContent(page):stamp.getUnderContent(page);
    canvas.addTemplate(head,scale,0,0,scale,(r.getWidth()-t.getWidth()*scale)/2,(r.getHeight()-t.getHeight()*scale)/2);
   }
   stamp.close();
  } finally {source.close();template.close();}
 }
}
