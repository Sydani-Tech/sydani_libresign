# Dashboard and document filters

The personal dashboard returns 10 documents per page. Counts still cover the full matching personal scope. Request Signature and Create Campaign respect the existing permissions. Open All Files is available above and below the inbox.

Files and Signed Archive share a filter form: document name, selected sender, separate recipient name/email, status, date range, sort field and order. Files additionally includes drafts, pending requests, signing in progress and Date Created. Fields apply when Apply filters is pressed; Reset restores the respective defaults. Sender is a searchable selection of existing document senders, not a free-text filter.

Files uses the existing associated-file query and applies report predicates with AND; filtering never grants additional access. The optional includeSenders response uses the same personal scope without active report filters so the selector remains useful after a zero-result search. Signed Archive remains administrator-only. Combined recipient name/email must match the same participant; account IDs resolve to their email addresses on the server. Dates use the recorded requested/completed columns, never the upload date as a fallback.

File-list totals count distinct documents to avoid duplicate pagination counts when a document has multiple signers or identification methods. Existing grid/list actions, signature requests, protected documents and table-header sorting remain available.

Read-only staging verification:

    env NEXTCLOUD_CONFIG_DIR=/home/codexdev/archive-qa-config php /var/www/html/apps/libresign/tools/document-filters/test-readonly.php

This checks existing records only; it does not send invitations, create accounts or alter documents.
