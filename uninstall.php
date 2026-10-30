<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'gfs_cron_sync' );
delete_option( 'gfs_settings' );
delete_option( 'gfs_log' );
delete_option( 'gfs_last_run' );
// Post meta (_gfs_source_id) is left in place so re-installing keeps the matches.
