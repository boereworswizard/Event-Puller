<?php
defined( 'ABSPATH' ) || exit;

/**
 * Fetches events and normalises them into a flat, predictable array.
 *
 * The remote payload is read defensively: each logical field is looked up
 * under several candidate keys, so small differences in the API response
 * don't break the import.
 */
class GFS_API {

	/** @var array */
	private $settings;

	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The API filters by a single ISO country code, so each configured country is
	 * requested separately (no countries configured = one unfiltered request).
	 *
	 * @return array|WP_Error List of normalised events.
	 */
	public function fetch() {
		$countries = array_filter( array_map( 'trim', explode( ',', strtoupper( (string) $this->settings['countries'] ) ) ) );
		$events    = array();
		foreach ( $countries ? array_values( $countries ) : array( '' ) as $country ) {
			$args = apply_filters( 'gfs_api_query_args', $country ? array( 'country' => $country ) : array() );
			$list = $this->request( add_query_arg( $args, $this->settings['api_url'] ) );
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			foreach ( $list as $raw ) {
				$event = is_array( $raw ) ? $this->normalise( $raw ) : null;
				if ( $event ) {
					$events[ $event['id'] ] = $event;
				}
			}
		}
		return array_values( $events );
	}

	/**
	 * The list endpoint omits the long texts, lineup, location and entry fee;
	 * those only exist on the single-party endpoint. Cached to spare the API.
	 *
	 * @return array The event, enriched where the detail call succeeded.
	 */
	public function with_details( array $event ) {
		$cache_key = 'gfs_detail_' . md5( $event['id'] );
		$detail    = get_transient( $cache_key );
		if ( false === $detail ) {
			$detail = $this->request( trailingslashit( $this->settings['api_url'] ) . rawurlencode( $event['id'] ) );
			if ( is_wp_error( $detail ) ) {
				return $event;
			}
			$detail = $detail ? $this->normalise( $detail[0] ) : array();
			set_transient( $cache_key, $detail, 12 * HOUR_IN_SECONDS );
		}
		if ( ! $detail ) {
			return $event;
		}
		foreach ( array( 'description', 'lineup', 'venue', 'price' ) as $field ) {
			if ( '' === $event[ $field ] && ! empty( $detail[ $field ] ) ) {
				$event[ $field ] = $detail[ $field ];
			}
		}
		return $event;
	}

	/** @return array|WP_Error Raw party arrays from a list or single-party response. */
	private function request( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 45,
				'user-agent' => 'FestivalSync/' . GFS_VERSION . '; ' . home_url(),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'gfs_http', sprintf( 'API returned HTTP %d', $code ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'gfs_json', 'API response was not valid JSON.' );
		}

