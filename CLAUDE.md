# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`community-business-directory` (text domain / prefix `cbd`, namespace `CBD\`) is a self-contained WordPress plugin: a business directory with listings, events, promotions, reviews, reactions, follows/favorites, branded auth (email + social), an admin panel, REST + AJAX APIs, and Gutenberg blocks. UK-focused defaults (GBP / £ / country `GB`). Runs inside a Local (Flywheel) WordPress site at `app/public`. There is **no standalone owner dashboard anymore** — owners self-manage their listing from modals opened on their own public business page via the account menu (`cbd_login_redirect()` sends them there).

## Commands & workflow

There is **no build step, no package manager, and no test suite** — no `composer.json`, `package.json`, `phpunit`, or linter config exists. PHP/CSS/JS are edited and loaded directly by WordPress.

- **To apply schema changes:** edit `Activator::create_tables()` then bump `CBD_DB_VERSION` in `community-business-directory.php`. `Activator::maybe_upgrade()` is called from `Plugin::init()` on every load and re-runs `dbDelta` whenever the stored `cbd_db_version` option lags the constant — no manual reactivation needed. (Note: `maybe_upgrade()` only calls `create_tables()`, not `add_roles()` / `seed_plans()` / `ensure_plugin_pages()`; for role or page changes, deactivate + reactivate.)
- **Rewrite rules self-heal:** `Plugin::maybe_flush_rewrites()` runs `flush_rewrite_rules( false )` once per `CBD_VERSION` change (option `cbd_rewrite_version`). Bump `CBD_VERSION` when you add a CPT/taxonomy/rewrite to clear stale `/directory/<slug>/` 404s.
- **Asset cache-busting is automatic.** Frontend CSS/JS are versioned off `filemtime()` in `Plugin::enqueue_frontend()`, so every edit busts the browser cache without touching `CBD_VERSION`.
- **To verify changes:** load the site in a browser. Enable `WP_DEBUG` / `WP_DEBUG_LOG`; fatals during boot are caught by `Plugin::init()`'s try/catch and surfaced as an admin notice + logged to `wp-content/debug.log` (the plugin deliberately never white-screens).
- **Diagnostics:** `wp-admin → Community Directory → Diagnostics`.

## Architecture

**Boot flow** (`community-business-directory.php`): defines `CBD_*` constants, declares a layer of **global `cbd_*()` helper functions**, registers a PSR-4-style autoloader mapping `CBD\Foo\Bar` → `includes/Foo/Bar.php`, then on `plugins_loaded` calls `CBD\Core\Plugin::get_instance()->init()`.

**Global helpers live in the bootstrap file, not a class.** `community-business-directory.php` defines the cross-cutting `cbd_*()` functions every surface calls — the **authorization layer** (`cbd_user_can($post_id, $cap)`, `cbd_user_can_manage_business()`, `cbd_is_business_owner()`, `cbd_delegate_capabilities()`, `cbd_delegate_perms()`), output helpers (`cbd_label()` decode-before-escape, `cbd_icon()` inline-SVG loader, `cbd_card_business_info()`, `cbd_provider_badge()`), gallery (`cbd_gallery_albums()`), and auth/redirect (`cbd_login_redirect()`, `cbd_user_business_url()`, `cbd_user_businesses()`, `cbd_set_password_url()`, `cbd_promote_business_owner()`, `cbd_plans_url()`). All are `function_exists`-guarded. When you need shared logic across shortcodes/AJAX/templates, this is where it goes.

**`Plugin` (singleton) + `Loader`** (`includes/Core/`): `Plugin::init()` is the single registration surface — every feature is wired here. Hooks are **not** added immediately; they are queued via `$this->loader->add_action()/add_filter()` and flushed once by `$loader->run()` at the end of `init()`. When adding a feature, register it inside the relevant `register_*()` helper in `Plugin`, not by calling `add_action` directly elsewhere.

**Hybrid data model — the most important thing to understand.** Each domain object exists as *both* a WordPress custom post type *and* a row in a parallel custom table:

| Post type (`includes/PostTypes/`) | Custom table (created in `Activator`) | Holds |
|---|---|---|
| `cbd_business` | `{prefix}cbd_businesses` | address, geo, plan, status, rating/follower/view counts |
| `cbd_event` | `{prefix}cbd_events` | dates, venue, ticketing |
| `cbd_promotion` | `{prefix}cbd_promotions` | coupon, discount, expiry |
| `cbd_review` | `{prefix}cbd_reviews` | rating, content, moderation status |
| `cbd_business_post` | (post meta `_cbd_post_type` only) | owner news/updates |

