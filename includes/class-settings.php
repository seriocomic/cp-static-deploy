<?php
/**
 * Settings management for CP Static Deploy.
 *
 * Handles registration, storage, encryption, and validation of all plugin settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPSD_Settings {

    const OPTION_PREFIX = 'cpsd_';
    const SETTINGS_GROUP = 'cpsd_settings';

    /**
     * Default settings with types and descriptions.
     */
    private static $defaults = array(
        'source_url'            => array( 'default' => '', 'type' => 'url', 'label' => 'Source URL', 'description' => 'Dev site URL to crawl (e.g. https://dev.example.com)' ),
        'production_url'        => array( 'default' => '', 'type' => 'url', 'label' => 'Production URL', 'description' => 'Production URL to rewrite to (e.g. https://www.example.com)' ),
        'exclude_domains'       => array( 'default' => '', 'type' => 'text', 'label' => 'Exclude Domains', 'description' => 'Comma-separated domains to exclude from wget (e.g. assets.example.com)' ),
        'github_repo'           => array( 'default' => '', 'type' => 'text', 'label' => 'GitHub Repository', 'description' => 'GitHub repo in owner/repo format (e.g. user/static-site)' ),
        'github_token'          => array( 'default' => '', 'type' => 'password', 'label' => 'GitHub Token', 'description' => 'Personal Access Token with repo scope. Stored encrypted.' ),
        'git_staging_branch'    => array( 'default' => 'staging', 'type' => 'text', 'label' => 'Staging Branch', 'description' => 'Git branch for deploy commits' ),
        'git_production_branch' => array( 'default' => 'master', 'type' => 'text', 'label' => 'Production Branch', 'description' => 'Git branch that PRs merge into' ),
        'deploy_user'           => array( 'default' => '', 'type' => 'text', 'label' => 'Deploy User', 'description' => 'System user that runs deploy (e.g. webmin). Must have write access to working directory.' ),
        'cache_clean_pages'     => array( 'default' => 'index.html,about/index.html,archives/index.html,contact/index.html', 'type' => 'textarea', 'label' => 'Cache Clean Pages', 'description' => 'Comma-separated paths to clean from wget cache before each build' ),
        'robots_txt'            => array( 'default' => "User-Agent: *\nSitemap: {{production_url}}/sitemap.xml", 'type' => 'textarea', 'label' => 'Robots.txt Content', 'description' => 'Content for robots.txt. Use {{production_url}} as placeholder.' ),
        'selective_threshold'   => array( 'default' => '100', 'type' => 'number', 'label' => 'Selective Threshold', 'description' => 'Maximum changed items before using full rebuild instead of selective' ),
        'auto_deploy'           => array( 'default' => '1', 'type' => 'checkbox', 'label' => 'Auto Deploy', 'description' => 'Automatically trigger deploy when posts/pages are published or updated' ),
        'pr_auto_merge_label'   => array( 'default' => 'auto-merge', 'type' => 'text', 'label' => 'Auto-Merge Label', 'description' => 'GitHub label to add to PRs for auto-merge' ),
        'readme_content'        => array( 'default' => '', 'type' => 'textarea', 'label' => 'README Content', 'description' => 'Content for README.md in the static site repo. Leave empty to skip.' ),
        'wget_extra_args'       => array( 'default' => '--no-check-certificate', 'type' => 'text', 'label' => 'Extra wget Arguments', 'description' => 'Additional arguments passed to wget (e.g. --no-check-certificate for self-signed certs)' ),
    );

    /**
     * Get a setting value.
     */
    public function get( $key ) {
        $option_name = self::OPTION_PREFIX . $key;
        $spec = isset( self::$defaults[ $key ] ) ? self::$defaults[ $key ] : null;

        if ( ! $spec ) {
            return null;
        }

        $value = get_option( $option_name, $spec['default'] );

        // Decrypt sensitive fields
        if ( 'password' === $spec['type'] && ! empty( $value ) ) {
            $value = $this->decrypt( $value );
        }

        // Process template variables
        if ( 'textarea' === $spec['type'] && strpos( $value, '{{' ) !== false ) {
            $value = str_replace( '{{production_url}}', $this->get( 'production_url' ), $value );
        }

        return $value;
    }

    /**
     * Set a setting value.
     */
    public function set( $key, $value ) {
        $option_name = self::OPTION_PREFIX . $key;
        $spec = isset( self::$defaults[ $key ] ) ? self::$defaults[ $key ] : null;

        if ( ! $spec ) {
            return false;
        }

        // Encrypt sensitive fields
        if ( 'password' === $spec['type'] && ! empty( $value ) ) {
            $value = $this->encrypt( $value );
        }

        return update_option( $option_name, $value );
    }

    /**
     * Get all settings specs.
     */
    public static function get_specs() {
        return self::$defaults;
    }

    /**
     * Set default values for all settings.
     */
    public static function set_defaults() {
        foreach ( self::$defaults as $key => $spec ) {
            $option_name = self::OPTION_PREFIX . $key;
            if ( false === get_option( $option_name ) ) {
                add_option( $option_name, $spec['default'] );
            }
        }
    }

    /**
     * Get the source domain from source_url (e.g. dev.example.com).
     */
    public function get_source_domain() {
        $url = $this->get( 'source_url' );
        return $url ? wp_parse_url( $url, PHP_URL_HOST ) : '';
    }

    /**
     * Get the production domain from production_url (e.g. www.example.com).
     */
    public function get_production_domain() {
        $url = $this->get( 'production_url' );
        return $url ? wp_parse_url( $url, PHP_URL_HOST ) : '';
    }

    /**
     * Encrypt a value using AES-256-CBC.
     */
    private function encrypt( $value ) {
        $key = $this->get_encryption_key();
        $iv = openssl_random_pseudo_bytes( openssl_cipher_iv_length( 'aes-256-cbc' ) );
        $encrypted = openssl_encrypt( $value, 'aes-256-cbc', $key, 0, $iv );
        return base64_encode( $iv . $encrypted );
    }

    /**
     * Decrypt a value.
     */
    private function decrypt( $value ) {
        $key = $this->get_encryption_key();
        $data = base64_decode( $value );
        $iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );

        if ( strlen( $data ) < $iv_length ) {
            return $value; // Not encrypted or corrupted
        }

        $iv = substr( $data, 0, $iv_length );
        $encrypted = substr( $data, $iv_length );
        $decrypted = openssl_decrypt( $encrypted, 'aes-256-cbc', $key, 0, $iv );

        return false !== $decrypted ? $decrypted : $value;
    }

    /**
     * Derive encryption key from WordPress auth constants.
     */
    private function get_encryption_key() {
        $salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'cpsd-fallback-key';
        $salt .= defined( 'AUTH_SALT' ) ? AUTH_SALT : 'cpsd-fallback-salt';
        return hash( 'sha256', $salt, true );
    }

    /**
     * Check system prerequisites and return status.
     */
    public function check_prerequisites() {
        $checks = array();

        // Check git
        $git_version = shell_exec( 'git --version 2>&1' );
        $checks['git'] = array(
            'label'  => 'Git',
            'ok'     => (bool) preg_match( '/git version/', $git_version ),
            'detail' => $git_version ? trim( $git_version ) : 'Not found',
        );

        // Check wget
        $wget_version = shell_exec( 'wget --version 2>&1 | head -1' );
        $checks['wget'] = array(
            'label'  => 'wget',
            'ok'     => (bool) preg_match( '/GNU Wget/', $wget_version ),
            'detail' => $wget_version ? trim( $wget_version ) : 'Not found',
        );

        // Check working directory
        $work_dir = CPSD_WORKING_DIR;
        $checks['work_dir'] = array(
            'label'  => 'Working Directory',
            'ok'     => is_dir( $work_dir ) && is_writable( $work_dir ),
            'detail' => is_dir( $work_dir ) ? ( is_writable( $work_dir ) ? $work_dir : 'Not writable' ) : 'Does not exist',
        );

        // Check repo directory
        $repo_dir = $work_dir . '/repo';
        $is_git_repo = is_dir( $repo_dir . '/.git' );
        $checks['repo'] = array(
            'label'  => 'Git Repository',
            'ok'     => $is_git_repo,
            'detail' => $is_git_repo ? 'Initialized' : 'Not initialized - clone the repo into ' . $repo_dir,
        );

        // Check deploy user
        $deploy_user = $this->get( 'deploy_user' );
        if ( $deploy_user ) {
            $user_exists = shell_exec( sprintf( 'id %s 2>&1', escapeshellarg( $deploy_user ) ) );
            $checks['deploy_user'] = array(
                'label'  => 'Deploy User',
                'ok'     => (bool) preg_match( '/uid=/', $user_exists ),
                'detail' => preg_match( '/uid=/', $user_exists ) ? $deploy_user : 'User not found: ' . $deploy_user,
            );

            // Check sudo access
            $sudo_check = shell_exec( sprintf( 'sudo -n -u %s true 2>&1', escapeshellarg( $deploy_user ) ) );
            $checks['sudo'] = array(
                'label'  => 'Sudo Access',
                'ok'     => empty( trim( $sudo_check ) ),
                'detail' => empty( trim( $sudo_check ) ) ? 'www-data can sudo to ' . $deploy_user : 'Sudo not configured: ' . trim( $sudo_check ),
            );
        }

        // Check GitHub token
        $token = $this->get( 'github_token' );
        $checks['github_token'] = array(
            'label'  => 'GitHub Token',
            'ok'     => ! empty( $token ),
            'detail' => ! empty( $token ) ? 'Configured' : 'Not set',
        );

        // Check source URL
        $source_url = $this->get( 'source_url' );
        $checks['source_url'] = array(
            'label'  => 'Source URL',
            'ok'     => ! empty( $source_url ),
            'detail' => ! empty( $source_url ) ? $source_url : 'Not configured',
        );

        return $checks;
    }

    /**
     * Register settings page.
     */
    public function register_settings_page() {
        // Settings are saved via custom handler, not WordPress Settings API
        // This avoids the complexity of registering individual fields
    }

    /**
     * Save settings from POST data.
     */
    public function save_from_post( $post_data ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        foreach ( self::$defaults as $key => $spec ) {
            if ( isset( $post_data[ 'cpsd_' . $key ] ) ) {
                $value = $post_data[ 'cpsd_' . $key ];

                // Skip empty password fields (keep existing value)
                if ( 'password' === $spec['type'] && empty( $value ) ) {
                    continue;
                }

                // Sanitize based on type
                switch ( $spec['type'] ) {
                    case 'url':
                        $value = esc_url_raw( $value );
                        break;
                    case 'number':
                        $value = absint( $value );
                        break;
                    case 'checkbox':
                        $value = '1';
                        break;
                    default:
                        $value = sanitize_textarea_field( $value );
                        break;
                }

                $this->set( $key, $value );
            } elseif ( 'checkbox' === $spec['type'] ) {
                // Unchecked checkboxes aren't in POST data
                $this->set( $key, '0' );
            }
        }

        return true;
    }
}
