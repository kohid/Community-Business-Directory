<?php
namespace CBD\Core;
defined( 'ABSPATH' ) || exit;
class Deactivator {
    public static function deactivate(): void {
        flush_rewrite_rules();
        // Stop the scheduled Love Inverness import.
        \CBD\Modules\LoqivaSync::unschedule_cron();
        // Drop the self-heal guard so the next activation always re-flushes the
        // CPT rewrite rules — otherwise a same-version reinstall keeps a stale
        // value and Plugin::maybe_flush_rewrites() skips the repair (404s).
        delete_option( 'cbd_rewrite_version' );
    }
}
