# SMT Analytics

A CodeIgniter 4 application for importing **only the worksheet named `Daily CSAT`** and exploring customer satisfaction with locally bundled Chart.js charts.

## Start on this Windows computer

From the outer project folder, run:

```powershell
.\smt_analysis\start.ps1
```

The script creates the SQLite database, runs migrations, and prompts you to create the first account. Choose **admin**. Use your own email and a password of at least 12 characters. The CLI password prompt is visible, so run it in a private terminal.

Then open **http://localhost:8080**, sign in, and choose **Upload reports**. Select your Excel file. Only `Daily CSAT` is imported. Stop the local server with Ctrl+C.

The script enables the existing SQLite PHP extension for this process only and configures a 20 MB upload limit, 512 MB memory limit, and 120-second execution limit. It does not edit the system's PHP configuration.

## Features and access

| Feature | Admin | User |
| --- | --- | --- |
| View reports, charts, filters, and agent totals | Yes | Yes |
| Upload `.xlsx` files | Yes | No |
| Create admin/user accounts and deactivate other accounts | Yes | No |

Passwords are hashed. Login attempts are throttled. All form submissions have CSRF protection; access restrictions are enforced on the server. Inactive accounts lose access on their next request. Report pages are not cached.

Users can choose **Create your account** on the sign-in page. Eligibility comes from the agent email in imported Daily CSAT rows (`agent_email`, or `email` when `agent_email` is absent). Emails are trimmed and lowercased; the account name comes from `agent_name`. Repeated report rows create only one roster identity. Blank email values do not grant eligibility. Reports without these optional email columns still import for analytics.

Registration creates an account directly using the sheet email and a confirmed password of 12–72 bytes. Email verification and SMTP configuration are not required. Self-created accounts always have the **user** role. Existing accounts, including inactive ones, cannot register again. Emails are normalized before insertion, and the database unique email constraint prevents duplicate accounts from simultaneous requests. Registration requests are rate limited and protected by CSRF.

The roster is independent of reports, so archiving or deleting a report does not erase eligibility. Administrators should deactivate accounts to revoke access. Without email verification, knowing an eligible email is enough to register it; email ownership is not checked.

Run `php spark migrate` when updating an existing installation. The registration migration builds the roster from valid agent names/emails in previously imported raw rows. New imports keep it updated and reject conflicting names for the same email within a workbook. Administrators can still create accounts through **Manage users**, or `php spark csat:user`. Password reset is not included; users needing recovery should contact their administrator.

## MySQL / phpMyAdmin setup

1. Import **`database/mysql.sql`** in phpMyAdmin or MySQL Workbench. It creates the `smt_analysis` database and the three application tables below. It does not create any accounts or insert workbook data.
2. Copy **`.env.mysql.example`** to **`.env`** in the folder containing `spark`. Set `database.default.username` and `database.default.password` to your actual MySQL credentials. Keep `database.default.DBDriver = MySQLi`.
3. Run `php spark migrate`, then `php spark csat:user` and choose `admin`. The migration recognizes the tables created by the supplied SQL file and adds CodeIgniter's migration tracking table.
4. Run `start.ps1` and open http://localhost:8080. The configured MySQL connection takes precedence over the SQLite fallback.

Alternatively, create an empty MySQL database, configure `.env`, and let `php spark migrate` create all tables; the SQL import is optional.

| Table | Purpose |
| --- | --- |
| `users` | Login identity, hashed password, admin/user role, active status |
| `imports` | One uploaded report snapshot: filename, file hash, uploader, dates, row count |
| `responses` | Validated Daily CSAT rows, Good/Bad flags, date, team, agent, channel, raw worksheet values |
| `migrations` | Created and maintained by CodeIgniter to track schema versions |

Relationships: one user uploads many imports; one import owns many responses. CSAT IDs are unique per import, and file hashes prevent duplicate uploads. The binary UTF-8 collation preserves case distinctions in the source's team names. SQL and migrations use InnoDB-compatible foreign keys; do not combine this schema with unrelated existing tables of the same names.

## Import contract

