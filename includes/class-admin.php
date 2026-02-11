<?php
/**
 * Admin UI for CP Static Deploy.
 *
 * Handles admin menu pages, AJAX endpoints, deploy triggering,
 * status display, and log viewing.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPSD_Admin {

    private $settings;
    private $trigger_log;

    const OPTION_LAST_DEPLOY = 'cpsd_last_deploy';
    const OPTION_LAST_RESULT = 'cpsd_last_result';

    public function __construct( CPSD_Settings $settings ) {
        $this->settings    = $settings;
        $this->trigger_log = CPSD_WORKING_DIR . '/logs/trigger.log';

        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_notices', array( $this, 'show_admin_notice' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // AJAX handlers
        add_action( 'wp_ajax_cpsd_manual_deploy', array( $this, 'ajax_manual_deploy' ) );
        add_action( 'wp_ajax_cpsd_manual_full_deploy', array( $this, 'ajax_manual_full_deploy' ) );
        add_action( 'wp_ajax_cpsd_get_status', array( $this, 'ajax_get_status' ) );
        add_action( 'wp_ajax_cpsd_test_github', array( $this, 'ajax_test_github' ) );
    }

    /**
     * Register admin menu pages.
     */
    public function add_admin_menu() {
        add_management_page(
            'Static Deploy',
            'Static Deploy',
            'manage_options',
            'cp-static-deploy',
            array( $this, 'render_status_page' )
        );

        add_options_page(
            'Static Deploy Settings',
            'Static Deploy',
            'manage_options',
            'cp-static-deploy-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Enqueue admin CSS on plugin pages.
     */
    public function enqueue_assets( $hook ) {
        if ( false === strpos( $hook, 'cp-static-deploy' ) ) {
            return;
        }

        wp_enqueue_style(
            'cpsd-admin',
            CPSD_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            CPSD_VERSION
        );
    }

    /**
     * Render the settings page.
     */
    public function render_settings_page() {
        $saved = '';

        // Handle form submission
        if ( isset( $_POST['cpsd_nonce'] ) && wp_verify_nonce( $_POST['cpsd_nonce'], 'cpsd_save_settings' ) ) {
            $this->settings->save_from_post( $_POST );
            $saved = '1';
        }

        $settings = $this->settings;
        $checks = $this->settings->check_prerequisites();

        include CPSD_PLUGIN_DIR . 'templates/settings-page.php';
    }

    /**
     * Render the deploy status page.
     */
    public function render_status_page() {
        $this->check_for_new_deploys();

        $deployer    = new CPSD_Deployer( $this->settings );
        $is_running  = $deployer->is_running();
        $last_deploy = get_option( self::OPTION_LAST_DEPLOY );
        $last_result = get_option( self::OPTION_LAST_RESULT, array() );
        $recent_logs = $deployer->get_recent_logs( 50 );
        $github_repo = $this->settings->get( 'github_repo' );
        $production_url = $this->settings->get( 'production_url' );

        $status = $this->build_status_data( $is_running, $last_result );

        include CPSD_PLUGIN_DIR . 'templates/status-page.php';
    }

    /**
     * Trigger a deploy from a post publish/update hook.
     */
    public function trigger_deploy( $post_id, $post ) {
        $deployer = new CPSD_Deployer( $this->settings );

        if ( $deployer->is_running() ) {
            $this->trigger_log( "Deploy already in progress, skipping trigger for: {$post->post_title}" );
            return;
        }

        $this->trigger_log( "Deploy triggered by post: {$post->post_title} (ID: {$post_id})" );

        $this->start_background_deploy();

        set_transient( 'cpsd_deploy_triggered', true, 30 );
    }

    /**
     * AJAX handler for manual deploy.
     */
    public function ajax_manual_deploy() {
        check_ajax_referer( 'cpsd_deploy', '_ajax_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        $deployer = new CPSD_Deployer( $this->settings );

        if ( $deployer->is_running() ) {
            wp_send_json_error( 'Deploy already in progress' );
        }

        $this->trigger_log( 'Manual deploy triggered from admin panel' );
        $this->start_background_deploy();

        $now = time();
        update_option( self::OPTION_LAST_DEPLOY, $now );
        delete_option( self::OPTION_LAST_RESULT );
        $this->schedule_result_check();

        $time_display = wp_date( 'Y-m-d H:i:s', $now );

        wp_send_json_success( array(
            'time'      => $time_display,
            'time_html' => '<strong>' . esc_html( $time_display ) . '</strong> <span style="color: #666;">(just now)</span>',
        ) );
    }

    /**
     * AJAX handler for manual full deploy.
     */
    public function ajax_manual_full_deploy() {
        check_ajax_referer( 'cpsd_full_deploy', '_ajax_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        $deployer = new CPSD_Deployer( $this->settings );

        if ( $deployer->is_running() ) {
            wp_send_json_error( 'Deploy already in progress' );
        }

        // Delete timestamp file to force full rebuild
        $timestamp_file = CPSD_WORKING_DIR . '/.last-build-time';
        $timestamp_deleted = false;
        if ( file_exists( $timestamp_file ) ) {
            $timestamp_deleted = unlink( $timestamp_file );
        }

        if ( $timestamp_deleted ) {
            $this->trigger_log( 'Manual FULL deploy triggered from admin panel (timestamp file deleted)' );
            $this->write_deploy_log( 'FULL REBUILD requested - timestamp file deleted, will mirror entire site' );
        } else {
            $this->trigger_log( 'Manual FULL deploy triggered from admin panel (timestamp file not found - will be full rebuild anyway)' );
            $this->write_deploy_log( 'FULL REBUILD requested - no previous build found, will mirror entire site' );
        }

        $this->start_background_deploy();

        $now = time();
        update_option( self::OPTION_LAST_DEPLOY, $now );
        delete_option( self::OPTION_LAST_RESULT );
        $this->schedule_result_check();

        $time_display = wp_date( 'Y-m-d H:i:s', $now );

        wp_send_json_success( array(
            'time'      => $time_display,
            'time_html' => '<strong>' . esc_html( $time_display ) . '</strong> <span style="color: #666;">(just now)</span>',
        ) );
    }

    /**
     * AJAX handler for status refresh.
     */
    public function ajax_get_status() {
        check_ajax_referer( 'cpsd_status', '_ajax_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        $this->check_for_new_deploys();

        $deployer    = new CPSD_Deployer( $this->settings );
        $is_running  = $deployer->is_running();
        $last_deploy = get_option( self::OPTION_LAST_DEPLOY );
        $last_result = get_option( self::OPTION_LAST_RESULT, array() );

        $status = $this->build_status_data( $is_running, $last_result );

        $last_deploy_display = $last_deploy ? wp_date( 'Y-m-d H:i:s', $last_deploy ) : 'Never';
        $time_ago = $last_deploy ? human_time_diff( $last_deploy, time() ) . ' ago' : '';
        $last_deploy_html = $last_deploy
            ? '<strong>' . esc_html( $last_deploy_display ) . '</strong> <span style="color: #666;">(' . esc_html( $time_ago ) . ')</span>'
            : 'Never';

        wp_send_json_success( array(
            'is_running'          => $is_running,
            'status_html'         => $status['html'],
            'last_deploy'         => $last_deploy_display,
            'last_deploy_html'    => $last_deploy_html,
            'last_result_message' => ! empty( $last_result['message'] ) ? $last_result['message'] : '',
            'logs'                => $deployer->get_recent_logs( 50 ),
        ) );
    }

    /**
     * AJAX handler for testing GitHub connection.
     */
    public function ajax_test_github() {
        check_ajax_referer( 'cpsd_test_github', '_ajax_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        $github = new CPSD_GitHub( $this->settings );
        $result = $github->test_connection();

        wp_send_json_success( $result );
    }

    /**
     * Check deploy result from log, called by scheduled event.
     */
    public function check_deploy_result() {
        $deployer = new CPSD_Deployer( $this->settings );

        if ( $deployer->is_running() ) {
            wp_schedule_single_event( time() + 30, 'cpsd_check_deploy_result' );
            return;
        }

        $log_tail = $deployer->get_recent_logs( 10 );

        if ( strpos( $log_tail, 'No changes detected via API. Skipping deployment.' ) !== false ) {
            update_option( self::OPTION_LAST_RESULT, array(
                'type'    => 'no_changes',
                'message' => 'Build completed but no content changes were detected. Site is already up to date.',
                'time'    => time(),
            ) );
        } elseif ( strpos( $log_tail, 'Auto-deploy completed successfully' ) !== false ) {
            update_option( self::OPTION_LAST_RESULT, array(
                'type'    => 'success',
                'message' => 'Build completed and PR created successfully.',
                'time'    => time(),
            ) );
        } elseif ( strpos( $log_tail, 'ERROR:' ) !== false ) {
            preg_match( '/ERROR: (.+)$/m', $log_tail, $matches );
            $error_msg = isset( $matches[1] ) ? $matches[1] : 'Unknown error';
            update_option( self::OPTION_LAST_RESULT, array(
                'type'    => 'error',
                'message' => 'Build failed: ' . $error_msg,
                'time'    => time(),
            ) );
        }
    }

    /**
     * Show admin notice after deploy trigger.
     */
    public function show_admin_notice() {
        if ( get_transient( 'cpsd_deploy_triggered' ) ) {
            delete_transient( 'cpsd_deploy_triggered' );
            $status_url = admin_url( 'tools.php?page=cp-static-deploy' );
            printf(
                '<div class="notice notice-info is-dismissible"><p><strong>Static Deploy:</strong> Build has been triggered. <a href="%s">View status</a>. If no content changes are detected, deployment will be skipped.</p></div>',
                esc_url( $status_url )
            );
        }
    }

    /**
     * Start the deploy process in the background.
     */
    private function start_background_deploy() {
        $deploy_user = $this->settings->get( 'deploy_user' );
        $wrapper     = CPSD_WORKING_DIR . '/run-deploy.sh';

        // Write immediate feedback to deploy log
        $this->write_deploy_log( 'Deploy triggered from admin panel, starting background process...' );

        if ( ! empty( $deploy_user ) && file_exists( $wrapper ) ) {
            $command = sprintf(
                'sudo -u %s bash %s > /dev/null 2>&1 &',
                escapeshellarg( $deploy_user ),
                escapeshellarg( $wrapper )
            );
        } else {
            // Fall back to running as current user
            $command = sprintf( 'bash %s > /dev/null 2>&1 &', escapeshellarg( $wrapper ) );
        }

        exec( $command, $output, $return_var );

        if ( 0 === $return_var ) {
            $this->trigger_log( 'Deploy script triggered successfully' );
            update_option( self::OPTION_LAST_DEPLOY, time() );
            delete_option( self::OPTION_LAST_RESULT );
            $this->schedule_result_check();
        } else {
            $this->trigger_log( "ERROR: Failed to trigger deploy script (exit code: $return_var)" );
            $this->write_deploy_log( "ERROR: Failed to trigger deploy script (exit code: $return_var)" );
        }
    }

    /**
     * Schedule a result check event.
     */
    private function schedule_result_check() {
        wp_schedule_single_event( time() + 60, 'cpsd_check_deploy_result' );
    }

    /**
     * Check for deploys that completed outside the plugin (e.g. cron, CLI).
     */
    private function check_for_new_deploys() {
        $deployer = new CPSD_Deployer( $this->settings );
        $log_file = $deployer->get_log_file();

        if ( ! file_exists( $log_file ) ) {
            return;
        }

        $last_deploy = get_option( self::OPTION_LAST_DEPLOY, 0 );
        $log_content = file_get_contents( $log_file );
        $lines = explode( "\n", $log_content );

        $most_recent_completion = 0;

        foreach ( array_reverse( $lines ) as $line ) {
            if ( strpos( $line, 'Auto-deploy completed successfully' ) !== false ||
                 strpos( $line, 'No changes detected' ) !== false ) {
                if ( preg_match( '/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches ) ) {
                    $tz = new DateTimeZone( get_option( 'timezone_string' ) ?: 'UTC' );
                    $dt = new DateTime( $matches[1], $tz );
                    $most_recent_completion = $dt->getTimestamp();
                    break;
                }
            }
        }

        if ( $most_recent_completion > $last_deploy ) {
            update_option( self::OPTION_LAST_DEPLOY, $most_recent_completion );
            $this->check_deploy_result();
        }
    }

    /**
     * Build status display data.
     */
    private function build_status_data( $is_running, $last_result ) {
        if ( $is_running ) {
            return array(
                'message' => 'Build in progress...',
                'color'   => '#d63638',
                'html'    => '<span style="color: #d63638;">Build in progress...</span>',
            );
        }

        if ( ! empty( $last_result['type'] ) ) {
            switch ( $last_result['type'] ) {
                case 'no_changes':
                    return array(
                        'message' => 'Idle (last build: no changes)',
                        'color'   => '#996600',
                        'html'    => '<span style="color: #996600;">Idle (last build: no changes)</span>',
                    );
                case 'success':
                    return array(
                        'message' => 'Idle (last build: success)',
                        'color'   => '#00a32a',
                        'html'    => '<span style="color: #00a32a;">Idle (last build: success)</span>',
                    );
                case 'error':
                    return array(
                        'message' => 'Idle (last build: error)',
                        'color'   => '#d63638',
                        'html'    => '<span style="color: #d63638;">Idle (last build: error)</span>',
                    );
            }
        }

        return array(
            'message' => 'Idle',
            'color'   => '#00a32a',
            'html'    => '<span style="color: #00a32a;">Idle</span>',
        );
    }

    /**
     * Log to trigger log file.
     */
    private function trigger_log( $message ) {
        $timestamp = wp_date( 'Y-m-d H:i:s' );
        $line = sprintf( "[%s] %s\n", $timestamp, $message );

        $log_dir = dirname( $this->trigger_log );
        if ( ! is_dir( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
            $this->fix_permissions( $log_dir );
        }

        file_put_contents( $this->trigger_log, $line, FILE_APPEND | LOCK_EX );
        $this->fix_permissions( $this->trigger_log );
    }

    /**
     * Write directly to the deploy log file for immediate user feedback.
     */
    private function write_deploy_log( $message ) {
        $deploy_log = CPSD_WORKING_DIR . '/logs/deploy.log';
        $timestamp = wp_date( 'Y-m-d H:i:s' );
        $line = sprintf( "[%s] %s\n", $timestamp, $message );

        $log_dir = dirname( $deploy_log );
        if ( ! is_dir( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
            $this->fix_permissions( $log_dir );
        }

        file_put_contents( $deploy_log, $line, FILE_APPEND | LOCK_EX );
        $this->fix_permissions( $deploy_log );
    }

    /**
     * Fix file/directory permissions for deploy user access.
     *
     * @param string $path Path to fix permissions for.
     */
    private function fix_permissions( $path ) {
        if ( ! file_exists( $path ) ) {
            return;
        }

        $deploy_user = $this->settings->get( 'deploy_user' );
        if ( empty( $deploy_user ) ) {
            return;
        }

        // Set ownership to deploy_user:www-data and permissions to 775/664
        if ( is_dir( $path ) ) {
            @chmod( $path, 0775 );
            @exec( sprintf( 'sudo chown -R %s:www-data %s 2>/dev/null',
                escapeshellarg( $deploy_user ),
                escapeshellarg( $path )
            ) );
        } else {
            @chmod( $path, 0664 );
            @exec( sprintf( 'sudo chown %s:www-data %s 2>/dev/null',
                escapeshellarg( $deploy_user ),
                escapeshellarg( $path )
            ) );
        }
    }
}
