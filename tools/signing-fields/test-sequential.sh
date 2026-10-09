#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sydani Technology contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu
: "${JSIGNPDF_JAR:?Set JSIGNPDF_JAR to the installed JSignPdf.jar}"
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
WORK=${TEST_WORK_DIR:-$(mktemp -d)}
JAVA=${JAVA:-java}
FONT=${FONT:-/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf}
mkdir -p "$WORK/classes"
if [ -n "${ECJ_JAR:-}" ]; then
    "$JAVA" -jar "$ECJ_JAR" -17 -cp "$JSIGNPDF_JAR" -d "$WORK/classes" \
        "$ROOT/tools/signing-fields/SigningFields.java" "$ROOT/tools/signing-fields/SequentialFieldRegression.java"
else
    javac --release 17 -cp "$JSIGNPDF_JAR" -d "$WORK/classes" \
        "$ROOT/tools/signing-fields/SigningFields.java" "$ROOT/tools/signing-fields/SequentialFieldRegression.java"
fi
# Isolated test certificate, never an installation's signing certificate.
openssl req -x509 -newkey rsa:2048 -keyout "$WORK/key.pem" -out "$WORK/cert.pem" \
    -days 1 -nodes -subj '/CN=LibreSign sequential regression' >/dev/null 2>&1
openssl pkcs12 -export -inkey "$WORK/key.pem" -in "$WORK/cert.pem" -out "$WORK/test.p12" \
    -passout pass:regression-only
chmod 600 "$WORK/key.pem" "$WORK/test.p12"
"$JAVA" -cp "$WORK/classes:$JSIGNPDF_JAR" coop.libresign.SequentialFieldRegression \
    "$WORK" "$FONT" "$JSIGNPDF_JAR" "$WORK/test.p12" regression-only
printf 'Test PDFs retained for visual inspection in %s\n' "$WORK"