- Workbook format: `.xlsx`, maximum 20 MB, maximum expanded ZIP size 100 MB.
- Worksheet name: exactly **Daily CSAT**, including case and space.
- Column headings must appear in row 1. Columns may be reordered.
- Required columns: `Date`, `Team`, `Good`, `Bad`, `agent_name`, `csat_id`, `csat_submission_channel_name`, `is_excluded`.
- Each nonempty row must contain exactly one Good/Bad response: `1/0` or `0/1`.
- `is_excluded` must be 0 or 1. **Flagged rows are excluded by default** (only `is_excluded = 0` contributes to the dashboard). The dashboard can separately show unflagged or flagged rows.
- Date accepts Excel serial dates, `YYYY-MM-DD`, `DD/MM/YYYY`, or `DD Mon YYYY`.
- `csat_id` must be present and unique within the worksheet. Identifiers are stored as strings; IDs already rounded by Excel cannot be recovered.
- `l1_name` and `handling_duration_secs` are optional. Other columns from this worksheet are preserved in each response's raw JSON, within a 60 KB row limit.
- Maximum 15,000 response rows / 100 columns. Blank rows are skipped; invalid nonempty rows reject the entire import.
- Formulas use their saved Excel result. The importer never evaluates cross-sheet formulas. Recalculate and save the workbook in Excel first if a formula has no cached value.
- Each successful upload creates an independent report snapshot. Identical files are rejected. Overlapping reports can coexist, but the dashboard selects **one snapshot at a time** and never adds them together.
- Validation happens before a database transaction. Failed imports leave no partial report. The original workbook is not retained; only data from `Daily CSAT` and import metadata are saved outside the public directory.

## Score and charts

Use the bottom-right Dark mode / Light mode toggle to change appearance. The browser remembers your choice, and charts follow the selected theme.

Agent search accepts case-insensitive partial names, accents, words in any order, and small spelling mistakes (one edit for words of 4–6 characters, two for longer words). All entered words must match. Suggestions appear as you type; press Enter to search all similar names, or select a suggestion with the mouse or arrow keys and Enter. Suggestions reflect the last applied report/date/team/channel/inclusion filters. Apply filters after changing those settings to refresh available agents. The results show the number of matching names, and all scores and charts use the same matches.

**CSAT = sum(Good) / (sum(Good) + sum(Bad)) × 100**, displayed to two decimal places. No matching responses displays an unavailable score rather than 0%.

Charts show daily CSAT, Good/Bad mix, CSAT for the 12 largest teams by response count, and response counts by **CSAT submission channel**. Team names remain as supplied; case variants are not merged. Agent performance groups by agent name and team, paginated in groups of 25. Date, team, channel, agent search, and row-inclusion filters affect all displayed results.

The supplied sample contains a single reporting date (6 September 2026), so the trend chart has one point. A multi-date worksheet will show multiple points. This version does not automatically combine daily uploads into a historical series.

## Manual setup / another server

Requires PHP 8.2+ with the extensions required by Composer, plus SQLite3 for the default database. The existing installation uses PHP 8.4. Chart.js 4.5.1 is included in `public/assets/vendor`; no CDN or Node build is required to use the app.

From the inner application folder:

```powershell
composer install
php -d extension=sqlite3 spark migrate
php -d extension=sqlite3 spark csat:user
```

Copy `.env.example` to `.env` to customize the URL or use a MySQL database. MySQL settings are documented in that file; create an empty database before running migrations. SQLite and MySQL-compatible migrations are supplied; runtime verification was performed using SQLite.

On a deployed server, set its document root to **public**, enable HTTPS, set `app.baseURL`, and configure secure cookies. Set PHP's `upload_max_filesize=20M`, `post_max_size=22M`, `memory_limit=512M`, and `max_execution_time=120`. Keep `writable` private and back up the database. The PowerShell runner and PHP built-in server are for local development.

## Verification

Run from the inner application folder:

```powershell
php -d extension=sqlite3 -d memory_limit=512M vendor/phpunit/phpunit/phpunit --no-coverage
```

The tests cover exact sheet selection, long identifiers, missing columns, invalid ratings/dates, duplicate IDs, row limits, weighted CSAT, filters, and authorization.

The optional `tests/smoke_http.py` checks the real HTTP upload/login flow against a **separate** `writable/csat-smoke.sqlite` database. The `CsatSmokeSeeder` refuses to seed any other database. Its example.test accounts are testing fixtures, not application credentials. Do not expose that test database or use its accounts in production.

The provided workbook was verified through the HTTP uploader in the isolated test database: **4,786 responses, 3,605 Good, 1,181 Bad, 75.32% CSAT** with all rows included. It was not modified or bundled into the repository.

Implementation references: [PhpSpreadsheet worksheet selection](https://phpspreadsheet.readthedocs.io/en/latest/topics/reading-files/#reading-only-named-worksheets-from-a-file) and [Chart.js integration](https://www.chartjs.org/docs/latest/getting-started/integration).
