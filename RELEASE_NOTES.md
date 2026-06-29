# Potts On This Day Email 1.0.0-beta.6

This beta fixes a navigation issue on installs where Pretty URLs are not working or not enabled.

## Changes

- Fixes the Control Panel breadcrumb and button on the module settings page.
- When the current request is using webtrees non-pretty routing, the module now links back to the Control Panel using `index.php?route=...` rather than `/admin`.
- Keeps the existing beta.5 fixes and packaging clean-up.

## Installation

Download the attached release ZIP, extract it and upload the `potts_on_this_day_email` folder to `modules_v4/potts_on_this_day_email`.
