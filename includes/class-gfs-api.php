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
		foreach ( array( 'description', 'lineup', 'venue', 'price', 'currency', 'ticket' ) as $field ) {
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

		// The API has no ticket link or currency field: both live in the free-text entry fee.
		$fee_text = $this->pick( $raw, array( 'textEntryFee', 'priceEntrance', 'price', 'entranceFee' ) );
		list( $price, $currency ) = $this->parse_price( $fee_text );
		$ticket = $this->pick( $raw, array( 'urlTicket', 'urlTickets', 'ticketUrl' ) );
		if ( '' === $ticket ) {
			$ticket = $this->find_ticket_url( $fee_text, true ) ?: $this->find_ticket_url( $this->pick( $raw, array( 'textMore', 'textOrganizer' ) ), false );
		}
		$image = $this->pick( $raw, array( 'urlImageFull', 'urlImageLarge', 'urlImageMedium', 'urlImage', 'image' ) );

		return array(
			'id'          => $id,
			'name'        => wp_strip_all_tags( $name ),
			'type'        => $this->pick( $raw, array( 'nameType', 'type' ) ),
			'status'      => strtolower( $this->pick( $raw, array( 'nameStatus', 'status' ) ) ),
			'start'       => $start,
			'end'         => $end,
			'description' => $this->clean_text( $this->pick( $raw, array( 'textMore', 'textOrganizer', 'textData', 'textIntro', 'description', 'text' ) ) ),
			'lineup'      => $this->clean_text( $this->pick( $raw, array( 'textLineup', 'lineup' ) ) ),
			'venue'       => $this->pick( $raw, array( 'textLocation', 'nameVenue', 'nameLocation', 'venue', 'location' ) ),
			'street'      => $this->pick( $raw, array( 'street', 'addressStreet', 'streetAddress' ) ),
			'town'        => $this->pick( $raw, array( 'nameTown', 'town', 'city' ) ),
			'region'      => $this->pick( $raw, array( 'nameRegion', 'region', 'state' ) ),
			'zip'         => $this->pick( $raw, array( 'zip', 'postalCode', 'postcode' ) ),
			'country'     => $this->pick( $raw, array( 'nameCountry', 'country' ) ),
			'iso'         => strtoupper( $this->pick( $raw, array( 'isoCountry', 'countryCode', 'iso' ) ) ),
			'price'       => $price,
			'currency'    => $this->pick( $raw, array( 'priceCurrency', 'currency' ) ) ?: $currency,
			'ticket'      => $this->is_external_url( $ticket ) ? esc_url_raw( $ticket ) : '',
			'facebook'    => $facebook ? esc_url_raw( $facebook ) : '',
			'video'       => $video ? esc_url_raw( $video ) : '',
			'image'       => $this->is_image_url( $image ) ? esc_url_raw( $image ) : '',
			'organizer'  => $this->pick( $raw, array( 'nameOrganizer' ) ),
			'organizer_url' => $this->external_url( $this->pick( $raw, array( 'urlOrganizer' ) ) ),
			'lat'         => $this->pick( $raw, array( 'geoLat' ) ),
			'lon'         => $this->pick( $raw, array( 'geoLon' ) ),
			'timezone'    => $this->pick( $raw, array( 'timezone' ) ),
			'styles'      => $this->collect_styles( $raw ),
		);
	}

	/** The API gives organizer sites without a scheme ("www.example.co.za"). */
	private function external_url( $url ) {
		if ( '' !== $url && ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		return $this->is_external_url( $url ) ? esc_url_raw( $url ) : '';
	}

	const CURRENCIES = 'EUR|USD|GBP|CHF|ZAR|LKR|THB|INR|IDR|AUD|NZD|CAD|BRL|MXN|CZK|PLN|HUF|SEK|NOK|DKK|RON|BGN|TRY|JPY|CNY|KRW|MYR|VND|PHP|RUB|UAH|ILS|AED|EGP|KES|NAD|BWP|MZN|ARS|CLP|COP|PEN|HRK|RSD|MAD|NPR|LAK|KHR|MMK|SGD|HKD|TWD';

	/**
	 * The entry fee is free text ("Online: 2500 LKR / At the gate: 3000 LKR").
	 * Returns the lowest price found and its currency, since the field is "Price from".
	 *
	 * @return array [ amount, currency ]
	 */
	private function parse_price( $text ) {
		if ( '' === $text ) {
			return array( '', '' );
		}
		$symbols = array( '€' => 'EUR', '$' => 'USD', '£' => 'GBP' );
		$num     = '(\d[\d.,]*)';
		$cur     = '(' . self::CURRENCIES . '|€|\$|£|(?<![A-Za-z])R(?=\s?\d))';
		$found   = array();
		$collect = function ( $amount, $currency ) use ( &$found, $symbols ) {
			$amount = (float) str_replace( ',', '.', preg_replace( '/[\s]/', '', rtrim( $amount, '.,' ) ) );
			if ( $amount > 0 ) {
				$currency = isset( $symbols[ $currency ] ) ? $symbols[ $currency ] : ( 'R' === $currency ? 'ZAR' : $currency );
				$found[]  = array( $amount, $currency );
			}
		};
		if ( preg_match_all( '/' . $num . '\s?' . $cur . '/u', $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$collect( $x[1], $x[2] );
			}
		}
		if ( preg_match_all( '/' . $cur . '\s?' . $num . '/u', $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$collect( $x[2], $x[1] );
			}
		}
		if ( ! $found ) {
			return array( preg_match( '/\d+(?:[.,]\d+)?/', $text, $m ) ? str_replace( ',', '.', $m[0] ) : '', '' );
		}
		usort(
			$found,
			function ( $a, $b ) {
				return $a[0] <=> $b[0];
			}
		);
		return array( rtrim( rtrim( number_format( $found[0][0], 2, '.', '' ), '0' ), '.' ), $found[0][1] );
	}

	/**
	 * Best ticket link in free text: a URL on a "ticket/book/buy" line. In the entry-fee
	 * text itself any non-social URL will do; elsewhere it must be marked as tickets.
	 */
	private function find_ticket_url( $text, $any_url ) {
		$first = '';
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			if ( ! preg_match_all( '#https?://[^\s<>"\')]+|www\.[^\s<>"\')]+#i', $line, $m ) ) {
				continue;
			}
			foreach ( $m[0] as $url ) {
				$url = rtrim( 0 === stripos( $url, 'www.' ) ? 'https://' . $url : $url, '.,;' );
				if ( ! $this->is_external_url( $url ) || preg_match( '#(facebook|instagram|youtube|youtu\.be|fb\.me|maps\.|goo\.gl)#i', $url ) ) {
					continue;
				}
				if ( preg_match( '/ticket|tix|book|buy|register|presale|webshop/i', $line . $url ) ) {
					return $url;
				}
				$first = $any_url && '' === $first ? $url : $first;
			}
		}
		return $first;
	}

	/** Flyers are served from goabase's own image host, which is the one goabase.net host we do want. */
	private function is_image_url( $url ) {
		return is_string( $url ) && preg_match( '#^https?://#i', $url ) && '' !== (string) wp_parse_url( $url, PHP_URL_HOST );
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
