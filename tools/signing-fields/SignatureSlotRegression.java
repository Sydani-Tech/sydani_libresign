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

/** Multiple visible slots for one signer and a later invisible signature use only reserved fields. */
public final class SignatureSlotRegression {
    public static void main(String[] args) throws Exception {
        if (args.length != 5) throw new IllegalArgumentException("Expected work directory, font, engine classpath, test keystore and password");
        Path root = Path.of(args[0]); Files.createDirectories(root);
        Path input = root.resolve("slot-original.pdf");
        Document doc = new Document(); PdfWriter.getInstance(doc, Files.newOutputStream(input));
        doc.open(); doc.add(new Paragraph("Synthetic visible and invisible reserved signature slots")); doc.close();
        for (int owner = 1; owner <= 3; owner++) {
            Path plan = root.resolve("slot-plan-" + owner + ".tsv");
            List<String> lines = new ArrayList<>();
            lines.add(line("1", "signature", "1", "40", "650", "180", "690", owner == 1 ? "true" : null, "1"));
            lines.add(line("2", "signature", "1", "220", "650", "360", "690", owner == 1 ? "true" : null, "1"));
            lines.add(line("s2", "signature", "1", "0", "0", "0", "0", owner == 2 ? "true" : null, "2"));
            lines.add(line("3", "text", "1", "40", "500", "220", "530", owner == 3 ? "Later signer" : null, "3"));
            lines.add(line("4", "signature", "1", "40", "550", "220", "590", owner == 3 ? "true" : null, "3"));
            Files.write(plan, lines, StandardCharsets.UTF_8);
            Path prepared = root.resolve("slot-prepared-" + owner + ".pdf");
            SigningFields.main(new String[] { input.toString(), prepared.toString(), plan.toString(), args[1] });
            input = prepared;
            for (String slot : owner == 1 ? List.of("1", "2") : owner == 2 ? List.of("s2") : List.of("4")) {
                List<String> command = new ArrayList<>(List.of(Path.of(System.getProperty("java.home"), "bin", "java").toString(), "-Djava.awt.headless=true",
                    "-cp", args[2], "com.intoolswetrust.jsignpdf.Bootstrap", "--engine", System.getProperty("libresign.test.engine", "dss"), "-a", "-kst", "PKCS12", "-ksf", args[3],
                    "-ksp", args[4], "-sf", "libresign_signature_" + slot, "-d", root.toString(), "-os", ".signed"));
                String trust = System.getProperty("libresign.test.trust");
                if (trust != null) command.addAll(List.of("--pades-level", "LT", "--tsa-server-url", System.getProperty("libresign.test.tsa"), "--option", "engine.dss.online.enabled=true", "--option", "engine.dss.trust.certFiles=" + trust, "--option", "engine.dss.trust.allowUntrusted=false"));
                command.add(input.toString());
                if (new ProcessBuilder(command).inheritIO().start().waitFor() != 0) throw new IllegalStateException("Slot signing failed");
                input = root.resolve(input.getFileName().toString().replace(".pdf", ".signed.pdf"));
            }
        }
        PdfReader reader = new PdfReader(input.toString());
        try {
            AcroFields fields = reader.getAcroFields();
            if (fields.getAllFields().size() != 5 || fields.getSignedFieldNames().size() != 4) throw new IllegalStateException("Signature slot count changed");
            for (String name : fields.getSignedFieldNames()) if (!fields.verifySignature(name).verify()) throw new IllegalStateException("Previous signature changed");
            if (!"Later signer".equals(fields.getField("libresign_field_3"))) throw new IllegalStateException("Later field missing");
        } finally { reader.close(); }
        if (!"verified".equals(ManagedFormRevisions.inspect(input.toString()))) throw new IllegalStateException("Reserved slot ownership proof failed");
        System.out.println("PASS: multiple visible slots, later invisible slot, final assigned field and all four signature digests verified.");
    }
    private static String line(String id, String type, String page, String x1, String y1, String x2, String y2, String value, String owner) {
        return String.join("\t", id, type, page, x1, y1, x2, y2, "1", "", value == null ? "-" : Base64.getEncoder().encodeToString(value.getBytes(StandardCharsets.UTF_8)), owner);
    }
}
