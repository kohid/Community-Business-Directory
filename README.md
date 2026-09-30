# Community Business Directory

A WordPress plugin that turns a site into a full business directory: member listings, events, promotions, jobs, reviews, reactions, social sign-in, an admin panel, and REST/AJAX APIs.

- **Text domain / prefix:** `community-business-directory` / `cbd`
- **Requires:** WordPress 6.4+, PHP 8.0+
- **Licence:** GPL v2 or later

## Features

- **Business listings** with categories, locations, galleries, opening details, social links and a public profile page
- **Events, promotions and jobs** with their own listing and profile pages
- **Reviews, reactions, follows and favourites**
- **Branded registration and login** (email plus social sign-in), email verification, delegate invites and team invites
- **Plans** for tiered listings
- **Account menu** that lets owners manage their own listing from modals on their public business page
- **Activity feed, calendar, map, search and featured businesses** widgets
- **Facebook, Instagram and Love Inverness (Loqiva) sync** modules
- **Gutenberg blocks**, REST API (`cbd/v1`) and AJAX endpoints
- **SEO:** schema markup for listings

## Installation

1. Copy this folder to `wp-content/plugins/community-business-directory/`.
2. In WordPress, go to **Plugins** and activate **Community Business Directory**.
3. Activation creates the custom database tables, roles and default pages. Re-activate the plugin after changing roles or pages.
4. Visit **Settings → Permalinks** and click **Save** once to refresh the rewrite rules.

There is no build step. The plugin has no Composer or npm dependencies; PHP, CSS and JS are loaded directly by WordPress.

## Shortcodes

| Shortcode | Purpose |
|---|---|
| `[cbd_directory]` | Business directory with filters |
| `[cbd_featured_businesses]` | Featured listings |
| `[cbd_business_profile]` | Single business profile |
| `[cbd_register_business]` | Business registration form |
| `[cbd_login]`, `[cbd_signup]`, `[cbd_set_password]` | Authentication |
| `[cbd_account_menu]` | Account menu |
| `[cbd_plans]` | Plans and pricing |
| `[cbd_events]`, `[cbd_event_profile]`, `[cbd_calendar]` | Events |
| `[cbd_promotions]`, `[cbd_promotion_profile]` | Promotions |
| `[cbd_jobs]`, `[cbd_job_profile]` | Jobs |
| `[cbd_reviews]` | Reviews |
| `[cbd_search]`, `[cbd_business_map]` | Search and map |
| `[cbd_activity_feed]`, `[cbd_business_feed]`, `[cbd_gallery]` | Feeds and galleries |
| `[cbd_facebook_page]`, `[cbd_instagram_feed]`, `[cbd_social_sync]` | Social content |

## Configuration

### Loqiva (Love Inverness) feeds

Feed URLs contain private access tokens, so they are **not** stored in the code. After activating the plugin:

1. Go to **Community Directory → Love Inverness**.
2. Paste the Business Profiles, Offers and Events feed URLs supplied by InvernessBID.
3. Click **Save Feed URLs**. They are stored in the `cbd_loqiva_url_*` options in the database.

Until the URLs are saved, the Loqiva sync does nothing.

### Diagnostics

**Community Directory → Diagnostics** shows the plugin's status. To debug problems, turn on `WP_DEBUG` and `WP_DEBUG_LOG`. Startup errors are caught and shown as an admin notice rather than crashing the site.

## Project layout

```
community-business-directory.php   Bootstrap, constants, global cbd_*() helpers, autoloader
includes/
  Core/          Plugin singleton, hook Loader, Activator/Deactivator, AdminGuard
  PostTypes/     Business, Event, Promotion, Job, Review, BusinessPost
  Taxonomies/    Category, Location
  Frontend/      Shortcodes, AJAX handlers, auth, reactions, templates loader, blocks
  Admin/         Admin panel and team invites
  API/           REST endpoints (namespace cbd/v1)
  Modules/       Facebook, Instagram, Loqiva sync, notifications, demo data
  SEO/           Schema markup
templates/       Archive, single and taxonomy templates
assets/          CSS, JS and SVG icons
```

Classes follow a PSR-4-style layout: `CBD\Foo\Bar` loads from `includes/Foo/Bar.php`.

## Development notes

- Changing the database schema: edit `Activator::create_tables()` and bump `CBD_DB_VERSION`. The upgrade runs automatically on the next page load.
- Adding a custom post type or rewrite: bump `CBD_VERSION` so rewrite rules are flushed.
- Frontend assets are cache-busted with `filemtime()`, so edits show immediately.
- Never commit API tokens, feed URLs with tokens, or `.claude/settings.local.json`.