Also tables with no post type: `cbd_follows`, `cbd_favorites`, `cbd_analytics`, `cbd_reactions` (one row per `(post_id, user_id)`; reaction type is one of `like|love|haha|wow|sad|angry` — keys defined in `Frontend\Reactions::TYPES`), and `cbd_membership_plans` (subscription tiers seeded by `Activator::seed_plans()`, surfaced by `[cbd_plans]` shortcode).

**Critical join-key quirk:** the custom tables key off the **WordPress post ID**, not their own auto-increment `id`. `cbd_businesses.post_id` is the canonical join key used everywhere. The `business_id` columns in `cbd_events`, `cbd_promotions`, `cbd_reviews`, `cbd_follows`, `cbd_favorites` all store a **business's `post_id`**, not `cbd_businesses.id`. When writing queries, join `cbd_*.post_id` / `cbd_*.business_id` to `{prefix}posts.ID`.

**Three frontend surfaces, one source of logic:**
1. **Shortcodes** (`includes/Frontend/Shortcodes/`) are canonical. Each `*Shortcode::render()` returns an HTML string (built with `ob_start()`), reading structured data from the custom tables. Register new ones in `ShortcodeRegistry::register_all()`.
2. **Gutenberg blocks** (`includes/Frontend/Blocks/BlockRegistry.php`) are auto-generated server-rendered wrappers — each block's `render_callback` just maps block attributes to a `do_shortcode()` call. Add a block by adding a `block-name => shortcode` entry to the `$blocks` map; only attributes in the `$safe` allow-list are forwarded. **No JS-built blocks / block.json exist.**
3. **Single templates** (`templates/single-*.php`) via `TemplateLoader`, which themes can override at `wp-content/themes/<theme>/community-business-directory/single-<posttype>.php`.

So: **to add a directory feature, write the shortcode first** — block and template support flow from there.

**Write paths (two, distinct from the read-only REST API):**
- **Frontend AJAX** (`includes/Frontend/AjaxHandler.php`, ~1600 lines): all the `cbd_*` mutating actions (register business, create event/promotion, submit review, follow, favorite, gallery upload, save delegates/hours, react, login/signup, etc.), registered for both `wp_ajax_` and `wp_ajax_nopriv_` in `Plugin::register_ajax()`. Every handler that mutates calls `$this->verify()` (nonce `cbd_nonce`) then authorizes through the **delegate capability layer** (see below), not raw `post_author` checks: `cbd_user_can( $business_post_id, $cap )` for per-business actions (caps `edit`/`posts`/`events`/`promotions`/`gallery`/`analytics`), `cbd_is_business_owner()` for owner-only actions like delegate management. `cbd_load_directory`, `cbd_live_search`, `cbd_calendar`, and `cbd_get_reactions` are intentionally public/no-nonce reads.
- **Admin panel** (`includes/Admin/AdminController.php`, ~1300 lines): top-level `cbd-dashboard` menu + subpages, all gated on `manage_options`. Renders HTML directly via `echo`, handles its own form posts with `wp_verify_nonce`/`check_admin_referer`. Mutating GET actions are routed through `handle_admin_actions()`.

