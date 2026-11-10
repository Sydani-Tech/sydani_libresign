# Sydani validation links

New links use eight random base62 characters, grouped as /v/SDs2-Vh4e. Uppercase and lowercase letters are distinct. The space contains 62^8 (218,340,105,584,896) codes. Allocation uses cryptographic randomness, a unique code key and a unique UUID index; collisions retry without replacing an existing mapping. Concurrent callers for one UUID reuse its committed alias.

The permanent mapping lives in Nextcloud's libresign_validation_link table. The primary key is the code's base62 numeric value, so case-sensitive lookup works independently of database text collations. The code is unrelated to a sequential document ID. Keep these mappings in normal database backups and restore them intact; never recycle old aliases. There is no external shortening provider, new DNS entry or additional TLS certificate.

Legacy 22-character codes retain their lossless UUID decoder. Existing /v/CODE links and full validation URLs continue to work. Grouped and ungrouped eight-character inputs resolve to the same UUID. Redirects go only to the existing same-origin validation page, with its existing public/private/login rules and anonymous rate limit. No signer credential is resolved or exposed here. Already signed PDFs are never rewritten.

The same app code supports staging and production with each environment's domain and database. Install the prepared release, then run the guarded Apache helper as root:

- Staging: bash /var/www/html/apps/libresign/tools/short-links/setup-apache.sh --enable-root-url nextcloud.sydani.org
- Production, after its separate upgrade is approved and deployed: bash /var/www/html/apps/libresign/tools/short-links/setup-apache.sh --enable-root-url documents.sydani.org

The helper checks the hostname/domain and existing app installation. It applies only the additive validation-link migration through Nextcloud's migration runner, verifies a stored alias round trip, backs up Apache configuration privately, tests the native short route, syntax-checks and reloads the root alias, and confirms the redirect preserves the code's case. Only after that does it enable grouped footer generation. It rejects unmanaged configuration and restores Apache configuration on failure. The root rule accepts both old and new code lengths. The Apache file is outside the application, so app upgrades do not overwrite it.

The typed settings are short_validation_store_ready, short_validation_grouped_enabled and short_validation_root_enabled. Installation defaults keep old generation active until storage and the root alias are ready. Disabling grouped generation affects future links only; existing mapped aliases remain resolvable. Keep the root alias installed for every PDF already containing it. If root generation is disabled, the native app route remains available as /apps/libresign/v/CODE.

An explicit custom validationSite footer policy continues to use its configured destination. The default empty validationSite uses the native links. New confirmation field appearances contain a tick only when checked, and no drawn box; their logical checkbox values and ownership checks are preserved.
