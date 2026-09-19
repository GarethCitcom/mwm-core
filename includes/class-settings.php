<?php
/**
 * Settings → Maths with Melissa: YouTube API key, playlist IDs, redirect map.
 */

defined( 'ABSPATH' ) || exit;

class MWM_Settings {

	public const OPTION_API_KEY   = 'mwm_youtube_api_key';
	public const OPTION_CHANNEL   = 'mwm_youtube_channel_url';
	public const OPTION_PLAYLISTS = 'mwm_playlists';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register' ] );
	}

	/**
	 * Playlist definitions: key → [label, format, theme].
	 */
	public static function playlist_defs(): array {
		return [
			'quick_maths' => [ 'label' => 'Quick Maths (Shorts playlist)', 'format' => 'short',  'theme' => '' ],
			'roblox'      => [ 'label' => 'Roblox lessons playlist',        'format' => 'gaming', 'theme' => 'roblox' ],
			'minecraft'   => [ 'label' => 'Minecraft lessons playlist',     'format' => 'gaming', 'theme' => 'minecraft' ],
			'story'       => [ 'label' => 'Story maths playlist',           'format' => 'gaming', 'theme' => 'story' ],
		];
	}

	/**
	 * Slots with their playlist IDs. Each slot can hold several playlists (one per line in the settings).
	 *
	 * @return array<string, array{key:string,label:string,format:string,theme:string,id:string,ids:array}>
	 */
	public static function playlists(): array {
		$saved = (array) get_option( self::OPTION_PLAYLISTS, [] );
		$out   = [];
		foreach ( self::playlist_defs() as $key => $def ) {
			$ids = self::split_ids( (string) ( $saved[ $key ] ?? '' ) );
			$out[ $key ] = array_merge( $def, [ 'key' => $key, 'id' => $ids[0] ?? '', 'ids' => $ids ] );
		}
		return $out;
	}

	public static function split_ids( string $raw ): array {
		$ids = preg_split( '/[\s,]+/', $raw ) ?: [];
		$out = [];
		foreach ( $ids as $id ) {
			$id = trim( $id );
			if ( preg_match( '~[?&]list=([A-Za-z0-9_-]+)~', $id, $m ) ) {
				$id = $m[1];
			}
			if ( $id !== '' && preg_match( '/^[A-Za-z0-9_-]{10,}$/', $id ) ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function menu(): void {
		add_options_page( 'Maths with Melissa', 'Maths with Melissa', 'manage_options', 'mwm-core', [ __CLASS__, 'render' ] );
	}

	public static function register(): void {
		register_setting( 'mwm_core', MWM_Coming_Soon::OPTION, [ 'type' => 'string', 'sanitize_callback' => [ __CLASS__, 'sanitize_checkbox' ], 'default' => '1' ] );
		register_setting( 'mwm_core', self::OPTION_API_KEY, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'mwm_core', self::OPTION_CHANNEL, [ 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => 'https://www.youtube.com/@mathswithmelissa' ] );
		register_setting( 'mwm_core', self::OPTION_PLAYLISTS, [ 'type' => 'array', 'sanitize_callback' => [ __CLASS__, 'sanitize_playlists' ] ] );
		register_setting( 'mwm_core', 'mwm_redirect_map', [ 'type' => 'array', 'sanitize_callback' => [ __CLASS__, 'sanitize_redirects' ] ] );
	}

	public static function sanitize_checkbox( $value ): string {
		return $value ? '1' : '0';
	}

	public static function sanitize_playlists( $value ): array {
		$out = [];
		foreach ( array_keys( self::playlist_defs() ) as $key ) {
			$out[ $key ] = implode( "\n", self::split_ids( (string) ( $value[ $key ] ?? '' ) ) );
		}
		return $out;
	}

	public static function sanitize_redirects( $value ): array {
		if ( is_string( $value ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', $value );
			$map   = [];
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( ! $line || str_starts_with( $line, '#' ) ) {
					continue;
				}
				$parts = preg_split( '/\s+/', $line, 2 );
				if ( count( $parts ) === 2 ) {
					$map[ '/' . trim( $parts[0], '/' ) . '/' ] = trim( $parts[1] );
				}
			}
			return $map;
		}
		return is_array( $value ) ? $value : [];
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$playlists = self::playlists();
		$state     = MWM_YouTube::sync_state();
		$map       = (array) get_option( 'mwm_redirect_map', [] );
		$map_text  = '';
		foreach ( $map as $from => $to ) {
			$map_text .= "$from $to\n";
		}
		?>
		<div class="wrap">
			<h1>Maths with Melissa</h1>
			<?php if ( isset( $_GET['mwm_synced'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Playlists checked. <?php echo esc_html( $state['summary'] ?? '' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'mwm_core' ); ?>
				<h2>Site visibility</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Coming soon mode</th>
						<td>
							<label for="mwm_coming_soon">
								<input type="checkbox" id="mwm_coming_soon" name="<?php echo esc_attr( MWM_Coming_Soon::OPTION ); ?>" value="1" <?php checked( get_option( MWM_Coming_Soon::OPTION, '1' ), '1' ); ?>>
								Hide the site behind a Coming Soon page
							</label>
							<p class="description">While ticked, visitors who aren't logged in see a branded holding page and the public API is closed. Log in at <code>/wp-login.php</code> to see the full site. Untick and save to launch.</p>
						</td>
					</tr>
				</table>
				<h2>YouTube</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mwm_youtube_api_key">YouTube Data API key</label></th>
						<td><input type="text" class="regular-text" id="mwm_youtube_api_key" name="<?php echo esc_attr( self::OPTION_API_KEY ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_API_KEY, '' ) ); ?>" autocomplete="off">
						<p class="description">One key from Google Cloud with the YouTube Data API v3 enabled. Used for the daily playlist sync and for fetching video lengths in the Studio.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="mwm_youtube_channel_url">Channel URL</label></th>
						<td><input type="url" class="regular-text" id="mwm_youtube_channel_url" name="<?php echo esc_attr( self::OPTION_CHANNEL ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_CHANNEL, 'https://www.youtube.com/@mathswithmelissa' ) ); ?>"></td>
					</tr>
					<?php foreach ( $playlists as $key => $pl ) : ?>
					<tr>
						<th scope="row"><label for="mwm_pl_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $pl['label'] ); ?></label></th>
						<td><textarea class="regular-text code" rows="<?php echo max( 2, count( $pl['ids'] ) + 1 ); ?>" id="mwm_pl_<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION_PLAYLISTS ); ?>[<?php echo esc_attr( $key ); ?>]" placeholder="PL…"><?php echo esc_textarea( implode( "\n", $pl['ids'] ) ); ?></textarea>
						<p class="description">One playlist ID per line (the part after <code>list=</code> in the playlist URL). Videos up to 3 minutes count as shorts; longer ones become Gaming &amp; Story lessons with the <?php echo esc_html( $pl['theme'] ? ucfirst( $pl['theme'] ) : 'Quick Maths' ); ?> tag.</p></td>
					</tr>
					<?php endforeach; ?>
				</table>
				<p>Last checked: <strong><?php echo esc_html( $state['last_label'] ?: 'never' ); ?></strong><?php if ( ! empty( $state['summary'] ) ) : ?> · <?php echo esc_html( $state['summary'] ); ?><?php endif; ?>
					<?php if ( ! empty( $state['error'] ) ) : ?><br><span style="color:#b32d2e">Last error: <?php echo esc_html( $state['error'] ); ?></span><?php endif; ?></p>
				<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mwm_sync_now' ), 'mwm_sync_now' ) ); ?>">Check playlists now</a></p>

				<h2>Redirects from the old site</h2>
				<p>One per line: <code>/lesson/6968/ /lesson/quadratic-simultaneous-equations/</code>. The import command fills this in for you.</p>
				<textarea name="mwm_redirect_map" rows="10" class="large-text code"><?php echo esc_textarea( $map_text ); ?></textarea>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
