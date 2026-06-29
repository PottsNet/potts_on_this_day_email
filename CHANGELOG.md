# Changelog

## 1.0.0-beta.6 - 2026-06-28

- Fixes the administrator tree selector so the selected tree is read from both query and route attributes.
- Adds a breadcrumb and Control panel link to the module settings page.
- Adds an admin-only settings link to the My Page block.
- Preserves a return link when the settings page is opened from the My Page block.
- Corrects the module support URL to the GitHub repository that uses underscores.

## 1.0.0-beta.4 - 2026-06-26

- Cleans the public release package by excluding runtime settings, subscriber data, scheduler locks and logs.
- Keeps only `data/.htaccess` and `data/.gitignore` in the release so the data directory is created safely without exposing private information.
- Removes an unreachable duplicate error return from the administration settings handler.
- Updates release documentation to clarify that users should install the attached release ZIP rather than GitHub's automatic source ZIP.

## 1.0.0-beta.3 - 2026-06-25

- Adds scheduler setup guidance for cPanel, Plesk, DirectAdmin and Linux SSH.
- Adds guidance for Windows, NAS and external URL scheduling services.
- Explains why the settings page cannot normally create a server scheduled task.
- Warns administrators that scheduler timezones and the module date timezone are separate.

## 1.0.0-beta.2 - 2026-06-25

- Adds a native settings page under Control panel > Modules > All modules.
- Moves sender, timezone, subscriber, scheduler and delivery status controls out of My Page.
- Adds family-tree selection to the administration page.
- Adds CSRF-protected scheduler creation and token regeneration.
- Uses the configured sender identity for manual test emails.
- Clarifies that cron controls timing while webtrees SMTP sends messages.

## 1.0.0-beta.1 - 2026-06-25

- Prepared the module for public testing with webtrees 2.2.6.
- Uses the native webtrees email service and documents authenticated SMTP setup.
- Removes site-specific individuals, hostnames and timezone defaults.
- Adds configurable sender name, sender address and daily timezone.
- Renames cron-facing interface text to provider-neutral scheduler terminology.
- Adds a lock to prevent overlapping scheduled runs.
- Stops writing the token-bearing scheduler URL to diagnostic logs.
- Keeps Potts Historical Facts integration optional.
- Excludes subscriber settings, tokens and logs from the release package.