		$list = $data;
		foreach ( array( 'partylist', 'parties', 'party', 'data' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$list = $data[ $key ];
				break;
			}
		}
		// A single object instead of a list.
		if ( $list && ! isset( $list[0] ) && isset( $list['id'] ) ) {
			$list = array( $list );
		}
		return array_values( $list );
	}

	private function pick( array $raw, array $keys ) {
		$raw = array_change_key_case( $raw );
		foreach ( array_map( 'strtolower', $keys ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) && '' !== trim( (string) $raw[ $key ] ) ) {
				return trim( (string) $raw[ $key ] );
			}
		}
		return '';
	}

	private function normalise( array $raw ) {
		$id   = $this->pick( $raw, array( 'id', 'idParty', 'partyId' ) );
		$name = $this->pick( $raw, array( 'nameParty', 'name', 'title' ) );
		if ( '' === $id || '' === $name ) {
			return null;
		}

		$start = $this->parse_date( $this->pick( $raw, array( 'dateStart', 'startDate', 'start' ) ) );
		$end   = $this->parse_date( $this->pick( $raw, array( 'dateEnd', 'endDate', 'end' ) ) );

		// Scan every url* field: social/video links can sit under varying keys.
		$facebook = '';
		$video    = '';
		foreach ( $raw as $key => $value ) {
			if ( ! is_string( $value ) || 0 !== stripos( $key, 'url' ) || ! $this->is_external_url( $value ) ) {
				continue;
			}
			$host = strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) );
			if ( '' === $facebook && false !== strpos( $host, 'facebook.com' ) ) {
				$facebook = $value;
			} elseif ( '' === $video && ( false !== strpos( $host, 'youtube.com' ) || false !== strpos( $host, 'youtu.be' ) ) ) {
				$video = $value;
			}
		}

		$ticket = $this->pick( $raw, array( 'urlTicket', 'urlTickets', 'ticketUrl' ) );
		$image  = $this->pick( $raw, array( 'urlImageFull', 'urlImageMedium', 'urlImage', 'image' ) );

		return array(
			'id'          => $id,
			'name'        => wp_strip_all_tags( $name ),
			'type'        => $this->pick( $raw, array( 'nameType', 'type' ) ),
			'status'      => strtolower( $this->pick( $raw, array( 'nameStatus', 'status' ) ) ),
			'start'       => $start,
			'end'         => $end,
			'description' => $this->clean_text( $this->pick( $raw, array( 'textMore', 'textData', 'textIntro', 'description', 'text' ) ) ),
			'lineup'      => $this->clean_text( $this->pick( $raw, array( 'textLineup', 'lineup' ) ) ),
			'venue'       => $this->pick( $raw, array( 'textLocation', 'nameVenue', 'nameLocation', 'venue', 'location' ) ),
			'street'      => $this->pick( $raw, array( 'street', 'addressStreet', 'streetAddress' ) ),
			'town'        => $this->pick( $raw, array( 'nameTown', 'town', 'city' ) ),
			'region'      => $this->pick( $raw, array( 'nameRegion', 'region', 'state' ) ),
			'zip'         => $this->pick( $raw, array( 'zip', 'postalCode', 'postcode' ) ),
			'country'     => $this->pick( $raw, array( 'nameCountry', 'country' ) ),
			'iso'         => strtoupper( $this->pick( $raw, array( 'isoCountry', 'countryCode', 'iso' ) ) ),
			'price'       => $this->first_number( $this->pick( $raw, array( 'textEntryFee', 'priceEntrance', 'price', 'entranceFee' ) ) ),
			'currency'    => $this->pick( $raw, array( 'priceCurrency', 'currency' ) ),
			'ticket'      => $this->is_external_url( $ticket ) ? esc_url_raw( $ticket ) : '',
			'facebook'    => $facebook ? esc_url_raw( $facebook ) : '',
			'video'       => $video ? esc_url_raw( $video ) : '',
			'image'       => $this->is_external_url( $image ) ? esc_url_raw( $image ) : '',
			'styles'      => $this->collect_styles( $raw ),
		);
	}

	/** The API's entry fee is free text ("20 EUR presale"); the price field wants a number. */
	private function first_number( $text ) {
		return preg_match( '/\d+(?:[.,]\d+)?/', $text, $m ) ? str_replace( ',', '.', $m[0] ) : '';
	}

	/** Valid http(s) URL that does not point back to the source site. */
	private function is_external_url( $url ) {
		if ( ! is_string( $url ) || ! preg_match( '#^https?://#i', $url ) ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return '' !== $host && false === strpos( $host, 'goabase.net' );
	}

	/** Plain text, no markup, and no links back to the source site. */
	private function clean_text( $text ) {
		if ( '' === $text ) {
			return '';
		}
		$text = preg_replace( '#<br\s*/?>#i', "\n", $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '#https?://(?:[\w-]+\.)*goabase\.net\S*#i', '', $text );
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		return trim( preg_replace( "/\n{3,}/", "\n\n", $text ) );
	}

	private function collect_styles( array $raw ) {
		$styles = array();
		foreach ( $raw as $key => $value ) {
			if ( ! preg_match( '/^(nameStyle|nameMusic|style|music)/i', $key ) ) {
				continue;
			}
			foreach ( (array) $value as $item ) {
				if ( is_scalar( $item ) ) {
					foreach ( preg_split( '/[,\/]+/', (string) $item ) as $part ) {
						$part = trim( $part );
						if ( '' !== $part ) {
							$styles[ strtolower( $part ) ] = $part;
						}
					}
				}
			}
		}
		return array_values( $styles );
	}

	/**
	 * Returns "Y-m-d H:i:s" (the ACF storage format) without timezone shifting,
	 * so the time shown is the time the source published.
	 */
	private function parse_date( $value ) {
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?/', $value, $m ) ) {
			return sprintf( '%s %s:%s:%s', $m[1], $m[2], $m[3], isset( $m[4] ) ? $m[4] : '00' );
		}
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})$/', $value, $m ) ) {
			return $m[1] . ' 00:00:00';
		}
		$ts = strtotime( $value );
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : '';
	}
}
