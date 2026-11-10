/* SPDX-License-Identifier: AGPL-3.0-or-later */
package coop.libresign;

import org.openpdf.text.pdf.*;
import java.nio.file.*;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.util.*;

/** Semantic proof for this application's preassigned form layout, additional to DSS cryptographic/revision checks. */
public final class ManagedFormRevisions {
    private static final PdfName OWNER = new PdfName("LibreSignOwner");
    private record Signature(String name, long end) {}
    private record Field(String owner, boolean signature, String definition, String value, String appearance,
                         int flags, int annotationFlags, boolean blank) {
        boolean locked() { return (flags & PdfFormField.FF_READ_ONLY) != 0; }
    }
    private record State(Map<String,Field> fields, String resources, String pages, String catalog, String info) {}

    public static String inspect(String filename) throws Exception {
        byte[] bytes = Files.readAllBytes(Path.of(filename));
        List<Signature> signatures = new ArrayList<>();
        PdfReader finalReader = new PdfReader(bytes);
        try {
            AcroFields fields = finalReader.getAcroFields();
            for (String name : fields.getSignedFieldNames()) {
                PdfArray range = fields.getSignatureDictionary(name).getAsArray(PdfName.BYTERANGE);
                if (range == null || range.size() != 4) return "invalid";
                signatures.add(new Signature(name, Long.parseLong(range.getAsNumber(2).toString()) + Long.parseLong(range.getAsNumber(3).toString())));
            }
        } finally { finalReader.close(); }
        if (signatures.isEmpty()) return "not_applicable";
        signatures.sort(Comparator.comparingLong(Signature::end));
        State previous = null;
        boolean managed = false;
        for (Signature signature : signatures) {
            if (signature.end <= 0 || signature.end > bytes.length || signature.end > Integer.MAX_VALUE) return "invalid";
            State next = snapshot(Arrays.copyOf(bytes, (int)signature.end));
            Field slot = next.fields.get(signature.name);
            if (previous == null) {
                managed = signature.name.startsWith("libresign_signature_") && slot != null && !slot.owner.isEmpty();
                if (!managed) return "not_applicable";
                if (!firstSignature(next, signature.name, slot.owner)) return "invalid";
            } else if (slot == null || !transition(previous, next, signature.name, slot.owner)) return "invalid";
            previous = next;
        }
        State last = snapshot(bytes);
        // Evidence appended after the last signature must not change a field, appearance, font, or page.
        return previous != null && previous.equals(last) ? "verified" : "invalid";
    }

    private static boolean firstSignature(State state, String signedName, String owner) {
        Set<String> owners = new HashSet<>();
        for (Map.Entry<String,Field> entry : state.fields.entrySet()) {
            if (entry.getKey().startsWith("libresign_signature_")) {
                if (!entry.getValue().signature || entry.getValue().owner.isEmpty()) return false;
                owners.add(entry.getValue().owner);
            }
        }
        for (Map.Entry<String,Field> entry : state.fields.entrySet()) {
            String name = entry.getKey(); Field field = entry.getValue();
            if (!name.startsWith("libresign_field_") && !name.startsWith("libresign_signature_")) continue;
            if (field.owner.isEmpty() || !owners.contains(field.owner)) return false;
            if (field.signature) {
                if (name.equals(signedName) == field.blank) return false;
            } else if (field.owner.equals(owner)) {
                if (!field.locked()) return false;
            } else if (!field.blank || field.locked()) return false;
        }
        return true;
    }

    private static boolean transition(State before, State after, String signedName, String owner) {
        if (owner.isEmpty() || !before.fields.keySet().equals(after.fields.keySet())
                || !before.resources.equals(after.resources) || !before.pages.equals(after.pages)) return false;
        for (String name : before.fields.keySet()) {
            Field old = before.fields.get(name), now = after.fields.get(name);
            if (!old.definition.equals(now.definition) || !old.owner.equals(now.owner) || old.signature != now.signature) return false;
            if (old.equals(now)) continue;
            if (name.equals(signedName)) {
                if (!old.signature || !old.blank || now.blank || !old.owner.equals(owner)
                        || (old.annotationFlags != now.annotationFlags && (old.annotationFlags | PdfAnnotation.FLAGS_LOCKED) != now.annotationFlags)) return false;
                if ((old.flags & ~PdfFormField.FF_READ_ONLY) != (now.flags & ~PdfFormField.FF_READ_ONLY)) return false;
            } else {
                if (old.signature || !name.startsWith("libresign_field_") || !old.owner.equals(owner)
                        || old.locked() || !old.blank || !now.locked() || old.annotationFlags != now.annotationFlags
                        || (old.flags | PdfFormField.FF_READ_ONLY) != now.flags) return false;
            }
        }
        return true;
    }

