/*
 * SPDX-FileCopyrightText: 2026 Sydani Technology contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
package coop.libresign;

import org.openpdf.text.Rectangle;
import org.openpdf.text.pdf.*;
import java.io.*;
import java.nio.charset.StandardCharsets;
import java.nio.file.*;
import java.time.LocalDate;
import java.time.format.DateTimeFormatter;
import java.time.format.DateTimeParseException;
import java.util.*;

/** Reserve the complete layout before the first signature; later revisions only fill existing fields. */
public final class SigningFields {
    public static void main(String[] args) throws Exception {
        if (args.length != 4) throw new IllegalArgumentException("Expected input, output, field plan and font");
        PdfReader reader = new PdfReader(args[0]);
        if (reader.isEncrypted()) throw new IllegalArgumentException("Encrypted form documents are not supported");
        AcroFields sourceFields = reader.getAcroFields();
        boolean signed = !sourceFields.getSignedFieldNames().isEmpty();
        boolean prepared = !signed || sourceFields.getAllFields().keySet().stream()
            .anyMatch(key -> String.valueOf(key).startsWith("libresign_signature_"));
        PdfDictionary perms = reader.getCatalog().getAsDict(PdfName.PERMS);
        if (perms != null && perms.getAsDict(PdfName.DOCMDP) != null) {
            PdfArray refs = perms.getAsDict(PdfName.DOCMDP).getAsArray(PdfName.REFERENCE);
            if (refs != null) for (int i = 0; i < refs.size(); i++) {
                PdfDictionary ref = refs.getAsDict(i);
                PdfDictionary params = ref.getAsDict(PdfName.TRANSFORMPARAMS);
                if (PdfName.DOCMDP.equals(ref.getAsName(PdfName.TRANSFORMMETHOD))
                        && params != null && params.getAsNumber(PdfName.P) != null
                        && params.getAsNumber(PdfName.P).intValue() == 1)
                    throw new IllegalArgumentException("Certification prohibits filling fields");
            }
        }
        List<String[]> plan = new ArrayList<>();
        Set<String> names = new HashSet<>();
        for (String line : Files.readAllLines(Paths.get(args[2]), StandardCharsets.UTF_8)) {
            String[] f = line.split("\t", -1);
            if ((f.length != 10 && f.length != 11) || !f[0].matches("s?[1-9][0-9]*"))
                throw new IllegalArgumentException("Invalid field plan");
            if (!Arrays.asList("text", "date", "checkbox", "signature").contains(f[1])
                    || (!f[1].equals("signature") && f[0].startsWith("s")) || !names.add(name(f)))
                throw new IllegalArgumentException("Unsupported or duplicate field definition");
            int page = Integer.parseInt(f[2]);
            if (page < 1 || page > reader.getNumberOfPages()) throw new IllegalArgumentException("Invalid page");
            Rectangle box = rectangle(f);
            Rectangle crop = reader.getCropBox(page);
            if (reader.getPageRotation(page) != 0 || crop.getLeft() != 0 || crop.getBottom() != 0)
                throw new IllegalArgumentException("Please use a PDF with unrotated pages and a zero-origin crop box");
            boolean invisible = f[1].equals("signature") && box.getWidth() == 0 && box.getHeight() == 0;
            if (box.getLeft() < 0 || box.getBottom() < 0 || (!invisible && (box.getWidth() < 8 || box.getHeight() < 8))
                    || box.getRight() > crop.getRight() || box.getTop() > crop.getTop())
                throw new IllegalArgumentException("Field is outside its page or too small");
            // Existing signed requests without reserved signature slots keep the legacy path.
            // Never add a new managed slot to a PDF which has already been signed.
            if (signed && !prepared && f[1].equals("signature")) continue;
            AcroFields.Item existing = sourceFields.getFieldItem(name(f));
            if (signed && existing == null) throw new IllegalArgumentException("Cannot add new form fields after the first signature");
            if (!signed && existing != null) throw new IllegalArgumentException("Document already contains a reserved LibreSign field name");
            if (signed) {
                PdfDictionary merged = existing.getMerged(0);
                PdfArray rect = merged.getAsArray(PdfName.RECT);
                int expectedType = f[1].equals("signature") ? AcroFields.FIELD_TYPE_SIGNATURE
                    : f[1].equals("checkbox") ? AcroFields.FIELD_TYPE_CHECKBOX : AcroFields.FIELD_TYPE_TEXT;
                PdfString actualLabel = merged.getAsString(PdfName.TU);
                PdfNumber flags = merged.getAsNumber(PdfName.FF);
                if (prepared && f.length == 11 && !f[10].isEmpty() && !f[10].equals(owner(merged)))
                    throw new IllegalArgumentException("Signed field owner differs from the saved request");
                boolean actualRequired = flags != null && (flags.intValue() & PdfFormField.FF_REQUIRED) != 0;
                if (rect == null || rect.size() != 4 || existing.getPage(0) != page
                        || rect.getAsNumber(0).floatValue() != box.getLeft()
                        || rect.getAsNumber(1).floatValue() != box.getBottom()
                        || rect.getAsNumber(2).floatValue() != box.getRight()
                        || rect.getAsNumber(3).floatValue() != box.getTop()
                        || sourceFields.getFieldType(name(f)) != expectedType
                        || actualLabel == null || !actualLabel.toUnicodeString().equals(decode(f[8]))
                        || actualRequired != f[7].equals("1"))
                    throw new IllegalArgumentException("Signed field definition differs from the saved request");
                if (f[1].equals("signature") && !f[9].equals("-") && merged.get(PdfName.V) != null)
                    throw new IllegalArgumentException("The selected signature slot has already been signed");
            }
            plan.add(f);
        }
        if (signed) for (Object existingKey : sourceFields.getAllFields().keySet()) {
            String key = String.valueOf(existingKey);
            if ((key.startsWith("libresign_field_") || key.startsWith("libresign_signature_")) && !names.contains(key))
                throw new IllegalArgumentException("A signed form field is missing from the request");
        }
        try (OutputStream output = Files.newOutputStream(Paths.get(args[1]))) {
            PdfStamper stamper = new PdfStamper(reader, output, (String)null, true);
            AcroFields fields = stamper.getAcroFields();
            if (reader.getCatalog().getAsDict(PdfName.ACROFORM) != null) fields.setGenerateAppearances(true);
            BaseFont font = null;
            if (!signed || !prepared) {
                font = BaseFont.createFont(args[3], BaseFont.IDENTITY_H, BaseFont.EMBEDDED);
                // Later signers may use different characters. The complete Unicode font
                // and mapping must already be in the revision the first signer approves.
                font.setSubset(false);
                font.addSubsetRange(new int[] { 0, 0xffff });
                if (prepared) registerCompleteUnicodeMap(stamper.getWriter(), font);
                if (!prepared) fields.addSubstitutionFont(font);
            }
            for (String[] f : plan) {
                String name = name(f);
                boolean filling = !f[9].equals("-");
                String value = filling ? decode(f[9]) : "";
                if (f[1].equals("signature")) {
                    if (fields.getFieldItem(name) == null) {
                        PdfFormField field = PdfFormField.createSignature(stamper.getWriter());
                        field.setFieldName(name);
                        field.setWidget(rectangle(f), PdfAnnotation.HIGHLIGHT_INVERT);
                        field.setUserName(decode(f[8]));
                    if (f.length == 11 && !f[10].isEmpty()) field.put(new PdfName("LibreSignOwner"), new PdfString(f[10]));
                        if (f[7].equals("1")) field.setFieldFlags(PdfFormField.FF_REQUIRED);
                        field.setFlags(PdfAnnotation.FLAGS_PRINT);
                        stamper.addAnnotation(field, Integer.parseInt(f[2]));
                        System.out.println("SIGNATURE_FIELD\t" + f[0] + "\t" + name);
                    } else if (fields.getFieldItem(name).getMerged(0).get(PdfName.V) == null) {
                        System.out.println("SIGNATURE_FIELD\t" + f[0] + "\t" + name);
                    }
                    continue;
                }
                if (f[1].equals("date") && filling) value = displayDate(value);
                if (f[1].equals("checkbox") && filling && !value.equals("true") && !value.equals("false"))
                    throw new IllegalArgumentException("Invalid checkbox value");
                if (fields.getFieldItem(name) == null) {
                    PdfFormField field;
                    if (f[1].equals("checkbox")) {
                        RadioCheckField check = new RadioCheckField(stamper.getWriter(), rectangle(f), name, "Yes");
                        check.setCheckType(RadioCheckField.TYPE_CHECK);
                        check.setBorderWidth(0);
                        check.setChecked(value.equals("true"));
                        field = check.getFullField();
                        setVectorCheckboxAppearance(field, stamper.getWriter(), rectangle(f));
                    } else {
                        TextField text = new TextField(stamper.getWriter(), rectangle(f), name);
                        text.setFont(font);
                        text.setFontSize(0);
                        text.setText(value);
                        text.setMaxCharacterLength(500);
                        field = text.getTextField();
                    }
                    field.setUserName(decode(f[8]));
                        if (f.length == 11 && !f[10].isEmpty()) field.put(new PdfName("LibreSignOwner"), new PdfString(f[10]));
                    if (f[7].equals("1")) field.setFieldFlags(PdfFormField.FF_REQUIRED);
                    if (filling) field.setFieldFlags(PdfFormField.FF_READ_ONLY);
                    field.setFlags(PdfAnnotation.FLAGS_PRINT);
                    stamper.addAnnotation(field, Integer.parseInt(f[2]));
                } else if (filling) {
                    AcroFields.Item item = fields.getFieldItem(name);
                    PdfNumber flags = item.getMerged(0).getAsNumber(PdfName.FF);
                    if (flags != null && (flags.intValue() & PdfFormField.FF_READ_ONLY) != 0)
                        throw new IllegalArgumentException("A completed field cannot be changed");
                    // Legacy partial PDFs may contain a subset missing the new signer's
                    // characters. Stable layouts never replace the first revision's font.
                    if (!prepared && !f[1].equals("checkbox") && !fields.setFieldProperty(name, "textfont", font, null))
                        throw new IllegalArgumentException("Could not set assigned field font");
                    if (prepared && !f[1].equals("checkbox")) {
                        fillStableText(stamper, item, rectangle(f), value);
                    } else if (!fields.setField(name, f[1].equals("checkbox") ? (value.equals("true") ? "Yes" : "Off") : value))
                        throw new IllegalArgumentException("Could not fill assigned field");
                    fields.setFieldProperty(name, "setfflags", PdfFormField.FF_READ_ONLY, null);
                }
            }
            stamper.close();
            System.out.println("SIGNATURE_LAYOUT\t" + (prepared ? "prepared" : "legacy"));
        } finally { reader.close(); }
    }
    private static void fillStableText(PdfStamper stamper, AcroFields.Item item, Rectangle box, String value) throws Exception {
        PdfDictionary merged = item.getMerged(0);
        String da = merged.getAsString(PdfName.DA).toUnicodeString();
        java.util.regex.Matcher match = java.util.regex.Pattern.compile("/([^\\s]+)\\s+[0-9.]+\\s+Tf").matcher(da);
        if (!match.find()) throw new IllegalArgumentException("Prepared text font is missing");
        PdfName resourceName = new PdfName(match.group(1));
        PdfObject fontRef = merged.getAsDict(PdfName.DR).getAsDict(PdfName.FONT).get(resourceName);
        if (!(fontRef instanceof PRIndirectReference)) throw new IllegalArgumentException("Prepared text font is not indirect");
        PdfDictionary fontDict = (PdfDictionary)PdfReader.getPdfObject(fontRef);
        PdfDictionary descendant = fontDict.getAsArray(PdfName.DESCENDANTFONTS).getAsDict(0);
        if (!PdfName.IDENTITY.equals(descendant.getAsName(PdfName.CIDTOGIDMAP)))
            throw new IllegalArgumentException("Prepared text font has an unsupported glyph mapping");
        PRStream program = (PRStream)PdfReader.getPdfObject(descendant.getAsDict(PdfName.FONTDESCRIPTOR).get(PdfName.FONTFILE2));
        BaseFont metrics = BaseFont.createFont("libresign-embedded.ttf", BaseFont.IDENTITY_H, true, false, PdfReader.getStreamBytes(program), null);
        // OpenPDF exposes glyph IDs on its package-private Unicode font implementation.
        java.lang.reflect.Method glyphLookup = metrics.getClass().getMethod("getMetricsTT", int.class);
        glyphLookup.setAccessible(true);
        StringBuilder encoded = new StringBuilder();
        for (int offset = 0; offset < value.length();) {
            int point = value.codePointAt(offset); offset += Character.charCount(point);
            int[] glyph = (int[])glyphLookup.invoke(metrics, point);
            if (glyph == null) throw new IllegalArgumentException("The prepared font does not support a field character");
            encoded.append(String.format(Locale.ROOT, "%04X", glyph[0]));
        }
        float fontSize = Math.min(14, Math.max(1, (box.getHeight() - 4) * 0.45f));
        if (!value.isEmpty()) fontSize = Math.min(fontSize, (box.getWidth() - 4) * 1000 / Math.max(1, metrics.getWidth(value)));
        float baseline = (box.getHeight() - metrics.getFontDescriptor(BaseFont.ASCENT, fontSize)
            - metrics.getFontDescriptor(BaseFont.DESCENT, fontSize)) / 2;
        PdfAppearance appearance = PdfAppearance.createAppearance(stamper.getWriter(), box.getWidth(), box.getHeight());
        appearance.saveState(); appearance.rectangle(1, 1, box.getWidth() - 2, box.getHeight() - 2); appearance.clip(); appearance.newPath();
        appearance.beginText(); appearance.setColorFill(java.awt.Color.BLACK);
        appearance.setFontAndSize(BaseFont.createFont((PRIndirectReference)fontRef), fontSize);
        appearance.setTextMatrix(2, baseline); appearance.setLiteral("<" + encoded + "> Tj\n"); appearance.endText(); appearance.restoreState();
        PdfDictionary ap = new PdfDictionary(); ap.put(PdfName.N, appearance.getIndirectReference());
        PdfString text = new PdfString(value, PdfObject.TEXT_UNICODE);
        for (int index = 0; index < item.size(); index++) {
            item.getWidget(index).put(PdfName.AP, ap); item.getValue(index).put(PdfName.V, text);
            item.getMerged(index).put(PdfName.AP, ap); item.getMerged(index).put(PdfName.V, text);
            stamper.markUsed(item.getWidget(index)); stamper.markUsed(item.getValue(index));
        }
    }

