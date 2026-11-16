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
            if ( $this->is_lock_stale() ) {
                $this->log( 'Clearing stale lock file (no live deploy process).' );
                @unlink( $this->lock_file );
            } else {
                $this->log( 'Deploy already in progress (lock file exists). Exiting.' );
                return false;
            }
        }

        file_put_contents( $this->lock_file, getmypid() );
        $this->fix_permissions( $this->lock_file );

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

        // Capture the build start time BEFORE change detection. The timestamp is
        // only persisted after a successful push + PR (see end of this method).
        // Using the start time means any edit made *during* the build falls into
        // the next detection window rather than being silently skipped, and a
        // failed push/PR leaves the timestamp unadvanced so the change is retried.
        $build_started = gmdate( 'Y-m-d\TH:i:s' );

        // Step 1: Check for changes via WordPress REST API
        $changes = $this->detect_changes();

        if ( false === $changes ) {
            $this->log( 'ERROR: Failed to query WordPress API' );
            return false;
        }

        if ( empty( $changes['urls'] ) && empty( $changes['force_full'] ) ) {
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
        } elseif ( ! empty( $changes['force_full'] ) ) {
            $this->log( 'Content left publish status - using full mirror to purge stale files' );
        } elseif ( $total > $threshold ) {
            $this->log( sprintf( 'Many changes (%d > %d threshold) - using full mirror', $total, $threshold ) );
        } else {
            $this->log( sprintf( 'Using selective build for %d changed items', $total ) );
            $selective_urls = $this->build_selective_urls( $changes['urls'] );
            $this->log( sprintf( 'Will rebuild %d URLs (including dependencies)', count( $selective_urls ) ) );
        }

        // Step 3: Sync repo to remote before the pipeline runs.
        // This MUST happen before run_pipeline() so that clean_removed_content()
        // deletions are not undone by a subsequent git reset --hard.
        if ( ! $this->sync_repo_to_remote( $repo_dir ) ) {
            return false;
        }

        // Step 4: Run the processing pipeline
        $processor = new CPSD_Processor( $this->settings, array( $this, 'log' ) );

        // Full builds must contain every published post and page; selective builds the changed ones
        $required_urls = ( null === $selective_urls ) ? $this->get_published_urls() : $changes['urls'];

        if ( ! $processor->run_pipeline( $build_dir, $repo_dir, $selective_urls, $required_urls ) ) {
            $this->log( 'ERROR: Build pipeline failed' );
            return false;
        }

        $this->log( 'Build pipeline completed successfully' );

        // Step 5: Git operations (add, commit, push — repo already synced in step 3)
        $git_result = $this->git_commit_and_push( $repo_dir );

        if ( false === $git_result ) {
            // Genuine git error (commit or push failed). Leave the timestamp
            // unadvanced so this change is retried on the next run.
            return false;
        }

        if ( 'no_changes' === $git_result ) {
            // Build produced output identical to what is already on staging.
            // Nothing to deploy, but the content *was* built — advance the
            // timestamp so we don't re-detect the same change every trigger.
            $this->save_build_timestamp( $build_started );
            $this->log( 'Build output unchanged; nothing to deploy.' );
            return true;
        }

        // Step 6: Create/update PR (only reached when something was pushed)
        if ( ! $this->create_pr( $repo_dir ) ) {
            // Pushed to staging but PR creation failed. Do NOT advance the
            // timestamp: a later run re-detects the change and retries the PR.
            return false;
        }

        // Persist the build timestamp only after a successful push + PR.
        $this->save_build_timestamp( $build_started );

        $this->log( '=========================================' );
        $this->log( 'Auto-deploy completed successfully' );
        $this->log( '=========================================' );

        return true;
    }

    /**
     * Persist the last-build timestamp used for incremental change detection.
     *
     * @param string $timestamp ISO-8601 timestamp captured at build start.
     */
    private function save_build_timestamp( $timestamp ) {
        file_put_contents( $this->timestamp_file, $timestamp );
        $this->fix_permissions( $this->timestamp_file );
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
                LIMIT 1000",
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
                LIMIT 1000",
                $last_build_mysql
            ) );

            // Detect content that left 'publish' since the last build (trashed,
            // unpublished, or made private). Such pages still exist as stale
            // files in the repo but are no longer in the change set, and
            // clean_removed_content() only runs during a full rebuild. If any
            // are found, force a full rebuild so the stale files are removed.
            $removed_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*)
                FROM {$wpdb->posts}
                WHERE post_type IN ('post','page')
                AND post_status IN ('trash','draft','pending','private')
                AND post_modified_gmt > %s",
                $last_build_mysql
            ) );

            if ( $removed_count > 0 ) {
                $this->log( sprintf( '%d item(s) left publish status since last build - forcing full rebuild to purge stale files', $removed_count ) );
            }

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
                'force_full'  => ( $removed_count > 0 ),
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
            'force_full'  => false,
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

        // Pages removed from the build by clean_wget_cache() must be fetched again,
        // otherwise they are never refreshed by a selective build
        $cache_pages = array_filter( array_map( 'trim', explode( ',', (string) $this->settings->get( 'cache_clean_pages' ) ) ) );
        foreach ( $cache_pages as $page ) {
            $page = preg_replace( '#(^|/)index\.html$#', '$1', $page );
            $urls[] = $source_url . '/' . $page;
        }

        // Extract years from changed post URLs and add year archives
        foreach ( $changed_urls as $url ) {
            if ( preg_match( '#/(\d{4})/#', $url, $matches ) ) {
                $urls[] = $source_url . '/' . $matches[1] . '/';
            }
        }

        // Add term archives and neighbouring posts, which display each changed post
        foreach ( $changed_urls as $url ) {
            $post_id = url_to_postid( $url );
            if ( $post_id && 'post' === get_post_type( $post_id ) ) {
                $urls = array_merge( $urls, $this->related_urls( $post_id ) );
            }
        }

        // Deduplicate
        return array_values( array_unique( $urls ) );
    }

    /**
     * URLs of pages that show a post: its term archives (with ancestors) and its
     * previous/next posts, overall and within each of its taxonomies.
     *
     * @param int $post_id Post ID.
     * @return array URLs.
     */
    private function related_urls( $post_id ) {
        global $post;
        $saved_post = $post;
        $post = get_post( $post_id ); // get_adjacent_post() reads the global post
        $urls = array();

        foreach ( get_object_taxonomies( $post, 'objects' ) as $taxonomy ) {
            if ( ! $taxonomy->public || ! $taxonomy->rewrite ) {
                continue;
            }
            $terms = wp_get_post_terms( $post_id, $taxonomy->name );
            if ( is_wp_error( $terms ) ) {
                continue;
            }
            foreach ( $terms as $term ) {
                $term_ids = array_merge( array( $term->term_id ), get_ancestors( $term->term_id, $taxonomy->name, 'taxonomy' ) );
                foreach ( $term_ids as $term_id ) {
                    $link = get_term_link( (int) $term_id, $taxonomy->name );
                    if ( ! is_wp_error( $link ) ) {
                        $urls[] = $link;
                    }
                }
            }
            foreach ( array( true, false ) as $previous ) {
                $adjacent = get_adjacent_post( true, '', $previous, $taxonomy->name );
                if ( $adjacent ) {
                    $urls[] = get_permalink( $adjacent );
                }
            }
        }

        foreach ( array( true, false ) as $previous ) {
            $adjacent = get_adjacent_post( false, '', $previous );
            if ( $adjacent ) {
                $urls[] = get_permalink( $adjacent );
            }
        }

        $post = $saved_post;

        // /category/ archives are excluded from the full mirror and removed from the repo
        return array_filter( $urls, function ( $url ) {
            return false === strpos( $url, '/category/' );
        } );
    }

    /**
     * Permalinks of all published posts and pages.
     *
     * @return array URLs.
     */
    private function get_published_urls() {
        global $wpdb;
        $ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('post','page') AND post_status = 'publish'" );
        return array_filter( array_map( 'get_permalink', $ids ) );
    }

    /**
     * Sync the local repo to the remote staging branch.
     *
     * Must be called BEFORE run_pipeline() so that file deletions made by
     * clean_removed_content() are not undone by a subsequent git reset --hard.
     *
     * @param string $repo_dir Git repository directory.
     * @return bool True on success.
     */
    private function sync_repo_to_remote( $repo_dir ) {
        $staging_branch = $this->settings->get( 'git_staging_branch' );

        $this->log( 'Syncing local staging with remote...' );

        if ( ! $this->git( $repo_dir, 'fetch origin' ) ) {
            $this->log( 'ERROR: git fetch failed' );
            return false;
        }

        if ( ! $this->git( $repo_dir, sprintf( 'checkout %s', escapeshellarg( $staging_branch ) ) ) ) {
            $this->log( 'ERROR: Failed to checkout staging branch' );
            return false;
        }

        // Reset local staging to match remote exactly.
        // The GitHub Actions workflow resets staging to master after every merge,
        // so this is always a clean fast-forward base — no conflicts possible.
        $this->git( $repo_dir, sprintf( 'reset --hard origin/%s', escapeshellarg( $staging_branch ) ) );

        return true;
    }

    /**
     * Stage, commit and push build output to the staging branch.
     *
     * Assumes sync_repo_to_remote() has already been called before the pipeline ran.
     *
     * @param string $repo_dir Git repository directory.
     * @return string|false 'pushed' on success, 'no_changes' if the build output
     *                       was identical, or false on a git error.
     */
    private function git_commit_and_push( $repo_dir ) {
        $staging_branch    = $this->settings->get( 'git_staging_branch' );
        $production_branch = $this->settings->get( 'git_production_branch' );

        $this->log( 'Starting git operations...' );

        // Stage all changes from build output
        $this->log( 'Staging build output...' );
        $this->git( $repo_dir, 'add -A' );

        // Check for actual changes
        $diff_output = array();
        exec( sprintf( 'cd %s && git diff --cached --quiet 2>&1', escapeshellarg( $repo_dir ) ), $diff_output, $diff_exit );

        if ( 0 === $diff_exit ) {
            $this->log( 'No changes detected. Skipping deployment.' );
            return 'no_changes';
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
        $commit_msg = sprintf( 'Auto-deploy: Site update %s', wp_date( 'Y-m-d H:i', null, new DateTimeZone( 'Australia/Melbourne' ) ) );
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

        return 'pushed';
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

        $title = sprintf( 'Auto-deploy: Site update %s', wp_date( 'Y-m-d H:i', null, new DateTimeZone( 'Australia/Melbourne' ) ) );

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
        return file_exists( $this->lock_file ) && ! $this->is_lock_stale();
    }

    /**
     * Determine whether an existing lock file is stale (left by a crashed run).
     *
     * Prefers process liveness via the recorded PID; falls back to lock age
     * when the posix extension is unavailable. A full rebuild takes well under
     * an hour, so a lock older than that with no live process is treated as dead.
     *
     * @return bool True if the lock can be safely cleared.
     */
    private function is_lock_stale() {
        if ( ! file_exists( $this->lock_file ) ) {
            return false;
        }

        $pid = (int) trim( (string) @file_get_contents( $this->lock_file ) );

        if ( $pid > 0 && function_exists( 'posix_kill' ) ) {
            // posix_kill($pid, 0) returns false (ESRCH) when no such process.
            return ! posix_kill( $pid, 0 );
        }

        // No PID or no posix: treat a lock older than one hour as stale.
        $age = time() - (int) @filemtime( $this->lock_file );
        return $age > 3600;
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
        $timestamp = wp_date( 'Y-m-d H:i:s' );
        $line = sprintf( "[%s] %s\n", $timestamp, $message );

        $log_dir = dirname( $this->log_file );
        if ( ! is_dir( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
            $this->fix_permissions( $log_dir );
        }

        file_put_contents( $this->log_file, $line, FILE_APPEND | LOCK_EX );
        $this->fix_permissions( $this->log_file );
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
