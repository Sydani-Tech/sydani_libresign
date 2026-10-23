# Signing campaigns (Sydani fork)

Implementation is under staging validation. Do not deploy to production until
the real signing, notification and permissions acceptance checks have passed.

## Workflow

### Campaign setup and recipient import

Setup is organised into Document & details, Recipients, optional Countersignature
and Reusable field layout. Attaching a PDF enables placement immediately; opening
the editor saves the draft first, without sending invitations. A countersigner
conflict produces a Nextcloud error popup and a bold, persistent section warning.

Recipient entry offers Add Internal Users, Add from a Group, Add an External User,
and Add from an Excel File. Imports accept `.xlsx` and `.csv` up to 5 MB and 2000
recipients, with automatic name/email column detection, manual column selection,
optional headings, worksheet selection and an editable preview. Names/emails can
be corrected and rows removed in the UI without re-uploading. Every edit or row
removal invalidates previous account validation. Invalid emails, missing or
oversized names, duplicate/existing recipients, countersigner conflicts, disabled
accounts and ambiguous account matches are flagged. Valid rows are added only
after an explicit validation and Add confirmation. Import never sends invitations.
Format validation cannot establish that a mailbox exists or is deliverable.

Parsing is value-only, with compressed expansion, row, column and cell limits.
Workbook formulas are not executed, external relationships are ignored, and XML
entities/DTDs are rejected. `.xls` and password-protected workbooks are unsupported;
save these as a normal `.xlsx` or UTF-8 CSV first.

### Personal Dashboard

The default signed-in landing page is Dashboard (`/f/dashboard`), with four
paginated views: Awaiting my signature, Waiting for my turn, Signed by me, and
Sent · awaiting completion. Individual and campaign requests share the inbox.
Ready documents link directly by their exact document UUID to review/signing;
future sequential participants are not offered a premature signing action.
Cancelled/exempt campaign copies are excluded from outstanding tasks; actual
signed history remains visible. Only the current account's assignments and sent
requests are included, even for administrators. Campaign management and global
archive shortcuts retain their existing permissions. External email-only signers
continue to use their individual invitation links rather than an account dashboard.

### Creating and managing a campaign

1. An administrator selects Campaign creator groups in LibreSign settings.
   Administrators are always allowed. This is separate from ordinary request
   permissions. A creator manages their own campaigns; administrators manage all.
2. Create a draft: name, document version, PDF (maximum 20 MB), optional deadline
   and message. Add named individuals, or import a reviewed snapshot of a group.
   Recipients added to the group later are not silently added to a campaign.
3. Recipient Name and Recipient Email are separate fields. The server resolves
   matching Nextcloud accounts as internal; unmatched addresses are external.
   Domain alone is not an identity check. Disabled and ambiguous accounts are
   rejected rather than converted into external invitations.
4. Place the Signature, Text, Date and Checkbox fields once. Optionally add one
   countersigner and place their fields separately. Each recipient gets a new,
   independent PDF/request; the countersigner follows that recipient on that
   copy. Recipients never wait for other employees' independent copies.
5. Review and explicitly send. The PDF snapshot, layout, cohort and identities
   are frozen. Replacing a draft PDF clears its layout. Reuse creates a new draft
   without copying the old recipient cohort or sending new invitations.
6. Track actual signature completion, outstanding/overdue requests, failures and
   countersignatures. Filter name, email and status; export the completion register.
   Date Requested and Date Completed come from signing records, not email delivery.

## Notifications and retention

The existing LibreSign identification, verification, email and signing flows are
reused; enabling Campaigns does not enable weaker authentication methods. External
email identification must already be configured by an administrator. A dispatch
job processes the queue when Nextcloud background jobs run. The open campaign
page also advances a small, campaign-scoped batch every ten seconds. Invitations
are not evidence of inbox delivery or reading.

Failed dispatch retries reuse an already-created request. Atomic leases and a
unique recipient-to-file binding prevent two workers from creating two committed
requests for one recipient. A failed notification remains outstanding, not signed.
Manual reminder/retry controls skip completed, exempted and cancelled requests.

Published campaign requests cannot be edited/deleted through normal request APIs.
Cancellation blocks new signing/notifications but preserves existing signatures
and campaign history. An exemption requires a reason and cannot remove a signed
or partially signed request. Exemptions are explicitly excluded from the required
recipient count; cancellations are not silently reported as completions.