    private static void registerCompleteUnicodeMap(PdfWriter writer, BaseFont font) {
        StringBuilder supported = new StringBuilder();
        for (int codePoint = 1; codePoint <= Character.MAX_CODE_POINT; codePoint++) {
            if (codePoint >= Character.MIN_SURROGATE && codePoint <= Character.MAX_SURROGATE) continue;
            if (font.charExists(codePoint)) supported.appendCodePoint(codePoint);
        }
        // Register glyph mappings without painting hidden text or changing page content.
        // Embedding the full TTF alone does not populate OpenPDF's ToUnicode mapping.
        PdfContentByte registration = PdfAppearance.createAppearance(writer, 1, 1);
        registration.beginText();
        registration.setFontAndSize(font, 1);
        registration.showText(supported.toString());
        registration.endText();
    }

    private static String owner(PdfDictionary field) {
        PdfString owner = field.getAsString(new PdfName("LibreSignOwner"));
        return owner == null ? "" : owner.toUnicodeString();
    }

    private static String name(String[] f) {
        return (f[1].equals("signature") ? "libresign_signature_" : "libresign_field_") + f[0];
    }
    private static String decode(String value) { return new String(Base64.getDecoder().decode(value), StandardCharsets.UTF_8); }
    private static String displayDate(String isoDate) {
        try {
            LocalDate date = LocalDate.parse(isoDate);
            int day = date.getDayOfMonth();
            String suffix = (day % 100 >= 11 && day % 100 <= 13) ? "th" : switch (day % 10) {
                case 1 -> "st";
                case 2 -> "nd";
                case 3 -> "rd";
                default -> "th";
            };
            return day + suffix + " " + date.format(DateTimeFormatter.ofPattern("MMM, uuuu", Locale.ENGLISH));
        } catch (DateTimeParseException e) {
            throw new IllegalArgumentException("Invalid ISO date value", e);
        }
    }
    private static void setVectorCheckboxAppearance(PdfFormField field, PdfWriter writer, Rectangle box) {
        float width = box.getWidth();
        float height = box.getHeight();
        for (boolean checked : new boolean[] { false, true }) {
            PdfAppearance appearance = PdfAppearance.createAppearance(writer, width, height);
            appearance.saveState();
            appearance.setColorStroke(java.awt.Color.BLACK);
            if (checked) {
                appearance.setLineWidth(Math.max(1.5f, Math.min(width, height) * 0.095f));
                appearance.moveTo(width * 0.19f, height * 0.51f);
                appearance.lineTo(width * 0.42f, height * 0.27f);
                appearance.lineTo(width * 0.82f, height * 0.76f);
                appearance.stroke();
            }
            appearance.restoreState();
            field.setAppearance(PdfAnnotation.APPEARANCE_NORMAL, checked ? "Yes" : "Off", appearance);
        }
    }
    private static Rectangle rectangle(String[] f) {
        return new Rectangle(Integer.parseInt(f[3]), Integer.parseInt(f[4]), Integer.parseInt(f[5]), Integer.parseInt(f[6]));
    }
}
