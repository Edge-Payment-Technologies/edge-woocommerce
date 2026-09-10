# Edge Gateway for WooCommerce

The official WordPress plugin for using [Edge Payment Technologies, Inc.](https://www.tryedge.io)
as a payment gateway in WooCommerce stores. API documentation lives at
<https://docs.tryedge.io>.

## Requirements

- WordPress and WooCommerce (see `README.txt` for the declared minimums)
- PHP 8.5

We adopt the L-2 version support policy for WordPress core strictly, and a loose
L-2 policy for WooCommerce.

## Installation

**From a release zip** — the archive already bundles its PHP dependencies and
built JavaScript, so no build step is needed:

1. In WordPress, go to 'Plugins' > 'Add New' > 'Upload Plugin' and upload the zip.
2. Click 'Activate'.

**From a git checkout** — `vendor/`, `assets/` and `languages/` are all generated
and are not tracked, so they must be built first. See
[Development](#development) below, then symlink or copy the directory into
`wp-content/plugins/`.

## Configuration

1. Go to 'WooCommerce' > 'Settings' > 'Payments'.
2. Enable 'Edge Gateway for WooCommerce' and click 'Manage'.
3. Set the title and description shown to shoppers at checkout.
4. Enter your API keys. With **Test mode** enabled the gateway uses the *Sandbox
   Publishable Key* and *Sandbox Private Key*; with it disabled it uses the *Live*
   pair. Each order records which mode processed it.

Never use live keys against a development site.

## Development

### Setup

[`mise`](https://mise.jdx.dev) pins the PHP and Node versions in `mise.toml`.
Prefix tool invocations with `mise exec --`, or activate mise in your shell.

```shell
mise install
mise exec -- composer install
mise exec -- npm install
```

`package-lock.json` and `composer.lock` are gitignored, so use `npm install`
rather than `npm ci` on a fresh clone.

### Building

The Checkout Block payment method is written in `resources/js/frontend/index.js`
and builds to `assets/js/frontend/blocks.js`. **Edit `resources/`; `assets/` is
build output.**

```shell
mise exec -- npm run build    # webpack, then regenerate translations
mise exec -- npm run start    # rebuild on change
```

PHP changes take effect immediately in a local site; JavaScript changes need a
rebuild.

### Linting

```shell
mise run lint             # everything; reports all violations, exits non-zero on any
mise run lint:php         # PHP coding standards (phpcs.xml)
mise run lint:phpstan     # static analysis (phpstan.neon, level 3)
mise run lint:js          # JavaScript
mise run check:plugin     # WordPress.org Plugin Check, via the local Studio site
```

Two of these need a word of explanation:

- **phpcs** reports a large amount of unavoidable formatting noise, because the
  ruleset layers PSR-12 over the WooCommerce standards while this codebase uses
  two-space indentation with braces on the next line. Judge a change by
  `mise run lint:report`: rows marked `[x]` are auto-fixable formatting, rows
  marked `[ ]` are the real findings. That count should not grow, and security or
  i18n findings must stay at zero. For the same reason, run `phpcbf` only on
  individual files you are already editing, never across the repository.
- **PHPStan** reports no errors because `phpstan-baseline.neon` suppresses the
  findings that predate its introduction. Run `mise run lint:phpstan:all` to see
  those. Shrink the baseline as they get fixed; never regenerate it to silence
  new errors.

`check:plugin` needs `EDGE_STUDIO_SITE` pointing at your local site, and Plugin
Check installed into it once:

```shell
export EDGE_STUDIO_SITE="$HOME/Studio/wp-edge"
studio wp --path "$EDGE_STUDIO_SITE" plugin install plugin-check --activate
```

Set the variable permanently under `[env]` in `mise.local.toml`, which is
gitignored for exactly this kind of per-machine setting. Because Plugin Check
scans the working tree rather than the release archive, it also reports
development-only files that the release build already strips; findings in shipped
code and in the plugin headers are the ones that matter.

### Releasing

The plugin version appears in both `edge-gateway-for-woocommerce.php` and
`package.json` and the two must agree. Pushing a `v*.*.*` tag triggers
`.github/workflows/release.yml`, which builds the zip with `npm run plugin-zip`.

To build the same artifact locally:

```shell
./bin/build_release.sh [output-dir]    # defaults to ./deploy
```

It builds from the working tree, including uncommitted changes, and asserts that
the archive contains an explicit list of required files — keep that list in sync
when adding or renaming files under `includes/`.

Note that `.github/workflows/validate.yml` marks every check `continue-on-error`,
so CI never fails on a lint violation. Run the linters locally.
