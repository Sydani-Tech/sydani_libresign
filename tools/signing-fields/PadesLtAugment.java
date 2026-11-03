/* SPDX-License-Identifier: AGPL-3.0-or-later */
package coop.libresign;

import eu.europa.esig.dss.model.FileDocument;
import eu.europa.esig.dss.pades.validation.PDFDocumentAnalyzer;
import eu.europa.esig.dss.pdf.pdfbox.PdfBoxNativeObjectFactory;
import eu.europa.esig.dss.service.crl.OnlineCRLSource;
import eu.europa.esig.dss.service.http.commons.CommonsDataLoader;
import eu.europa.esig.dss.service.ocsp.OnlineOCSPSource;
import eu.europa.esig.dss.spi.validation.CommonCertificateVerifier;
import java.nio.file.Path;
import java.util.List;
import java.util.Set;

/** Append revocation evidence for the selected new signatures only. */
public final class PadesLtAugment {
 public static void main(String[] args) throws Exception {
  if (args.length != 4) throw new IllegalArgumentException("Expected input, output, trust anchors and selected signatures");
  var ids = Set.of(args[3].split(","));
  if (ids.isEmpty() || ids.stream().anyMatch(id -> !id.matches("S-[A-F0-9]+"))) {
   throw new IllegalArgumentException("Invalid signature selection");
  }
  var document = new FileDocument(args[0]);
  var factory = new PdfBoxNativeObjectFactory();
  var analyzer = new PDFDocumentAnalyzer(document);
  analyzer.setPdfObjFactory(factory);
  var verifier = new CommonCertificateVerifier();
  verifier.setTrustedCertSources(PadesReport.trustAnchors(args[2]));
  verifier.setCheckRevocationForUntrustedChains(false);
  var http = new CommonsDataLoader();
  http.setTimeoutConnection(5000);
  http.setTimeoutResponse(8000);
  http.setTimeoutSocket(8000);
  http.setRedirectsEnabled(false);
  var ocspHttp = new CommonsDataLoader("application/ocsp-request");
  ocspHttp.setTimeoutConnection(5000);
  ocspHttp.setTimeoutResponse(8000);
  ocspHttp.setTimeoutSocket(8000);
  ocspHttp.setRedirectsEnabled(false);
  verifier.setCrlSource(new OnlineCRLSource(http));
  verifier.setOcspSource(new OnlineOCSPSource(ocspHttp));
  analyzer.setCertificateVerifier(verifier);
  var selected = analyzer.getSignatures().stream().filter(signature -> ids.contains(signature.getId())).toList();
  if (selected.size() != ids.size()) throw new IllegalArgumentException("A selected signature is missing");
  var evidence = analyzer.getValidationData(selected, List.of());
  factory.newPAdESSignatureService().addDssDictionary(document, evidence, null, true).save(Path.of(args[1]).toString());
 }
}