**Delegate authorization model — how listing access actually works.** A business has one **owner** (the post author) plus zero or more **delegates** (other users granted partial access). Storage is post meta on the business: `_cbd_delegates` (array of user IDs) and `_cbd_delegate_perms` (`[user_id => [cap => 1]]`). The cap keys come from `cbd_delegate_capabilities()`. `cbd_user_can($post_id, $cap)` is the gate: owner/admin → always true; a delegate → limited to granted caps; a **legacy delegate with no stored perm map → full access** (so older data isn't locked out). Site admins (`manage_options`) pass every check. Invites are issued via `Frontend\DelegateInvite` (accepted through `/?cbd_accept_invite=…&cbd_biz=…`). Use these helpers for any new owner-facing surface — do not re-check `post_author` directly.

**Auth & accounts are plugin-owned, not stock WordPress.** Branded shortcode pages replace the WP login/registration/reset screens: `[cbd_login]`, `[cbd_signup]`, `[cbd_set_password]`, `[cbd_register]` (+ `AccountMenuShortcode`). Supporting classes in `Frontend/`: `SocialAuth` (social sign-in; provider stored in `cbd_social_provider` user meta, surfaced by `cbd_provider_badge()`), `EmailVerification` (signup confirmation links + the shared email-logo resolver `EmailVerification::logo_url()`), `DelegateInvite`. New owner accounts start as plain subscribers and are promoted to `cbd_business_owner` only when their listing goes live, via `cbd_promote_business_owner()`.

**wp-admin is locked to the site `admin_email`** by `Core\AdminGuard` (`admin_init` priority 1) — every other logged-in user, `administrator` role included, is bounced to the frontend. The one exception: **team members invited via `Admin\TeamInvite`** (Community Directory → Team). An invite emails a single-use tokenised link (`/?cbd_team_invite=…`, 7-day expiry, mirrors `DelegateInvite`); accepting it grants the `administrator` role plus `cbd_site_team` user-meta = `'1'`, which is what `AdminGuard` checks to wave them into the dashboard. `cbd_login_redirect()` sends `cbd_site_team` users to the front-end **`/socmed`** page instead of wp-admin. Revoke from the Team page (drops the meta + role).

**`[cbd_social_sync]` (`/socmed`)** — `Frontend\Shortcodes\SocialSyncShortcode`, an auto-created page where `cbd_site_team` members (and admins) self-manage the Facebook/Instagram feeds *without* wp-admin: live preview of `[cbd_facebook_page]` + `[cbd_instagram_feed]` on the left, tabbed settings on the right, saved via `wp_ajax_cbd_socmed_save` → `FacebookSync::save()` / `InstagramSync::save()` (the same option-writers the wp-admin pages now call). `Plugin::guard_socmed_page()` (`template_redirect`) bounces everyone else; `Plugin::noindex_socmed_page()` keeps it out of search. The page round-trips the fields it doesn't display as hidden inputs so a team member's save never wipes a wp-admin-only setting.

**Facebook / Instagram feeds** (`Modules\FacebookSync`, `Modules\InstagramSync` — config/data only; markup in `Frontend\Shortcodes\{Facebook,Instagram}Shortcode`). Render modes resolved by `resolve_mode()`: `feed` (native cards from the Graph API — needs a Meta app with `pages_read_engagement`/`instagram_basic`), `embed` (FB's official Page Plugin, ≤500px), `code` (a raw third-party widget snippet — Behold / SnapWidget / Curator / Elfsight — pasted on the admin page in `OPT_EMBED`, stored via `AdminController::sanitize_embed_code()` which keeps raw HTML for `unfiltered_html` users, output verbatim full-width), and IG-only `profile` (Follow card). `auto` prefers feed → code → embed/profile. `GRAPH_VERSION` is also the FB Page Plugin SDK version — keep it current.

**Reactions** (`Frontend/Reactions.php`): per-`(post_id, user_id)` rows in `cbd_reactions`, six types in `Reactions::TYPES` (`like|love|haha|wow|sad|angry`), driven by the `cbd_react` / `cbd_get_reactions` AJAX actions.

**REST API** (`includes/API/`, namespace `cbd/v1`) is **read-only** — `BusinessEndpoints`, `EventEndpoints`, `SearchEndpoints` expose `GET` routes with `permission_callback => __return_true`. All writes go through AJAX or admin, not REST.

**SEO** (`includes/SEO/SchemaMarkup.php`) is hooked on `wp_head` from `Plugin::register_seo()` and emits a `LocalBusiness` JSON-LD blob on `is_singular( 'cbd_business' )`, joining `cbd_businesses.post_id` for address/phone/rating. New structured-data output belongs here.

**Frontend JS contract** (`assets/js/frontend.js`, jQuery): consumes the `cbdData` object localized in `Plugin::enqueue_frontend()` (`restUrl`, `ajaxUrl`, `nonce`, `userId`, `loggedIn`, `currency`, `mapsKey`, `i18n`). AJAX responses use `wp_send_json_success`/`wp_send_json_error` with a `data.message` field the JS surfaces.

**Roles & capabilities** (`Activator::add_roles()`): custom roles `cbd_business_owner` and `cbd_directory_admin` with `cbd_*` caps, plus those caps granted to `administrator`. Note that frontend AJAX handlers authorize through the **delegate model** (`cbd_user_can()` / ownership) rather than these WP caps; admin pages authorize via `manage_options`.

**Icon system** (`cbd_icon( $slug, $class )`): inlines an SVG from `assets/icons/` (Material Design Icons, Apache-2.0) so `currentColor` + CSS sizing work like hand-coded inline icons. Names are sanitized to `[a-z0-9-]` (path-traversal-safe) and results are cached per request; an unknown slug returns `''`. Add an icon by dropping `<slug>.svg` into `assets/icons/`.

## Conventions

- PHP 8.0+, typed signatures, `defined( 'ABSPATH' ) || exit;` at the top of every file.
- All user-facing strings use `__()`/`esc_html_e()` etc. with text domain `community-business-directory`.
- Sanitize on input (`sanitize_text_field`, `wp_kses_post`, `esc_url_raw`…), escape on output (`esc_html`, `esc_attr`, `esc_url`); custom-table queries use `$wpdb->prepare`.
- Domain events are broadcast with `do_action( 'cbd_business_submitted' | 'cbd_business_approved' | 'cbd_business_rejected' | 'cbd_business_updated' )` — hook these for side effects rather than inlining them.
- **CSS isolation:** plugin markup is wrapped in a `.cbd-scope` container so the host theme (Hello Elementor reset + Elementor kit) can't bleed in. New shortcodes/templates that emit standalone markup should opt into the same scope wrapper rather than relying on global styles.
- Some hot fields are mirrored from the custom table into post meta (`_cbd_is_featured`, `_cbd_rating_avg`, `_cbd_view_count`) purely so `WP_Query` can order/filter by them. The custom table is the source of truth; keep both in sync when you change one (the featured toggle does; review submission only updates the table).

## Demo Mode

`Settings → Demo Mode` toggles `CBD\Modules\DemoData`, which populates every feature with Inverness-themed sample content (businesses, events, promotions, reviews, follows, favorites, analytics, demo users) and tears it all down again. **Tagging convention:** every demo post and user carries meta `_cbd_demo = 1` (constant `DemoData::FLAG`); custom-table rows are matched on the demo `post_id`/`business_id`. Teardown deletes *only* tagged content, so it never removes real listings. The toggle's on/off transition (detected in `AdminController::save_settings('demo')`) drives `generate()` / `teardown()`; `get_option('cbd_demo_generated')` guards against double-population.

## Known gotchas (verify before relying on these features)

- **Auto-created plugin pages and seeded roles/plans only run on (re)activation.** `Activator::maybe_upgrade()` self-heals *schema* on `CBD_DB_VERSION` bumps, but `add_roles()`, `seed_plans()`, and `ensure_plugin_pages()` only run from full `Activator::activate()`. Installs that pre-date a new role/plan/page need a deactivate + reactivate.
- **`cbd_review_pending` / `cbd_review_approved` actions are dormant.** `Modules\Notifications` listens for them, but nothing currently fires them (review submission in `AjaxHandler::cbd_submit_review` and approval in the admin do not `do_action` these), so review-related emails never send until those `do_action()` calls are added.
- **`includes/Modules/{Community,Dashboard,Directory,Events,Promotions,Registration,Reviews}/` are empty placeholder directories.** Only the top-level files `DemoData.php`, `Notifications.php`, and `LoqivaSync.php` in `Modules/` are real. Feature logic lives in `Frontend/Shortcodes/`, `Frontend/AjaxHandler.php`, and `Admin/AdminController.php` — don't grep these subdirs expecting code.
- **Plugin header version drifts from `CBD_VERSION`.** The header in `community-business-directory.php` is hand-maintained and may lag the `CBD_VERSION` constant; trust the constant.

## Loqiva sync

`CBD\Modules\LoqivaSync` (`register_loqiva()`) imports InvernessBID's Loqiva app feeds (events / offers / business JSON) as native listings on a WP-cron schedule (`LoqivaSync::CRON_HOOK`, `schedule_cron()` is idempotent). Imported content is tagged so it can be distinguished from manually-created listings; the admin page + `assets/js/admin-loqiva.js` drive a manual sync. Treat the feeds as external/best-effort — field shapes vary.

## Agent note

`.claude/agents/inverness-directory-builder.md` defines a project-specific agent for building Inverness, Scotland city-guide/directory UI; it keeps its own memory under `.claude/agent-memory/`. The seed categories/locations and GBP defaults reflect that UK/Inverness focus.
