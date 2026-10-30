<?php
defined( 'ABSPATH' ) || exit;

/**
 * Filters events, matches them to existing festival posts and writes ACF fields.
 */
class GFS_Sync {

	const POST_TYPE = 'festival';

	/** ACF field name => field key (from the ACF export). */
	const FIELDS = array(
		'details'              => 'field_60c457cfadac3',
		'event_start'          => 'field_60c45446662dc',
		'event_end'            => 'field_60c45471662dd',
		'event_video'          => 'field_60c4548c662de',
		'facebook_link'        => 'field_675c38e7d7351',
		'festival_lineup'      => 'field_6687c055149f4',
		'venue_name'           => 'field_60c4e2e32e8ee',
		'streetAddress'        => 'field_60c4e3c12e8ef',
		'addressLocality'      => 'field_60c4e3cb2e8f1',
		'addressregion'        => 'field_60c4e4202e8f2',
		'postalcode'           => 'field_60c4e4262e8f3',
		'priceCurrency'        => 'field_62972df095754',
		'price_from'           => 'field_621a6434e2038',
		'buy_link'             => 'field_621a6440e2039',
	);

	/** @var array */
	private $settings;
	/** @var array Normalised title index of existing festival posts. */
	private $index = array();
	/** @var bool */
	private $dry_run = false;

	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @param bool $dry_run When true nothing is written.
	 * @return array|WP_Error Summary counts and per-event lines.
	 */
	public function run( $dry_run = false ) {
		if ( get_transient( 'gfs_lock' ) ) {
			return new WP_Error( 'gfs_locked', 'A sync is already running.' );
		}
		set_transient( 'gfs_lock', 1, 10 * MINUTE_IN_SECONDS );
		$this->dry_run = $dry_run;

		try {
			$result = $this->process();
		} finally {
			delete_transient( 'gfs_lock' );
		}

		if ( is_wp_error( $result ) ) {
			gfs_log( 'Sync failed: ' . $result->get_error_message() );
		} elseif ( ! $dry_run ) {
			gfs_log( sprintf( 'Sync done: %d created, %d updated, %d skipped, %d errors.', $result['created'], $result['updated'], $result['skipped'], $result['errors'] ) );
			update_option( 'gfs_last_run', array_merge( $result, array( 'time' => time() ) ), false );
		}
		return $result;
	}

	private function process() {
		$events = ( new GFS_API( $this->settings ) )->fetch();
		if ( is_wp_error( $events ) ) {
			return $events;
		}

		$events = array_filter( $events, array( $this, 'passes_filters' ) );
		usort(
			$events,
			function ( $a, $b ) {
				return strcmp( $a['start'], $b['start'] );
			}
		);
		$events = array_slice( $events, 0, max( 1, (int) $this->settings['max_per_run'] ) );

		$this->build_index();

		$summary = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => 0,
			'lines'   => array(),
		);

