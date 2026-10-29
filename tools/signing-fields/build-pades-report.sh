#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Usage: bash build-pades-report.sh /path/to/jdk21 /path/to/jsignpdf/lib
set -euo pipefail
jdk=$(realpath "$1")
engine=$(realpath "$2")
root=$(cd "$(dirname "$0")/../.." && pwd)
build=$(mktemp -d)
trap 'rm -rf -- "$build"' EXIT
"$jdk/bin/javac" -cp "$engine/*" -d "$build" "$root/tools/signing-fields/PadesReport.java"
"$jdk/bin/jar" --create --file "$root/resources/pades-report.jar" -C "$build" .
