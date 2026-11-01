=== DEV3 AI Site Health & Security Log Analyst for WordPress ===
Contributors: dev3tech
Tags: site health, error log, security log, file integrity, debug
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reads your PHP error log, Site Health, security events, updates, cron, slow queries and file integrity, groups everything into issues and explains each one in plain words.

== Description ==

DEV3 AI Site Health & Security Log Analyst turns the noise in your logs into a short, ranked list of issues, each with a plain-words explanation, the likely cause and step-by-step fixes.

It is **read-only by default**: it never changes your site on its own. Optional one-click fixes (deactivate a broken plugin, restore a changed core file, block an IP, remove an orphan scheduled task, disable XML-RPC) must be switched on in Settings, show a preview, need your confirmation and can be undone.

= What it collects =

* PHP error log (debug.log or php.ini error_log): reads only new lines, notices log rotation and truncation, folds stack traces.
* WordPress Site Health results (on a schedule).
* Failed logins, brute-force attempts and suspicious requests (path traversal, SQL injection, .env/backup probes, user enumeration, 404 floods) with a lightweight logger.
* Plugin, theme and core changes, including changes made over FTP/SSH.
* Pending core/plugin/theme updates, inactive plugins, old PHP.
* WP-Cron health: overdue tasks, tasks without code, cron not running.
* Slow database queries when SAVEQUERIES is on.
* File integrity: core files and wordpress.org plugins against the official checksums.

= Issues, score, alerts and reports =

* Repeated errors are grouped into one issue with a count and first/last seen; resolved issues re-open when they come back.
* A 0-100 health score with a 30-day trend.
* Email alerts for new fatal errors, new critical issues, changed files, failed-login and suspicious-request thresholds and a low score, with a cool-down.
* A weekly email report with an optional DEV3 AI summary.

= DEV3 AI (optional) =

With your own Google Gemini API key, "Explain with DEV3 AI" writes a plain-words explanation for your exact setup, and you can ask follow-up questions. Before anything is sent, secrets (API keys, passwords, tokens, cookies, database credentials), absolute paths, emails, IP addresses, user names and your site address are masked. You can see exactly what is sent with "What is sent?". Without a key every issue still has a built-in explanation.

== Installation ==

1. Upload the plugin zip in Plugins → Add New → Upload, and activate it.
2. Open "DEV3 AI Site Health" in the admin menu and click "Scan now".
3. Optional: add a Google Gemini API key under API Config.
4. Optional: enable PHP error logging in wp-config.php (see the Overview screen for the lines to add).

== Frequently Asked Questions ==

= Does it change my site? =
No. It is read-only unless you switch on "Allow one-click fixes", and even then each fix needs a confirmation and can be undone.

= Is my data sent anywhere? =
Only when you click a DEV3 AI button (or enable the AI summary in the weekly report), a masked summary of that issue is sent to Google Gemini with your own API key. Integrity checks download checksums from wordpress.org.

= Does it replace a firewall? =
No. It logs and explains; blocking is optional and limited to single IP addresses you choose.

== Screenshots ==

1. Overview: health score, trend and what is being watched.
2. An issue explained by DEV3 AI with fix steps.
3. Security: failed logins, suspicious requests, blocklist and event log.
4. File integrity against wordpress.org checksums.
5. The weekly email report.
6. Works on phones.

== Changelog ==

= 1.0.0 =
* Initial release.
