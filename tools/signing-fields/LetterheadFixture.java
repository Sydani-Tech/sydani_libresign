/* SPDX-License-Identifier: AGPL-3.0-or-later */
package coop.libresign;
import com.lowagie.text.*;
import com.lowagie.text.pdf.*;
import java.io.*;
/** Synthetic files only, for the staging approval regression. */
public final class LetterheadFixture {
 public static void main(String[] args)throws Exception {
  if(args.length!=2)throw new IllegalArgumentException("Expected template and body PDF paths");
  Document t=new Document(PageSize.A4);PdfWriter writer=PdfWriter.getInstance(t,new FileOutputStream(args[0]));t.open();PdfContentByte cb=writer.getDirectContent();BaseFont font=BaseFont.createFont(BaseFont.HELVETICA,BaseFont.WINANSI,false);cb.beginText();cb.setFontAndSize(font,18);cb.setTextMatrix(40,800);cb.showText("STAGING TEST LETTERHEAD");cb.setFontAndSize(font,10);cb.setTextMatrix(40,35);cb.showText("Synthetic approval test - no business use");cb.endText();t.close();
  Document d=new Document(PageSize.A4,40,40,100,80);PdfWriter.getInstance(d,new FileOutputStream(args[1]));d.open();d.add(new Paragraph("Synthetic letterhead approval document, page one."));d.newPage();d.add(new Paragraph("Synthetic letterhead approval document, page two."));d.close();
 }
}
