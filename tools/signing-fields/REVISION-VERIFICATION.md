# LT revision verification

The legacy DocMDP checker can classify an incremental DSS dictionary update as an unauthorised change. A successful signature digest or PAdES validation alone is insufficient to dismiss that warning: content appended after a signature can preserve the signature's digest.

PadesReport therefore emits independent PDF revision diagnostics from DSS. PdfRevisionPolicy reconciles the legacy warning only when all signed participants are covered by successful LT/LTA proofs, trusted timestamps, consistent byte ranges and dictionaries, and completed revision analysis. At least one extension update must exist; signature/form changes, annotation changes, undefined object changes, visual differences, page differences and annotation overlaps must all be zero. Missing evidence fails closed. The original result is retained as legacy_modification_validation. Neither the PDF nor stored signing records are rewritten.

An unresolved modification-policy warning is shown as requiring review rather than claiming proven content tampering. Cryptographic failures remain errors. The post-signing notice confirms verification only when the checks actually pass.

Build the helper with JDK 21 and the configured JSignPdf/DSS libraries using build-pades-report.sh. Deploy resources/pades-report.jar together with PadesVerificationService.php and PdfRevisionPolicy.php; the helper hash invalidates old verification cache entries. No trust or timestamp settings change.

Run php tools/signing-fields/test-revision-policy.php for fail-closed policy cases. On staging, also validate three isolated copies: the original LT PDF, a PDF with page content appended incrementally, and a PDF with a changed byte inside its signed range. The original is accepted, the appended content retains a valid digest but is rejected by revision diagnostics, and changed signed bytes fail the hash check. Never edit stored archive files to produce these fixtures.
