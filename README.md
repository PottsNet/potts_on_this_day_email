# Potts On This Day Email for webtrees

Potts On This Day Email sends personalised daily anniversary emails to registered
webtrees users who opt in from My Page.

## Features

- Includes births, deaths and marriages occurring on the current day.
- Sends each subscriber a special personalised birthday edition on their own birthday.
- Lets each registered user opt in or out.
- Uses each subscriber's linked or selected individual as the relationship root.
- Filters events by a configurable relationship distance.
- Can optionally limit results to people who are still living.
- Builds each email using the subscriber's webtrees privacy permissions.
- Sends only when matching events exist, with the subscriber's own birthday treated as a special event.
- Adds age, death and wedding-anniversary details.
- Links relationship descriptions to the webtrees relationship chart.
- Shows administrators subscriber and delivery status.
- Supports an optional integration with Potts Historical Facts.
- Adds starter translations for Dutch, German, French, Spanish, Polish and Portuguese.
- Uses webtrees' native `EmailService`.

## Requirements

- webtrees 2.2.6 or a compatible webtrees 2.2.x release
- PHP 8.3 or later
- A scheduler capable of requesting a secure HTTPS URL every 15 minutes (hourly is also supported)
- Correctly configured webtrees email delivery

## Installation

Use the release ZIP attached to the GitHub release, not GitHub's automatic "Source code" ZIP, when installing into webtrees.

1. Download and extract the release ZIP.
2. Upload the `potts_on_this_day_email` folder to `modules_v4`.
3. In webtrees, go to **Control panel > Modules > All modules**.
4. Enable **Potts On This Day Email**.
5. Add the block to My Page.
6. Select the module's settings icon under **Control panel > Modules > All modules**.
7. Confirm the webtrees website timezone under **Control panel > Website > Website preferences**.
8. Choose the family tree and save the sender name, sender email and daily send time.
9. Configure authenticated SMTP under **Control panel > Website > Sending email**.
10. Add the block to My Page and send yourself a test email.
11. Return to the module settings page, prepare the secure scheduler link and configure it to run every 15 minutes.

The final module path should be:

`modules_v4/potts_on_this_day_email/module.php`

## Email Delivery

The module does not open its own SMTP connection and does not use PHP `mail()`.
It passes every message to the webtrees `EmailService`.

Configure delivery under:

**Control panel > Website > Sending email**

Authenticated SMTP is strongly recommended. If webtrees uses the hosting server's
local sendmail service, receiving providers may reject messages because of the
server IP address or its reputation. A successful test sent from Outlook, Gmail
or another mail application does not test the route used by webtrees.

The sender address entered on the module settings page should be accepted by the
configured SMTP provider. Some providers require it to match the authenticated
mailbox or an approved alias.

## Administrator Settings

Open:

**Control panel > Modules > All modules > Potts On This Day Email settings**

The administrator page provides:

- family-tree selection
- sender name and sender email
- webtrees website timezone display
- daily send time
- authenticated SMTP guidance
- scheduler URL and cron command
- scheduler-token regeneration
- delivery status and diagnostics
- registered-user subscriber status

Site-wide controls are kept out of My Page. The My Page block contains only each
signed-in user's personal subscription and relationship settings.

## Scheduling

webtrees 2.2.x does not provide a general exact-time scheduler for custom modules.
The module therefore provides a token-protected HTTPS endpoint that can be called
by cPanel cron, another hosting scheduler or an external scheduling service.

The scheduler should normally request the endpoint every 15 minutes. These
routine checks are deliberately lightweight. Before any subscriber or genealogy
processing occurs, the module:

1. reads the webtrees website timezone
2. converts the current instant to that timezone
3. checks whether the configured daily send time has arrived
4. checks whether that local calendar date has already been processed

If the email is not due, the request exits immediately. This makes the scheduler
independent of the physical server location, hosting-panel timezone and daylight-
saving changes.

The administrator settings page displays the secure URL, the `curl` command and
a recommended Linux cron entry. Keep the URL private because it contains the
scheduler token.

A typical Linux entry is:

```cron
*/15 * * * * /usr/bin/curl -L -sS --fail 'SECURE_SCHEDULER_URL' >/dev/null 2>&1
```

For cPanel, set **Minute** to `*/15` and the remaining schedule fields to `*`.
For Plesk, use **Fetch a URL** and run it every 15 minutes. Other hosting panels,
Windows Task Scheduler, NAS schedulers and external URL schedulers can use the
same interval.

If a hosting provider does not allow 15-minute jobs, an hourly request is also
safe. The only difference is that delivery can be up to 59 minutes later than
the configured local send time.

### Timezone and daylight saving

The module no longer stores its own date timezone. Version 1.2.0 uses the
**webtrees website timezone** configured under:

**Control panel > Website > Website preferences**

That timezone is used consistently for the email subject, heading, event lookup,
ages, anniversaries and daily processed-date key. PHP timezone identifiers such
as `Australia/Melbourne`, `Europe/London` and `America/New_York` automatically
apply daylight-saving rules from the timezone database.

The hosting server and cron service can be in another country or use UTC. Their
timezone does not determine the family-tree date or delivery time.

The endpoint:

- processes at most once per webtrees-site local calendar day unless `force=1` is added
- prevents overlapping runs with a file lock
- performs the due-time check before subscriber or genealogy processing
- skips subscribers who have no matching events
- records only meaningful delivery runs in `data/scheduler.log`
- treats a complete SMTP failure as processed for that day to avoid retrying every 15 minutes; after fixing email delivery, an administrator can deliberately retry with `force=1`
- migrates the previous 1.1.x `last_run` date when necessary so a timezone-boundary upgrade does not immediately duplicate the current day's email

## User Settings

Each signed-in user can choose:

- whether to receive the daily email
- their root individual
- whether relationship filtering is enabled
- the maximum relationship distance
- whether to include only events for people who are still living

When the selected root individual's birthday falls on the current day, the normal daily email becomes a birthday edition. It uses the individual's first name, includes their age when the birth year is known and continues with any other family events for that day.

The module reads the user's current account email address at delivery time.

## Privacy

Each subscriber's email is generated temporarily under that subscriber's
webtrees user context. Living and private records are included only when the
subscriber is allowed to view them in webtrees.

Do not publish or commit files generated in the module's `data` directory.
They can contain subscriber details, delivery history and a scheduler token.

## Optional Historical Context

If `potts_historical_facts` is installed beside this module, matching regional
historical facts can be included in emails. Potts On This Day Email continues to
work normally when that module is absent.

## Upgrading

Preserve the existing `data` directory when replacing module files. It contains
site settings, subscriber preferences and delivery status.

## Known Limitations

- Exact daily delivery requires a scheduler.
- Delivery reputation, SPF, DKIM and DMARC are controlled by the site's email
  provider and DNS configuration, not by this module.
- Starter translations are included for Dutch, German, French, Spanish, Polish and Portuguese, but native-speaker corrections are welcome.
- Version 1.2.0 makes daily scheduling timezone-safe by using the webtrees website timezone and a lightweight recurring scheduler check.

## Licence

GPL-3.0-or-later. See `LICENSE`.

## Support

Report bugs through GitHub Issues. Include the webtrees version, PHP version,
theme, scheduler result and relevant webtrees log entries. Remove email
addresses, tokens and private genealogy data before posting logs.
