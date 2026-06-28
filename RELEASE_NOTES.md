# Potts On This Day Email 1.0.0-beta.5

This public beta improves settings-page navigation for webtrees 2.2.6.

It lets registered users opt in to personalised daily emails containing births,
deaths and marriages from the family tree. Results can be limited by relationship
distance and are generated using each subscriber's webtrees privacy permissions.

## Important

Configure authenticated SMTP in **Control panel > Website > Sending email**
before enabling scheduled delivery. The module uses webtrees' email service, so
delivery quality depends on that configuration.

Exact-time delivery requires a daily request to the secure scheduler URL shown
on the module settings page.

Open **Control panel > Modules > All modules**, locate Potts On This Day Email
and select its settings icon. The page contains sender, timezone, tree,
subscriber, scheduler and delivery-status controls.

This release also adds a Control panel breadcrumb to the settings page, keeps
the selected tree stable when administrators switch between trees and adds an
admin-only settings link to the My Page block.

The release ZIP intentionally includes only `data/.htaccess` and
`data/.gitignore` inside the `data` directory. Do not publish a `data` folder
copied from a working site because runtime files can contain subscriber details,
delivery history and a private scheduler token.

Use the release ZIP attached to this GitHub release for installation. GitHub's
automatic **Source code** downloads are useful for developers but are not the
recommended install package for webtrees users.
