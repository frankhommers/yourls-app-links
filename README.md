# App Links for YOURLS

Version **0.8.4** adds readable app destinations to the existing YOURLS shortener.
Requires PHP 8.0+; YOURLS 1.10.6 requires PHP 8.1+.

The public opening page uses the installation’s native YOURLS stylesheet.

## Create links in YOURLS

Sign in to the normal YOURLS administration and paste an app URI into its normal URL field:

```text
obsidian://open?vault=MyVault&file=Inbox.md
```

Choose a keyword, for example `inbox`, or let YOURLS generate one. The result has two addresses:

```text
https://links.example/inbox
https://links.example/obsidian/MyVault/Inbox.md
```

Both open the app. The readable URL is the destination stored in the native YOURLS row and appears in the normal dashboard. There is no separate plugin shortener or public converter.

Every valid app destination opens publicly, including destinations without a stored short link. Bare gateways and invalid app destinations return 404. Automatic creation is optional and off by default. POST to app routes returns 405. App URIs do not publish local files: recipients still need the app and access to the vault/file.

## One linked short URL for the readable URL

A short-link visit increments that keyword's native counter and log once. A readable URL visit increments the linked keyword’s counter and log once. Short links open the app directly, without another HTTP visit to the readable URL. The opening page shows the corresponding short URL and a Copy link button when a short URL is linked. Displaying or copying it does not generate another visit. HEAD requests do not count. A click records a visit, not a confirmed app launch.

The first authenticated creation of a destination links its keyword to the readable URL. Duplicate short links, if enabled by YOURLS, retain their own counters and do not replace that link. Under **App Links → Linked short URLs**, an administrator can explicitly select a different existing keyword for that destination. Counters are not moved or merged.

For legacy destinations with exactly one matching row, that row is the unambiguously linked short URL without a migration write. Legacy duplicates require an explicit selection for counting. Their readable URL still opens the app without counting or displaying a short URL until a link is selected. If a linked short URL is deleted or edited to another destination, the readable URL still opens without counting until an administrator selects a linked short URL again. Renaming that keyword through YOURLS preserves the association.

The native URL table and click log remain authoritative. Linked-keyword references use the YOURLS option `hmm_open_owners`; there is no separate analytics table. The association always verifies that the referenced keyword still points to the same app destination.

## Gateways and app registrations

Registering an app automatically enables its app-key route, for example `/obsidian/…`. Under **App registrations**, a custom **Direct route** can replace that prefix; a blank value uses the app key. Routes must not conflict with gateways, system directories, existing keywords or user pages.

The global **Gateways** section configures the shared `open` and `_` prefixes and preferred gateway for raw fallback URLs. These legacy readable variants remain accepted for registered destinations:

```text
/obsidian/MyVault/Inbox.md
/_/obsidian/MyVault/Inbox.md
/open/obsidian/MyVault/Inbox.md
```

Raw app URIs for enabled schemes are also supported after a gateway. They do not require a stored short link. Fully encoded raw URIs preserve fragments and unusual paths. Double prefixes such as `/open/_/…` are unsupported. A browser fragment on a manually pasted raw URL does not reach PHP; use an encoded URI or the authenticated API for fragment-specific destinations.

Route settings and transformations use native YOURLS options. Changing or removing a route/template can invalidate existing readable URLs. Keep old gateways if links still use them.

## Transformation templates

Each app registration has an app key, display name, direct route, path template, URI template and optional value constraints. These templates are used by native creation, routes and the API.

| App | Path template | URI template |
|---|---|---|
| Obsidian | `{vault}[/{file...}]` | `obsidian://open?vault={vault}[&file={file}]` |
| VS Code | `{path...}` | `vscode://file/{path...}` |
| Spotify | `{type}/{id}` | `spotify:{type}:{id}` |

`{name}` is one value. `{name...}` preserves path separators and colons for drive letters/line numbers. In a URI query use `{name}` to encode slashes as `%2F`. Both templates must contain the same placeholder names exactly once. Whole path segments are supported, with one optional trailing segment, such as `[/{file...}]`. The corresponding optional query parameter is `[&file={file}]`.

Constraints are an optional JSON object: an allowed-values list, `alphanumeric`, or `file_path` (rejects legacy `@project` aliases). Templates cannot execute PHP or arbitrary regex. Additional URI options and fragments that cannot be simplified use the lossless raw fallback. Browser/script schemes remain forbidden.

