<?php
/**
 * Coming soon mode: while enabled, only logged-in users see the site.
 * Everyone else gets a branded holding page (503, noindex) and the REST API is closed,
 * so lessons and worksheets can't be read around the gate. wp-login.php stays open.
 */

defined( 'ABSPATH' ) || exit;

class MWM_Coming_Soon {

	public const OPTION = 'mwm_coming_soon';

	public static function init(): void {
		add_action( 'template_redirect', [ __CLASS__, 'maybe_lock' ], 0 );
		add_filter( 'rest_authentication_errors', [ __CLASS__, 'lock_rest' ], 99 );
		add_action( 'admin_bar_menu', [ __CLASS__, 'admin_bar_badge' ], 100 );
	}

	/**
	 * On by default, so the site is private from the moment this ships until the box is unticked.
	 */
	public static function enabled(): bool {
		return (bool) apply_filters( 'mwm_coming_soon_enabled', get_option( self::OPTION, '1' ) === '1' );
	}

	public static function maybe_lock(): void {
		if ( ! self::enabled() || is_user_logged_in() ) {
			return;
		}
		self::render();
	}

	/**
	 * The public mwm/v1 and wp/v2 reads would leak content to logged-out visitors; close REST while the gate is up.
	 */
	public static function lock_rest( $result ) {
		if ( ! empty( $result ) || is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! self::enabled() || is_user_logged_in() ) {
			return $result;
		}
		return new WP_Error( 'mwm_coming_soon', 'This site is not open yet.', [ 'status' => 401 ] );
	}

	/**
	 * A reminder in the toolbar so it's obvious the public can't see the site.
	 */
	public static function admin_bar_badge( WP_Admin_Bar $bar ): void {
		if ( ! self::enabled() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$bar->add_node( [
			'id'    => 'mwm-coming-soon',
			'title' => 'Coming soon mode is on',
			'href'  => admin_url( 'options-general.php?page=mwm-core' ),
			'meta'  => [ 'title' => 'Only logged-in users can see the site. Click to change.' ],
		] );
	}

	private static function theme_asset( string $rel ): string {
		$path = get_theme_file_path( $rel );
		return file_exists( $path ) ? get_theme_file_uri( $rel ) : '';
	}

	private static function render(): void {
		nocache_headers();
		status_header( 503 );
		header( 'Retry-After: 86400' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: text/html; charset=utf-8' );

		$name      = get_bloginfo( 'name' );
		$logo      = self::theme_asset( 'assets/img/logo-charcoal.svg' );
		$logo_dark = self::theme_asset( 'assets/img/logo-white.svg' );
		$font      = self::theme_asset( 'assets/fonts/inter-latin.woff2' );
		$channel   = (string) get_option( MWM_Settings::OPTION_CHANNEL, 'https://www.youtube.com/@mathswithmelissa' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $name ); ?> — coming soon</title>
<style>
	<?php if ( $font ) : ?>
	@font-face {
		font-family: 'Inter';
		src: url('<?php echo esc_url( $font ); ?>') format('woff2');
		font-weight: 100 900;
		font-display: swap;
	}
	<?php endif; ?>
	:root {
		--bg: #FFFFFF; --ink: #171717; --muted: #5C5C5C;
		--blush: #F9E8EE; --border: #F1CBD8; --pink: #C2185B; --pink-dark: #A3144C;
	}
	@media (prefers-color-scheme: dark) {
		:root { --bg: #171717; --ink: #FFFFFF; --muted: #B5B5B5; --blush: #2A1B21; --border: #4A2A38; }
	}
	* { box-sizing: border-box; margin: 0; }
	body {
		background: var(--bg); color: var(--ink);
		font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
		min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center;
		padding: 56px 16px; text-align: center;
	}
	main { max-width: 560px; }
	.logo { height: 72px; width: auto; margin-bottom: 40px; }
	.logo-dark { display: none; }
	@media (prefers-color-scheme: dark) {
		.logo-light { display: none; }
		.logo-dark { display: inline; }
	}
	.badge {
		display: inline-block; background: var(--blush); color: var(--pink);
		border: 1px solid var(--border); border-radius: 999px;
		font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase;
		padding: 6px 14px; margin-bottom: 24px;
	}
	h1 { font-size: 40px; font-weight: 600; line-height: 1.15; margin-bottom: 16px; }
	p { font-size: 16px; line-height: 1.6; color: var(--muted); }
	.actions { margin-top: 32px; }
	.button {
		display: inline-flex; align-items: center; justify-content: center;
		min-height: 44px; padding: 10px 24px; border-radius: 12px;
		background: var(--pink); color: #FFFFFF; font-size: 16px; font-weight: 600; text-decoration: none;
	}
	.button:hover { background: var(--pink-dark); }
	a:focus-visible, .button:focus-visible { outline: 2px solid var(--pink); outline-offset: 2px; }
	footer { margin-top: 64px; font-size: 14px; color: var(--muted); }
	footer a { color: var(--muted); }
	@media (max-width: 700px) {
		h1 { font-size: 32px; }
		.logo { height: 56px; margin-bottom: 32px; }
	}
</style>
</head>
<body>
<main>
	<?php if ( $logo ) : ?>
		<img class="logo logo-light" src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $name ); ?>">
		<?php if ( $logo_dark ) : ?>
			<img class="logo logo-dark" src="<?php echo esc_url( $logo_dark ); ?>" alt="" aria-hidden="true">
		<?php endif; ?>
	<?php endif; ?>
	<p class="badge">Coming soon</p>
	<h1>Free maths help is on its way</h1>
	<p>We're putting the finishing touches to the new <?php echo esc_html( $name ); ?> website: free GCSE and A-level maths lessons, worksheets and revision pathways. Check back soon.</p>
	<?php if ( $channel ) : ?>
		<p class="actions"><a class="button" href="<?php echo esc_url( $channel ); ?>">In the meantime, watch on YouTube</a></p>
	<?php endif; ?>
</main>
<footer>
	<p>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php echo esc_html( $name ); ?> &middot; <a href="<?php echo esc_url( wp_login_url( home_url( '/' ) ) ); ?>">Log in</a></p>
</footer>
</body>
</html>
		<?php
		exit;
	}
}
