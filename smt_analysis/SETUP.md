# SMT Analytics

A CodeIgniter 4 application for connecting a private Google Sheet or importing the Excel worksheet named exactly **Raw Data CSAT**, with CSAT and DSAT reporting. Both paths ignore every other worksheet and stop if Raw Data CSAT is missing.

## Shopee Food and Marketplace

Run `php -d extension=sqlite3 spark migrate` after updating. The module migration assigns all existing reports to **Shopee Food** and preserves its saved connection. **Marketplace** starts with no URL or reports.

Administrators choose **Shopee Food** or **Marketplace** on the main page to open that module's statistics. Use **Modules** in the sidebar to return to this choice. URL configuration is in **Google Sheets**: use its module switcher to select Shopee Food or Marketplace, edit that module's URL and connection settings, then choose **Save connection**. Each module has its own dashboard, uploads, reports, column mappings, refresh interval, credentials, and sync status. Both use the same Raw Data CSAT import contract, and the same workbook can be imported once into each module.

Existing `/dashboard`, `/admin/sheets`, and `/admin/uploads` links continue to open Shopee Food. Marketplace uses `/marketplace/dashboard`, `/marketplace/admin/sheets`, and `/marketplace/admin/uploads`. These paths are relative to the app's base URL. Users can open either module's dashboard through its direct link and continue to see only their own responses. Login accounts, user administration, and registration eligibility are shared across the application.

## Connect Google Sheets once

1. Run `php -d extension=sqlite3 spark migrate` after updating the app.
2. In Google Cloud Console, create a project, enable **Google Sheets API**, then create a **service account** and its **JSON key**. This is the web app's identity; the spreadsheet may belong to a different email from your GPT or app account.
3. Sign in to the app as an administrator and open **Google Sheets**. Choose **Shopee Food** or **Marketplace** in the module switcher. Paste that module's file URL, choose the refresh interval (15 minutes by default), attach its JSON key, and choose **Save connection**. The existing source URL is prefilled only for Shopee Food. Server administrators can instead set `GOOGLE_SHEETS_CREDENTIALS` for Shopee Food or `MARKETPLACE_GOOGLE_SHEETS_CREDENTIALS` for Marketplace to an absolute path outside `public`; this disables replacement through that module's web form.
4. Open the spreadsheet with its owner account. Share it with the displayed service-account email as **Viewer**. The app uses the read-only Sheets API scope.
5. Choose **Refresh now**. The app selects the worksheet named exactly **Raw Data CSAT** by its name. The pasted URL may point to any tab; its `gid` does not change this selection. There is no fallback to Daily CSAT or another worksheet. The app does not write to the source spreadsheet.

Each module's service-account key is stored separately under `writable/private`, outside the public directory and ignored by Git. Shopee Food retains `google-service-account.json`. Only administrators can save connection settings, replace a key, or force a refresh. Configure and share the spreadsheet for each module independently. ChatGPT's Google Drive connection is not required for web app syncing.

To switch files later, edit **Google Sheet URL** and save. The replacement file must also contain **Raw Data CSAT**. Share a new private file with the same service-account email. The last successful report stays available until the replacement source passes validation and syncs. The optional exact **Reason column**, **Customer comment column**, and **Case or ticket ID column** mappings select fields within Raw Data CSAT. Recorded DSAT reasons and customer comments stay separate; the app does not invent a cause from a comment or issue category.

Each successful sync updates one managed report to match **Raw Data CSAT**. Keep historical rows in that worksheet for weekly and monthly trends; this connection does not combine other worksheets or previously removed source rows. Exact duplicate CSAT IDs with identical data are skipped and counted; conflicting repeated IDs stop the sync with an actionable error. The source sheet is never deleted or edited. Negative ratings count as DSAT, with each original reason and customer comment shown separately. Daily, Monday–Sunday weekly, and calendar monthly views use the same date/team/channel/agent/inclusion filters. Reason shares use all matching DSATs, including “Reason not provided”. Rates are calculated from counts, not averaged percentages. An enabled connection restores its managed report if that report was archived; pause the connection before archiving it to keep it archived.

The dashboard checks for due updates when opened and every minute while visible. Network reads occur only when the configured interval is due. For unattended syncing while the dashboard is closed, install the included Windows task once:

```powershell
.\deploy\install-sheet-sync-task.ps1
```

