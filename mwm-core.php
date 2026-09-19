<?php
/**
 * Plugin Name:       MWM Core
 * Plugin URI:        https://mathswithmelissa.co.uk
 * Description:       Data model, REST API, YouTube playlist sync and the front-end Studio for Maths with Melissa.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Maths with Melissa
 * Text Domain:       mwm-core
 */

defined( 'ABSPATH' ) || exit;

define( 'MWM_CORE_VERSION', '1.0.0' );
define( 'MWM_CORE_FILE', __FILE__ );
define( 'MWM_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'MWM_CORE_URL', plugin_dir_url( __FILE__ ) );

require_once MWM_CORE_DIR . 'includes/helpers.php';
require_once MWM_CORE_DIR . 'includes/class-post-types.php';
require_once MWM_CORE_DIR . 'includes/class-fields.php';
require_once MWM_CORE_DIR . 'includes/class-settings.php';
require_once MWM_CORE_DIR . 'includes/class-coming-soon.php';
require_once MWM_CORE_DIR . 'includes/class-progress.php';
require_once MWM_CORE_DIR . 'includes/class-quiz.php';
require_once MWM_CORE_DIR . 'includes/class-youtube.php';
require_once MWM_CORE_DIR . 'includes/class-rewrites.php';
require_once MWM_CORE_DIR . 'includes/class-redirects.php';
require_once MWM_CORE_DIR . 'includes/class-rest.php';
require_once MWM_CORE_DIR . 'includes/class-studio.php';
require_once MWM_CORE_DIR . 'includes/class-activator.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once MWM_CORE_DIR . 'includes/cli/class-cli.php';
}

register_activation_hook( __FILE__, [ 'MWM_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'MWM_Activator', 'deactivate' ] );

add_action( 'plugins_loaded', static function () {
	MWM_Post_Types::init();
	MWM_Fields::init();
	MWM_Settings::init();
	MWM_Coming_Soon::init();
	MWM_Progress::init();
	MWM_Quiz::init();
	MWM_YouTube::init();
	MWM_Rewrites::init();
	MWM_Redirects::init();
	MWM_REST::init();
	MWM_Studio::init();
} );

add_action( 'admin_notices', static function () {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		echo '<div class="notice notice-error"><p>MWM Core needs <strong>Advanced Custom Fields Pro</strong> to be active.</p></div>';
	}
} );
