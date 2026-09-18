<?php
/**
 * /studio/ — full-page template. Chrome mirrors Admin.dc.html; the views are rendered by studio.js.
 */

defined( 'ABSPATH' ) || exit;

$user   = wp_get_current_user();
$name   = $user->first_name ?: ( $user->display_name ?: $user->user_login );
$name   = explode( ' ', trim( $name ) )[0];
$theme  = get_theme_file_uri( 'assets/img' );
$views  = [ 'dash' => 'Dashboard', 'lesson' => 'Add a lesson', 'worksheets' => 'Add a worksheet', 'content' => 'Your content', 'papers' => 'Past papers', 'dates' => 'Exam dates' ];
$active = sanitize_key( (string) get_query_var( 'mwm_studio_view' ) ) ?: 'dash';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>Studio · Maths with Melissa</title>
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'mwm mwm-studio' ); ?>>
<div class="wp-site-blocks">
	<header class="mwm-header">
		<div class="mwm-header__inner">
			<div class="mwm-header__brand mwm-header__brand--admin">
				<a href="<?php echo esc_url( MWM_Studio::url() ); ?>" aria-label="Studio home" class="mwm-logo">
					<img class="mwm-logo__full is-light" src="<?php echo esc_url( $theme . '/logo-charcoal.svg' ); ?>" alt="Maths with Melissa" width="111" height="44">
					<img class="mwm-logo__icon is-light" src="<?php echo esc_url( $theme . '/icon-charcoal.svg' ); ?>" alt="Maths with Melissa" width="36" height="36">
					<img class="mwm-logo__full is-dark" src="<?php echo esc_url( $theme . '/logo-white.svg' ); ?>" alt="Maths with Melissa" width="89" height="44">
					<img class="mwm-logo__icon is-dark" src="<?php echo esc_url( $theme . '/icon-white.svg' ); ?>" alt="Maths with Melissa" width="36" height="36">
					<span class="mwm-header__badge">Admin</span>
				</a>
				<nav aria-label="Admin sections" class="mwm-nav mwm-nav--admin" data-studio-nav>
					<?php foreach ( $views as $key => $label ) : ?>
						<a href="<?php echo esc_url( MWM_Studio::url( $key === 'dash' ? '' : $key ) ); ?>" class="mwm-nav__link<?php echo $active === $key ? ' is-current' : ''; ?>" data-view="<?php echo esc_attr( $key ); ?>"<?php echo $active === $key ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</nav>
			</div>
			<div class="mwm-header__tools">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="mwm-header__viewsite">View the site<span aria-hidden="true">→</span></a>
				<button type="button" class="mwm-theme-toggle" aria-label="Switch between light and dark mode" data-mwm-theme-toggle>
					<span class="mwm-theme-toggle__moon"><?php echo function_exists( 'mwm_icon' ) ? mwm_icon( 'moon', 18 ) : ''; ?></span>
					<span class="mwm-theme-toggle__sun"><?php echo function_exists( 'mwm_icon' ) ? mwm_icon( 'sun', 18 ) : ''; ?></span>
				</button>
				<span class="mwm-userpill mwm-userpill--static"><span class="mwm-avatar" aria-hidden="true"><?php echo esc_html( strtoupper( mb_substr( $name, 0, 1 ) ) ); ?></span><?php echo esc_html( $name ); ?></span>
			</div>
		</div>
	</header>
	<main class="mwm-main">
		<div id="mwm-studio" class="st-page" aria-live="polite">
			<noscript><p class="mwm-intro">The Studio needs JavaScript. You can also add lessons from the WordPress dashboard.</p></noscript>
		</div>
	</main>
</div>
<?php wp_footer(); ?>
</body>
</html>
