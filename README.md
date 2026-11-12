# GDS Monitoring

A must-use plugin that writes one line to the PHP error log for each thing a
person should look at, prefixed `gds-monitoring:`. Monitoring that reads the
log (foreman's Kinsta watcher) turns each kind of line into a card. Lines hold
ids, counts and the plugins' own messages, cut at 100 characters. A message
can echo what a visitor typed, so email addresses, double-quoted values and
numbers of 7 or more digits are masked; anything else in it isn't.

```sh
composer require generoi/gds-monitoring
```

## Checks

Each is a class in `src/Checks/`.

| Check | When | Line |
|---|---|---|
| `SpamEntries` | A Gravity Forms entry is marked as spam, except for reasons only bots give (a filled honeypot, no `version_hash`, the speed checks, identical repeat submissions, reCAPTCHA's low score). CleanTalk's verdict stands in for its missing reason | `gform spam blog 2 form 4 entry 120 (Honeypot Spam Filter): The form failed state validation…` |
| `EntryErrorNotes` | An error note on an entry: a failed feed of any add-on, a notification that couldn't be sent, a failed save | `gform gravityformsmailchimp error blog 2 form 4 entry 121: Mailchimp feed: <email> is already a list member.` |
| `FormRefusals` | A visitor refused by Gravity Forms, which leaves no entry: fields failing the state check (a page cached before the form was edited, or past the state token's life); a multi-file upload refused for an expired nonce ("Your session has expired"); or one the server failed (too large for `post_max_size`, no temp folder, a file that couldn't be written). Refusals of the visitor's own file (type, size) and malformed requests are skipped | `gform state invalid blog 2 form 4 fields 3,5` / `gform upload nonce expired blog 2 form 4 field 6` / `gform upload failed blog 2 form 4 field 6 (500): Failed to upload file.` |
| `MailFailures` | `wp_mail_failed`, from any plugin | `wp_mail failed blog 2: SMTP Error: The following recipients failed: <email> …` |
| `FeedsNotRun` | Hourly: an active feed with no run recorded for entries 1–24 h old that meet its condition | `gform feed not run blog 2 form 4 feed 1 (gravityformsmailchimp): 4 of 9 entries in the last 24 h` |
| `FormSilence` | Hourly: an active form with 10+ entries in 90 days quiet for 1.5× its longest gap in them, and at least a day | `gform silent blog 2 form 4: no entry for 40 h, longest gap in 90 days 15 h` |
| `Heartbeat` | Once a day, so a site that stopped logging can be told from a quiet one | `heartbeat` |

The hourly checks run from the main site's cron and visit every site of a
network where Gravity Forms is active, counting only feeds whose add-on is
active there. Gravity Forms has to be active on the main site too, for its
code to be loaded. A condition that lasts logs at most twice a day.

`FeedsNotRun` leaves out entries from before it first saw a feed active
(Gravity Forms keeps no date for a feed, so a new or re-enabled feed starts
with its next entry), and entries restored from spam, which Gravity Forms
doesn't run feeds for.

Not covered: a feed that waits on purpose (until a payment completes) counts
as not run; a finished campaign form left active is reported silent until it
has fewer than 10 entries in 90 days.

## Development

```sh
composer install
npm install
npm run env:start
npm run test:php      # integration tests in wp-env, with the real Gravity Forms
npm run test:e2e      # a visitor submitting real forms on wp-env's site, in the installed Google Chrome
composer lint
composer stan
```

Gravity Forms is a dev dependency from Genero's private packagist; CI installs
it with the organisation's Composer bot.
