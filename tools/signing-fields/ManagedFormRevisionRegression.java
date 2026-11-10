/* SPDX-License-Identifier: AGPL-3.0-or-later */
package coop.libresign;
import org.openpdf.text.pdf.*;
import java.nio.file.*;
import java.io.*;
import java.util.*;
/** Ensure a valid signature digest cannot conceal changes to another signer's completed fields. */
public final class ManagedFormRevisionRegression {
 public static void main(String[] args) throws Exception {
  Path directory=Path.of(args[0]),source=directory.resolve("step5.signed.pdf");
  require(ManagedFormRevisions.inspect(source.toString()).equals("verified"),"Legitimate five-signer revisions must verify");
  for(String kind:List.of("value","appearance","font","rectangle","owner","unlock","page","new-field","regenerate")) {
   Path candidate=directory.resolve("tampered-"+kind+".pdf");alter(source,candidate,kind);
   require(ManagedFormRevisions.inspect(candidate.toString()).equals("invalid"),"Tampering must be rejected: "+kind);
   PdfReader reader=new PdfReader(candidate.toString());try{for(String signature:reader.getAcroFields().getSignedFieldNames())require(reader.getAcroFields().verifySignature(signature).verify(),"Synthetic incremental tampering should leave old digest valid");}finally{reader.close();}
  }
  System.out.println("PASS: nine incremental tamper cases rejected while every original signature digest remains valid.");
 }
 private static void alter(Path input,Path output,String kind) throws Exception {
  PdfReader reader=new PdfReader(input.toString());
  try(OutputStream stream=Files.newOutputStream(output)) {
   PdfStamper stamper=new PdfStamper(reader,stream,(String)null,true);
   AcroFields.Item item=stamper.getAcroFields().getFieldItem("libresign_field_1");
   PdfDictionary widget=item.getWidget(0),value=item.getValue(0);
   switch(kind) {
    case "value" -> value.put(PdfName.V,new PdfString("FORGED",PdfObject.TEXT_UNICODE));
    case "appearance" -> {PdfAppearance ap=PdfAppearance.createAppearance(stamper.getWriter(),180,28);ap.setLiteral("1 0 0 rg 0 0 180 28 re f");PdfDictionary dict=new PdfDictionary();dict.put(PdfName.N,ap.getIndirectReference());widget.put(PdfName.AP,dict);}
    case "font" -> widget.put(PdfName.DA,new PdfString("/Unknown 13 Tf"));
    case "rectangle" -> widget.put(PdfName.RECT,new PdfArray(new float[]{0,0,180,28}));
    case "owner" -> widget.put(new PdfName("LibreSignOwner"),new PdfString("9999"));
    case "unlock" -> value.put(PdfName.FF,new PdfNumber(PdfFormField.FF_REQUIRED));
    case "page" -> {PdfContentByte page=stamper.getOverContent(1);page.rectangle(20,20,100,100);page.fill();}
    case "new-field" -> {TextField field=new TextField(stamper.getWriter(),new org.openpdf.text.Rectangle(20,20,120,48),"unassigned");stamper.addAnnotation(field.getTextField(),1);}
    case "regenerate" -> {stamper.getAcroFields().setGenerateAppearances(false);PdfDictionary form=reader.getCatalog().getAsDict(PdfName.ACROFORM);form.put(PdfName.NEEDAPPEARANCES,PdfBoolean.PDFTRUE);stamper.markUsed(form);}
    default -> throw new IllegalArgumentException();
   }
   stamper.markUsed(widget);stamper.markUsed(value);stamper.close();
  }finally{reader.close();}
  if(kind.equals("regenerate"))appendRegenerationFlag(output);
 }
 private static void appendRegenerationFlag(Path path) throws Exception {
  byte[] original=Files.readAllBytes(path);PdfReader reader=new PdfReader(original);
  try {
   PdfDictionary catalog=reader.getCatalog();PdfObject raw=catalog.get(PdfName.ACROFORM);PdfDictionary form=catalog.getAsDict(PdfName.ACROFORM);form.put(PdfName.NEEDAPPEARANCES,PdfBoolean.PDFTRUE);
   PRIndirectReference ref=raw instanceof PRIndirectReference?(PRIndirectReference)raw:(PRIndirectReference)reader.getTrailer().get(PdfName.ROOT);
   PdfDictionary object=raw instanceof PRIndirectReference?form:catalog;
   java.util.regex.Matcher marker=java.util.regex.Pattern.compile("startxref\\s+(\\d+)").matcher(new String(original,java.nio.charset.StandardCharsets.ISO_8859_1));long previous=0;while(marker.find())previous=Long.parseLong(marker.group(1));
   ByteArrayOutputStream bytes=new ByteArrayOutputStream();bytes.write(original);bytes.write('\n');long offset=bytes.size();
   bytes.write((ref.getNumber()+" "+ref.getGeneration()+" obj\n").getBytes(java.nio.charset.StandardCharsets.US_ASCII));literal(object,bytes);bytes.write("\nendobj\n".getBytes());long xref=bytes.size();
   bytes.write(("xref\n"+ref.getNumber()+" 1\n"+String.format(java.util.Locale.ROOT,"%010d %05d n \n",offset,ref.getGeneration())+"trailer\n").getBytes());
   PdfDictionary trailer=new PdfDictionary();trailer.putAll(reader.getTrailer());trailer.put(PdfName.PREV,new PdfNumber(previous));trailer.remove(PdfName.XREFSTM);literal(trailer,bytes);
   bytes.write(("\nstartxref\n"+xref+"\n%%EOF\n").getBytes());Files.write(path,bytes.toByteArray());
  }finally{reader.close();}
  PdfReader check=new PdfReader(path.toString());try{require(PdfBoolean.PDFTRUE.equals(check.getCatalog().getAsDict(PdfName.ACROFORM).get(PdfName.NEEDAPPEARANCES)),"Regeneration fixture must actually retain the flag");}finally{check.close();}
 }
 private static void literal(PdfObject object,OutputStream out) throws Exception {
  if(object instanceof PdfIndirectReference){out.write(object.toString().getBytes(java.nio.charset.StandardCharsets.US_ASCII));return;}
  if(object instanceof PdfDictionary){out.write("<< ".getBytes());for(PdfName key:((PdfDictionary)object).getKeys()){key.toPdf(null,out);out.write(' ');literal(((PdfDictionary)object).get(key),out);out.write(' ');}out.write(">>".getBytes());return;}
  if(object instanceof PdfArray){out.write('[');for(int i=0;i<((PdfArray)object).size();i++){literal(((PdfArray)object).getPdfObject(i),out);out.write(' ');}out.write(']');return;}
  object.toPdf(null,out);
 }
 private static void require(boolean ok,String message){if(!ok)throw new IllegalStateException(message);}
}
