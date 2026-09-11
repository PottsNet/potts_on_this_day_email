# Changelog

## 1.2.0 - 2026-08-09

- Uses the webtrees website timezone as the single authoritative timezone for daily email dates.
- Removes the separate module timezone setting to prevent date mismatches between email headings and webtrees event lookup.
- Adds a configurable daily send time, defaulting to 06:00 in the webtrees website timezone.
- Changes the scheduler design from one exact daily cron run to lightweight recurring checks, with every 15 minutes recommended.
- Performs due-time and already-processed checks before subscriber or genealogy processing, keeping routine scheduler calls inexpensive.
- Handles daylight-saving changes automatically through the webtrees/PHP timezone database.
- Makes the hosting server and cron-service timezone irrelevant to the family-tree date and configured delivery time.
- Freezes one effective local instant for each due run so the subject, heading, event lookup, ages, anniversaries and processed-date key remain consistent.
- Calculates the event Julian day directly from the webtrees-site local Gregorian date.
- Marks no-subscriber, no-event and complete-failure runs as processed for the local date so a frequent scheduler does not repeat expensive work or hammer SMTP.
- Keeps `force=1` for deliberate same-day testing or retry after an administrator fixes delivery.
- Migrates legacy 1.1.x `last_run` values across timezone boundaries to reduce the risk of a duplicate email immediately after upgrade.
- Updates the administrator scheduler guidance for cPanel, Plesk, DirectAdmin, Linux, Windows/NAS and external schedulers.
- Stops logging routine not-due and already-processed checks, reducing diagnostic-log growth.

## 1.1.1 - 2026-07-17

- Restores the administrator daily delivery report after birthday-edition logging changed the scheduler log format.
- Parses both the original log lines and the newer lines containing birthday and email-type details.
- Uses the newest scheduler run that actually checked subscribers, so a later `Already sent today` request no longer hides the report.
- Continues to show recipient name, email address, event count and delivery status on the module settings page.

## 1.1.0 - 2026-07-15

- Adds a special personalised birthday edition for an opted-in subscriber when the selected root individual has a birthday on the current day.
- Changes the subject to `Happy Birthday, First name!` and displays a prominent birthday greeting in both HTML and plain-text email formats.
- Includes the subscriber's age when the recorded birth year is known.
- Sends the birthday edition even when the subscriber's own birth is the only matching event.
- Removes the subscriber's birth entry from the ordinary event list to avoid repeating the birthday message.
- Continues with any other matching family-tree events under an `Also on this day` heading.
- Records birthday-edition delivery in the scheduler diagnostic log and daily result.

## 1.0.1 - 2026-07-06

- Fixes the Control panel link so it uses the webtrees admin route and works more reliably with pretty URLs.
- Condenses the My Page help text into a single clearer explanation block.
- Greys out relationship root and maximum-step fields when the relationship filter is switched off.
- Adds an optional living-people-only filter for personal and manual-recipient emails.
- Makes generated email detail strings more translation-ready, including birthday, death, wedding-anniversary and relationship detail text.
- Improves German translation coverage for the new and previously missed strings.

## 1.0.0 - 2026-07-05

- Promotes the tested 1.0.0-beta.10 build to a regular stable release.
- Fixes compound relationship wording so an in-law relationship uses the gender of the intermediate relative, for example `niece's husband` instead of `nephew's husband`.
- Adds a most recent daily email send report to the settings page, showing recipients, email addresses, event counts and send status from the scheduler log.
- Improves scheduler logging so future runs include subscriber name and email details.
- Confirms the public release package excludes runtime settings, subscriber data, scheduler tokens, locks and logs.

## 1.0.0-beta.10 - 2026-07-04

- Fixed compound relationship wording so an in-law relationship uses the gender of the intermediate relative, for example `niece's husband` instead of `nephew's husband`.
- Added a most recent daily email send report to the settings page, showing recipients, email addresses, event counts and send status from the scheduler log.
- Improved scheduler logging so future runs include subscriber name and email details.

## 1.0.0-beta.9 - 2026-06-29

- Adds starter translations for Polish and Portuguese.
- Keeps the existing Dutch, German, French and Spanish starter translations.
- Updates documentation for the expanded language coverage.

## 1.0.0-beta.7 - 2026-06-29

- Adds starter translations for Dutch, German, French and Spanish.
- Wires module interface strings through webtrees translation handling.
- Covers the settings page, My Page block, daily email headings, scheduler help, status messages and common alerts.
- Keeps the module name as the public brand while translating surrounding labels and descriptions.
- Notes that native-speaker corrections are welcome.

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
