# Deploying frontend builds

The web server may cache JavaScript and CSS as immutable resources. Vite now gives
entry bundles content-hashed names, and writes `resources/frontend-assets.json`
to connect those names to the PHP page loaders. The CSS entry aliases generated
by `@nextcloud/vite-config` must be included too, not only the hashed CSS chunks.

Deploy `js/`, `css/`, image assets and `resources/frontend-assets.json` from the
same completed build. Install new assets before publishing the manifest/page
loaders. Keep old hashed files during deployment so already-open pages continue
to work. Do not change Nextcloud core or disable its cache protections.

For lower-memory, per-entry builds, set `LIBRESIGN_BUILD_ENTRY` to one of `main`,
`external`, `validation`, `init`, `tab` or `settings`. Each build preserves the
other entries in the generated manifest. Complete all six before deployment.
The default full build regenerates the complete manifest.

The manifest and bundles are generated artifacts, not committed source. PHP
loaders retain a conventional-filename fallback for older build packages.
