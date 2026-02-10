<?php
/**
 * Deploy orchestrator for CP Static Deploy.
 *
 * Replaces deploy.sh. Handles change detection via WordPress REST API,
 * build strategy, post-processing pipeline, git operations, and PR creation.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPSD_Deployer {

    private $settings;
    private $log_file;
    private $lock_file;
    private $timestamp_file;

    public function __construct( CPSD_Settings $settings ) {
        $this->settings       = $settings;
        $this->log_file       = CPSD_WORKING_DIR . '/logs/deploy.log';
        $this->lock_file      = CPSD_WORKING_DIR . '/.lock';
        $this->timestamp_file = CPSD_WORKING_DIR . '/.last-build-time';
    }

    /**
     * Main deploy entry point.
     */
    public function run() {
        // Acquire lock
        if ( file_exists( $this->lock_file ) ) {
            $this->log( 'Deploy already in progress (lock file exists). Exiting.' );
            return false;
        }

        file_put_contents( $this->lock_file, getmypid() );

        try {
            return $this->execute();
        } catch ( \Exception $e ) {
            $this->log( 'ERROR: ' . $e->getMessage() );
            return false;
        } finally {
            @unlink( $this->lock_file );
        }
    }

    /**
     * Execute the deploy pipeline.
     */
    private function execute() {
        $this->log( '=========================================' );
        $this->log( 'Starting auto-deploy process' );
        $this->log( '=========================================' );

        $build_dir = CPSD_WORKING_DIR . '/build';
        $repo_dir  = CPSD_WORKING_DIR . '/repo';

        // Step 1: Check for changes via WordPress REST API
        $changes = $this->detect_changes();

        if ( false === $changes ) {
            $this->log( 'ERROR: Failed to query WordPress API' );
            return false;
        }

        if ( empty( $changes['urls'] ) ) {
            $this->log( 'No changes detected via API. Skipping deployment.' );
            return true;
        }

        $total = $changes['total'];
        $this->log( sprintf( 'Detected %d changed items (%d posts, %d pages)',
            $total, $changes['posts_count'], $changes['pages_count'] ) );

        // Step 2: Determine build strategy
        $selective_urls = null;
        $threshold = (int) $this->settings->get( 'selective_threshold' );

        if ( $changes['is_first_build'] ) {
            $this->log( 'First build - using full mirror' );
        } elseif ( $total > $threshold ) {
            $this->log( sprintf( 'Many changes (%d > %d threshold) - using full mirror', $total, $threshold ) );
        } else {
            $this->log( sprintf( 'Using selective build for %d changed items', $total ) );
            $selective_urls = $this->build_selective_urls( $changes['urls'] );
            $this->log( sprintf( 'Will rebuild %d URLs (including dependencies)', count( $selective_urls ) ) );
        }

        // Step 3: Run the processing pipeline
        $processor = new CPSD_Processor( $this->settings, array( $this, 'log' ) );

        if ( ! $processor->run_pipeline( $build_dir, $repo_dir, $selective_urls ) ) {
            $this->log( 'ERROR: Build pipeline failed' );
            return false;
        }

        $this->log( 'Build pipeline completed successfully' );

        // Save timestamp for next build
        file_put_contents( $this->timestamp_file, gmdate( 'Y-m-d\TH:i:s' ) );

        // Step 4: Git operations
        if ( ! $this->git_commit_and_push( $repo_dir ) ) {
            return false;
        }

        // Step 5: Create/update PR
        if ( ! $this->create_pr( $repo_dir ) ) {
            return false;
        }

        $this->log( '=========================================' );
        $this->log( 'Auto-deploy completed successfully' );
        $this->log( '=========================================' );

        return true;
    }

    /**
     * Detect changes via direct database query (fallback to REST API if needed).
     *
     * @return array|false Array with change data, or false on failure.
     */
    private function detect_changes() {
        // Try database method first, fallback to REST API if it fails
        $result = $this->detect_changes_via_database();

        if ( false === $result ) {
            $this->log( 'Database method failed, falling back to REST API...' );
            $result = $this->detect_changes_via_rest_api();
        }

        return $result;
    }

    /**
     * Detect changes via direct database query.
     *
     * @return array|false Array with change data, or false on failure.
     */
    private function detect_changes_via_database() {
        global $wpdb;

        // Read last build timestamp
        $is_first_build = false;
        if ( file_exists( $this->timestamp_file ) ) {
            $last_build = trim( file_get_contents( $this->timestamp_file ) );
            $this->log( "Last build: $last_build" );
        } else {
            $last_build = '1970-01-01T00:00:00';
            $is_first_build = true;
            $this->log( 'First build - will do full mirror' );
        }

        $this->log( 'Checking for changed content via database...' );

        try {
            // Query database directly for changed posts/pages
            $last_build_mysql = str_replace( 'T', ' ', $last_build );

            // Get changed posts
            $posts = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID, post_name, post_type, post_modified_gmt
                FROM {$wpdb->posts}
                WHERE post_type = 'post'
                AND post_status = 'publish'
                AND post_modified_gmt > %s
                ORDER BY post_modified_gmt DESC
                LIMIT 100",
                $last_build_mysql
            ) );

            // Get changed pages
            $pages = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID, post_name, post_type, post_modified_gmt
                FROM {$wpdb->posts}
                WHERE post_type = 'page'
                AND post_status = 'publish'
                AND post_modified_gmt > %s
                ORDER BY post_modified_gmt DESC
                LIMIT 100",
                $last_build_mysql
            ) );

            // Convert to URLs
            $post_urls = array();
            foreach ( $posts as $post ) {
                $url = get_permalink( $post->ID );
                if ( $url ) {
                    $post_urls[] = $url;
                }
            }

            $page_urls = array();
            foreach ( $pages as $page ) {
                $url = get_permalink( $page->ID );
                if ( $url ) {
                    $page_urls[] = $url;
                }
            }

            $all_urls = array_merge( $post_urls, $page_urls );
            $this->log( sprintf( 'Database returned %d posts, %d pages', count( $post_urls ), count( $page_urls ) ) );

            return array(
                'urls'        => $all_urls,
                'posts'       => $post_urls,
                'pages'       => $page_urls,
                'posts_count' => count( $post_urls ),
                'pages_count' => count( $page_urls ),
                'total'       => count( $all_urls ),
                'is_first_build' => $is_first_build,
            );
        } catch ( \Exception $e ) {
            $this->log( 'Database query error: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * Detect changes via WordPress REST API (fallback method).
     *
     * @return array|false Array with change data, or false on failure.
     */
    private function detect_changes_via_rest_api() {
        $source_url = $this->settings->get( 'source_url' );

        if ( empty( $source_url ) ) {
            $this->log( 'ERROR: Source URL not configured' );
            return false;
        }

        $api_base = rtrim( $source_url, '/' ) . '/wp-json/wp/v2';

        // Read last build timestamp
        $is_first_build = false;
        if ( file_exists( $this->timestamp_file ) ) {
            $last_build = trim( file_get_contents( $this->timestamp_file ) );
        } else {
            $last_build = '1970-01-01T00:00:00';
            $is_first_build = true;
        }

        $this->log( 'Checking for changed content via REST API...' );

        // Query for changed posts
        $posts_url = sprintf( '%s/posts?modified_after=%s&per_page=100', $api_base, urlencode( $last_build ) );
        $posts = $this->fetch_api_links( $posts_url );

        // Query for changed pages
        $pages_url = sprintf( '%s/pages?modified_after=%s&per_page=100', $api_base, urlencode( $last_build ) );
        $pages = $this->fetch_api_links( $pages_url );

        if ( false === $posts || false === $pages ) {
            return false;
        }

        $all_urls = array_merge( $posts, $pages );
        $this->log( sprintf( 'API returned %d posts, %d pages', count( $posts ), count( $pages ) ) );

        return array(
            'urls'        => $all_urls,
            'posts'       => $posts,
            'pages'       => $pages,
            'posts_count' => count( $posts ),
            'pages_count' => count( $pages ),
            'total'       => count( $all_urls ),
            'is_first_build' => $is_first_build,
        );
    }

    /**
     * Fetch links from a WordPress REST API endpoint.
     *
     * @param string $url API URL.
     * @return array|false Array of links, or false on error.
     */
    private function fetch_api_links( $url ) {
        $args = array(
            'timeout'   => 30,
            'sslverify' => false,
        );

        // Workaround: If accessing own hostname, use localhost with Host header
        $source_url = $this->settings->get( 'source_url' );
        if ( ! empty( $source_url ) && strpos( $url, $source_url ) === 0 ) {
            $parsed = parse_url( $source_url );
            if ( ! empty( $parsed['host'] ) ) {
                // Replace hostname with 127.0.0.1 for local access
                $url = str_replace( $parsed['host'], '127.0.0.1', $url );
                // Add Host header to ensure correct vhost matching
                $args['headers'] = array( 'Host' => $parsed['host'] );
            }
        }

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            $this->log( 'API error: ' . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            $this->log( sprintf( 'API returned HTTP %d', $code ) );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) ) {
            return array();
        }

        $links = array();
        foreach ( $body as $item ) {
            if ( isset( $item['link'] ) ) {
                $links[] = $item['link'];
            }
        }

        return $links;
    }

    /**
     * Build the full list of URLs for selective wget, including dependencies.
     *
     * @param array $changed_urls Changed post/page URLs.
     * @return array Complete URL list including dependencies.
     */
    private function build_selective_urls( $changed_urls ) {
        $source_url = rtrim( $this->settings->get( 'source_url' ), '/' );
        $urls = array();

        // Add changed URLs
        foreach ( $changed_urls as $url ) {
            if ( ! empty( $url ) ) {
                $urls[] = $url;
            }
        }

        // Add dependency URLs (homepage, feeds)
        $urls[] = $source_url . '/';
        $urls[] = $source_url . '/feed/';
        $urls[] = $source_url . '/feed/all.rss';

        // Extract years from changed post URLs and add year archives
        foreach ( $changed_urls as $url ) {
            if ( preg_match( '#/(\d{4})/#', $url, $matches ) ) {
                $urls[] = $source_url . '/' . $matches[1] . '/';
            }
        }

        // Deduplicate
        return array_unique( $urls );
    }

    /**
     * Git commit and push operations.
     *
     * @param string $repo_dir Git repository directory.
     * @return bool True on success.
     */
    private function git_commit_and_push( $repo_dir ) {
        $staging_branch    = $this->settings->get( 'git_staging_branch' );
        $production_branch = $this->settings->get( 'git_production_branch' );

        $this->log( 'Starting git operations...' );

        // Fetch and checkout staging branch
        if ( ! $this->git( $repo_dir, 'fetch origin' ) ) {
            $this->log( 'ERROR: git fetch failed' );
            return false;
        }

        if ( ! $this->git( $repo_dir, sprintf( 'checkout %s', escapeshellarg( $staging_branch ) ) ) ) {
            $this->log( 'ERROR: Failed to checkout staging branch' );
            return false;
        }

        $this->git( $repo_dir, sprintf( 'pull origin %s', escapeshellarg( $staging_branch ) ) );

        // Stage all changes
        $this->log( 'Staging new files from build...' );
        $this->git( $repo_dir, 'add -A' );

        // Pre-sync with production branch to prevent merge conflicts
        $this->log( 'Pre-syncing with production branch to prevent conflicts...' );
        $merge_result = $this->git( $repo_dir, sprintf(
            'merge origin/%s --no-commit --no-ff',
            escapeshellarg( $production_branch )
        ) );

        if ( ! $merge_result ) {
            $this->log( 'Merge conflicts detected, auto-resolving...' );
            // Get conflicted files
            $conflicts = array();
            exec( sprintf( 'cd %s && git diff --name-only --diff-filter=U 2>/dev/null',
                escapeshellarg( $repo_dir ) ), $conflicts );

            foreach ( $conflicts as $file ) {
                $file = trim( $file );
                if ( empty( $file ) ) continue;

                $file_path = $repo_dir . '/' . $file;
                if ( file_exists( $file_path ) ) {
                    $this->git( $repo_dir, sprintf( 'checkout --theirs %s', escapeshellarg( $file ) ) );
                } else {
                    $this->git( $repo_dir, sprintf( 'rm %s', escapeshellarg( $file ) ) );
                }
            }
            $this->git( $repo_dir, 'add -A' );
        }

        // Check for actual changes
        $diff_output = array();
        exec( sprintf( 'cd %s && git diff --cached --quiet 2>&1', escapeshellarg( $repo_dir ) ), $diff_output, $diff_exit );

        if ( 0 === $diff_exit ) {
            $this->log( 'No changes detected after merge. Skipping deployment.' );
            $this->git( $repo_dir, 'merge --abort' );
            return false;
        }

        // Generate change summary
        $stat_output = array();
        exec( sprintf( 'cd %s && git diff --cached --stat 2>/dev/null', escapeshellarg( $repo_dir ) ), $stat_output );
        $changes_full = implode( "\n", $stat_output );

        $name_output = array();
        exec( sprintf( 'cd %s && git diff --cached --name-only 2>/dev/null', escapeshellarg( $repo_dir ) ), $name_output );
        $num_files = count( array_filter( $name_output ) );

        $this->log( sprintf( 'Detected changes in %d file(s)', $num_files ) );

        // Build change summary for PR body
        if ( $num_files > 20 ) {
            $summary_lines = array_slice( $stat_output, 0, 20 );
            $summary_lines[] = sprintf( '... and %d more files', $num_files - 20 );
            if ( ! empty( $stat_output ) ) {
                $summary_lines[] = end( $stat_output );
            }
            $this->changes_summary = implode( "\n", $summary_lines );
        } else {
            $this->changes_summary = $changes_full;
        }
        $this->num_files = $num_files;

        // Commit
        $commit_msg = sprintf( 'Auto-deploy: Site update %s', date( 'Y-m-d H:i' ) );
        $this->log( "Committing: $commit_msg" );

        if ( ! $this->git( $repo_dir, sprintf( 'commit -m %s -m %s',
            escapeshellarg( $commit_msg ),
            escapeshellarg( "$num_files files changed" )
        ) ) ) {
            $this->log( 'ERROR: Git commit failed' );
            return false;
        }

        // Push
        $this->log( 'Pushing to staging branch...' );
        if ( ! $this->git( $repo_dir, sprintf( 'push origin %s', escapeshellarg( $staging_branch ) ) ) ) {
            $this->log( 'ERROR: Git push failed' );
            return false;
        }

        return true;
    }

    /**
     * Create or update a GitHub pull request.
     *
     * @param string $repo_dir Git repository directory.
     * @return bool True on success.
     */
    private function create_pr( $repo_dir ) {
        $staging_branch    = $this->settings->get( 'git_staging_branch' );
        $production_branch = $this->settings->get( 'git_production_branch' );
        $label             = $this->settings->get( 'pr_auto_merge_label' );
        $production_url    = $this->settings->get( 'production_url' );
        $source_url        = $this->settings->get( 'source_url' );

        $title = sprintf( 'Auto-deploy: Site update %s', date( 'Y-m-d H:i' ) );

        $body = sprintf(
            "Automated deployment from %s\n\n## Changes\n```\n%s\n```\n\nThis PR will be automatically merged by GitHub Actions.",
            $source_url,
            isset( $this->changes_summary ) ? $this->changes_summary : 'No summary available'
        );

        $this->log( 'Creating pull request...' );

        $github = new CPSD_GitHub( $this->settings );
        $result = $github->create_or_update_pr( $title, $body, $staging_branch, $production_branch, $label );

        if ( is_wp_error( $result ) ) {
            $this->log( 'ERROR: ' . $result->get_error_message() );
            return false;
        }

        $action = ! empty( $result['existing'] ) ? 'updated' : 'created';
        $this->log( sprintf( 'Pull request %s: %s', $action, $result['html_url'] ) );

        return true;
    }

    /**
     * Execute a git command in the repo directory.
     *
     * @param string $repo_dir Repository directory.
     * @param string $command  Git command (without 'git' prefix).
     * @return bool True if exit code is 0.
     */
    private function git( $repo_dir, $command ) {
        $full_cmd = sprintf( 'cd %s && git %s 2>&1', escapeshellarg( $repo_dir ), $command );
        $output = array();
        $return_var = 0;
        exec( $full_cmd, $output, $return_var );
        return 0 === $return_var;
    }

    /**
     * Check if a deploy is currently running.
     */
    public function is_running() {
        return file_exists( $this->lock_file );
    }

    /**
     * Get the last build timestamp.
     */
    public function get_last_build_time() {
        if ( file_exists( $this->timestamp_file ) ) {
            return trim( file_get_contents( $this->timestamp_file ) );
        }
        return null;
    }

    /**
     * Log a message.
     */
    public function log( $message ) {
        $timestamp = date( 'Y-m-d H:i:s' );
        $line = sprintf( "[%s] %s\n", $timestamp, $message );

        $log_dir = dirname( $this->log_file );
        if ( ! is_dir( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }

        file_put_contents( $this->log_file, $line, FILE_APPEND | LOCK_EX );
    }

    /**
     * Get recent log lines.
     *
     * @param int $lines Number of lines to return.
     * @return string Log content.
     */
    public function get_recent_logs( $lines = 50 ) {
        if ( ! file_exists( $this->log_file ) ) {
            return 'No deploy log found.';
        }

        $cmd = sprintf( 'tail -n %d %s 2>/dev/null', (int) $lines, escapeshellarg( $this->log_file ) );
        $output = shell_exec( $cmd );

        return $output ?: 'Unable to read log file.';
    }

    /**
     * Get the log file path.
     */
    public function get_log_file() {
        return $this->log_file;
    }
}
