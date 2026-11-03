/* SPDX-License-Identifier: AGPL-3.0-or-later */
package coop.libresign;

import eu.europa.esig.dss.model.FileDocument;
import eu.europa.esig.dss.pades.SignatureFieldParameters;
import eu.europa.esig.dss.pdf.PdfPermissionsChecker;
import eu.europa.esig.dss.pdf.pdfbox.PdfBoxDocumentReader;
import org.apache.pdfbox.Loader;
import org.apache.pdfbox.pdmodel.PDPage;
import org.apache.pdfbox.pdmodel.interactive.annotation.PDAnnotationWidget;
import org.apache.pdfbox.pdmodel.interactive.form.PDSignatureField;
import java.io.File;
import java.nio.charset.StandardCharsets;
import java.util.Base64;
import java.util.HashMap;

/** Inspect empty digital signature fields using the same permission checks as the signing engine. */
public final class PadesSignatureFields {
 public static void main(String[] args) throws Exception {
  if (args.length != 1) throw new IllegalArgumentException("Expected a PDF path");
  try (var pdf = Loader.loadPDF(new File(args[0]));
       var reader = new PdfBoxDocumentReader(new FileDocument(args[0]))) {
   var form = pdf.getDocumentCatalog().getAcroForm();
   if (form == null) return;

   var pageNumbers = new HashMap<org.apache.pdfbox.cos.COSDictionary, Integer>();
   var widgetPages = new HashMap<org.apache.pdfbox.cos.COSDictionary, Integer>();
   int number = 0;
   for (PDPage page : pdf.getPages()) {
    pageNumbers.put(page.getCOSObject(), ++number);
    for (var annotation : page.getAnnotations()) {
     if (annotation instanceof PDAnnotationWidget) widgetPages.put(annotation.getCOSObject(), number);
    }
   }
   var permissions = new PdfPermissionsChecker();
   for (var field : form.getFieldTree()) {
    if (!(field instanceof PDSignatureField signature) || signature.getSignature() != null || field.isReadOnly()) continue;
    String name = field.getFullyQualifiedName();
    // JSignPdf reserves these names for automatic or ordinal selection.
    if (name == null || name.isBlank() || name.equalsIgnoreCase("auto") || name.startsWith("#")
      || name.length() > 512 || name.codePoints().anyMatch(Character::isISOControl)
      || form.getField(name) == null || !form.getField(name).getCOSObject().equals(field.getCOSObject())
      || signature.getWidgets().size() != 1) continue;
    var widget = signature.getWidgets().getFirst();
    var rectangle = widget.getRectangle();
    var page = widget.getPage();
    var pageNumber = page == null ? widgetPages.get(widget.getCOSObject()) : pageNumbers.get(page.getCOSObject());
    if (pageNumber == null || rectangle == null || rectangle.getWidth() <= 0 || rectangle.getHeight() <= 0) continue;

    var target = new SignatureFieldParameters();
    target.setFieldId(name);
    try {
     permissions.checkDocumentPermissions(reader, target);
    } catch (eu.europa.esig.dss.pades.exception.ProtectedDocumentException denied) {
     continue;
    }

    String encoded = Base64.getEncoder().encodeToString(name.getBytes(StandardCharsets.UTF_8));
    System.out.printf(java.util.Locale.ROOT, "FIELD\t%s\t%d\t%f\t%f\t%f\t%f%n", encoded, pageNumber,
      rectangle.getLowerLeftX(), rectangle.getLowerLeftY(), rectangle.getUpperRightX(), rectangle.getUpperRightY());
   }
  }
 }
}
