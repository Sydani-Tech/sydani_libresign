# Ordinary-request signing-date policy fix

Ordinary request serialization previously retained only label and required, discarding useSigningDate and signingDateTimezone. Campaign layouts used a separate path and were unaffected. A checked creator option could therefore appear correct in the editor while the saved ordinary request was manual.

The ordinary save allowlist now retains automatic-date policy only for Date fields, defaulting its timezone to Africa/Lagos (WAT, UTC+1). Unknown metadata is still discarded. Draft visible-element metadata is cloned so unsaved edits do not change the last server definition.

Automatic fields render as non-input text on the PDF and in Complete your fields. The summary states Will use the signing date (WAT · UTC+1). No calendar or date input exists for these fields. Server-side signing still ignores client-submitted values and derives the day from the actual signer timestamp in the stored timezone. Sequential signers retain their individual signing dates. Manual dates stay editable.

Already-signed requests that lost the setting remain unchanged. They cannot be retrospectively converted without changing signed evidence. Create a new request with the corrected saved policy. For an unsigned request, reopen its layout, enable Use signing date and save.

Signer tabs now say Internal Signers and External Signers. The validation page labels the certificate date Certificate expires; this is separate from document retention and historical integrity. No certificate validation rules or timestamp settings were weakened or changed.

An isolated real-signing test is available at tools/signing-date/test-ordinary-staging.php --allow-staging-mutations. It requires the web-service account, creates two synthetic users and one ordinary sequential request, suppresses test notifications, submits forged automatic dates, verifies server WAT dates and manual dates in audit records and PDF text, and disables the test accounts.
