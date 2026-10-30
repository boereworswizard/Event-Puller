<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers the ACF fields for Goabase data the existing field groups don't cover
 * (organizer, coordinates, timezone, status). Registered in code, so nothing needs
 * importing; they show up on the festival edit screen like any other field group.
 */
class GFS_Fields {

	/** Field name => array( field key, label, ACF type ). */
	const FIELDS = array(
		'organizer_name' => array( 'field_gfs_organizer_name', 'Organizer', 'text' ),
		'organizer_url'  => array( 'field_gfs_organizer_url', 'Organizer Website', 'url' ),
		'event_latitude' => array( 'field_gfs_event_latitude', 'Latitude', 'text' ),
		'event_longitude' => array( 'field_gfs_event_longitude', 'Longitude', 'text' ),
		'event_timezone' => array( 'field_gfs_event_timezone', 'Timezone', 'text' ),
		'event_status'   => array( 'field_gfs_event_status', 'Event Status', 'text' ),
	);

	public function __construct() {
		add_action( 'acf/init', array( $this, 'register' ) );
	}

	/** Field name => key, for GFS_Sync. */
	public static function keys() {
		return array_map(
			function ( $f ) {
				return $f[0];
			},
			self::FIELDS
		);
	}

	public function register() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}
		$fields = array();
		foreach ( self::FIELDS as $name => $f ) {
			$fields[] = array(
				'key'   => $f[0],
				'label' => $f[1],
				'name'  => $name,
				'type'  => $f[2],
			);
		}
		acf_add_local_field_group(
			array(
				'key'      => 'group_gfs_goabase',
				'title'    => 'Festival: Goabase Details',
				'fields'   => $fields,
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => GFS_Sync::POST_TYPE ) ) ),
			)
		);
	}
}