Use **Add an app** and **Save app registrations** to add another scheme without PHP. No personal vault or project aliases are stored. Existing `hmm_open_apps` extension hooks remain supported for custom PHP encoder/decoder callbacks; built-in apps use editable template presets.

## API

Use the existing authenticated `yourls-api.php` endpoint:

```sh
curl --request POST 'https://links.example/yourls-api.php' \
  --data-urlencode "signature=$YOURLS_SIGNATURE" \
  --data-urlencode 'action=shorturl' \
  --data-urlencode 'format=json' \
  --data-urlencode 'url=obsidian://open?vault=MyVault&file=Inbox.md' \
  --data-urlencode 'keyword=inbox'
```

The native response includes `shorturl`, its normal `url` data, and `appurl` for the readable address. Native duplicate handling, keyword generation, errors and counters stay in YOURLS. App-link creation/editing additionally requires a valid YOURLS user, even if an installation exposes a custom public shortener.

Authenticated read-only helpers remain available:

- `open-convert` with `uri`: returns the prospective readable URL. It does not register a link. The result opens publicly; visits without a linked short URL are uncounted unless automatic creation is enabled.
- `open-resolve` with `url`: returns the app URI represented by an app URL without opening it.
- `open-apps`: returns registrations, templates, constraints, routes and the `gateway` base. There is no `converter` page.

Use native `expand` for short links and native statistics actions for counts.

## Upgrade from 0.6.x / 0.7.x

1. Back up the plugin directory and YOURLS database.
2. Replace `user/plugins/open-links` with this release.
3. Keep the plugin active; no core or database schema changes are required.
4. Review route conflicts and **Linked short URLs** in the plugin settings.

Existing rows and statistics are not deleted or merged, including records automatically created by older versions. Public visits create records only when automatic creation is explicitly enabled. Bare `/open/` and `/_/` no longer display a converter. The plugin's separate short-link form has been removed. App-key routes now work by default; configured custom prefixes remain in use. Existing gateway variants remain accepted.

Backups and deployment tooling must remain outside the public web root. Deploy only the `open-links` directory.

## Tests

```sh
php tests/run.php
php tests/templates.php
php tests/stats.php
php tests/api.php
php -S 127.0.0.1:18974 tests/server.php
# Another terminal:
python3 tests/check_http.py
```

Local HTTP tests use a YOURLS boundary stand-in. Validate native authenticated creation, actual database counters and cleanup on the target installation as part of deployment.

## Optional automatic creation

Under **App Links → Unregistered app URLs**, enable **Automatically create a short link for unregistered app URLs** to let public GET visits create a native YOURLS short link when no matching record exists. The default is off. The first visit counts once and the new short URL is immediately displayed on the opening page. Later visits reuse that link.

HEAD requests never create or count. Ambiguous matches and previously deleted/reassigned links do not trigger another record. A per-destination database lock prevents simultaneous automatic requests from creating duplicate records. Native keyword generation and flood limits remain in effect. A failed or rate-limited creation does not stop the app from opening; it simply has no short URL or count for that visit. This setting does not enable anonymous access to the native creation API.

The public converter remains removed. Short-link settings and the automatic-creation option require the normal YOURLS admin login and nonce. The automatic setting is stored as `hmm_open_auto_create`.

## Development and releases

Run all local checks with `python3 scripts/check.py` (PHP and Python 3 required).
GitHub Actions runs these checks on PHP 8.1 and 8.4. The HTTP suite uses a local
YOURLS stand-in; real hosting integration is verified separately during deployment.

Build an installable ZIP and SHA-256 checksum with `python3 scripts/package.py`.
Only plugin PHP files and this README are included. The `open-links` installation
directory is intentionally retained so existing installations keep their activation
and settings after the rename to App Links.

Push a version tag matching `plugin.php`, such as `v0.8.4`, to publish a GitHub
release after all checks pass. Branch builds also provide a downloadable workflow
artifact. Workflows never deploy to a hosting account or access hosting credentials.

This repository is private while the distribution license is being selected.
Hosting backups, deployment helpers, credentials and generated archives are excluded
from version control. No open-source license is granted yet.
