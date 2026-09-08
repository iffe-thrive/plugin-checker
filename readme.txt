=== Plugin Checker ===
Contributors: you
Tags: diagnostics, health, conflicts, php compatibility, debugging, site health, wp-cli
Requires at least: 5.5
Tested up to: 6.6
Requires PHP: 7.2
Stable tag: 1.1.0
License: GPLv2 or later

Professional diagnostics for WordPress: scans installed plugins for PHP errors, conflicts, PHP/WP compatibility, deprecated code, abandoned plugins, and general site health. Includes scheduled scans, email reports, WP-CLI, REST API, Site Health integration, dashboard widget, admin-bar badge, and JSON/CSV export.

== Description ==

Adds a top-level **Plugin Checker** admin menu (plus Site Health integration and a dashboard widget). One click runs a report covering:

* **PHP errors** — tokenizer lint of each plugin's main file (or, in **Deep Scan** mode, every PHP file in the plugin) surfaces syntax errors.
* **Recent fatal errors** — scans `wp-content/debug.log` for fatal/uncaught errors traced to any installed plugin.
* **PHP compatibility** — compares each plugin's `Requires PHP` header, plus flags use of deprecated/removed PHP functions (`each`, `create_function`, `mysql_*`, `utf8_encode`, …) for your server's PHP version.
* **WordPress compatibility** — compares each plugin's `Requires at least` and "tested up to" (via WordPress.org API).
* **Conflicts** — detects known conflicting plugin pairs (multiple caching / SEO / security / image-optimizer / editor plugins active) and duplicate function/class names declared by two or more active plugins.
* **Updates & abandonment** — flags outdated versions and plugins with no update in 2+ years.
* **General suggestions** — PHP upgrade advice, OPcache status, `WP_DEBUG_LOG`/`WP_DEBUG_DISPLAY` guidance, too-many-plugins / unused-plugin warnings.

**Professional features**

* **Scheduled scans** — run daily or weekly, entirely automated.
* **Email reports** — optionally emailed to any recipient list, either every scan or only when issues are found.
* **Site Health integration** — findings appear inside WordPress's built-in **Tools → Site Health** screen (critical / recommended / good).
* **Dashboard widget** — at-a-glance status on the main WP dashboard.
* **Admin-bar badge** — shows error/warning counts on every admin page after a scan.
* **Scan history** — retains the last N scan summaries so you can see trends.
* **JSON / CSV export** of the full report.
* **REST API** — `GET /wp-json/plugin-checker/v1/report`, `POST /wp-json/plugin-checker/v1/scan`, `GET /wp-json/plugin-checker/v1/history` (all `manage_options`-gated).
* **WP-CLI** — `wp plugin-checker scan [--deep] [--only-issues] [--format=json|csv|yaml|table]` and `wp plugin-checker report`. Non-zero exit code when errors are found, so it slots neatly into CI.
* **Clean uninstall** — removes all options, transients, and scheduled tasks.

== Installation ==

1. Upload the `plugin-checker` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu.
3. Go to **Plugin Checker → Scan** and click **Run Scan**.
4. Optional: configure automatic scans and email reports in **Plugin Checker → Settings**.

== Changelog ==

= 1.1.0 =
* Added Settings, History, dashboard widget, admin-bar badge, scheduled scans, email reports, Site Health integration, REST API, WP-CLI command, JSON/CSV export, deep-scan mode, uninstall cleanup.

= 1.0.0 =
* Initial release.
