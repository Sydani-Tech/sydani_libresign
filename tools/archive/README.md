# Organisational signed-document archive

This fork stores new signed PDF masters in LibreSign appdata, outside personal
Files/WebDAV mounts. `libresign_file.user_id` remains the sender. The source
`node_id` is independent of `signed_node_id` and can be deleted without deleting
the signing record. Later signers use the signed master, never a replacement source.

The first successful signature protects the master, its participant records and
field definitions. Unsigned drafts retain their normal deletion permissions.
Protection is enforced server-side, including envelope deletion and file-delete
events; UI controls are only a convenience. Remaining signers can still append
signatures through the signing engine.

`/f/archive` is an admin-only, read-only list with sender/recipient/name/status
filters, Date Requested / Date Completed range filters and sorting. All list,
history and PDF endpoints independently check actual membership in `admin`.
No tagging, account-offboarding integration or Nextcloud core patches are included.

## Dates

New request dates are captured on transition from draft to ready-to-sign (or
creation directly in ready-to-sign status). Completion uses the last signer
timestamp when the document becomes fully signed. Older request dates cannot be
reliably reconstructed from upload or signer-record creation dates and remain
NULL. The upgrade recovers historical completion dates from stored signatures.

## Upgrade / rollback

1. Back up the database, current app code and Nextcloud data consistently before
   upgrading. The signed PDF masters will leave personal folders.
2. Deploy the app code and built JS/CSS, then run Nextcloud's standard app upgrade.
3. The restartable `ArchiveSignedDocuments` repair step verifies each existing
   signed PDF against its recorded SHA-256, copies it byte-for-byte into appdata,
   updates the pointer, and removes only the old signed duplicate. It does not
   remove original source files or signing records. Hash mismatches stop the
   upgrade and require investigation rather than silently accepting changed PDFs.
4. Verify normal sender/recipient access, admin-only archive APIs, source deletion
   after the first signature, and final cryptographic signatures.

Rollback after migrating storage requires restoring **data and database together**
with the previous app code. Merely reverting PHP files will not make old code
understand app-owned storage. Preserve deployment backups until acceptance.

The archive prevents ordinary application users from deleting signed masters.
It is not storage-level WORM: privileged filesystem/database operators and backup
retention still need organisational controls. Archive appdata must be included in
all backups, including on installations using S3 primary storage.

## Staging integration test

Run as the actual web-service user, not merely an SSH user in its group:

```sh
sudo -u www-data /usr/bin/php /var/www/html/apps/libresign/tools/archive/test-staging.php --allow-staging-mutations
```

The explicit opt-in test is restricted to the agreed staging hostname. It creates
isolated QA accounts and a document, signs sequentially around source deletion,
checks archive permissions and deletion guards, and disables its QA users on exit.
An existing admin is used only for a non-persistent, read-only permission check;
the test does not create privileged accounts or change existing accounts.
The signed QA evidence is deliberately retained. It does not modify real users'
documents or grant access to other users. An ordinary SSH account may fail while
creating core avatar folders; do not weaken those folder permissions to run tests.
