/*
 * SPDX-FileCopyrightText: 2026 Sydani Technology contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
package coop.libresign;

import com.lowagie.text.Rectangle;
import com.lowagie.text.pdf.*;
import java.io.*;
import java.nio.charset.StandardCharsets;
import java.nio.file.*;
import java.util.*;

/** Uses the OpenPDF bundled with JSignPdf. Never rewrites an existing revision. */
public final class SigningFields {
    public static void main(String[] args) throws Exception {
        if (args.length != 4) throw new IllegalArgumentException("Expected input, output, field plan and font");
        PdfReader reader = new PdfReader(args[0]);
        if (reader.isEncrypted()) throw new IllegalArgumentException("Encrypted form documents are not supported");
        boolean signed = !reader.getAcroFields().getSignatureNames().isEmpty();
        // No changes are permitted by a certification signature with DocMDP P=1.
        PdfDictionary perms = reader.getCatalog().getAsDict(PdfName.PERMS);
        if (perms != null && perms.getAsDict(PdfName.DOCMDP) != null) {
            PdfArray refs = perms.getAsDict(PdfName.DOCMDP).getAsArray(PdfName.REFERENCE);
            if (refs != null) for (int i = 0; i < refs.size(); i++) {
                PdfDictionary ref = refs.getAsDict(i);
                PdfDictionary params = ref.getAsDict(PdfName.TRANSFORMPARAMS);
                if (PdfName.DOCMDP.equals(ref.getAsName(PdfName.TRANSFORMMETHOD))
                        && params != null && params.getAsNumber(PdfName.P) != null
                        && params.getAsNumber(PdfName.P).intValue() == 1) {
                    throw new IllegalArgumentException("Certification prohibits filling fields");
                }
            }
        }
        List<String[]> plan = new ArrayList<>();
        Set<String> names = new HashSet<>();
        for (String line : Files.readAllLines(Paths.get(args[2]), StandardCharsets.UTF_8)) {
            String[] f = line.split("\t", -1);
            if (f.length != 10 || !f[0].matches("[1-9][0-9]*") || !names.add(f[0]))
                throw new IllegalArgumentException("Invalid field plan");
            if (!Arrays.asList("text", "date", "checkbox").contains(f[1]))
                throw new IllegalArgumentException("Unsupported field type");
            int page = Integer.parseInt(f[2]);
            if (page < 1 || page > reader.getNumberOfPages()) throw new IllegalArgumentException("Invalid page");
            Rectangle box = rectangle(f);
            Rectangle crop = reader.getCropBox(page);
            // The editor uses upright page coordinates. Reject unusual PDFs until mapped explicitly.
            if (reader.getPageRotation(page) != 0 || crop.getLeft() != 0 || crop.getBottom() != 0)
                throw new IllegalArgumentException("Please use a PDF with unrotated pages and a zero-origin crop box");
            if (box.getLeft() < 0 || box.getBottom() < 0 || box.getWidth() < 8 || box.getHeight() < 8
                    || box.getRight() > crop.getRight() || box.getTop() > crop.getTop())
                throw new IllegalArgumentException("Field is outside its page or too small");
            if (signed && reader.getAcroFields().getFieldItem(name(f)) == null)
                throw new IllegalArgumentException("Cannot add new form fields after the first signature");
            if (!signed && reader.getAcroFields().getFieldItem(name(f)) != null)
                throw new IllegalArgumentException("Document already contains a reserved LibreSign field name");
            if (signed) {
                AcroFields.Item existing = reader.getAcroFields().getFieldItem(name(f));
                PdfDictionary merged = existing.getMerged(0);
                PdfArray rect = merged.getAsArray(PdfName.RECT);
                int actualType = reader.getAcroFields().getFieldType(name(f));
                int expectedType = f[1].equals("checkbox") ? AcroFields.FIELD_TYPE_CHECKBOX : AcroFields.FIELD_TYPE_TEXT;
                PdfString actualLabel = merged.getAsString(PdfName.TU);
                PdfNumber flags = merged.getAsNumber(PdfName.FF);
                boolean actualRequired = flags != null && (flags.intValue() & PdfFormField.FF_REQUIRED) != 0;
                if (rect == null || rect.size() != 4 || existing.getPage(0) != page
                        || rect.getAsNumber(0).intValue() != box.getLeft()
                        || rect.getAsNumber(1).intValue() != box.getBottom()
                        || rect.getAsNumber(2).intValue() != box.getRight()
                        || rect.getAsNumber(3).intValue() != box.getTop()
                        || actualType != expectedType
                        || actualLabel == null || !actualLabel.toUnicodeString().equals(decode(f[8]))
                        || actualRequired != f[7].equals("1"))
                    throw new IllegalArgumentException("Signed field definition differs from the saved request");
            }
            plan.add(f);
        }
        if (signed) for (Object existingKey : reader.getAcroFields().getFields().keySet()) {
            String existingName = String.valueOf(existingKey);
            if (existingName.startsWith("libresign_field_")
                    && !names.contains(existingName.substring("libresign_field_".length())))
                throw new IllegalArgumentException("A signed form field is missing from the request");
        }
        try (OutputStream output = Files.newOutputStream(Paths.get(args[1]))) {
            PdfStamper stamper = new PdfStamper(reader, output, '\0', true);
            AcroFields fields = stamper.getAcroFields();
            if (reader.getCatalog().getAsDict(PdfName.ACROFORM) != null)
                fields.setGenerateAppearances(true);
            BaseFont font = BaseFont.createFont(args[3], BaseFont.IDENTITY_H, BaseFont.EMBEDDED);
            fields.addSubstitutionFont(font);
            for (String[] f : plan) {
                String name = name(f);
                boolean filling = !f[9].equals("-");
                String value = filling ? decode(f[9]) : "";
                if (f[1].equals("checkbox") && filling && !value.equals("true") && !value.equals("false"))
                    throw new IllegalArgumentException("Invalid checkbox value");
                if (fields.getFieldItem(name) == null) {
                    PdfFormField field;
                    if (f[1].equals("checkbox")) {
                        RadioCheckField check = new RadioCheckField(stamper.getWriter(), rectangle(f), name, "Yes");
                        check.setCheckType(RadioCheckField.TYPE_CHECK);
                        check.setBorderWidth(0.75f);
                        check.setBorderColor(java.awt.Color.BLACK);
                        check.setChecked(value.equals("true"));
                        field = check.getCheckField();
                    } else {
                        TextField text = new TextField(stamper.getWriter(), rectangle(f), name);
                        text.setFont(font);
                        text.setFontSize(0); // Fit to the sender's rectangle.
                        text.setText(value);
                        text.setMaxCharacterLength(500);
                        field = text.getTextField();
                    }
                    field.setUserName(decode(f[8]));
                    if (f[7].equals("1")) field.setFieldFlags(PdfFormField.FF_REQUIRED);
                    if (filling) field.setFieldFlags(PdfFormField.FF_READ_ONLY);
                    field.setFlags(PdfAnnotation.FLAGS_PRINT);
                    stamper.addAnnotation(field, Integer.parseInt(f[2]));
                } else if (filling) {
                    AcroFields.Item item = fields.getFieldItem(name);
                    PdfNumber flags = item.getMerged(0).getAsNumber(PdfName.FF);
                    if (flags != null && (flags.intValue() & PdfFormField.FF_READ_ONLY) != 0)
                        throw new IllegalArgumentException("A completed field cannot be changed");
                    if (!fields.setField(name, f[1].equals("checkbox") ? (value.equals("true") ? "Yes" : "Off") : value))
                        throw new IllegalArgumentException("Could not fill assigned field");
                    fields.setFieldProperty(name, "setfflags", PdfFormField.FF_READ_ONLY, null);
                }
            }
            stamper.close();
        } finally {
            reader.close();
        }
    }
    private static String name(String[] f) { return "libresign_field_" + f[0]; }
    private static String decode(String value) { return new String(Base64.getDecoder().decode(value), StandardCharsets.UTF_8); }
    private static Rectangle rectangle(String[] f) {
        return new Rectangle(Integer.parseInt(f[3]), Integer.parseInt(f[4]), Integer.parseInt(f[5]), Integer.parseInt(f[6]));
    }
}
