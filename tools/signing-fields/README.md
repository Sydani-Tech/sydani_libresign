# PDF field helper regression

`test-sequential.sh` creates an isolated test certificate and signs one synthetic
PDF five times. Each participant fills text, date and checkbox fields at different
page positions. The test checks stored values, nonempty text appearances, unchanged
field rectangles, read-only flags, preservation of prior revisions, and the
cryptographic validity of every signature after each participant signs.

Run against the same JSignPdf jar and Java runtime used by LibreSign:

```sh
JSIGNPDF_JAR=/path/to/JSignPdf.jar \
JAVA=/path/to/java \
ECJ_JAR=/path/to/ecj.jar \
sh tools/signing-fields/test-sequential.sh
```

Omit `ECJ_JAR` when `javac` is installed. `FONT` defaults to DejaVu Sans on Linux.
The script prints the temporary test directory and retains the PDFs for visual
inspection. No Nextcloud users, real document requests or installation certificates
are used. Rebuild the production helper using `build.sh` after changing its source.
