# GDS Monitoring

A must-use plugin that writes one line to the PHP error log for each thing a
person should look at, as `gds-monitoring: report <source> blog <id>: <message>`
(the heartbeat is the one exception). Every check writes through `Reports`,
the same path other plugins use (see below). Monitoring that reads the
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
| `SpamEntries` | A Gravity Forms entry is marked as spam, except for reasons only bots give (a filled honeypot, no `version_hash`, the speed checks, identical repeat submissions, reCAPTCHA's low score, ALTCHA's rate limit). A missing `version_hash` or `gform_submission_speeds` is logged after all when [our own script](#which-script-ran) ran: a person whose page didn't run Gravity Forms' script. CleanTalk's verdict stands in for its missing reason | `report gform blog 2: spam form 4 entry 120 (Honeypot Spam Filter): The form failed state validation…` |
| `EntryErrorNotes` | An error note on an entry: a failed feed of any add-on, a notification that couldn't be sent, a failed save. Skipped when Mailchimp refused the visitor's own address (fake-looking, already a member, deleted, in a compliance state), which its response's detail names | `report gform blog 2: gravityformsmailchimp error form 4 entry 121: Mailchimp Feed 1: Unable to add/update subscriber: Invalid Resource` |
| `FormRefusals` | A visitor refused by Gravity Forms, which leaves no entry: fields failing the state check (a page cached before the form was edited, or past the state token's life); a multi-file upload refused for an expired nonce ("Your session has expired"); or one the server failed (too large for `post_max_size`, no temp folder, a file that couldn't be written). Refusals of the visitor's own file (type, size), malformed requests, and state failures from a bot posting the page's HTML ([no script ran](#which-script-ran)) are skipped | `report gform blog 2: state invalid form 4 fields 3,5` / `report gform blog 2: upload nonce expired form 4 field 6` / `report gform blog 2: upload failed form 4 field 6 (500): Failed to upload file.` |
| `MailFailures` | `wp_mail_failed`, from any plugin; and Postmark's `postmark_error`, since its plugin replaces `wp_mail()` and never fires `wp_mail_failed` | `report wp_mail blog 2: failed: SMTP Error: The following recipients failed: <email> …` / `report wp_mail blog 2: failed: Postmark API 422: …` |
| `FeedsNotRun` | Hourly: an active feed with no run recorded for entries 1–24 h old that meet its condition | `report gform blog 2: feed not run form 4 feed 1 (gravityformsmailchimp): 4 of 9 entries in the last 24 h` |
| `FormSilence` | Hourly: an active form placed on the site, with 10+ entries in 90 days, quiet for 1.5× its longest gap in them, and at least a day | `report gform blog 2: silent form 4: no entry for 40 h, longest gap in 90 days 15 h` |
| `Reports` | Another plugin reports a problem only it can see (see below). The first per source in 12 h | `report plm blog 1: Failed to sync recipe: cURL error 28: …` |
| `Jobs` | Hourly: a job another plugin said it completed (see below) has gone longer than the max age it gave. Watched only once it has completed on the site | `report job blog 1: overdue plm recipes: last completed 30 h ago` |
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

Some bots copy `version_hash` from the page's `gf_global`, where its value
is, and post it with the HTML's mangled values. So the form's state token,
which Gravity Forms signs, also records that the page printed our script, and
from such a page `FormRefusals` logs a state failure only with
`gds_monitoring_js`. `version_hash` still counts for a page from before this,
and for a token Gravity Forms refuses whole (signed before an update changed
how, as from a tab left open across the update), which can't be read.

## Reporting from other plugins

A plugin reports to monitoring through two actions, which do nothing when this
plugin isn't installed, so it needs no dependency on it:

```php
// A problem only the plugin can see. Message cleaned and masked like the rest.
do_action('gds/monitoring/report', 'plm', $message);

// A recurring job completed; report it if it doesn't again within the max age.
do_action('gds/monitoring/completed', 'plm recipes', 26 * HOUR_IN_SECONDS);
```

A third argument to `report` names another throttle key, or `false` logs every
one.

wp-plm (the beCPG importer) uses both. gravityforms-altcha reports each
rejection that turned a real visitor away (an expired proof, or one signed with
a changed key), unthrottled.

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