		foreach ( $events as $event ) {
			$match = $this->find_match( $event );
			if ( $match && ! $match['owned'] && ! $this->has_gaps( $match['id'], $event ) ) {
				$summary['skipped']++;
				$summary['lines'][] = sprintf( 'SKIP   %s (matches existing #%d, nothing to fill)', $event['name'], $match['id'] );
				continue;
			}
			if ( $match && $match['owned'] && empty( $this->settings['update_synced'] ) ) {
				$summary['skipped']++;
				$summary['lines'][] = sprintf( 'SKIP   %s (#%d, updates disabled)', $event['name'], $match['id'] );
				continue;
			}

			if ( $this->dry_run ) {
				$key = $match ? 'updated' : 'created';
				$summary[ $key ]++;
				$summary['lines'][] = sprintf( '%s %s%s', $match ? 'UPDATE' : 'CREATE', $event['name'], $match ? " (existing #{$match['id']}, " . ( $match['owned'] ? 'synced' : 'fill empty fields only' ) . ')' : '' );
				continue;
			}

			$event   = ( new GFS_API( $this->settings ) )->with_details( $event );
			$post_id = $match ? $match['id'] : $this->create_post( $event );
			if ( is_wp_error( $post_id ) || ! $post_id ) {
				$summary['errors']++;
				$summary['lines'][] = 'ERROR  ' . $event['name'];
				gfs_log( 'Could not save "' . $event['name'] . '"' );
				continue;
			}

			// Existing posts the plugin did not create only get empty fields filled.
			$fill_only = $match && ! $match['owned'];
			$this->write_fields( $post_id, $event, $fill_only );
			$this->write_terms( $post_id, $event );
			if ( ! empty( $this->settings['import_images'] ) ) {
				$this->maybe_set_image( $post_id, $event );
			}
			update_post_meta( $post_id, GFS_SOURCE_META, $event['id'] );

			$key = $match ? 'updated' : 'created';
			$summary[ $key ]++;
			$summary['lines'][] = sprintf( '%s %s (#%d)', $match ? 'UPDATE' : 'CREATE', $event['name'], $post_id );
		}
		return $summary;
	}

	/* ---------------------------------------------------------------- */
	/* Filtering                                                        */
	/* ---------------------------------------------------------------- */

	private function allowed_types() {
		$types = (array) $this->settings['types'];
		foreach ( explode( ',', (string) $this->settings['extra_types'] ) as $extra ) {
			$types[] = $extra;
		}
		return array_filter( array_map( array( $this, 'norm_type' ), $types ) );
	}

	private function norm_type( $type ) {
		return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', ' ', (string) $type ) ) );
	}

	public function passes_filters( $event ) {
		if ( 'cancelled' === $event['status'] ) {
			return false;
		}

		$allowed = $this->allowed_types();
		if ( $allowed ) {
			$type = $this->norm_type( $event['type'] );
			$ok   = false;
			foreach ( $allowed as $a ) {
				if ( '' !== $type && ( $type === $a || false !== strpos( $type, $a ) ) ) {
					$ok = true;
					break;
				}
			}
			if ( ! $ok ) {
				return false;
			}
		}

		$countries = array_filter( array_map( 'trim', explode( ',', strtoupper( (string) $this->settings['countries'] ) ) ) );
		if ( $countries && ! in_array( $event['iso'], $countries, true ) ) {
			return false;
		}

		if ( ! empty( $this->settings['future_only'] ) && $event['start'] ) {
			$cutoff = $event['end'] ? $event['end'] : $event['start'];
			if ( substr( $cutoff, 0, 10 ) < wp_date( 'Y-m-d' ) ) {
				return false;
			}
		}
		return true;
	}

	/* ---------------------------------------------------------------- */
	/* Matching                                                         */
	/* ---------------------------------------------------------------- */

	private function normalise_title( $title ) {
		$title = strtolower( remove_accents( html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' ) ) );
		$title = preg_replace( '/\b(19|20)\d{2}\b/', ' ', $title );
		$title = preg_replace( '/\b(festival|open air|openair|the)\b/', ' ', $title );
		$title = preg_replace( '/[^a-z0-9]+/', ' ', $title );
		return trim( preg_replace( '/\s+/', ' ', $title ) );
	}

	private function build_index() {
		$this->index = array();
		$ids         = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $ids as $id ) {
			$this->index[ $id ] = array(
				'title'  => $this->normalise_title( get_the_title( $id ) ),
				'start'  => (string) get_post_meta( $id, 'event_start', true ),
				'source' => (string) get_post_meta( $id, GFS_SOURCE_META, true ),
			);
		}
	}

	private function days_apart( $a, $b ) {
		$ta = strtotime( substr( $a, 0, 10 ) );
		$tb = strtotime( substr( $b, 0, 10 ) );
		return ( $ta && $tb ) ? abs( $ta - $tb ) / DAY_IN_SECONDS : null;
	}

	/**
	 * Match order: stored source id, then exact title + date, then fuzzy title + date.
	 * Same title in a different year is deliberately NOT a match (a new edition).
	 *
	 * @return array|null [ 'id' => int, 'owned' => bool ]
	 */
	private function find_match( array $event ) {
		foreach ( $this->index as $id => $row ) {
			if ( $row['source'] === $event['id'] ) {
				return array( 'id' => $id, 'owned' => true );
			}
		}

		$title = $this->normalise_title( $event['name'] );
		if ( '' === $title ) {
			return null;
		}

		$best       = null;
		$best_score = 0;
		foreach ( $this->index as $id => $row ) {
			// Already linked to a different source event.
			if ( '' !== $row['source'] ) {
				continue;
			}
			$days = ( $event['start'] && $row['start'] ) ? $this->days_apart( $event['start'], $row['start'] ) : null;

			if ( $row['title'] === $title ) {
				$score = ( null === $days ) ? 90 : ( $days <= 3 ? 100 : 0 );
			} else {
				similar_text( $title, $row['title'], $pct );
				$contains = '' !== $row['title'] && ( false !== strpos( $title, $row['title'] ) || false !== strpos( $row['title'], $title ) );
				$score    = ( null !== $days && $days <= 3 && ( $pct >= 85 || ( $contains && min( strlen( $title ), strlen( $row['title'] ) ) >= 5 ) ) ) ? (int) $pct : 0;
			}
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $id;
			}
		}
		return $best ? array( 'id' => $best, 'owned' => false ) : null;
	}

	/** Whether any mapped field on an existing post is empty and could be filled. */
	private function has_gaps( $post_id, array $event ) {
		// Text fields only exist on the detail endpoint, so check them against the post alone.
		foreach ( array( 'details', 'festival_lineup', 'venue_name', 'price_from' ) as $name ) {
			if ( '' === (string) get_post_meta( $post_id, $name, true ) ) {
				return true;
			}
		}
		foreach ( $this->field_values( $event ) as $name => $value ) {
			if ( '' !== $value && '' === (string) get_post_meta( $post_id, $name, true ) ) {
				return true;
			}
		}
		return ! has_post_thumbnail( $post_id ) && $event['image'] && ! empty( $this->settings['import_images'] );
	}

	/* ---------------------------------------------------------------- */
	/* Writing                                                          */
	/* ---------------------------------------------------------------- */

	private function field_values( array $event ) {
		return array(
			'details'         => $event['description'],
			'event_start'     => $event['start'],
			'event_end'       => $event['end'],
			'event_video'     => $event['video'],
			'facebook_link'   => $event['facebook'],
			'festival_lineup' => $event['lineup'] ? wpautop( esc_html( $event['lineup'] ) ) : '',
			'venue_name'      => $event['venue'],
			'streetAddress'   => $event['street'],
			'addressLocality' => $event['town'],
			'addressregion'   => $event['region'],
			'postalcode'      => $event['zip'],
			'priceCurrency'   => $event['currency'],
			'price_from'      => $event['price'],
			'buy_link'        => $event['ticket'],
			'organizer_name'  => $event['organizer'],
			'organizer_url'   => $event['organizer_url'],
			'event_latitude'  => $event['lat'],
			'event_longitude' => $event['lon'],
			'event_timezone'  => $event['timezone'],
			'event_status'    => $event['status'] ? ucfirst( $event['status'] ) : '',
		);
	}

	private function create_post( array $event ) {
		$status = in_array( $this->settings['post_status'], array( 'publish', 'draft', 'pending' ), true ) ? $this->settings['post_status'] : 'draft';
		return wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => $event['name'],
				'post_status' => $status,
			),
			true
		);
	}

	private function write_fields( $post_id, array $event, $fill_only ) {
		foreach ( $this->field_values( $event ) as $name => $value ) {
			if ( '' === $value ) {
				continue;
			}
			if ( $fill_only && '' !== (string) get_post_meta( $post_id, $name, true ) ) {
				continue;
			}
			$key = self::FIELDS[ $name ] ?? GFS_Fields::keys()[ $name ];
			if ( function_exists( 'update_field' ) ) {
				update_field( $key, $value, $post_id );
			} else {
				update_post_meta( $post_id, $name, $value );
				update_post_meta( $post_id, '_' . $name, $key );
			}
		}
	}

	private function write_terms( $post_id, array $event ) {
		$terms = array(
			'event_continent' => array( GFS_Geo::continent( $event['iso'] ) ),
			'event_country'   => array( $event['country'] ),
			'event_city'      => array( $event['town'] ),
			'festival-genre'  => $event['styles'],
		);
		foreach ( $terms as $taxonomy => $names ) {
			$names = array_values( array_filter( $names ) );
			if ( ! $names || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$ids = array();
			foreach ( $names as $name ) {
				$existing = term_exists( $name, $taxonomy );
				if ( ! $existing ) {
					$existing = wp_insert_term( $name, $taxonomy );
				}
				if ( ! is_wp_error( $existing ) ) {
					$ids[] = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
				}
			}
			if ( $ids ) {
				wp_set_object_terms( $post_id, $ids, $taxonomy, true );
			}
		}
	}

	private function maybe_set_image( $post_id, array $event ) {
		if ( ! $event['image'] || has_post_thumbnail( $post_id ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $event['image'], 30 );
		if ( is_wp_error( $tmp ) ) {
			return;
		}
		$name = sanitize_file_name( sanitize_title( $event['name'] ) . '.' . ( pathinfo( wp_parse_url( $event['image'], PHP_URL_PATH ), PATHINFO_EXTENSION ) ?: 'jpg' ) );
		$id   = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), $post_id, $event['name'] );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return;
		}
		set_post_thumbnail( $post_id, $id );
	}
}

