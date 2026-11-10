<!--
SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Sydani production rollout

The fork targets Nextcloud 35 and uses the existing Nextcloud database, object storage, account identities, and signing issuer. Deploy a verified distributable package, not a raw source checkout. Keep the Git commit, app version and package SHA-256 together in the deployment record.

## Order and recovery

1. Confirm a consistent recoverable backup of the database, Nextcloud code/configuration, local PKI/signing keys and primary object storage. A virtual-machine snapshot alone does not back up S3 objects.
2. Inventory the installed core/apps, PHP/database versions, enabled OIDC provider, cron, queues, and signed PDF hashes. Preserve the production issuer fingerprint, object-storage configuration and OIDC subject/UID mapping.
3. Upgrade the database prerequisite using the reviewed platform helper. Patch Nextcloud 32 before upgrading through 33, 34 and 35 one major at a time. Use checksum-verified official releases; update the installed login backend and other enabled apps to compatible releases. Never raise max-version to force an incompatible app.
4. Disable the old incompatible LibreSign during the core upgrades. Pause this installation's cron/workers and prevent web writes during code/database transitions. Inspect and complete required migration jobs using Nextcloud's supported job command; a general cron run can send real queued email.
5. Install the exact tested fork package with production dependencies, all three PDF helper JARs, all six built frontend entries, frontend manifest, PDF worker, and upstream dependency integrity manifests. Run Nextcloud's normal app/core upgrade as the web-service user. Existing signing keys, config, S3 settings and user data are retained.
6. Run the read-only schema gate below, compare every existing signed PDF hash and the issuer fingerprint, then apply the reviewed production settings. Reopen Apache before strict LT signing checks so the signing engine can retrieve this server's CRL.
7. Verify production S3 archive write/readback, signature integrity, external/internal signing, downloads, approver queues, M365 login, footer redirects and controlled notification delivery. Inspect real queues before restoring the original cron schedule. Do not run staging mutation helpers on production.

After a database migration, rolling back code alone is unsafe. Recover the matching database, code, configuration/PKI and affected storage objects from the same recovery point.

## Database changes

Nextcloud records exact migration IDs in its migrations table and executes every available unrecorded migration. Do not copy staging tables or migration records, delete history, or rerun all migrations manually.

| Migration | Custom schema/data |
| --- | --- |
| 18004Date20261015000000 | File requested_at/completed_at and archive indexes |
| 18005Date20261022000000 | Campaign, recipient and campaign event tables |
| 18005Date20261028000000 | Letterhead template/document/event and reminder slot tables |
| 18006Date20261103000000 | File externally_signed flag |
| 18007Date20261110000000 | Permanent short validation aliases with unique UUID mapping |
| 19005Date20261110000000 | Independent archive index repair and conservative missing legacy footer recovery |

The upstream policy migrations also run when their full exact IDs are absent. A changed migration does not rerun merely because its source changed; fixes for already recorded steps need a new forward migration. The archive repair copies signed masters into protected appdata, verifies bytes, updates node references, and may remove the former signed duplicate after verification. Preserve the original source where separate, and include S3 in the recovery backup.

After the app upgrade, run as the web-service account (set NEXTCLOUD_ROOT to this installation):

~~~sh
php "$NEXTCLOUD_ROOT/apps/libresign/tools/production/check-schema.php" --nextcloud-root="$NEXTCLOUD_ROOT" --require-migrations
~~~

The gate constructs the migration target in memory and compares all eight custom tables, three file columns, column types/constraints and required indexes with the live schema. It performs no schema/data writes. Partial or incompatible existing tables stop acceptance for review.

## Configuration is separate from schema

Do not clone staging appconfig or users. Configure production's one-line Validate at footer and own validation host, grouped /v/ redirects using tools/short-links/setup-apache.sh, signing-order delegation, permitted campaign creators and Letterhead Document Approver memberships. Preserve existing SMTP/SSO and reminder choices; installing this fork does not authorize broad reminder emails.

Strict LT requires Java 21, complete JSignPdf 3.2.1 bootstrap/libs with tested OpenPDF 3, correct production runtime paths, explicit production CA/TSA public trust anchors and reachable timestamp/revocation services. Preserve the existing issuer/private keys and compare its fingerprint; never replace it with staging's CA. Browser reader trust remains separate from LibreSign validation. The accepted Edge-only display/validation limitations remain in the acceptance record.
