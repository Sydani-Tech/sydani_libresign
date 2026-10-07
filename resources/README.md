# Signing-field runtime

`signing-fields.jar` is compiled from `tools/signing-fields/SigningFields.java` and
contains only the `coop.libresign.SigningFields` helper. It uses the OpenPDF
library already bundled with the configured JSignPdf installation. Keep the
source and JAR in sync when changing PDF form behavior.

The helper is packaged with the app. To rebuild it, set `JSIGNPDF_JAR` to the
installed `JSignPdf.jar` and run `tools/signing-fields/build.sh` on a machine
with Java 17+ and PHP's Zip extension. When `javac` is unavailable, set
`ECJ_JAR` to an Eclipse Compiler for Java JAR as well.

The server also needs a Unicode TrueType font. The default is
`/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf`; set the LibreSign app config
key `signing_fields_font` when that file is elsewhere. The helper rejects
encrypted PDFs, rotated pages, non-zero-origin crop boxes, and certification
signatures that prohibit changes. It refuses to move, remove, or redefine a
field after the first signature. Each signer fills only their assigned fields;
the PDF is updated incrementally so earlier signed revisions remain intact.

After installing the fork, test a two-person sequential request using a
non-sensitive document. Fill a text, date, and checkbox field, sign in order,
and verify both signatures and the final field values with an independent PDF
validator before using the feature for real documents.