/**
 * ISO 3166-1 alpha-2 country code => continent name.
 */
class GFS_Geo {

	public static function continent( $iso ) {
		static $map = null;
		if ( null === $map ) {
			$map  = array();
			$data = array(
				'Europe'        => 'AL AD AT BY BE BA BG HR CY CZ DK EE FO FI FR DE GI GR HU IS IE IT XK LV LI LT LU MT MD MC ME NL MK NO PL PT RO RU SM RS SK SI ES SE CH UA GB VA AX GG JE IM',
				'Asia'          => 'AF AM AZ BH BD BT BN KH CN GE HK IN ID IR IQ IL JP JO KZ KW KG LA LB MO MY MV MN MM NP KP OM PK PS PH QA SA SG KR LK SY TW TJ TH TL TR TM AE UZ VN YE',
				'Africa'        => 'DZ AO BJ BW BF BI CV CM CF TD KM CG CD CI DJ EG GQ ER SZ ET GA GM GH GN GW KE LS LR LY MG MW ML MR MU MA MZ NA NE NG RW ST SN SC SL SO ZA SS SD TZ TG TN UG ZM ZW RE YT EH',
				'North America' => 'AG BS BB BZ CA CR CU DM DO SV GD GT HT HN JM MX NI PA KN LC VC TT US PR GL BM AW CW SX KY VG VI TC',
				'South America' => 'AR BO BR CL CO EC GY PY PE SR UY VE GF FK',
				'Oceania'       => 'AU FJ KI MH FM NR NZ PW PG WS SB TO TV VU NC PF GU',
			);
			foreach ( $data as $continent => $codes ) {
				foreach ( explode( ' ', $codes ) as $code ) {
					$map[ $code ] = $continent;
				}
			}
		}
		return isset( $map[ strtoupper( (string) $iso ) ] ) ? $map[ strtoupper( $iso ) ] : '';
	}
}