Snapshots and per-recipient copies are in application-owned storage, not a shared
personal folder. Once signed, the existing organisational archive protects the
signed document and signing history. Personal source files in Nextcloud Files
remain independently deletable. There is no new tagging step or ERP integration.

## API boundary

Routes are under `/ocs/v2.php/apps/libresign/api/v1/`, using Nextcloud authentication
and OCS responses. Every campaign read, write, PDF and CSV endpoint checks the
current creator permission and campaign ownership (or administrator access).
Anonymous users, ordinary recipients and unrelated campaign creators cannot use
management endpoints. Signing recipients use the existing LibreSign signer UI.

- `GET campaign/access`, `GET/POST campaigns`, `GET/PUT campaigns/{id}`
- `POST campaigns/{id}/publish|duplicate|process|remind|retry|cancel`
- `POST campaigns/{id}/recipients/{recipientId}/exempt`
- `GET campaigns/{id}/source|export`
- `GET campaigns/{id}/recipients/{recipientId}/file`
- `GET campaign/users|groups`, `GET campaign/groups/{groupId}/users`
- `POST campaign/resolve`
- `POST campaign/import` (bounded `.xlsx`/`.csv` parse preview only)
- `POST campaign/resolve-import` (per-row account/email validation only)
- Signed-in personal inbox: `GET dashboard?view=ready|waiting|signed|sent&page=1`
- Administrator only: `GET/POST campaign-settings`; permission changes also
  require Nextcloud password confirmation and CSRF protection.

Campaign completion responses do not grant access to the global organisational
archive. The existing archive now accepts independent `recipientName` and
`recipientEmail` filters which must match the same participant. The old combined
`recipient` API filter remains compatible with already-open clients.

## Staging installation

Migration `18005Date20261022000000` adds `libresign_campaign`,
`libresign_camp_rcpt` and `libresign_camp_event`. No existing document tables or
personal file contents are rewritten by this migration. The guarded staging CLI
installer makes a private database backup before applying just this migration;
it does not run unrelated upgrade repairs or change sudo permissions.

```sh
sudo -u www-data /usr/bin/php /var/www/html/apps/libresign/tools/campaign/setup-staging.php --install-campaign-schema
```

Run pure backend checks with `tools/campaign/test-definition.php` and
`tools/campaign/test-identities.php` (separate PHP CLI processes). These tests do
not bootstrap Nextcloud, alter documents, change settings or send mail. End-to-end
acceptance must additionally check mixed internal/external invitations, independent
PDFs, countersigning, reminders/retries, cancellation and manager access through
real Nextcloud requests before declaring this feature production-ready.

The opt-in real-engine staging test creates three isolated synthetic accounts
(one temporarily in the administrator group), independent request PDFs and QA
campaigns. It suppresses email for those accounts and fences the QA queue from
cron, signs only the fixture PDFs, and cancels the QA campaigns/removes temporary
administrator membership/disables the QA accounts on exit. Signed QA evidence is
retained rather than deleting protected signing records. No existing users'
documents are changed. External email delivery still needs a separate human test.

```sh
sudo -u www-data /usr/bin/php /var/www/html/apps/libresign/tools/campaign/test-staging.php --allow-staging-mutations
```

### Staging results — 23 October 2026

- 71 focused frontend checks and 45 pure backend checks passed; all six frontend
  entry builds completed successfully.
- 47 real-engine checks passed: independent per-recipient PDFs, recipient then
  countersigner, required fields, 50/100 percent completion, dates and archived
  hashes, separate recipient filters, access denial, retry binding, reuse,
  exemption and cancellation. No real emails were sent or existing documents
  changed. The three synthetic accounts were disabled and temporary administrator
  membership removed; signed QA evidence remains protected.
- Browser checks confirmed draft creation without invitations, four field types,
  label/Required changes persisting after reopening, and a phone-sized editor.
- Remaining acceptance: real internal/external email delivery and external signer
  verification, plus a human review of the mobile workflow and permission groups.
  The NSS database warning printed during PDF inspection was non-fatal; the
  successful engine checks are not a claim of universally trusted certificates.

Staging used a targeted migration without bumping the app version. A normal
upgrade currently encounters an older staging archive-integrity failure from a
pre-fix synthetic test. Do not disable archive checks or copy that workaround to
production. Production packaging, app-version migration and upgrade rehearsals
remain a separate release step.