    private static State snapshot(byte[] bytes) throws Exception {
        PdfReader reader = new PdfReader(bytes);
        try {
            Canonical canonical = new Canonical();
            Map<String,Field> result = new TreeMap<>();
            AcroFields fields = reader.getAcroFields();
            for (String name : fields.getAllFields().keySet()) {
                AcroFields.Item item = fields.getFieldItem(name);
                PdfDictionary merged = item.getMerged(0);
                PdfString owner = merged.getAsString(OWNER);
                StringBuilder definitions = new StringBuilder(), values = new StringBuilder(), appearances = new StringBuilder();
                for (int i = 0; i < item.size(); i++) {
                    PdfDictionary widget = item.getMerged(i);
                    boolean assigned = name.startsWith("libresign_field_") || name.startsWith("libresign_signature_");
                    if (assigned && item.size() != 1) throw new IllegalArgumentException("Managed field has multiple widgets");
                    Set<PdfName> mutable = assigned ? Set.of(PdfName.V, PdfName.AP, PdfName.AS, PdfName.FF, PdfName.F, PdfName.P, PdfName.PARENT, PdfName.KIDS)
                        : Set.of(PdfName.V, PdfName.AP, PdfName.P, PdfName.PARENT, PdfName.KIDS);
                    // getMerged includes inherited AcroForm /Fields, which changes when peers are filled.
                    // Compare actual field/widget definitions; the shared form resources are checked separately.
                    definitions.append(item.getPage(i)).append(canonical.except(item.getWidget(i), mutable))
                        .append(canonical.except(item.getValue(i), mutable));
                    values.append(canonical.hash(widget.get(PdfName.V)));
                    appearances.append(canonical.hash(widget.get(PdfName.AP))).append(canonical.hash(widget.get(PdfName.AS)));
                    if (fields.getFieldType(name) == AcroFields.FIELD_TYPE_CHECKBOX
                            && !Objects.equals(widget.get(PdfName.V), widget.get(PdfName.AS)))
                        throw new IllegalArgumentException("Checkbox value and appearance state disagree");
                }
                PdfNumber ff = merged.getAsNumber(PdfName.FF), annotation = merged.getAsNumber(PdfName.F);
                PdfObject value = PdfReader.getPdfObject(merged.get(PdfName.V));
                boolean blank = value == null || value.isNull() || new PdfName("Off").equals(value)
                    || (value instanceof PdfString && ((PdfString)value).toUnicodeString().isEmpty());
                result.put(name, new Field(owner == null ? "" : owner.toUnicodeString(),
                    fields.getFieldType(name) == AcroFields.FIELD_TYPE_SIGNATURE, definitions.toString(), values.toString(),
                    appearances.toString(), ff == null ? 0 : ff.intValue(), annotation == null ? 0 : annotation.intValue(), blank));
            }
            PdfDictionary form = reader.getCatalog().getAsDict(PdfName.ACROFORM);
            String resources = form == null ? "" : canonical.except(form, Set.of(PdfName.FIELDS));
            StringBuilder pages = new StringBuilder();
            for (int page = 1; page <= reader.getNumberOfPages(); page++)
                pages.append(canonical.except(reader.getPageN(page), Set.of(PdfName.ANNOTS, PdfName.PARENT)));
            return new State(result, resources, pages.toString(), canonical.except(reader.getCatalog(), Set.of(new PdfName("DSS"))), canonical.hash(reader.getTrailer().get(PdfName.INFO)));
        } finally { reader.close(); }
    }

    private static final class Canonical {
        private final Map<String,String> cache = new HashMap<>();
        private final Set<String> active = new HashSet<>();
        String except(PdfDictionary dictionary, Set<PdfName> ignored) throws Exception {
            List<PdfName> keys = new ArrayList<>(dictionary.getKeys()); keys.sort(Comparator.comparing(PdfName::toString));
            StringBuilder value = new StringBuilder();
            for (PdfName key : keys) if (!ignored.contains(key)) value.append(key).append(hash(dictionary.get(key)));
            return digest(value.toString().getBytes(StandardCharsets.UTF_8));
        }
        String hash(PdfObject raw) throws Exception {
            if (raw == null) return "null";
            String ref = raw instanceof PRIndirectReference ? raw.toString() : null;
            if (ref != null) {
                if (cache.containsKey(ref)) return cache.get(ref);
                if (!active.add(ref)) return "cycle:" + ref;
            }
            PdfObject object = PdfReader.getPdfObject(raw);
            String result;
            if (object == null || object.isNull()) result = "null";
            else if (object instanceof PRStream) {
                result = except((PdfDictionary)object, Set.of(PdfName.LENGTH, PdfName.FILTER, PdfName.DECODEPARMS))
                    + digest(PdfReader.getStreamBytes((PRStream)object));
            } else if (object instanceof PdfDictionary) result = except((PdfDictionary)object, Set.of());
            else if (object instanceof PdfArray) {
                StringBuilder array = new StringBuilder(); for (int index = 0; index < ((PdfArray)object).size(); index++) array.append(hash(((PdfArray)object).getPdfObject(index)));
                result = digest(array.toString().getBytes(StandardCharsets.UTF_8));
            } else if (object instanceof PdfString) result = digest(((PdfString)object).getOriginalBytes());
            else result = digest(object.toString().getBytes(StandardCharsets.UTF_8));
            if (ref != null) { active.remove(ref); cache.put(ref, result); }
            return result;
        }
        private static String digest(byte[] value) throws Exception { return HexFormat.of().formatHex(MessageDigest.getInstance("SHA-256").digest(value)); }
    }
}
