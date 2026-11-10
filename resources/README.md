# Signing-field runtime

`signing-fields.jar` is compiled from `tools/signing-fields/SigningFields.java` and
contains the field preparation, appearance, and managed-revision helpers. It uses
the tested OpenPDF 3 library directory from the complete JSignPdf 3.2.1 distribution. Keep the
source and JAR in sync when changing PDF form behavior.

The helper is packaged with the app. To rebuild it, set `JSIGNPDF_JAR` to the
installed JSignPdf bootstrap JAR and run `tools/signing-fields/build.sh` on a machine
with Java 21 and PHP's Zip extension. When `javac` is unavailable, set
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

The app also ships `pades-report.jar` and `letterhead-pdf.jar`; keep all three
JARs with their matching Java sources. Configure `signing_fields_lib_dir` (or
`pades_lt_lib_dir`) to the tested OpenPDF 3 library directory. A legacy JSignPdf
2.x installation alone cannot run these helpers. Production release packages
must also contain built frontend assets, the PDF worker, and production Composer
and scoped dependencies; a source checkout is not a deployment package.
