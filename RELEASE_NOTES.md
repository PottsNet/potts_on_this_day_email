# Potts On This Day Email 1.2.0

Version 1.2.0 redesigns daily scheduling so the module behaves correctly on webtrees installations anywhere in the world, regardless of where the hosting server or cron service is located.

The module now uses the timezone configured in **Control panel > Website > Website preferences** as its single source of truth. Administrators choose a local daily send time, while the secure scheduler URL is normally called every 15 minutes. Routine checks exit immediately until the local send time is due and the current local date has not already been processed. This also means daylight-saving changes are handled automatically without changing the cron schedule.

The same local date is now used consistently for the email subject, body heading, genealogy event lookup, ages, anniversaries and scheduler processed-date key. The release also includes an upgrade safeguard that converts the previous 1.1.x `last_run` date when the old module timezone and webtrees timezone were on different calendar days, reducing the chance of an immediate duplicate email after upgrade.

Routine scheduler checks are intentionally lightweight and are not written to the diagnostic log. A complete SMTP failure is treated as processed for that day to prevent repeated 15-minute retries; after fixing email delivery an administrator can deliberately retry with `force=1`.

# Potts On This Day Email 1.1.1

This maintenance release restores the administrator daily delivery report on the module settings page. Version 1.1.0 added birthday and email-type information to scheduler log lines, but the report parser still expected the older format and therefore displayed no recipients.

The report now recognises both formats and selects the newest scheduler run that actually checked subscribers. This also prevents a later `Already sent today` scheduler request from hiding the most recent delivery details.

# Potts On This Day Email 1.1.0

This release adds a personalised birthday edition for registered subscribers. On the birthday recorded for a subscriber's selected individual, the normal On This Day email changes to a special birthday message using their first name and, when available, their age. Any other family events for the day are included below the greeting.

The birthday email uses the existing opt-in, account email address, privacy permissions, scheduler and sender settings. No new configuration is required.

# Potts On This Day Email v1.0.1

Maintenance release for early public GitHub issue feedback.

## Fixed

- Fixed the Control panel link so it uses the webtrees admin route and works more reliably when pretty URLs are enabled.
- Condensed the My Page explanation text into one clearer help block.
- Greyed out relationship root and maximum-step fields when the relationship filter is switched off.
- Improved translation coverage for settings, scheduler help, recent-send reporting and generated email detail strings.
- Improved German translation coverage for previously missed strings.

## Added

- Added an optional **Only include living people** filter for personal daily emails and manual-recipient emails.
- When this option is enabled, birth events are included only for living people, death events are excluded and marriage events are included only when the spouses are still living.

## Changed

- Updated module version metadata to `1.0.1`.
- Updated `latest-version.txt` to `1.0.1`.

## Notes

Install the attached release ZIP asset named `potts_on_this_day_email-1.0.1.zip`. Do not use GitHub's automatic source-code ZIP for installation.
