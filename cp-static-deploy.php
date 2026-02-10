<?php
/**
 * Plugin Name: CP Static Deploy
 * Plugin URI: https://github.com/seriocomic/cp-static-deploy
 * Description: Static site deployment for ClassicPress. Mirrors the dev site via wget, processes HTML/XML, and deploys to GitHub Pages/Cloudflare Pages via PR.
 * Version: 1.0.3
 * Author: seriocomic
 * Author URI: https://github.com/seriocomic
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 7.4
 * Requires CP: 1.0.0
 * Requires at least: 1.0.0
 * Text Domain: cp-static-deploy
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CPSD_VERSION', '1.0.3' );
define( 'CPSD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CPSD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CPSD_WORKING_DIR', WP_CONTENT_DIR . '/static-deploy' );

// Autoload classes
require_once CPSD_PLUGIN_DIR . 'includes/class-settings.php';
require_once CPSD_PLUGIN_DIR . 'includes/class-processor.php';
require_once CPSD_PLUGIN_DIR . 'includes/class-github.php';
require_once CPSD_PLUGIN_DIR . 'includes/class-deployer.php';
require_once CPSD_PLUGIN_DIR . 'includes/class-admin.php';

/**
 * Main plugin class.
 */
class CP_Static_Deploy {

    private static $instance = null;
    private $settings;
    private $admin;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings = new CPSD_Settings();
        $this->admin = new CPSD_Admin( $this->settings );

        if ( $this->settings->get( 'auto_deploy' ) ) {
            add_action( 'publish_post', array( $this, 'handle_publish' ), 10, 2 );
            add_action( 'publish_page', array( $this, 'handle_publish' ), 10, 2 );
            add_action( 'post_updated', array( $this, 'handle_update' ), 10, 3 );
        }

        add_action( 'cpsd_check_deploy_result', array( $this->admin, 'check_deploy_result' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_fields' ) );
    }

    /**
     * Plugin activation.
     */
    public static function activate() {
        $dirs = array(
            CPSD_WORKING_DIR,
            CPSD_WORKING_DIR . '/build',
            CPSD_WORKING_DIR . '/repo',
            CPSD_WORKING_DIR . '/logs',
        );

        foreach ( $dirs as $dir ) {
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }
        }

        // Create default run-deploy.sh wrapper
        $wrapper = CPSD_WORKING_DIR . '/run-deploy.sh';
        if ( ! file_exists( $wrapper ) ) {
            $content = "#!/usr/bin/env bash\n";
            $content .= "# Thin wrapper for sudo execution\n";
            $content .= "# Called by the plugin via: sudo -u <deploy_user> bash <this_script>\n";
            $content .= "set -euo pipefail\n\n";
            $content .= "WP_PATH=\"" . ABSPATH . "\"\n";
            $content .= "cd \"\$WP_PATH\"\n\n";
            $content .= "# Run the deployer within ClassicPress context\n";
            $content .= "php -r \"\n";
            $content .= "define('ABSPATH', '\$WP_PATH');\n";
            $content .= "define('CPSD_DEPLOYER_RUN', true);\n";
            $content .= "require_once ABSPATH . 'wp-load.php';\n";
            $content .= "do_action('cpsd_run_deploy');\n";
            $content .= "\"\n";
            file_put_contents( $wrapper, $content );
            chmod( $wrapper, 0755 );
        }

        CPSD_Settings::set_defaults();
    }

    /**
     * Plugin deactivation.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( 'cpsd_check_deploy_result' );
    }

    /**
     * Handle post/page publish.
     */
    public function handle_publish( $post_id, $post ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }
        $this->admin->trigger_deploy( $post_id, $post );
    }

    /**
     * Handle post/page update (already published).
     */
    public function handle_update( $post_id, $post_after, $post_before ) {
        if ( 'publish' === $post_after->post_status && 'publish' === $post_before->post_status ) {
            remove_action( 'publish_post', array( $this, 'handle_publish' ), 10 );
            remove_action( 'publish_page', array( $this, 'handle_publish' ), 10 );
            $this->admin->trigger_deploy( $post_id, $post_after );
        }
    }

    /**
     * Register custom REST API query parameters.
     */
    public function register_rest_fields() {
        $post_types = array( 'post', 'page' );

        foreach ( $post_types as $post_type ) {
            add_filter( "rest_{$post_type}_query", array( $this, 'filter_rest_query_by_modified_date' ), 10, 2 );
        }
    }

    /**
     * Filter REST API queries to support modified_after parameter.
     *
     * @param array           $args    WP_Query arguments.
     * @param WP_REST_Request $request REST API request.
     * @return array Modified query arguments.
     */
    public function filter_rest_query_by_modified_date( $args, $request ) {
        if ( isset( $request['modified_after'] ) ) {
            $modified_after = $request['modified_after'];

            // Validate ISO 8601 format (YYYY-MM-DDTHH:MM:SS)
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $modified_after ) ) {
                $args['date_query'] = array(
                    array(
                        'column' => 'post_modified_gmt',
                        'after'  => $modified_after,
                        'inclusive' => false,
                    ),
                );
            }
        }

        return $args;
    }
}

// Activation/deactivation hooks
register_activation_hook( __FILE__, array( 'CP_Static_Deploy', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CP_Static_Deploy', 'deactivate' ) );

// Initialize
add_action( 'plugins_loaded', array( 'CP_Static_Deploy', 'get_instance' ) );

// Deploy runner hook (called from run-deploy.sh via wp-load.php)
add_action( 'cpsd_run_deploy', function() {
    if ( ! defined( 'CPSD_DEPLOYER_RUN' ) ) {
        return;
    }
    $settings = new CPSD_Settings();
    $deployer = new CPSD_Deployer( $settings );
    $deployer->run();
} );
