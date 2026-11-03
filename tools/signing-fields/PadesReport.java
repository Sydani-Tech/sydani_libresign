/* SPDX-License-Identifier: AGPL-3.0-or-later */
package coop.libresign;
import eu.europa.esig.dss.model.FileDocument;
import eu.europa.esig.dss.model.x509.CertificateToken;
import eu.europa.esig.dss.spi.x509.CommonTrustedCertificateSource;
import eu.europa.esig.dss.spi.validation.CommonCertificateVerifier;
import eu.europa.esig.dss.validation.SignedDocumentValidator;
import java.security.cert.*;
import java.io.*;
import java.nio.charset.StandardCharsets;
import java.util.Base64;
/** Offline check against explicit organisation/TSA anchors, using PDF-embedded evidence. */
public final class PadesReport {
 private static String safe(Object v){return v==null?"":v.toString().replace('\t',' ').replace('\n',' ');}
 public static void main(String[] args)throws Exception{
  if(args.length<2||args.length>3)throw new IllegalArgumentException("Expected PDF and trust files");
  var trusted=trustAnchors(args[1]);
  CommonCertificateVerifier verifier=new CommonCertificateVerifier(true);verifier.setTrustedCertSources(trusted);
  SignedDocumentValidator validator=SignedDocumentValidator.fromDocument(new FileDocument(args[0]));validator.setCertificateVerifier(verifier);
  if(args.length==3)validator.setValidationTime(java.util.Date.from(java.time.Instant.parse(args[2])));
  var reports=validator.validateDocument();var report=reports.getSimpleReport();var diagnostic=reports.getDiagnosticData();
  for(String id:report.getSignatureIdList()){
   var stamps=report.getSignatureTimestamps(id);boolean timestampValid=!stamps.isEmpty();for(var ts:stamps)timestampValid&=report.isValid(ts.getId());
   String name=Base64.getEncoder().encodeToString(safe(report.getSignedBy(id)).getBytes(StandardCharsets.UTF_8));
   var time=report.getBestSignatureTime(id);
   var token=diagnostic.getSignatureById(id);
   var revision=token.getPDFRevision();
   var range=revision==null?java.util.List.<java.math.BigInteger>of():revision.getSignatureByteRange();
   boolean coversFile=range.size()==4&&range.get(0).signum()==0&&range.get(2).add(range.get(3)).equals(java.math.BigInteger.valueOf(new File(args[0]).length()));
   boolean timestampAnchored=!stamps.isEmpty();for(var ts:stamps){var t=diagnostic.getTimestampById(ts.getId());timestampAnchored&=t!=null&&t.isSignatureIntact()&&t.isSignatureValid()&&t.isTrustedChain();}
   String fingerprint=token.getSigningCertificate()==null?"":safe(token.getSigningCertificate().getId());
   System.out.println("INTEGRITY\t"+safe(id)+"\t"+(token.isSignatureIntact()&&token.isSignatureValid()?"1":"0")+"\t"+fingerprint+"\t"+safe(revision==null?null:revision.getDocMDPPermissions())+"\t"+(revision!=null&&(revision.getFieldMDP()!=null||revision.getSigFieldLock()!=null)?"1":"0")+"\t"+(token.isTrustedChain()?"1":"0")+"\t"+(coversFile?"1":"0")+"\t"+(timestampAnchored?"1":"0"));
   var rawRevision=diagnostic.getJaxbModel().getSignatures().stream().filter(s->id.equals(s.getId())).findFirst().map(s->s.getPDFRevision()).orElse(null);
   boolean revisionChecked=rawRevision!=null&&rawRevision.getModificationDetection()!=null&&rawRevision.getModificationDetection().getObjectModifications()!=null;
   if(revision!=null)System.out.println("REVISION\t"+safe(id)+"\t"+(revisionChecked?"1":"0")+"\t"+(revision.isSignatureByteRangeValid()?"1":"0")+"\t"+(revision.isPdfSignatureDictionaryConsistent()?"1":"0")+"\t"+revision.getPdfExtensionChanges().size()+"\t"+revision.getPdfSignatureOrFormFillChanges().size()+"\t"+revision.getPdfAnnotationChanges().size()+"\t"+revision.getPdfUndefinedChanges().size()+"\t"+revision.getPdfVisualDifferenceConcernedPages().size()+"\t"+revision.getPdfPageDifferenceConcernedPages().size()+"\t"+revision.getPdfAnnotationsOverlapConcernedPages().size());
   System.out.println("SIGNATURE\t"+safe(id)+"\t"+name+"\t"+safe(report.getSignatureFormat(id)==null?null:report.getSignatureFormat(id).name())+"\t"+safe(report.getIndication(id)==null?null:report.getIndication(id).name())+"\t"+safe(report.getSubIndication(id)==null?null:report.getSubIndication(id).name())+"\t"+(timestampValid?"1":"0")+"\t"+(time==null?"":time.toInstant().toString()));
  }
 }
 static CommonTrustedCertificateSource trustAnchors(String paths) throws Exception {
  CommonTrustedCertificateSource trusted=new CommonTrustedCertificateSource();CertificateFactory factory=CertificateFactory.getInstance("X.509");
  for(String path:paths.split(","))try(InputStream in=new FileInputStream(path)){for(java.security.cert.Certificate cert:factory.generateCertificates(in))trusted.addCertificate(new CertificateToken((X509Certificate)cert));}
  return trusted;
 }
}
