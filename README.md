# GDS Monitoring

A must-use plugin that writes one line to the PHP error log for each thing a
person should look at, prefixed `gds-monitoring:`. Monitoring that reads the
log (foreman's Kinsta watcher) turns each kind of line into a card. Lines hold
ids, counts and the plugins' own messages, with email addresses masked; never
what a visitor typed.

```sh
composer require generoi/gds-monitoring
```

## Checks

Each is a class in `src/Checks/`.

| Check | When | Line |
|---|---|---|
| `SpamEntries` | A Gravity Forms entry is marked as spam, except for reasons only bots give (a filled honeypot, no `version_hash`, the speed checks, identical repeat submissions, reCAPTCHA's low score). CleanTalk's verdict stands in for its missing reason | `gform spam blog 28 form 3 entry 3104 (Honeypot Spam Filter): The form failed state validation…` |
| `EntryErrorNotes` | An error note on an entry: a failed feed of any add-on, a notification that couldn't be sent, a failed save | `gform gravityformsmailchimp error blog 28 form 3 entry 3107: Mailchimp feed: <email> is already a list member.` |
| `MailFailures` | `wp_mail_failed`, from any plugin | `wp_mail failed blog 28: SMTP Error: The following recipients failed: <email> …` |
| `FeedsNotRun` | Hourly: an active feed with no run recorded for entries 1–24 h old that meet its condition | `gform feed not run blog 28 form 3 feed 3 (gravityformsmailchimp): 4 of 9 entries in the last 24 h` |
| `FormSilence` | Hourly: an active form with 10+ entries in 90 days quiet for 1.5× its longest gap in them, and at least a day | `gform silent blog 28 form 3: no entry for 40 h, longest gap in 90 days 15 h` |
| `Heartbeat` | Once a day, so a site that stopped logging can be told from a quiet one | `heartbeat` |

The hourly checks run from the main site's cron and visit every site of a
network. A condition that lasts logs at most twice a day.

Not covered: feeds that wait on purpose (until a payment completes) count as
not run; a finished campaign form left active is reported silent until it has
fewer than 10 entries in 90 days.

## Development

```sh
composer install
npm install
npm run env:start
npm run test:php      # integration tests in wp-env, with the real Gravity Forms
composer lint
composer stan
```

Gravity Forms is a dev dependency from Genero's private packagist; CI installs
it with the organisation's Composer bot.
