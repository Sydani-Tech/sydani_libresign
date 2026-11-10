/*
 * SPDX-FileCopyrightText: 2026 Sydani Technology contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
package coop.libresign;

import org.openpdf.text.Document;
import org.openpdf.text.Paragraph;
import org.openpdf.text.pdf.*;
import java.nio.charset.StandardCharsets;
import java.nio.file.*;
import java.util.*;

/** Exercises real incremental signatures and later signers' reserved form fields. */
public final class SequentialFieldRegression {
    private static final String[] VALUES = { "Rahee", "Dv", "Zoë ₦250", "Μαρία", "Ирина" };
    private static final String[] DATES = { "2026-01-01", "2026-10-09", "2026-03-22", "2026-05-13", "2026-12-31" };
    private static final String[] DISPLAY_DATES = { "1st Jan, 2026", "9th Oct, 2026", "22nd Mar, 2026", "13th May, 2026", "31st Dec, 2026" };

    public static void main(String[] args) throws Exception {
        if (args.length != 5) throw new IllegalArgumentException("Expected work directory, font, JSignPdf classpath, test keystore, test password");
        Path root = Paths.get(args[0]);
        Files.createDirectories(root);
        Path input = root.resolve("original.pdf");
        Document document = new Document();
        PdfWriter writer = PdfWriter.getInstance(document, Files.newOutputStream(input));
        writer.setPdfVersion(PdfWriter.VERSION_1_7);
        document.open();
        document.add(new Paragraph("Five sequential signers: text, date and checkbox"));
        document.close();
        String[] keystores = System.getProperty("libresign.test.keystores", args[3]).split(",");
        for (int signer = 0; signer < Integer.getInteger("libresign.test.signers", VALUES.length); signer++) {
            Path plan = root.resolve("plan-" + (signer + 1) + ".tsv");
            List<String> lines = new ArrayList<>();
            for (int owner = 0; owner < VALUES.length; owner++) {
                for (int type = 0; type < 4; type++) {
                    int[] rect = rectangle(owner, type);
                    String value = owner == signer ? encode(type == 0 ? VALUES[owner] : type == 1 ? DATES[owner] : "true") : "-";
                    lines.add(String.join("\t", String.valueOf(owner * 4 + type + 1),
                        type == 0 ? "text" : type == 1 ? "date" : type == 2 ? "checkbox" : "signature", "1",
                        String.valueOf(rect[0]), String.valueOf(rect[1]), String.valueOf(rect[2]), String.valueOf(rect[3]),
                        "1", encode("Signer " + (owner + 1) + " field " + type), value, String.valueOf(owner + 1)));
                }
            }
            Files.write(plan, lines, StandardCharsets.UTF_8);
            Path filled = root.resolve("step" + (signer + 1) + ".pdf");
            SigningFields.main(new String[] { input.toString(), filled.toString(), plan.toString(), args[1] });
            require(Arrays.equals(Files.readAllBytes(input), Arrays.copyOf(Files.readAllBytes(filled), (int)Files.size(input))), "Previous PDF revision was rewritten");
            List<String> command = new ArrayList<>(List.of(Paths.get(System.getProperty("java.home"), "bin", "java").toString(),
                "-Djava.awt.headless=true", "-cp", args[2], "com.intoolswetrust.jsignpdf.Bootstrap", "--engine", System.getProperty("libresign.test.engine", "openpdf"),
                "-a", "-kst", "PKCS12", "-ksf", keystores[Math.min(signer, keystores.length - 1)], "-ksp", args[4], "-sf", "libresign_signature_" + (signer * 4 + 4), "-d", root.toString(), "-os", ".signed"));
            String trust = System.getProperty("libresign.test.trust");
            if (trust != null) command.addAll(List.of("--pades-level", "LT", "--tsa-server-url", System.getProperty("libresign.test.tsa"),
                "--tsa-hash-algorithm", "SHA-256", "--option", "engine.dss.online.enabled=true", "--option", "engine.dss.trust.certFiles=" + trust,
                "--option", "engine.dss.trust.allowUntrusted=false", "--option", "engine.dss.tsa.nonce=true"));
            command.add(filled.toString());
            Process process = new ProcessBuilder(command).inheritIO().start();
            require(process.waitFor() == 0, "Test signing failed");
            input = root.resolve("step" + (signer + 1) + ".signed.pdf");
            verify(input, signer);
            verifyStableResources(root.resolve("step1.pdf"), input, signer);
        }
        System.out.println("Sequential regression passed: precreated slots, values, appearances, stable fonts and all cryptographic signatures.");
    }

