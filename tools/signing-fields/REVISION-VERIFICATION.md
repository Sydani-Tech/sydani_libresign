# LT revision verification

A valid cryptographic digest alone does not prove that later PDF changes were permitted. Content can be appended without changing the signed bytes. The legacy DocMDP checker and DSS revision analysis can also disagree about otherwise legitimate form or evidence additions.

For new native requests with long-term signing enabled, LibreSign reserves every participant's signature, text, date and checkbox fields before the first signature. Each field carries its saved request owner. The complete embedded Unicode font and glyph mappings are prepared in that first revision. Later fills construct appearances with that same embedded font instead of introducing replacement font objects. Each participant fills only their assigned fields, then signs an existing reserved signature slot.

ManagedFormRevisions compares every signed revision. Definitions, owners, font resources and page content must stay fixed. Earlier values, appearances and read-only flags must stay fixed. Only the current owner's previously blank fields and selected signature slot may be completed. After the last signature, the catalog (apart from DSS evidence), document information, fields, resources and pages must be unchanged. Missing, inconsistent or altered ownership fails closed. Existing PDFs without the reserved layout retain the legacy route.

This semantic proof supplements DSS. It does not replace cryptographic validation, trusted timestamp checks, byte-range checks or object revision analysis. PdfRevisionPolicy explains assigned form completion only when every signature has verified LT/LTA evidence, a valid native digest, consistent byte ranges and dictionaries, and zero unknown, annotation, page or overlap changes. Without the ownership proof, form and visual changes retain the warning. The original checker result remains available as legacy_modification_validation. No stored PDF or signing record is rewritten.

A new timestamp-only native signature may be extended privately with revocation evidence. It cannot be returned as a completed document until every signature passes the final LT checks. Existing external countersigning permissions and field locks remain enforced.

## Reader interoperability

Direct testing in Edge's Adobe-powered PDF reader confirms that the prepared text and dates render. Its modification checker can still reject a DSS evidence append even when the exact signed prefix is accepted and independent semantic/cryptographic checks confirm that only validation evidence changed. Changing the form dictionary storage, keeping the original DSS reference, and declaring the ESIC extension did not remove this warning; those experiments are not part of the deployed fix. Identity trust in a public reader is separate from the internal issuer trust used by LibreSign.

DSS documents the potential difference between its revision checks and other PDF readers in its [interoperability FAQ](https://ec.europa.eu/digital-building-blocks/DSS/webapp-demo/doc/dss-documentation.html). A reader warning must not be hidden by disabling integrity checks. Cross-reader modification status remains a production-review item.

## Tests and deployment

Build with the pinned JSignPdf/OpenPDF/DSS libraries using build.sh and build-pades-report.sh. Deploy both helper jars with their PHP consumers; the helper hash invalidates old verification cache entries.

The synthetic regressions check five sequential signers, distinct signing certificates, Unicode names, dates, fixed field rectangles, stable fonts, prior appearances, and all cryptographic signatures. SignatureSlotRegression checks two visible slots for one participant and a later invisible slot with the DSS engine. ManagedFormRevisionRegression rejects incremental value, appearance, font, rectangle, owner, unlock, page, added-field and NeedAppearances tampering while the original signature digests remain valid. Run test-revision-policy.php for fail-closed reconciliation cases.

Never use archived user PDFs as writable test fixtures. Already signed documents require a new request to benefit from the prepared layout.
