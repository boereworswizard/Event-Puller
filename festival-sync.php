<?php
/**
 * Plugin Name: Festival Sync
 * Description: Pulls events from the Goabase API into the "festival" post type (ACF fields), with event-type filtering, duplicate matching and scheduled sync.
 * Version:     1.0.0
 * Requires PHP: 7.4
 * License:     GPL-2.0-or-later
 * Text Domain: festival-sync
 */

defined( 'ABSPATH' ) || exit;

define( 'GFS_VERSION', '1.0.0' );
define( 'GFS_DIR', plugin_dir_path( __FILE__ ) );
define( 'GFS_OPTION', 'gfs_settings' );
define( 'GFS_CRON_HOOK', 'gfs_cron_sync' );
define( 'GFS_SOURCE_META', '_gfs_source_id' );

require_once GFS_DIR . 'includes/class-gfs-api.php';
require_once GFS_DIR . 'includes/class-gfs-sync.php';
require_once GFS_DIR . 'includes/class-gfs-fields.php';
require_once GFS_DIR . 'includes/class-gfs-settings.php';

/**
 * Settings with defaults applied.
 */
function gfs_get_settings() {
	$defaults = array(
		'types'         => array( 'festival', 'open air' ),
		'extra_types'   => '',
		'countries'     => '',
		'post_status'   => 'draft',
		'update_synced' => 1,
		'future_only'   => 1,
		'import_images' => 1,
		'schedule'      => 'daily',
		'max_per_run'   => 100,
		'api_url'       => 'https://www.goabase.net/api/party/json/',
	);
	$saved = get_option( GFS_OPTION, array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

function gfs_log( $message ) {
	$log   = get_option( 'gfs_log', array() );
	$log   = is_array( $log ) ? $log : array();
	$log[] = '[' . wp_date( 'Y-m-d H:i:s' ) . '] ' . $message;
	update_option( 'gfs_log', array_slice( $log, -200 ), false );
}

function gfs_schedule_cron() {
	wp_clear_scheduled_hook( GFS_CRON_HOOK );
	$schedule = gfs_get_settings()['schedule'];
	if ( in_array( $schedule, array( 'hourly', 'twicedaily', 'daily' ), true ) ) {
		wp_schedule_event( time() + 300, $schedule, GFS_CRON_HOOK );
	}
}

register_activation_hook( __FILE__, 'gfs_schedule_cron' );
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( GFS_CRON_HOOK );
	}
);

add_action(
	GFS_CRON_HOOK,
	function () {
		( new GFS_Sync( gfs_get_settings() ) )->run( false );
	}
);

new GFS_Fields();

if ( is_admin() ) {
	new GFS_Settings();
}
