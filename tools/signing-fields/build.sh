#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sydani Technology contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu
# Build-only dependencies: Java compiler (javac or ECJ_JAR), PHP zip extension.
: "${JSIGNPDF_JAR:?Set JSIGNPDF_JAR to the installed JSignPdf.jar}"
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
mkdir -p "$ROOT/build/signing-fields" "$ROOT/resources"
if [ -n "${ECJ_JAR:-}" ]; then
    "${JAVA:-java}" -jar "$ECJ_JAR" -17 -cp "$JSIGNPDF_JAR" -d "$ROOT/build/signing-fields" "$ROOT/tools/signing-fields/SigningFields.java"
else
    javac --release 17 -cp "$JSIGNPDF_JAR" -d "$ROOT/build/signing-fields" "$ROOT/tools/signing-fields/SigningFields.java"
fi
php "$ROOT/tools/signing-fields/package.php" "$ROOT"
