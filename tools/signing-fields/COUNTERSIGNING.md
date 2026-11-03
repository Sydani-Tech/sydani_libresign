# Countersigning a PDF with an existing digital signature

With PAdES-LT enabled, LibreSign can append a signature to a compatible PDF that already carries a digital signature from another service. LibreSign verifies that the earlier signature is intact and keeps the original PDF bytes. Its issuer trust is assessed independently against the configured trust anchors.

For this flow, use signature fields only. LibreSign can reuse an empty signature field if the PDF permissions and every signed revision allow that field to be filled. Place one marker inside the intended field when several are available. Certification that forbids further signing blocks countersigning. It also rejects unclassified or overlapping changes and incomplete verification. If the PDF is locked, keep it as received and ask the sender to add the remaining signer to the original signing workflow, or arrange a new signing workflow from an unsigned original with all parties.

The signing engine creates a private, timestamped intermediate revision. The verifier checks the expected certificate and timestamp chain before gathering revocation evidence for the new signature only. The final revision must retain all earlier signatures and pass strict offline PAdES-LT verification for the new signature. The intermediate is never published.

Run the policy checks with `php tools/signing-fields/test-countersigning-policy.php`. On a test host with Java, PHP, OpenSSL and Python, run `bash tools/signing-fields/test-countersigning.sh <jdk-directory> <JSignPdf-lib-directory> <TSA-URL> <TSA-trust-PEM>` for a synthetic two-signature PDF. The test serves its synthetic issuer CRL on loopback and calls the supplied timestamp authority; it never reads actual signing requests or sends invitations.
