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
| `SpamEntries` | A Gravity Forms entry is marked as spam, except for reasons only bots give (a filled honeypot, no `version_hash`, the speed checks, identical repeat submissions, reCAPTCHA's low score, ALTCHA's rate limit). A missing `version_hash` or `gform_submission_speeds` is logged after all when [our own script](#which-script-ran) ran: a person whose page didn't run Gravity Forms' script. CleanTalk's verdict stands in for its missing reason | `gform spam blog 2 form 4 entry 120 (Honeypot Spam Filter): The form failed state validation…` |
| `EntryErrorNotes` | An error note on an entry: a failed feed of any add-on, a notification that couldn't be sent, a failed save. Skipped when Mailchimp refused the visitor's own address (fake-looking, already a member, deleted, in a compliance state), which its response's detail names | `gform gravityformsmailchimp error blog 2 form 4 entry 121: Mailchimp Feed 1: Unable to add/update subscriber: Invalid Resource` |
| `FormRefusals` | A visitor refused by Gravity Forms, which leaves no entry: fields failing the state check (a page cached before the form was edited, or past the state token's life); a multi-file upload refused for an expired nonce ("Your session has expired"); or one the server failed (too large for `post_max_size`, no temp folder, a file that couldn't be written). Refusals of the visitor's own file (type, size), malformed requests, and state failures from a bot posting the page's HTML ([no script ran](#which-script-ran)) are skipped | `gform state invalid blog 2 form 4 fields 3,5` / `gform upload nonce expired blog 2 form 4 field 6` / `gform upload failed blog 2 form 4 field 6 (500): Failed to upload file.` |
| `AltchaRejections` | A submission [ALTCHA](https://github.com/generoi/gravityforms-altcha) refused, which leaves no entry, when the proof had expired or its signature no longer matches (the key changed). Both take a real, signed challenge, so they point to real people. Missing, malformed or replayed proofs, which bots send, are skipped | `gform altcha rejected blog 2 form 4 (expired): 125 s past expiry` |
| `MailFailures` | `wp_mail_failed`, from any plugin | `wp_mail failed blog 2: SMTP Error: The following recipients failed: <email> …` |
| `FeedsNotRun` | Hourly: an active feed with no run recorded for entries 1–24 h old that meet its condition | `gform feed not run blog 2 form 4 feed 1 (gravityformsmailchimp): 4 of 9 entries in the last 24 h` |
| `FormSilence` | Hourly: an active form placed on the site, with 10+ entries in 90 days, quiet for 1.5× its longest gap in them, and at least a day | `gform silent blog 2 form 4: no entry for 40 h, longest gap in 90 days 15 h` |
| `Heartbeat` | Once a day, so a site that stopped logging can be told from a quiet one | `heartbeat` |

A line written during cron or WP-CLI is queued (the latest 200, network-wide)
and written at the start (`init`) of the next ordinary request: `wp-cron.php`
ends the web request before WordPress loads, so the web server no longer takes
its error log, and a crontab's WP-CLI usually discards stderr. Not at the
request's end, when a client that left or a response finished early would take
the lines with it. Monitoring sees such a line when it's written, not when the
check ran.

The hourly checks run from the main site's cron and visit every site of a
network where Gravity Forms is active, counting only feeds whose add-on is
active there. Gravity Forms has to be active on the main site too, for its
code to be loaded. A condition that lasts logs at most twice a day.

`FeedsNotRun` leaves out entries from before it first saw a feed active
(Gravity Forms keeps no date for a feed, so a new or re-enabled feed starts
with its next entry). An entry marked not spam is counted: Gravity Forms runs
its feeds then.

Not covered: a feed that waits on purpose (until a payment completes) counts
as not run; a finished campaign form left active and still on a published page
is reported silent until it has fewer than 10 entries in 90 days.

A form counts as placed when a published post's content embeds it (block or
shortcode), a published post uses a synced pattern holding it, a block widget
holds it, or it was viewed in the last 30 days. The last catches a form placed
in theme code, unless every page showing it is served from cache.

## Which script ran

Gravity Forms' script adds `version_hash` to a form as it is sent, so a
submission without it didn't run that script. That's a bot posting the page's
HTML, or a person whose page failed to load or run it. To tell them apart, a
small inline script of our own, printed with the first form on the page and
apart from Gravity Forms' files, adds `gds_monitoring_js` to every form as it
is sent:

| `version_hash` | `gds_monitoring_js` | Who | |
|---|---|---|---|
| sent | any | a person, Gravity Forms' script worked | logged as usual |
| missing | sent | a person whose Gravity Forms script didn't run | logged |
| missing | missing | a bot posting the HTML, or a person with scripts off | skipped |

Like Gravity Forms, our script adds nothing in a browser driven by automation
(Selenium, PhantomJS and the like), so those count as bots too.

## Test submissions

To check on the live site that a form works, without the client hearing of
it, submit it with the test address in its email field:

```php
// config/application.php, or the gds/monitoring/test_email filter
Config::define('GDS_MONITORING_TEST_EMAIL', env('GDS_MONITORING_TEST_EMAIL'));
```

The address itself or with a `+tag` (`forms-test+contact@example.com`)
counts, whatever its case. The submission goes the whole real way (the page,
its scripts, the spam checks, saving, mail), but:

- every notification goes to the address typed, its subject marked `[test]`,
  instead of its recipients (the site's staff, the visitor, CC and BCC);
- no add-on feed runs (Mailchimp, a CRM, a webhook, a payment);
- once handled, the entry gets a note and goes to the trash, so exports and
  the site's lists never show it and `FormSilence` doesn't count it. One
  caught as spam stays spam, so the test shows what the spam checks made of
  it.

A form needs an email field for this, and the address a real domain:
Gravity Forms refuses `@example.com` and `@domain.com` (2.9.15 on). What another plugin does on
`gform_after_submission` itself, outside Gravity Forms' feeds, still runs.

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
