# Staging workflow expansion

This fork adds campaign reporting, a four-card personal dashboard, reusable PDF letterheads with external-release approval, administrator reminder schedules, and strict PAdES-LT signing. No Nextcloud core patch is required.

## Campaigns and dashboard
Campaign filters run on the server before pagination: name, version, existing sender, state (draft/active/completed/cancelled/overdue), recipient name/email/type, date created/requested/completed/deadline and ordering. Completion is derived from actual protected signed copies; name/email/type predicates match one recipient row. Administrators see all campaigns, authorised campaign creators see their own. Dashboard remains personal, includes at most ten records per page, refreshes every ten minutes while visible, and offers Inbound ready/signed and Outbound incomplete/completed views. Sequential waiting participants remain signable only when their turn activates.

## Letterheads
Administrators add or deactivate shared one-page PDF letterhead templates in the Letterheads tab. Staff upload an unsigned, unencrypted finished PDF (up to 20 MB/100 pages), select a letterhead/layer, choose signers and review page images. The template is scaled proportionally to each page; content is not reflowed. Use suitable margins/transparent artwork and review every page. Different content or recipient lists require a new document.

A recipient without an existing account is external. Any external recipient requires another active user in the Nextcloud group **Letterhead Document Approver**. The selected approver reviews the frozen composed PDF hash and recipients. The creator cannot self-approve or bypass the gate using admin membership. Review/decision emails respect LibreSign email preferences; failed email is reported without losing the decision. Approval email links require login.

Before approval, only authenticated, authorised page-image previews are exposed; no PDF download or signing request exists. This cannot prevent screenshots or printing a visible image. After approval, the creator opens the existing request/field editor and explicitly sends invitations. Signer identities are frozen. Exact managed PDF exports are recognised to prevent ordinary-upload bypass; this is not universal logo/brand recognition or control over independently recreated PDFs. Source changes require a fresh approval. Internal-only documents do not require external-release approval. Signed Archive has a Letterheaded documents filter; protected signing history/deletion guards remain in force.

API namespace: /ocs/v2.php/apps/libresign/api/v1
- GET/POST letterheads; POST letterheads/{id}/state (admin template management)
- GET letterhead/users (search, approvers=true); POST letterhead/resolve
- GET/POST letterhead/documents; GET letterhead/documents/{id}
- POST .../{id}/submit (revision), .../{id}/decision (revision, approve, reason), .../{id}/request
- GET .../{id}/preview/{page} (image), .../{id}/download (approved PDF)
All endpoints enforce Nextcloud authentication, role/ownership and CSRF where applicable. A separate ERP frontend can use the same workflow APIs; no ERP integration is included.

## Administrator reminders
LibreSign admin settings > Reminders controls the global schedule for normal requests and campaign copies. Once daily, twice daily (two chosen times) or every two days; first delay in calendar days, per-signer maximum, duration after invitation and optional inclusive final date. All scheduling uses Africa/Lagos (WAT). Only currently active sequential participants are reminded. Completed/cancelled/exempt requests and expired signing links are skipped. A durable unique request/slot claim prevents concurrent/repeated cron dispatch. Missed runs never replay a burst of past slots. Delivery failures are logged and not automatically retried in the same slot, because delivery can be ambiguous. Native notification preferences are respected; a dispatched event does not guarantee inbox delivery.

The job checks every fifteen minutes; system Nextcloud cron must run reliably. No global reminders are enabled by deployment or tests. Existing legacy scheduling stays active until an administrator explicitly saves the replacement policy. Settings are administrator-only; senders have no scheduling override.

## PAdES-LT
The legacy JSignPdf 2.3.0 dependency remains configured for the existing PDF-field helper. A separate checksum-verified JSignPdf 3.2.1 DSS engine is selected only when pades_lt_enabled is true. Its bootstrap JAR must be used, with the complete sibling lib directory. Java 21 is required. Existing DocMDP policy is retained. The reusable signing handler resets its CLI options and rendered-text cache for each signing operation, preventing an earlier participant’s coordinates or certification options from leaking into the next participant’s run. No permissive trust or silent downgrade is enabled.

New admin app-config values:
- pades_lt_enabled (bool)
- pades_lt_jar (absolute path to jsignpdf-bootstrap-3.2.1.jar)
- pades_lt_lib_dir (absolute lib directory)
- pades_lt_trust_files (comma-separated public CA anchor files)
Native tsa_url/policy/auth settings are used. FreeTSA is the staging pilot; its public root PEM SHA256 is 2151b61137ffa86bf664691ba67e7da0b19f98c758e3d228d5d8ebf27e044438 (file digest, not DER fingerprint). Its public service has usage/availability limits. Private organisation CA trust is explicit; external PDF readers do not automatically inherit it.

Existing signed inputs must first pass offline LT verification before network-enabled augmentation; basic legacy requests should be tested as new requests. Before LT signing, local certificate CRL endpoints are matched to the actual issuing CA and refreshed, so the signed revocation evidence does not predate a newly issued certificate. External revocation endpoints are still validated by DSS. Every newly produced LT PDF is independently checked using DSS and PDF-embedded evidence with online retrieval disabled before it is saved. Missing trust, timestamp or revocation evidence blocks signing. Verification results distinguish signed-content integrity, issuer trust, timestamp verification and long-term evidence; certificate expiry alone is not labelled tampering. Results identify the configured organisational trust scope. No old signed PDF is rewritten or retrospectively claimed to have a trusted historical signing time. PAdES-LTA renewal scheduling is not included.

## Staging verification / rollout
Additive migration Version18005Date20261028000000 creates four tables. The staging-only tools/workflows/setup-staging.php creates a private database backup and an empty approver group. Production rollout needs a separately reviewed installation/upgrade plan.

The opt-in www-data-only tools/workflows/test-staging.php creates synthetic users/files, backs up signing settings, enables strict staging LT, tests two sequential signers, a simulated future validation date, modified bytes, letterhead release/identity/role gates and archive filtering, and synthetic-only reminder slots without enabling global reminders. It suppresses test mail, disables users/removes temporary roles and restores LTV settings on failure. It retains LT enabled only after all checks succeed. Signed QA evidence is preserved. Do not use a general sudo expansion to run this helper.
