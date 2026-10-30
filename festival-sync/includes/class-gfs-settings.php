<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin screen: Festivals > Festival Sync.
 */
class GFS_Settings {

	const SLUG = 'festival-sync';

	/** Event types offered as checkboxes (matched case-insensitively against the API's type name). */
	const TYPES = array(
		'festival' => 'Festival',
		'open air' => 'Open Air',
		'club'     => 'Club',
		'indoor'   => 'Indoor',
		'party'    => 'Party',
		'boat'     => 'Boat',
		'other'    => 'Other',
	);

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_post_gfs_run', array( $this, 'handle_run' ) );
	}

	public function menu() {
		add_submenu_page(
			'edit.php?post_type=festival',
			'Festival Sync',
			'Festival Sync',
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function register() {
		register_setting( 'gfs', GFS_OPTION, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	public function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$out = array(
			'types'         => array_values( array_intersect( array_keys( self::TYPES ), (array) ( $in['types'] ?? array() ) ) ),
			'extra_types'   => sanitize_text_field( $in['extra_types'] ?? '' ),
			'countries'     => strtoupper( preg_replace( '/[^A-Za-z,]/', '', $in['countries'] ?? '' ) ),
			'post_status'   => in_array( $in['post_status'] ?? '', array( 'publish', 'draft', 'pending' ), true ) ? $in['post_status'] : 'draft',
			'update_synced' => empty( $in['update_synced'] ) ? 0 : 1,
			'future_only'   => empty( $in['future_only'] ) ? 0 : 1,
			'import_images' => empty( $in['import_images'] ) ? 0 : 1,
			'schedule'      => in_array( $in['schedule'] ?? '', array( 'off', 'hourly', 'twicedaily', 'daily' ), true ) ? $in['schedule'] : 'off',
			'max_per_run'   => max( 1, min( 1000, (int) ( $in['max_per_run'] ?? 100 ) ) ),
			'api_url'       => esc_url_raw( $in['api_url'] ?? '' ) ?: 'https://www.goabase.net/api/party/json/',
		);
		// Re-schedule once the option has been saved.
		add_action( 'update_option_' . GFS_OPTION, 'gfs_schedule_cron' );
		add_action( 'add_option_' . GFS_OPTION, 'gfs_schedule_cron' );
		return $out;
	}

	public function handle_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'gfs_run' );
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		$dry    = ! empty( $_POST['dry_run'] );
		$result = ( new GFS_Sync( gfs_get_settings() ) )->run( $dry );
		set_transient(
			'gfs_notice_' . get_current_user_id(),
			array( 'dry' => $dry, 'result' => is_wp_error( $result ) ? $result->get_error_message() : $result ),
			5 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'edit.php?post_type=festival&page=' . self::SLUG ) );
		exit;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = gfs_get_settings();
		$name   = GFS_OPTION;
		$notice = get_transient( 'gfs_notice_' . get_current_user_id() );
		delete_transient( 'gfs_notice_' . get_current_user_id() );
		$last = get_option( 'gfs_last_run' );
		$next = wp_next_scheduled( GFS_CRON_HOOK );
		?>
		<div class="wrap">
			<h1>Festival Sync</h1>

			<?php if ( $notice ) : ?>
				<?php if ( is_string( $notice['result'] ) ) : ?>
					<div class="notice notice-error"><p><?php echo esc_html( $notice['result'] ); ?></p></div>
				<?php else : ?>
					<div class="notice notice-success">
						<p><strong><?php echo $notice['dry'] ? 'Preview (nothing saved): ' : 'Sync finished: '; ?></strong>
							<?php
							printf(
								'%d to create, %d to update, %d skipped, %d errors.',
								(int) $notice['result']['created'],
								(int) $notice['result']['updated'],
								(int) $notice['result']['skipped'],
								(int) $notice['result']['errors']
							);
							?>
						</p>
						<?php if ( $notice['result']['lines'] ) : ?>
							<pre style="max-height:280px;overflow:auto;"><?php echo esc_html( implode( "\n", $notice['result']['lines'] ) ); ?></pre>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'gfs' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Event types to sync</th>
						<td>
							<?php foreach ( GFS_Settings::TYPES as $value => $label ) : ?>
								<label style="display:inline-block;margin-right:16px;">
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[types][]" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( $value, (array) $s['types'], true ) ); ?>>
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description">Nothing ticked (and no extra types) imports every type.</p>
							<p><input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[extra_types]" value="<?php echo esc_attr( $s['extra_types'] ); ?>" placeholder="Extra type names, comma separated"></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gfs-countries">Countries</label></th>
						<td>
							<input id="gfs-countries" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[countries]" value="<?php echo esc_attr( $s['countries'] ); ?>" placeholder="ZA, NA, MZ">
							<p class="description">Two-letter ISO country codes, comma separated. Leave blank for all countries.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gfs-status">Status for new festivals</label></th>
						<td>
							<select id="gfs-status" name="<?php echo esc_attr( $name ); ?>[post_status]">
								<?php foreach ( array( 'draft' => 'Draft', 'pending' => 'Pending review', 'publish' => 'Published' ) as $v => $l ) : ?>
									<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $s['post_status'], $v ); ?>><?php echo esc_html( $l ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">Options</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[future_only]" value="1" <?php checked( $s['future_only'] ); ?>> Only import upcoming events</label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[update_synced]" value="1" <?php checked( $s['update_synced'] ); ?>> Refresh festivals this plugin created on each sync</label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[import_images]" value="1" <?php checked( $s['import_images'] ); ?>> Import the flyer as featured image (if none set)</label>
							<p class="description">Festivals you already had are only ever filled in where a field is empty; your own content is never overwritten.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gfs-schedule">Automatic sync</label></th>
						<td>
							<select id="gfs-schedule" name="<?php echo esc_attr( $name ); ?>[schedule]">
								<?php foreach ( array( 'off' => 'Off (manual only)', 'hourly' => 'Hourly', 'twicedaily' => 'Twice daily', 'daily' => 'Daily' ) as $v => $l ) : ?>
									<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $s['schedule'], $v ); ?>><?php echo esc_html( $l ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php if ( $next ) : ?>
								<span class="description">Next run: <?php echo esc_html( wp_date( 'Y-m-d H:i', $next ) ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gfs-max">Max events per run</label></th>
						<td><input id="gfs-max" type="number" min="1" max="1000" name="<?php echo esc_attr( $name ); ?>[max_per_run]" value="<?php echo esc_attr( $s['max_per_run'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="gfs-url">API URL</label></th>
						<td><input id="gfs-url" type="url" class="regular-text" name="<?php echo esc_attr( $name ); ?>[api_url]" value="<?php echo esc_attr( $s['api_url'] ); ?>"></td>
					</tr>
				</table>
				<?php submit_button( 'Save settings' ); ?>
			</form>

			<hr>
			<h2>Run now</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gfs_run">
				<?php wp_nonce_field( 'gfs_run' ); ?>
				<button class="button" name="dry_run" value="1">Preview (dry run)</button>
				<button class="button button-primary" name="go" value="1">Sync now</button>
				<p class="description">Save your settings first. The preview shows what would be created or matched without changing anything.</p>
			</form>
			<?php if ( $last ) : ?>
				<p>Last sync: <?php echo esc_html( wp_date( 'Y-m-d H:i', $last['time'] ) ); ?> &mdash;
					<?php printf( '%d created, %d updated, %d skipped, %d errors', (int) $last['created'], (int) $last['updated'], (int) $last['skipped'], (int) $last['errors'] ); ?></p>
			<?php endif; ?>

			<?php $log = get_option( 'gfs_log', array() ); ?>
			<?php if ( $log ) : ?>
				<h2>Log</h2>
				<pre style="max-height:240px;overflow:auto;background:#fff;padding:8px;"><?php echo esc_html( implode( "\n", array_reverse( $log ) ) ); ?></pre>
			<?php endif; ?>
		</div>
		<?php
	}
}
