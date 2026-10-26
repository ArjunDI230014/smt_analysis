# SMT Analytics — CSAT and DSAT

The application includes administrator/agent access, a private Google Sheets connection with automatic refresh, Excel imports from **Raw Data CSAT**, and daily, weekly, and monthly CSAT/DSAT dashboards. Both import paths require that exact worksheet name and ignore all other worksheets, even when a Google Sheets URL points to another tab. Agents can see each recorded DSAT reason and customer comment. Connected-sheet refreshes remove exact duplicate entries and preserve the last report on conflicts or failures. Flagged rows are excluded by default.

Administrators choose **Shopee Food** or **Marketplace** on the main page to open its statistics. Manage each module's URL in **Google Sheets**, using the module switcher to select its connection settings. Each module has its own service-account key, reports, and sync status. Existing URLs and reports remain in Shopee Food; Marketplace starts empty. Run `php spark migrate` after updating. User accounts remain shared across both modules.

Master workbooks up to **128 MB** are supported. Identical survey IDs count once; the source’s **Inclulde in CSAT?** flag controls eligibility. Recorded reason codes are decoded to their source labels, including multiple reasons per DSAT. Missing reasons and customer comments remain explicitly unavailable.

Dropdowns and calendar popups follow the light or dark appearance throughout the app. Long dropdowns include option search; calendars support arrow keys, Page Up/Down, Today, and Clear while keeping dates editable by hand. Use the appearance button to switch between light and dark mode.

**Manage users** shows workspace account totals and a searchable team directory with role and status filters. Add an account or choose **Manage** to edit a member’s name, email, and role, reset their password, or change their access. Archive and permanent deletion require confirmation; your own administrator access is protected. The directory and account dialogs support mobile screens and both appearances.

See **[SETUP.md](SETUP.md)** for startup, account creation, upload requirements, and tests. Use XAMPP Apache at **http://localhost/smt_analysis/**, or **http://192.168.1.5/smt_analysis/** from another device on your network. See SETUP.md for firewall and startup details.

---

## Original CodeIgniter starter information

## What is CodeIgniter?

CodeIgniter is a PHP full-stack web framework that is light, fast, flexible and secure.
More information can be found at the [official site](https://codeigniter.com).

This repository holds a composer-installable app starter.
It has been built from the
[development repository](https://github.com/codeigniter4/CodeIgniter4).

More information about the plans for version 4 can be found in [CodeIgniter 4](https://forum.codeigniter.com/forumdisplay.php?fid=28) on the forums.

You can read the [user guide](https://codeigniter.com/user_guide/)
corresponding to the latest version of the framework.

## Installation & updates

`composer create-project codeigniter4/appstarter` then `composer update` whenever
there is a new release of the framework.

When updating, check the release notes to see if there are any changes you might need to apply
to your `app` folder. The affected files can be copied or merged from
`vendor/codeigniter4/framework/app`.

## Setup

Copy `env` to `.env` and tailor for your app, specifically the baseURL
and any database settings.

## Important Change with index.php

`index.php` is no longer in the root of the project! It has been moved inside the *public* folder,
for better security and separation of components.

This means that you should configure your web server to "point" to your project's *public* folder, and
not to the project root. A better practice would be to configure a virtual host to point there. A poor practice would be to point your web server to the project root and expect to enter *public/...*, as the rest of your logic and the
framework are exposed.

**Please** read the user guide for a better explanation of how CI4 works!

## Repository Management

We use GitHub issues, in our main repository, to track **BUGS** and to track approved **DEVELOPMENT** work packages.
We use our [forum](http://forum.codeigniter.com) to provide SUPPORT and to discuss
FEATURE REQUESTS.

This repository is a "distribution" one, built by our release preparation script.
Problems with it can be raised on our forum, or as issues in the main repository.

## Server Requirements

PHP version 8.2 or higher is required, with the following extensions installed:

- [intl](http://php.net/manual/en/intl.requirements.php)
- [mbstring](http://php.net/manual/en/mbstring.installation.php)

> [!WARNING]
> - The end of life date for PHP 7.4 was November 28, 2022.
> - The end of life date for PHP 8.0 was November 26, 2023.
> - The end of life date for PHP 8.1 was December 31, 2025.
> - If you are still using below PHP 8.2, you should upgrade immediately.
> - The end of life date for PHP 8.2 will be December 31, 2026.

Additionally, make sure that the following extensions are enabled in your PHP:

- json (enabled by default - don't turn it off)
- [mysqlnd](http://php.net/manual/en/mysqlnd.install.php) if you plan to use MySQL
- [libcurl](http://php.net/manual/en/curl.requirements.php) if you plan to use the HTTP\CURLRequest library

