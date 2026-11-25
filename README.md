# Form Alert

WordPress plugin that sends all site email through the Mailgun HTTP API, or through a shared Mailgun SMTP account when the site has no API key. It logs every send, uses Mailgun webhooks to confirm delivery, and alerts the client by email and Twilio SMS when form emails fail or go unopened. It also posts an hourly health report to True Market's collector.

Setup is done in **Form Alert → Settings**, where a checklist shows what's still missing.

## Code

- `mailgun-email-monitor.php`: bootstrap, version constant, update checker
- `includes/class-wpel-mailer.php`: sending (Mailgun API, SMTP fallback)
- `includes/class-wpel-monitor.php`: log, webhook endpoint, alerts
- `includes/class-wpel-sources.php`: records which form and page sent each email
- `includes/class-wpel-health.php`: hourly health reports
- `includes/class-wpel-activator.php`: log table and cron jobs
- `admin/class-wpel-admin.php`: Settings and Email Log screens
- `tools/simulate-webhook.php`: fakes signed Mailgun webhooks for local testing (usage in the file header)

## Releasing an update

Sites get updates from tagged releases on the public `TrueMarket/form-alert` GitHub repo, via the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker). The release's `readme.txt` changelog is what WordPress shows in the "View version details" popup.

To release:

1. Bump the version in all three places: the `Version:` header and `WPEL_VERSION` in `mailgun-email-monitor.php`, and `Stable tag:` in `readme.txt`.
2. Add an entry at the top of `== Changelog ==` in `readme.txt`.
3. Commit, tag and push:
   ```
   git tag vX.Y.Z && git push origin main && git push origin vX.Y.Z
   ```

Or paste this to Claude Code:

```
Release a new version of this plugin (patch bump unless I say otherwise).
Bump the Version header and WPEL_VERSION in mailgun-email-monitor.php and
Stable tag in readme.txt. Add an entry at the top of == Changelog == in
readme.txt with a short list of all changes, in the past tense. Show me the
list before committing, then commit, tag vX.Y.Z and push main and the tag.
```