This runs `spark csat:sync` once a minute with `php-win.exe`, the windowless PHP CLI beside `php.exe`; the command checks both modules independently and refreshes each configured connection when due. A failure in one module does not prevent checking the other. No PowerShell or console window opens for scheduled checks. The task runs with the current user's normal privileges while that user is signed in. Keep the computer awake and connected. Run the installer again after updating an older PowerShell-based task or moving the app or PHP installation. Use `deploy/sync-sheets.ps1` for a manual run with visible output. To remove the task, run `Unregister-ScheduledTask -TaskName 'Summit Analytics Google Sheets Sync' -Confirm:$false`.

On another server, schedule `php -d memory_limit=768M spark csat:sync` every minute with the app directory as working directory. Use `php -d memory_limit=768M spark csat:sync --force` for a manual command-line refresh. Pausing the connection prevents both scheduled and forced syncs. A file lock prevents concurrent refreshes; failed requests retry after at least a minute and preserve the last successful report.

Google references: [service accounts and sharing](https://developers.google.com/workspace/guides/create-credentials), [read-only Sheets scopes](https://developers.google.com/workspace/sheets/api/scopes).

On supported Windows PHP/cURL builds, the Google Sheets client uses the Windows native certificate store while retaining TLS peer and hostname checks. Other servers use their configured system or PHP CA bundle. A certificate, DNS, or network error occurs before Google can check sheet permissions; HTTP 403/404 instead gives the Viewer-sharing guidance. See [curl native CA support](https://curl.se/libcurl/c/CURLOPT_SSL_OPTIONS.html).

## Apache access on this computer and the local network

XAMPP Apache is configured to serve this workspace's `public` folder at:

- This computer: **http://localhost/smt_analysis/**
- Other devices on the same network: **http://192.168.1.5/smt_analysis/**

Keep Apache running in XAMPP Control Panel. No `php spark serve` or terminal server is needed. Start Apache again after a reboot, or install its Windows service using XAMPP Control Panel as administrator for automatic startup.

The installed Apache include is `C:/xampp/apache/conf/extra/httpd-smt.conf`; its reference copy is `deploy/apache-smt.conf`. This app uses the existing PHP 8.4 CGI executable because XAMPP's PHP 8.2 is too old for the installed dependencies. `deploy/php.ini` supplies the required PHP settings. Other XAMPP apps continue using their current handler.

For LAN access, use a trusted Windows **Private** network. Run `deploy/allow-lan.ps1` as administrator to allow Apache on TCP port 80 from the local subnet. Devices must be on the same Wi-Fi/LAN without client isolation. No router port forwarding is needed. If your PC's IP changes, update `app/Config/App.php`'s `allowedHostnames` with the new IP and share the new URL; a DHCP reservation keeps the address stable. All users access the same database and sign in with their own accounts.

The configurations contain absolute paths for this computer. Update them and Apache's Include path if moving the project. Only the public folder is exposed.

## Optional development server

From the outer project folder, run:

```powershell
.\smt_analysis\start.ps1
```

The script creates the SQLite database, runs migrations, and prompts you to create the first account. Choose **admin**. Use your own email and a password of at least 12 characters. The CLI password prompt is visible, so run it in a private terminal.

Then open **http://localhost:8080**, sign in, choose a module, and choose **Upload reports**. Select your master Excel workbook. Only `Raw Data CSAT` is imported; every other worksheet is ignored. Stop the local server with Ctrl+C.

The script enables the existing SQLite PHP extension for this process only and configures 128 MB uploads, 130 MB requests, 768 MB memory, and a 300-second execution limit. It does not edit the system's PHP configuration. The upload page shows the effective limit of the running server.

## Features and access

| Feature | Admin | User |
| --- | --- | --- |
| View reports, charts, filters, and agent totals | Yes | Yes |
| Upload `.xlsx` files | Yes | No |
| Create admin/user accounts and deactivate other accounts | Yes | No |

Passwords are hashed. Login attempts are throttled. All form submissions have CSRF protection; access restrictions are enforced on the server. Inactive accounts lose access on their next request. Report pages are not cached.

Users can choose **Create your account** on the sign-in page. Eligibility comes from agent identities in imported source records. Valid email addresses are trimmed and lowercased. Repeated report rows create only one roster identity. Blank email values do not grant eligibility. Administrators can create accounts through **Manage users** when the source has no eligible email identity.

Registration creates an account directly using the sheet email and a confirmed password of 12–72 bytes. Email verification and SMTP configuration are not required. Self-created accounts always have the **user** role. Existing accounts, including inactive ones, cannot register again. Emails are normalized before insertion, and the database unique email constraint prevents duplicate accounts from simultaneous requests. Registration requests are rate limited and protected by CSRF.

The roster is independent of reports, so archiving or deleting a report does not erase eligibility. Administrators should deactivate accounts to revoke access. Without email verification, knowing an eligible email is enough to register it; email ownership is not checked.

Run `php spark migrate` when updating an existing installation. The registration migration builds the roster from valid agent names/emails in previously imported raw rows. New imports keep it updated. When the same valid email has multiple source name variants, the latest dated response determines the roster display name; each response retains its original source name. Administrators can still create accounts through **Manage users**, or `php spark csat:user`. Password reset is not included; users needing recovery should contact their administrator.

## MySQL / phpMyAdmin setup

1. Import **`database/mysql.sql`** in phpMyAdmin or MySQL Workbench. It creates the `smt_analysis` database and the three application tables below. It does not create any accounts or insert workbook data.
2. Copy **`.env.mysql.example`** to **`.env`** in the folder containing `spark`. Set `database.default.username` and `database.default.password` to your actual MySQL credentials. Keep `database.default.DBDriver = MySQLi`.
3. Run `php spark migrate`, then `php spark csat:user` and choose `admin`. The migration recognizes the tables created by the supplied SQL file and adds CodeIgniter's migration tracking table.
4. Run `start.ps1` and open http://localhost:8080. The configured MySQL connection takes precedence over the SQLite fallback.

Alternatively, create an empty MySQL database, configure `.env`, and let `php spark migrate` create all tables; the SQL import is optional.

| Table | Purpose |
| --- | --- |
| `users` | Login identity, hashed password, admin/user role, active status |
| `imports` | One module's report snapshot: module, filename, file hash, uploader, dates, row count |
| `sheet_sources` | Separate Shopee Food and Marketplace connection settings and sync status |
| `responses` | Validated source responses, normalized Good/Bad flags, date, team, agent, channel, raw worksheet values |
| `migrations` | Created and maintained by CodeIgniter to track schema versions |

Relationships: one user uploads many imports; one import owns many responses. CSAT IDs are unique per import, and file hashes prevent duplicate uploads within each module. The binary UTF-8 collation preserves case distinctions in the source's team names. SQL and migrations use InnoDB-compatible foreign keys; do not combine this schema with unrelated existing tables of the same names.

## Import contract

- Workbook format: `.xlsx`, up to **128 MiB** compressed. The upload page shows the effective file limit set by the app and the running server.
- Worksheet name: exactly **Raw Data CSAT**, including case and spaces. Excel uploads and Google Sheets connections use the same fixed name, with no fallback to any other worksheet.
- Source headings are in row 1. The master workbook uses the following fields; its calculated Good/Bad values and submission date must have saved results.
- Required source headings: `CSAT Submit Date`, `agent_owner_team`, `Good`, `Bad`, `agent_name`, `csat_id`, `csat_submission_channel_name`, and `is_excluded`. Prepared `Date` and `Team` headings are accepted when their corresponding raw headings are absent; raw headings take precedence when both are present.
- All other worksheets are ignored. The original workbook is never changed.
- Limits: **100,000 business response rows**, **200,000 physical worksheet rows**, and **100 columns**. Blank template rows and trailing cells beyond the named source table are skipped.
- The selected worksheet XML may expand to **512 MiB**, shared strings to **32 MiB**, and the workbook metadata and relationship parts to **1 MiB each**. Other worksheets' expanded sizes are not added to the selected worksheet limit. The importer streams the named worksheet and does not load other worksheet contents.
- Source records are normalized into response dates, Good/Bad ratings, agent identities, teams, channels, and the recorded DSAT details used by the dashboard.
- Identifiers are stored as strings; IDs already rounded by Excel cannot be recovered.
- Identical records with the same CSAT ID count once, and the upload confirmation reports the number skipped. Conflicting records with the same ID reject the import without a partial snapshot.
- Missing DSAT reasons display **Reason not provided**. Customer comments stay separate from recorded reasons.
- Formulas use their saved Excel result. The importer never evaluates cross-sheet formulas. Recalculate and save the workbook in Excel first if a required formula has no cached value.
- Each successful upload creates an independent report snapshot in the selected module. Identical files are rejected within that module. Overlapping reports can coexist, but the dashboard selects **one snapshot at a time** and never adds them together.
- Failed imports leave no partial report. The original workbook is not retained; only data from `Raw Data CSAT` and import metadata are saved outside the public directory. Existing historical snapshots retain their original source data and are not relabelled as Raw Data CSAT imports.

| Master source field | Meaning |
| --- | --- |
| `CSAT Submit Date`, `csat_submit_timestamp` | Customer rating submission date and timestamp |
| `Good`, `Bad`, `csat_level` | Calculated rating flags and source rating |
| `agent_name`, `agent_email`, `agent_owner_team` | Agent identity and owning team |
| `csat_id`, `case_id` | Survey identity and related case |
| `csat_submission_channel_name` | Rating submission channel |
| `csat_reason` | Recorded customer rating reason |
| `Inclulde in CSAT?` | Master source inclusion flag: 1 = included, 0 = excluded |
| `is_excluded` | Original source field retained for provenance; does not control eligibility when the master inclusion flag is present |
| `handling_duration_secs` | Handling duration when available |

The supplied master source has no dedicated customer comment column. The app displays **No customer comment** when one is absent. If a separate comment field is added, its exact heading can be mapped in the connection settings. A recorded reason is never replaced by an inferred explanation from another field.

The master’s **Inclulde in CSAT?** heading preserves the source spelling. The app uses its inverse for the canonical report exclusion flag. The source excludes `No message sent by user`, `Not answer agent`, `Incomplete Chat`, and `Junk/Spam`. Its original `is_excluded` field remains in the raw record and is not substituted for this inclusion rule. Prepared sources without the master inclusion field use their validated `is_excluded` flag. Unflagged rows remain the default view; **All rows** also includes excluded responses.

The source’s `CSAT Submit Date` is the calendar date of `csat_submit_timestamp` (the workbook formula is `INT` of that timestamp). `Good` and `Bad` use the source’s saved calculated rating results; the app does not use the workbook’s month or week summary worksheets. Optional invalid handling durations become unavailable while the original source value is retained; they do not change rating totals.

The source defines these `csat_reason` codes:

| Code | Recorded reason label |
| --- | --- |
| 1 | Long Resolution Time |
| 2 | Agent attitude can be improved |
| 3 | Company Policy can be improved |
| 4 | Courier can be improved |

Comma-separated codes represent multiple selected reasons. Each category counts at most once per DSAT. Reason shares use all matching DSAT responses as their denominator, so shares can add to more than 100%. Unknown source values remain as recorded instead of receiving an invented label. A custom reason-column mapping remains available.

## Score and charts

Use the light/dark toggle in the workspace header (top-right on sign-in pages) to switch appearance. The initial theme follows your device's appearance; manual choices are remembered and synchronized across browser tabs. Dark mode uses charcoal surfaces and the orange accent `#EE4D2D`. Charts follow the theme while Good and Bad ratings keep distinct green and coral colors.

Both Shopee Food and Marketplace dashboards open on **Statistics**, which shows the charts. **DSAT feedback** and **Agent performance** have separate tabs that share the selected report and filters. Detailed tables scroll within a bounded area with sticky column headers. Choose 10, 25, 50, or 100 rows per page; the defaults are 50 DSAT responses and 25 agent/team combinations.

Quick date ranges show the entire report or its last 7, 30, or 90 days, ending on the report's latest date. Applied filter chips show what contributes to the current results and can be removed individually. Agent search updates scores and the selected tab's results as you type; press `/` to focus search or use the clear button to show all agents. Date ranges are checked before applying. **Copy view link** copies the current module, report, tab, and applied filters from the browser address; apply pending filter changes first. On smaller screens, administrators can use **Menu** to open workspace navigation.

The report uploader accepts a single workbook through the file picker or drag and drop. It previews the filename and size, supports clearing the selection, and reports format or file-size problems before submission. Server-side workbook validation still runs on every import.

Agent search accepts names or email addresses. Name matching supports case-insensitive partial names, accents, words in any order, and small spelling mistakes (one edit for words of 4–6 characters, two for longer words). All entered words must match. Name suggestions appear as you type; press Enter to search all similar names, or select a suggestion with the mouse or arrow keys and Enter. Queries containing `@` use literal, case-insensitive email matching without name suggestions. Suggestions reflect the last applied report/date/team/channel/inclusion filters. Apply filters after changing those settings to refresh available agents. The results show the number of matching identities, and all scores and charts use the same matches.

Each detailed table also has its own controls. **Agent performance** can narrow results by minimum response volume or maximum CSAT and sort by responses, CSAT, DSAT, or name. **DSAT feedback** can search the recorded feedback and identifiers, select a reason, filter by whether a customer comment exists, and sort by date, agent, or reason. Apply these controls to update the table and its pagination. They preserve the shared report and filters and do not change the overall score cards, charts, DSAT totals, or reason shares.

**Quick views** open the lowest CSAT, CSAT at or below 80% with at least 10 responses, the most DSATs, or the latest feedback. The threshold is shown explicitly and can be changed in the agent table controls. Click sortable column headings to change order, or a ranked DSAT reason to inspect matching feedback. Sorting and table filters apply across all matching records before pagination.

**CSAT = sum(Good) / (sum(Good) + sum(Bad)) × 100**, displayed to two decimal places. No matching responses displays an unavailable score rather than 0%.

Charts show daily, weekly, or monthly CSAT, Good/Bad mix, CSAT for the 12 largest teams by response count, and response counts by channel. Team names remain as supplied; case variants are not merged. Agent performance groups by agent name and team. Date, team, channel, agent search, and row-inclusion filters affect all displayed results.

Agents see their **top four DSAT reasons** with response counts, percentage shares, and horizontal bars on their Statistics and Agent performance views. Individual agent pages include the same reason ranking, CSAT trend, Good/Bad mix, response volume over time, and feedback by channel. Click a ranked reason to inspect its feedback. Missing reasons are shown as **Reason not provided**, and multi-reason feedback counts once in each selected category. Performance charts and reason totals follow the report filters; feedback table searches do not change the rankings.

The administrator's Agent performance table also shows each agent/team combination's top four reasons with counts and a CSAT meter. Comparison charts show the first 12 combinations on the current page in the chosen table order, using the same table filters. Change the sort or page to compare another set of agents.

Trend charts use the dates in the selected snapshot. Separate workbook uploads are not automatically combined into a historical series.

## Manual setup / another server

Requires PHP 8.2+ with the extensions required by Composer, plus SQLite3 for the default database. The existing installation uses PHP 8.4. Chart.js 4.5.1 is included in `public/assets/vendor`; no CDN or Node build is required to use the app.

From the inner application folder:

```powershell
composer install
php -d extension=sqlite3 spark migrate
php -d extension=sqlite3 spark csat:user
```

Copy `.env.example` to `.env` to customize the URL or use a MySQL database. MySQL settings are documented in that file; create an empty database before running migrations. SQLite and MySQL-compatible migrations are supplied; runtime verification was performed using SQLite.

On a deployed server, set its document root to **public**, enable HTTPS, set `app.baseURL`, and configure secure cookies. Set PHP's `upload_max_filesize=128M`, `post_max_size=130M`, `memory_limit=768M`, and `max_execution_time=300`, as supplied in `deploy/php.ini`, for large master workbooks. Restart the PHP/Apache service after changing its configuration. Keep `writable` private and back up the database. The PowerShell runner and PHP built-in server are for local development.

## Verification

Run from the inner application folder:

```powershell
php -d extension=sqlite3 -d memory_limit=512M vendor/phpunit/phpunit/phpunit --no-coverage
node --test tests/theme.test.cjs tests/dashboard.test.cjs tests/dashboard-qol.test.cjs tests/agent-search.test.cjs tests/sheets-sync.test.cjs
```

The tests cover exact sheet selection, long identifiers, missing columns, invalid ratings/dates, duplicate IDs, row limits, weighted CSAT, filters, and authorization.

For browser checks of themed form controls, run `node tests/form-controls.browser.cjs` with Playwright available and Chrome installed. Set `PLAYWRIGHT_MODULE` to an absolute package path if it is outside the normal Node module search path; `BROWSER_CHANNEL` can select another installed Playwright browser channel. These checks use an isolated HTML fixture with the app’s real assets, covering keyboard navigation, search, date boundaries, form validation, dynamic updates, and narrow screens without accessing the app database.

The optional `tests/smoke_http.py` checks the real HTTP upload/login flow against a **separate** `writable/csat-smoke.sqlite` database. The `CsatSmokeSeeder` refuses to seed any other database. Its example.test accounts are testing fixtures, not application credentials. Do not expose that test database or use its accounts in production.

Implementation reference: [Chart.js integration](https://www.chartjs.org/docs/latest/getting-started/integration).

