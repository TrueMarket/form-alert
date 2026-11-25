# Form Alert

Sends every outgoing WordPress email through the **Mailgun HTTP API** when API sending is enabled and this site has its own API key and sending domain; otherwise it uses the configured **Mailgun SMTP fallback**, or WordPress's default mail transport if neither is configured. It logs sends, reconciles delivery through Mailgun webhooks, flags failures, and can alert by **email and SMS (Twilio)**. Texts need alert phone numbers and Twilio to be configured, and can still reach you when the site can't send email. (Slack support remains in the code but is currently disabled and hidden from Settings; see [Alerting logic](#alerting-logic).)


## Install

1. Copy the `form-alert` folder into `wp-content/plugins/`.
2. Activate **Form Alert** in **Plugins**. Activation creates or upgrades the log table and schedules daily log pruning and an hourly unopened-email check.
3. Deactivate WP Mail SMTP (or any other SMTP plugin) to avoid transport conflicts. Form Alert sends through the API when configured; its SMTP fallback uses WordPress's PHPMailer when the API isn't handling a send.
4. Get Mailgun's **HTTP webhook signing key** (Mailgun → *Settings → API Security*; it's the same for every domain on the account) and enter it as **Webhook signing key** on the Sending tab (next step). Without it, every Mailgun webhook is rejected. A `wp-config.php` constant can stand in for it — see [Shared credentials (wp-config.php)](#shared-credentials-wp-configphp).
5. Go to **Form Alert → Settings** and fill in the following. There are three tabs: **General** (client-facing: a plain-language overview plus alert email, alert phone numbers, submission texts, Track opens with its unopened-email alerts, Delivery failure (SMS alerts), and the watched forms list; the setup checklist sidebar is hidden on this tab), **Sending** and **Alerting & Logging** (Twilio credentials, webhook details, retention, outage detection, [health reports](#health-reports) and other watched emails). The Sending tab's **SMTP fallback** section is normally already filled in on sites cloned from the boilerplate, so mail works before any of this is done.
   - **Mailgun API key** (Sending) — create a separate key for each site (Mailgun → *Settings → API Keys*) so one site's key can be revoked without affecting the others. Use a full API key, not a domain sending key.
   - **Mailgun sending domain** (Sending, US region only) — a dedicated one for this site
   - **Webhook signing key** (Sending) — see step 4. Once saved it shows as a masked value; leave it as is to keep it, type over it to change it, or clear it to remove it.
   - **From name/email** (Sending; shared by the API and the SMTP fallback) — the email must be on this site's sending domain once it has one; entering the domain switches an SMTP-domain address over to `noreply@` that domain automatically. Leave the name blank to use the Site Title.
   - **Alert email recipient** (General) — falls back to the site's admin email when blank, for both real alerts and **Send test email**. A warning lists any Forminator or HTML Forms email that only goes to an alert address or its domain (big webmail domains like gmail.com only count on an exact match), since an alert about it bouncing would probably bounce too. **Dismiss warning** hides the form emails listed at the time; one that starts going there later shows the warning again.
   - **Texts** (optional) — tick **Delivery failure** (*Text me when the forms fail to send an email*: texts when an email can't be delivered or the site stops sending email; saved as `sms_enabled`) and/or **Submission texts** on the General tab, then fill in the fields below. Unopened-email alerts (with **Track opens** on) also text these numbers, whatever else is ticked:
     - **Alert phone numbers** (General, always shown) — comma-separated numbers that get every text that's turned on. Meant for whoever replies to incoming submissions, such as a sales team member. Recipients don't need an app, an account, or to subscribe to anything. **Send test SMS** works whichever texts are on.
     - **Twilio Account SID** (Alerting & Logging) — the real Account SID from the Twilio Console dashboard (starts with `AC`). The API path always needs it, whichever credentials you authenticate with.
     - **Twilio API Key SID** + **Twilio auth token** (Alerting & Logging) — either an API Key SID (`SK...`) plus its Secret from *Console → Account → API keys & tokens* (recommended, since it can be revoked on its own), or leave the API Key SID blank and enter the account's master Auth Token.
     - **Twilio phone number** (Alerting & Logging) — the number alerts are sent from.

     The Twilio fields are always shown and keep their saved values whichever texts are on. Use **Send test SMS** on the General tab (after saving) to check the setup.
6. In the **Mailgun dashboard**, go to *Send → Webhooks → Add webhook → Domain-level* (not Account-level), pick this site's dedicated sending domain, and point it at the endpoint shown on the Settings page:
   ```
   https://YOURSITE/wp-json/wpel/v1/mailgun-webhook
   ```
   Subscribe to `accepted`, `delivered` and `permanent_fail`. Also subscribe to `opened` if you enable open tracking. `temporary_fail` events can be recorded as `temp-fail`, but they do not trigger alerts in the current settings.
7. Click **Send test email** on the Settings page to confirm the whole pipeline — API send, logging, and webhook reconciliation — works end to end.

## What gets logged

Every website email handled by the plugin becomes a row (the plugin's own alert emails are excluded to prevent alert loops) with a status that moves through
`pending → sent (accepted) → delivered`, or lands on `failed` / `temp-fail` / `complained`.
View and filter them under **Form Alert → Log**.

Each row also records its **source**: what sent it, and the page the visitor was on (`includes/class-wpel-sources.php`). `wp_mail()` doesn't carry this itself, so it's worked out at send time:

- **Forminator**: the form and the notification (e.g. *Contact Us — Admin Email*), via Forminator's own send hooks, and the page the form was submitted from. Notification-level detail needs Forminator 1.57+; older versions record just the form.
- **HTML Forms**: the form and which of its **Send Email** actions sent it (*Email #1*, *Email #2*, … in the order they appear in the form's actions), via HTML Forms' own hooks, and the page the form was submitted from. Those actions have no ID of their own, so reordering or removing one shifts the numbers of the ones after it. Mail sent by any other action of the form is recorded against the form as a whole.
- **Anything else**: the type of request, e.g. `ajax:<action>`, `admin-post:<action>`, `rest:<route>`, cron, WP-CLI, the admin, the login page, or a front-end page. For AJAX, REST and admin-post requests the page comes from the referer, since form submissions are usually posted somewhere other than the page the form is on.

The log has a **Source** column and a source filter that works with the status tabs. Rows created only from Mailgun events, and rows logged before this was added, have no source.

The log also has a **From** column. For a form email it shows the visitor's address: the email's Reply-To, or else the From the form set (e.g. HTML Forms' `[EMAIL]`), which **Force from email** replaces with the site's own address and keeps as the Reply-To. A From that's one of the site's own addresses (the From email setting, `wordpress@`, or the admin email) doesn't count. Underneath, *sent as* shows the address it actually went out from; hover it for the full name and address. Emails without a visitor address show just the address they went out from. Rows logged before the plugin recorded senders, and emails that haven't been sent yet, show a dash unless they have a visitor address. The **View** modal shows the same, as **From** and **Sent as**.

The search box at the top right matches the recipient, subject, From address (including the visitor's, from the logged headers) or Mailgun message ID. Searching keeps the selected status tab and source, and the tab counts reflect the search.

**Hide Wordfence alerts** (next to the source filter, shown while there are any in the current view) hides emails whose subject starts with `[Wordfence Alert]`. Wordfence sends one for every lockout or block, so a brute-force run against the login page or `xmlrpc.php` can bury everything else. It applies as soon as it's ticked, works with the status tabs, source filter and search (the tab counts leave the hidden emails out), and is remembered for each user. The number beside it is how many it hides. It only changes what the log shows: the emails are still sent, logged, and alerted on if they fail. To stop Wordfence sending them, untick the lockout and block alerts under *Wordfence → All Options → Email Alert Preferences*.

## How it works

This plugin **is** the Mailgun transport — it doesn't sit alongside another SMTP plugin, it replaces one. Three layers reconcile into one log:

1. **Pre-send capture** (`wp_mail` filter): records the send the instant it happens, as `status = pending`.
2. **Send** (`pre_wp_mail` filter, `includes/class-wpel-mailer.php`): posts the message straight to Mailgun's HTTP API and short-circuits `wp_mail()` with the real result — WordPress's default PHPMailer/SMTP transport is never invoked. The row from step 1 is immediately updated with Mailgun's assigned message-id, or marked `failed` (and alerted) on an API-level send failure. When the API isn't set up, the send goes through PHPMailer instead (the [SMTP fallback](#smtp-fallback)), and the row is updated from core's `wp_mail_succeeded` / `wp_mail_failed`.
3. **Webhook reconcile**: the source of truth for the *final* outcome (delivered vs bounced) — the one thing step 2 can't see, since it only knows the API handoff succeeded. Matches by message-id, falls back to recipient+subject for the rare case where step 2 didn't fire (e.g. Mailgun sending was switched off at send time), and can create a row on its own as a last resort.

## Sending behavior

- **From name/email**: one setting, used whether a send goes through the Mailgun API or the SMTP fallback. A blank name means the Site Title; a blank email means the SMTP username while on the fallback (otherwise the admin email).
- **Force from name** and **Force from email** (both on by default, each under its own field): always send using the configured name/email, regardless of what a plugin/theme sets via `wp_mail()`'s headers. When the email is forced, the address it overrode is kept as the **Reply-To** (unless the email already has one), so replies still reach it. Turn **Force from email** off if you specifically want per-email From addresses honored — but they must all be on Mailgun-verified domains, or those sends will fail. Sites that haven't saved Settings since these were split from the old single **Force from address** setting keep its value for both.
- **Send via Mailgun API** toggle (on by default, including on installs that activated before this option existed): an emergency off-switch. When disabled, `wp_mail()` falls through to the SMTP fallback, or to WordPress's default transport (usually PHP `mail()`, which most hosts don't deliver reliably) if that isn't set up either — logging and alerting still work either way.
- **Duplicate submissions:** when a form plugin (Forminator or HTML Forms) sends an email identical to one it sent in the last two minutes (same form email, recipient, subject and message), it's the same submission arriving twice, usually because the visitor clicked the submit button twice (HTML Forms doesn't disable it while it sends). The repeat isn't sent, logged or texted; it's noted as `duplicate_suppressed` in the original email's timeline, and `wp_mail()` reports success. A failed original doesn't count, so a retry after a failure still goes out. Other emails (and **Resend** from the log) are never treated as duplicates. Change the window with the `wpel_duplicate_window` filter (seconds; `0` turns it off).
- HTML vs. plain text, Cc/Bcc, Reply-To, and file attachments (via hand-built `multipart/form-data`, since WordPress's HTTP API has no upload helper) are all forwarded to the Mailgun API.

## SMTP fallback

The **SMTP fallback** section of the Sending tab holds one set of Mailgun SMTP credentials shared by every site (e.g. `smtp.mailgun.org`, port `587`, TLS, `postmaster@mg.truemarket.io`). It's stored in the database, so set it up once on the boilerplate site and every site cloned from it inherits it. On the boilerplate, leave **From name** blank so each site sends under its own Site Title, and either leave **From email** blank (sends as the SMTP username) or set it to another address on the SMTP domain, e.g. `noreply@mg.truemarket.io`.

Which transport a send uses:

1. **Mailgun API**, if this site has its own API key + sending domain and **Send via Mailgun API** is on — full delivery reconciliation and open tracking.
2. **SMTP fallback**, if it has a username + password — sends and logs `sent` / `failed` (failures alert as usual), but there's no delivery confirmation or open tracking, since the SMTP domain is shared between sites and Mailgun webhooks are per domain.
3. WordPress's default transport otherwise.

The settings sidebar shows which one is active, and **Send test email** reports which one it used.

- A failed **API** send is logged and alerted, but never retried over SMTP: a timeout doesn't prove Mailgun rejected the message, so a retry could deliver it twice, and a broken per-site setup should stay visible rather than be quietly papered over.
- SMTP sends use the same From name/email and **Force from name** / **Force from email** rules as API sends, including keeping an overridden From as the Reply-To. An `X-Mailgun-Variables` header tags each message with the site's host, so sites are easy to tell apart in Mailgun's logs.
- **Don't add a webhook for the shared SMTP domain** in Mailgun — one site would receive every other site's events. The webhook endpoint ignores events from that domain anyway (unless it's also this site's own API domain).
- A Mailgun SMTP acceptance counts as a success for outage detection (it's the only success signal SMTP sends get); PHP `mail()` returning true doesn't.
- To turn the fallback off, clear its username. Once saved, the password (like the Twilio auth token) shows as dots and is never printed into the page. Leaving the dots alone keeps it.

## Alerting logic

- A failure that isn't suppressed by duplicate-event or repeat-failure grouping attempts an **alert email** (best effort, itself sent through the site's active mail transport) and, when **Delivery failure** texts are on and Twilio is configured, an **SMS via Twilio** to every configured number. Permanent failures alert; temporary failures are logged but don't currently alert.
- **Repeats are grouped:** after a failure alert, failures with the same recipient and reason (say, every Wordfence email to an address Mailgun refuses) aren't alerted again for an hour. They're still logged, and the next alert for that recipient + reason says how many were held back.
- **Ordinary alert texts are capped at 10 an hour**, whatever triggers them. The text that hits the limit says so, and the first one after the hour says how many weren't sent. The total-outage alarm bypasses this cap so it can still get through. Alert emails aren't capped. Change the limit with the `wpel_sms_max_per_hour` filter (`0` turns it off).
- Webhook events for the plugin's **own alert emails are ignored** (they're tagged with a Mailgun variable when sent). Otherwise a bouncing alert address would turn each bounced alert into a new failure, a new alert, another bounce, and so on.
- A burst of failures with no successful sends in the window → a distinct **"POSSIBLE EMAIL NOTIFICATION OUTAGE"** text (pointing the client to True Market for the website and their IT provider for email) (throttled to one per window). This is the case where the alert email itself can't get out, which is exactly what SMS covers. It's sent even when the hourly text limit has been reached.
- **Unopened emails:** on whenever **Track opens** (*Alert me when form submissions are read*) is enabled. Each alert goes by email (to the alert email recipient, or the admin email when that's blank) and by text to the alert phone numbers when any are saved and Twilio is set up. Neither depends on **Delivery failure** or **Submission texts**. (Up to 2.2.21 they went by text with SMS alerts on, otherwise by email.) Whenever the check goes from not running to running (Track opens turned on), emails already in the log are skipped, since they were either sent without the tracking pixel or went unchecked while it was off. The hourly check sends one alert per form for everything it flags in that run (with every recipient and submitter when there's more than one), and each email is flagged once. **Alert again if it's still unopened after** adds a second alert ("2nd notice") for emails still unopened a later number of hours after sending; turning it on skips emails already past that point, so it doesn't alert about a backlog of old ones. Only emails ticked under **Watched forms** (General) or **Other watched emails** (Alerting & Logging) are covered. **Watched forms** shows individual Forminator notifications or HTML Forms email actions (so you can watch a form's email to your team without the visitor's auto-reply); every other source the log has seen is under **Other watched emails** on the Alerting & Logging tab. Each has one tick: a ticked email gets this check (with Track opens on) and a submission text (with **Submission texts** on, below). The list is applied when the check runs, so changing it also covers emails still waiting out the threshold. Ticking nothing means no unopened alerts. (Older versions had a **Send by** choice of Text message, Email or Both, plus separate "Alert if unopened" and "Text me when sent" ticks with an optional page limit. Anything ticked in either column carries over as watched, without the page limit; on a site where Text message was picked with SMS off, the update skips the emails already in the log, since the check now starts running there.)
- **Submission texts** (off unless ticked): with **Submission texts** ticked on the General tab (**Delivery failure**, formerly SMS alerts, doesn't need to be on; 2.2.19 required it, and updating unticks submission texts on sites that had them ticked with SMS alerts off, since they weren't being sent there), every email ticked under **Watched forms** or **Other watched emails** texts the alert numbers the moment it's handed to `wp_mail()`, typically a form's notification to the team, so a new lead is seen without waiting for the email. Leave it off to only hear about watched emails that go unopened. (2.2.14 to 2.2.18 sent these texts whenever SMS alerts were on, so a form ticked only for unopened alerts also texted on every submission; they now need the tick.) It doesn't need Track opens: the Watched forms list shows whenever Track opens or submission texts are on (Other watched emails, on the Alerting & Logging tab, always shows). The text names the page it was submitted on, the recipient and the submitter (the same visitor address the log's From column shows, so the line is left out when the form sets neither a Reply-To nor a From of its own). It's sent before the email goes out, so it still arrives if the email then fails (which raises its usual failure alert too). These texts count toward the hourly text limit, and each one is noted as `submission_text` in that email's event history. Tick only a form's email to the team, not the visitor's confirmation, or each submission texts twice.
- Slack support (`notify_slack()` in `includes/class-wpel-monitor.php`) is still in the code but every call site is currently commented out in favor of Twilio SMS, and the Settings field is hidden (not removed — see `render_settings_page()`). Uncomment the calls (in `handle_failure()`, `notify_outage_alarm()`, and `notify_unopened()`) and un-hide the field to run Slack alongside SMS again — the saved webhook URL, if any, is untouched.

Thresholds and retention are on the Settings page. Every logged email's message is saved and can be viewed from the Email Log (**View**), and is deleted with its log entry once the retention period passes. Tick entries in the Email Log to **Delete** them or **Resend** them via the Bulk actions menu; a resend goes out as a new log entry (without attachments, which aren't saved), and entries created only from Mailgun events have no message to resend.

## Health reports

The alerts above go to the client, and only fire when something fails loudly. Health reports cover the quiet failures, for the True Market team: once an hour, each site posts a summary of its email pipeline to a collector the team runs, which alerts the team when a site's numbers look wrong or it stops reporting. Examples:

- Webhooks stop reaching the site. Emails stay at Sent, so bounces don't alert, and unopened alerts stop too, since they only cover delivered emails.
- Opens stop being recorded, so every watched email looks unopened and the client gets false alerts.
- The site drops to the SMTP fallback or WordPress's default transport.
- The site, the plugin or WP-Cron stops running, so no report arrives at all.

Set **Report URL** and **Report secret** under **Health reports** on the Alerting & Logging tab. Like the SMTP fallback, they're stored in the database, so setting them on the boilerplate covers every site cloned from it. While either field is blank, the `WPEL_HEALTH_REPORT_URL` / `WPEL_HEALTH_REPORT_SECRET` constants are used (see [Shared credentials](#shared-credentials-wp-configphp)). Reports are off until both are set. Once they are, the first report goes out within a few minutes, then hourly (on WP-Cron, so on site visits, unless the site has a real cron). **Last report** shows whether the collector accepted the latest one, and **Send report now** sends one straight away to check the setup.

Reports hold only counts, settings flags and timestamps: no email addresses, subjects or message content. The site doesn't decide what's a problem; the collector does, so its rules can change without a plugin release.

### For the collector

Each report is a `POST` with a JSON body and two headers:

- `X-WPEL-Timestamp`: Unix time of the send.
- `X-WPEL-Signature`: hex HMAC-SHA256 of `<timestamp>.<raw body>`, keyed with the report secret.

Recompute the signature over the raw body, compare with `hash_equals()`, and reject timestamps more than a few minutes old. Any 2xx response counts as accepted; anything else shows as a failure under **Last report**, with the start of the response body. Times are UTC ISO 8601, or `null` for never.

```json
{
  "schema": 1,
  "site": "clientsite.com",
  "home_url": "https://clientsite.com",
  "log_url": "https://clientsite.com/wp-admin/admin.php?page=wpel-log",
  "environment": "production",
  "plugin_version": "2.2.23",
  "wp_version": "6.8.1",
  "php_version": "8.2.29",
  "generated_at": "2026-11-25T15:00:04Z",
  "config": {
    "transport": "api",
    "api_sending_enabled": true,
    "mailgun_domain": "mg.clientsite.com",
    "signing_key_set": true,
    "track_opens": true,
    "watched_emails": 2,
    "alert_phone_numbers": 1,
    "twilio_configured": true,
    "delivery_failure_texts": true,
    "submission_texts": false
  },
  "activity": {
    "last_webhook_at": "2026-11-25T14:42:10Z",
    "last_webhook_rejected_at": null,
    "last_open_at": "2026-11-25T13:05:51Z",
    "last_success_at": "2026-11-25T14:42:10Z",
    "last_unopened_check_at": "2026-11-25T14:00:02Z",
    "unopened_check_scheduled": true,
    "wp_cron_disabled": false,
    "outage_alarm_active": false
  },
  "counts": {
    "24h": { "logged": 9, "pending": 0, "sent": 0, "delivered": 9, "failed": 0, "temp_fail": 0, "complained": 0, "opened": 6, "via_api": 9 },
    "7d":  { "logged": 41, "pending": 0, "sent": 1, "delivered": 39, "failed": 1, "temp_fail": 0, "complained": 0, "opened": 27, "via_api": 41 },
    "stuck_pending": 0,
    "stuck_sent_api": 0
  }
}
```

- `schema` goes up when a field changes meaning or is removed. New fields can appear without it changing.
- `transport` is `api`, `smtp` (the SMTP fallback) or `default` (WordPress's own, usually PHP `mail()`).
- `last_webhook_at` is the last Mailgun webhook that passed signature verification, of any kind. `last_webhook_rejected_at` is the last one that didn't: a wrong or missing signing key, but also any stray POST to the endpoint, so it's only meaningful alongside emails stuck at Sent. Both are updated at most every five minutes.
- `last_success_at` is the last `delivered` webhook or SMTP fallback acceptance (the same signal outage detection uses).
- `last_unopened_check_at` is the last run of the hourly unopened check, whether or not Track opens is on.
- `counts` covers emails logged in the last 24 hours and 7 days. `opened` counts emails opened at least once. `via_api` counts emails with a Mailgun message ID, i.e. sent through the API (SMTP fallback and default-transport sends have none), so `transport: "api"` with `via_api` well below `logged` suggests another plugin is sending the site's mail.
- `stuck_pending` counts emails from the last 7 days still pending after an hour (the send died part-way, or another plugin took over `wp_mail()`). `stuck_sent_api` counts API sends from the last 7 days still at Sent after two hours: a few can be slow deliveries, but a pile of them means webhooks aren't arriving.
- `environment` is `wp_get_environment_type()`. Local (`local`) and staging copies cloned from a reporting site report too, under their own `site`, so the collector should ignore those or at least not alert when they go quiet.

Rules should only fire when mail was actually sent: a site with no form submissions this week has nothing to deliver or open.

## Shared credentials (wp-config.php)

The webhook signing key is normally entered as **Webhook signing key** on the Sending tab. While that field is blank, the plugin uses `WPEL_MAILGUN_SIGNING_KEY` from `wp-config.php` instead, the same way the Twilio constants below work:

```php
define( 'WPEL_MAILGUN_SIGNING_KEY', 'account-level HTTP webhook signing key' );
define( 'WPEL_SLACK_WEBHOOK', 'https://hooks.slack.com/services/...' ); // unused while Slack alerting is disabled
```

A key saved in Settings always wins over the constant. The setup checklist on the Settings page warns when neither is set: without a key every Mailgun webhook is rejected, so emails stay at Sent, opens aren't recorded and bounces don't alert.

The [health report](#health-reports) URL and secret work the same way: `WPEL_HEALTH_REPORT_URL` and `WPEL_HEALTH_REPORT_SECRET` are used while the matching Settings field is blank.

The Twilio credentials are entered in Settings (see the install steps above), not here. Sites that already define `WPEL_TWILIO_ACCOUNT_SID`, `WPEL_TWILIO_SID`, `WPEL_TWILIO_AUTH_TOKEN` or `WPEL_TWILIO_FROM_NUMBER` in `wp-config.php` keep working: each constant is used while its Settings field is blank, and a value saved in Settings wins over it. **Delivery failure** texts or submission texts still have to be turned on in Settings (unopened-email alerts text whenever alert phone numbers are saved), though sites upgraded with alert phone numbers already saved start out with Delivery failure texts enabled.

The sending domain, From address, and alert email/phone numbers stay in Settings because they're different on each site (each site sends from its own dedicated domain — see the checklist note on why domains can't be shared).

## Notes / limitations

- Webhook calls are rejected unless the Mailgun signature verifies, so the signing key is required.
- `wp_mail_succeeded` / `wp_mail_failed` don't fire natively for Mailgun API sends (short-circuiting `pre_wp_mail` skips the rest of core's `wp_mail()`), so third-party plugins that hook those directly won't see them. Monitor still catches failures independently via `WPEL_Mailer::mark_failed_and_alert()`. SMTP fallback sends go through core's PHPMailer, so both hooks fire normally for those.
- `blocking => false` is used for the Twilio API call so a slow response never stalls a page load.
- On a Twilio trial account, each "to" number must be verified in the Twilio Console before it can receive SMS; upgrade to a paid account to text arbitrary numbers. For any real US SMS volume, Twilio may also require A2P 10DLC brand/campaign registration for the sending number to avoid carrier filtering — low-volume ops alerts to a handful of numbers typically work unregistered, but this is worth checking if messages start getting silently dropped.

## Updates

This plugin ships with the [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) library and checks the private `TrueMarket/mailgun-watch` GitHub repo for new tagged releases, surfacing them on the Plugins screen like any wordpress.org plugin.

Since the repo is private, each site needs a read-only GitHub token to check for updates:

1. Create a fine-grained personal access token at GitHub → Settings → Developer settings → Personal access tokens, scoped to only the `mailgun-watch` repo with **Contents: Read-only** permission.
2. Add it to `wp-config.php`:
   ```php
   define( 'WPEL_GITHUB_TOKEN', 'github_pat_...' );
   ```

### Releasing a new version

To ship an update, paste this to Claude Code (fill in the changelog notes), or follow it by hand:

```
Release a new version of this plugin. Bump the Version header and
WPEL_VERSION in mailgun-email-monitor.php together (patch bump unless
I say otherwise). Add a new entry at the top of the == Changelog ==
section in readme.txt containing a short and concise list of all changes in the past tense.

Show me the list of changes before commiting.

Commit everything, tag the commit vX.Y.Z to match, and push both main and the tag to origin.
```

The changelog notes matter, not just as documentation — they're what WordPress shows in the "View version X.X details" popup on the Plugins screen, since the update checker reads them straight out of `readme.txt`'s `== Changelog ==` section for whichever tag it's looking at.

That's: bump `Version:` in the file header and `WPEL_VERSION` together, add the changelog entry to `readme.txt`, commit, then tag and push — e.g. `git tag v2.3.0 && git push origin main && git push origin v2.3.0`.