    private static void verify(Path pdf, int completed) throws Exception {
        PdfReader reader = new PdfReader(pdf.toString());
        try {
            AcroFields fields = reader.getAcroFields();
            List<String> signatures = fields.getSignedFieldNames();
            require(signatures.size() == completed + 1, "Signature count changed");
            for (String signature : signatures) require(fields.verifySignature(signature).verify(), "Cryptographic signature is invalid");
            for (int owner = 0; owner < VALUES.length; owner++) {
                for (int type = 0; type < 4; type++) {
                    String name = (type == 3 ? "libresign_signature_" : "libresign_field_") + (owner * 4 + type + 1);
                    AcroFields.Item item = fields.getFieldItem(name);
                    require(item != null, "Reserved field missing");
                    PdfDictionary widget = item.getMerged(0);
                    PdfArray box = widget.getAsArray(PdfName.RECT);
                    int[] expectedBox = rectangle(owner, type);
                    for (int axis = 0; axis < 4; axis++) require(box.getAsNumber(axis).intValue() == expectedBox[axis], "Field moved");
                    if (type == 3) {
                        require(fields.getFieldType(name) == AcroFields.FIELD_TYPE_SIGNATURE, "Prepared signature slot missing");
                        require(signatures.contains(name) == (owner <= completed), "Wrong signature slot used");
                        continue;
                    }
                    boolean locked = (widget.getAsNumber(PdfName.FF).intValue() & PdfFormField.FF_READ_ONLY) != 0;
                    require(locked == (owner <= completed), "Wrong field locked");
                    if (owner > completed) continue;
                    String expected = type == 0 ? VALUES[owner] : type == 1 ? DISPLAY_DATES[owner] : "Yes";
                    require(expected.equals(fields.getField(name)), "Completed field value changed");
                    if (type == 2) {
                        PdfDictionary states = widget.getAsDict(PdfName.AP).getAsDict(PdfName.N);
                        String tick = new String(PdfReader.getStreamBytes((PRStream)states.getDirectObject(new PdfName("Yes"))), StandardCharsets.ISO_8859_1);
                        String empty = new String(PdfReader.getStreamBytes((PRStream)states.getDirectObject(new PdfName("Off"))), StandardCharsets.ISO_8859_1);
                        require(!tick.contains(" re") && tick.contains(" l") && tick.contains("S"), "Confirmation must be a vector tick without a box");
                        require(!empty.contains("S") && !empty.contains(" re"), "Unchecked confirmation must not paint a box or tick");
                    } else {
                        PdfStream appearance = (PdfStream)PdfReader.getPdfObject(widget.getAsDict(PdfName.AP).get(PdfName.N));
                        String content = new String(PdfReader.getStreamBytes((PRStream)appearance), StandardCharsets.ISO_8859_1);
                        require(!content.contains("()Tj") && !content.contains("() Tj") && content.contains("Tj"), "Text appearance is blank");
                    }
                }
            }
        } finally {
            reader.close();
        }
    }

    private static void verifyStableResources(Path first, Path current, int completed) throws Exception {
        PdfReader a = new PdfReader(first.toString());
        PdfReader b = new PdfReader(current.toString());
        try {
            PdfDictionary fontsA = a.getCatalog().getAsDict(PdfName.ACROFORM).getAsDict(PdfName.DR).getAsDict(PdfName.FONT);
            PdfDictionary fontsB = b.getCatalog().getAsDict(PdfName.ACROFORM).getAsDict(PdfName.DR).getAsDict(PdfName.FONT);
            require(fontsA.getKeys().equals(fontsB.getKeys()), "Shared font names changed after first signature");
            for (PdfName key : fontsA.getKeys()) require(fontsA.get(key).toString().equals(fontsB.get(key).toString()), "Shared font reference changed");
            require(b.getAcroFields().getAllFields().size() == VALUES.length * 4, "A field was added after first signature");
            require(!PdfBoolean.PDFTRUE.equals(b.getCatalog().getAsDict(PdfName.ACROFORM).get(PdfName.NEEDAPPEARANCES)), "Reader must not regenerate appearances");
            for (int type = 0; type < 2; type++) {
                String name = "libresign_field_" + (type + 1);
                PdfStream apA = (PdfStream)PdfReader.getPdfObject(a.getAcroFields().getFieldItem(name).getMerged(0).getAsDict(PdfName.AP).get(PdfName.N));
                PdfStream apB = (PdfStream)PdfReader.getPdfObject(b.getAcroFields().getFieldItem(name).getMerged(0).getAsDict(PdfName.AP).get(PdfName.N));
                require(Arrays.equals(PdfReader.getStreamBytes((PRStream)apA), PdfReader.getStreamBytes((PRStream)apB)), "An earlier signer's appearance changed");
            }
        } finally { a.close(); b.close(); }
    }

    private static int[] rectangle(int signer, int type) {
        int x = signer % 2 == 0 ? 40 : 310;
        int y = 710 - signer * 135;
        if (type == 0) return new int[] { x, y, x + 180, y + 28 };
        if (type == 1) return new int[] { x + 20, y - 36, x + 160, y - 8 };
        if (type == 2) return new int[] { x + 166, y - 34, x + 190, y - 10 };
        return new int[] { x, y + 35, x + 180, y + 65 };
    }

    private static String encode(String value) { return Base64.getEncoder().encodeToString(value.getBytes(StandardCharsets.UTF_8)); }
    private static void require(boolean condition, String message) { if (!condition) throw new IllegalStateException(message); }
}
