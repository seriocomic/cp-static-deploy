<?php
/**
 * Post-processor for CP Static Deploy.
 *
 * Replaces the entire gulpfile.js pipeline with PHP equivalents.
 * Handles wget mirroring, URL rewriting, feed processing, and file operations.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPSD_Processor {

    private $settings;
    private $log_callback;

    public function __construct( CPSD_Settings $settings, callable $log_callback = null ) {
        $this->settings = $settings;
        $this->log_callback = $log_callback;
    }

    private function log( $message ) {
        if ( $this->log_callback ) {
            call_user_func( $this->log_callback, $message );
        }
    }

    /**
     * Clean wget cache - delete key HTML files so wget fetches fresh copies.
     * Replaces: gulp 'clean-wget-cache' task.
     */
    public function clean_wget_cache( $build_dir ) {
        $pages_str = $this->settings->get( 'cache_clean_pages' );
        if ( empty( $pages_str ) ) {
            return;
        }

        $source_domain = $this->settings->get_source_domain();
        $base_dir = $build_dir . '/' . $source_domain;
        $pages = array_map( 'trim', explode( ',', $pages_str ) );

        foreach ( $pages as $page ) {
            $file_path = $base_dir . '/' . $page;
            if ( file_exists( $file_path ) ) {
                unlink( $file_path );
                $this->log( "Cleaned cached file: $page" );
            }
        }
    }

    /**
     * Mirror the dev site using wget.
     * Replaces: gulp 'mirror-site' and 'mirror-selective' tasks.
     *
     * @param string     $build_dir      Build directory path.
     * @param array|null $selective_urls  URLs for selective build, or null for full mirror.
     * @return bool True on success.
     */
    public function mirror_site( $build_dir, $selective_urls = null ) {
        $source_url = $this->settings->get( 'source_url' );
        $source_domain = $this->settings->get_source_domain();
        $exclude = $this->settings->get( 'exclude_domains' );
        $extra_args = $this->settings->get( 'wget_extra_args' );
        $production_domain = $this->settings->get_production_domain();

        // Build exclude domains list
        $exclude_domains = $production_domain;
        if ( ! empty( $exclude ) ) {
            $exclude_domains .= ',' . $exclude;
        }

        // Ensure build directory exists
        $site_dir = $build_dir . '/' . $source_domain;
        if ( ! is_dir( $site_dir ) ) {
            wp_mkdir_p( $site_dir );
        }

        if ( $selective_urls ) {
            return $this->mirror_selective( $build_dir, $selective_urls, $source_domain, $exclude_domains, $extra_args );
        }

        return $this->mirror_full( $build_dir, $source_url, $source_domain, $exclude_domains, $extra_args );
    }

    /**
     * Full site mirror via wget.
     */
    private function mirror_full( $build_dir, $source_url, $source_domain, $exclude_domains, $extra_args ) {
        $cmd = sprintf(
            'wget %s --page-requisites --adjust-extension --mirror --span-hosts --domains=%s --exclude-domains=%s --reject-regex %s %s -P %s 2>&1',
            $extra_args,
            escapeshellarg( $source_domain ),
            escapeshellarg( $exclude_domains ),
            escapeshellarg( '.*/category/.*' ),
            escapeshellarg( $source_url ),
            escapeshellarg( $build_dir )
        );

        $this->log( 'Running full wget mirror...' );
        $output = array();
        $return_var = 0;
        exec( $cmd, $output, $return_var );

        // wget returns 8 for some server errors (404s), which is acceptable
        if ( $return_var !== 0 && $return_var !== 8 ) {
            $this->log( "wget exited with code $return_var" );
            return false;
        }

        // Download sitemap
        $this->download_extra_file(
            $source_url . '/sitemap.xml',
            $build_dir . '/' . $source_domain . '/sitemap.xml',
            $extra_args
        );

        // Download 404 page
        $this->download_extra_file(
            $source_url . '/404.html',
            $build_dir . '/' . $source_domain . '/404.html',
            $extra_args,
            true
        );

        return true;
    }

    /**
     * Selective mirror via wget - download specific URLs only.
     */
    private function mirror_selective( $build_dir, $urls, $source_domain, $exclude_domains, $extra_args ) {
        $url_file = CPSD_WORKING_DIR . '/wget-input.txt';
        file_put_contents( $url_file, implode( "\n", $urls ) );

        $this->log( sprintf( 'Selective mirror: downloading %d URLs', count( $urls ) ) );

        $cmd = sprintf(
            'wget %s --page-requisites --adjust-extension --span-hosts --domains=%s --exclude-domains=%s --input-file=%s -P %s 2>&1',
            $extra_args,
            escapeshellarg( $source_domain ),
            escapeshellarg( $exclude_domains ),
            escapeshellarg( $url_file ),
            escapeshellarg( $build_dir )
        );

        $output = array();
        $return_var = 0;
        exec( $cmd, $output, $return_var );

        // Clean up temp file
        @unlink( $url_file );

        if ( $return_var !== 0 && $return_var !== 8 ) {
            $this->log( "Selective wget failed (exit $return_var), falling back to full mirror" );
            return $this->mirror_full(
                $build_dir,
                $this->settings->get( 'source_url' ),
                $source_domain,
                $exclude_domains,
                $extra_args
            );
        }

        $source_url = $this->settings->get( 'source_url' );

        // Download sitemap
        $this->download_extra_file(
            $source_url . '/sitemap.xml',
            $build_dir . '/' . $source_domain . '/sitemap.xml',
            $extra_args
        );

        // Download 404 page
        $this->download_extra_file(
            $source_url . '/404.html',
            $build_dir . '/' . $source_domain . '/404.html',
            $extra_args,
            true
        );

        return true;
    }

    /**
     * Download a single extra file via wget.
     */
    private function download_extra_file( $url, $dest, $extra_args, $expect_error = false ) {
        $cmd = sprintf(
            'wget %s %s -O %s 2>&1',
            $extra_args,
            escapeshellarg( $url ),
            escapeshellarg( $dest )
        );

        $output = array();
        $return_var = 0;
        exec( $cmd, $output, $return_var );

        if ( $return_var !== 0 && ! $expect_error ) {
            $this->log( "Warning: failed to download " . basename( $dest ) );
        }
    }

    /**
     * Rewrite URLs in HTML files.
     * Replaces: gulp 'replace-html' task.
     */
    public function rewrite_urls_html( $build_dir ) {
        $source_domain = $this->settings->get_source_domain();
        $production_domain = $this->settings->get_production_domain();
        $site_dir = $build_dir . '/' . $source_domain;

        $this->log( 'Rewriting URLs in HTML files...' );
        $count = 0;

        $files = $this->find_files( $site_dir, '*.html' );
        foreach ( $files as $file ) {
            $content = file_get_contents( $file );
            $original = $content;

            // Domain swap: //dev.example.com → //www.example.com
            $content = str_replace( '//' . $source_domain, '//' . $production_domain, $content );

            // Normalize single quotes to double quotes in attributes
            $content = preg_replace( '/(\w+)=\'([^\']*)\'/s', '$1="$2"', $content );

            // Remove space before self-closing tags
            $content = str_replace( ' />', '>', $content );

            // Remove newlines (HTML minification)
            $content = preg_replace( '/\r?\n|\r/', '', $content );

            // Rewrite feed URLs
            $content = preg_replace( '/\/feed\/(?!all\.rss)/', '/feed/all.rss', $content );

            if ( $content !== $original ) {
                file_put_contents( $file, $content );
                $count++;
            }
        }

        $this->log( "Rewrote URLs in $count HTML files" );
    }

    /**
     * Rewrite URLs in XML and RSS files.
     * Replaces: gulp 'replace-xml-rss' task.
     */
    public function rewrite_urls_xml( $build_dir ) {
        $source_domain = $this->settings->get_source_domain();
        $production_domain = $this->settings->get_production_domain();
        $site_dir = $build_dir . '/' . $source_domain;

        $this->log( 'Rewriting URLs in XML/RSS files...' );
        $count = 0;

        $xml_files = $this->find_files( $site_dir, '*.xml' );
        $rss_files = $this->find_files( $site_dir, '*.rss' );
        $files = array_merge( $xml_files, $rss_files );

        foreach ( $files as $file ) {
            $content = file_get_contents( $file );
            $original = $content;

            $content = str_replace( '//' . $source_domain, '//' . $production_domain, $content );
            $content = preg_replace( '/\/feed\/(?!all\.rss)/', '/feed/all.rss', $content );

            if ( $content !== $original ) {
                file_put_contents( $file, $content );
                $count++;
            }
        }

        $this->log( "Rewrote URLs in $count XML/RSS files" );
    }

    /**
     * Process RSS feeds - update GUIDs and rename files.
     * Replaces: gulp 'update-guid' task.
     */
    public function process_feeds( $build_dir ) {
        $source_domain = $this->settings->get_source_domain();
        $site_dir = $build_dir . '/' . $source_domain;

        $this->log( 'Processing feeds...' );

        // Find all feed/index.html files
        $feed_files = $this->find_files( $site_dir, 'feed/index.html' );

        foreach ( $feed_files as $feed_file ) {
            $content = file_get_contents( $feed_file );

            // Try parsing as XML
            libxml_use_internal_errors( true );
            $xml = simplexml_load_string( $content );

            if ( false !== $xml && isset( $xml->channel ) ) {
                // Update GUIDs to match link values
                foreach ( $xml->channel->item as $item ) {
                    if ( isset( $item->link ) && isset( $item->guid ) ) {
                        $item->guid = (string) $item->link;
                    }
                }

                // Save as all.rss in the same directory
                $rss_path = dirname( $feed_file ) . '/all.rss';
                $xml->asXML( $rss_path );
                $this->log( 'Created: ' . str_replace( $site_dir, '', $rss_path ) );
            }

            libxml_clear_errors();
        }
    }

    /**
     * Clean old feed files (feed/index.html after conversion to all.rss).
     * Replaces: gulp 'clean-old-files' task.
     */
    public function clean_old_files( $build_dir ) {
        $source_domain = $this->settings->get_source_domain();
        $site_dir = $build_dir . '/' . $source_domain;

        $feed_files = $this->find_files( $site_dir, 'feed/index.html' );
        foreach ( $feed_files as $file ) {
            @unlink( $file );
        }
    }

    /**
     * Generate robots.txt.
     * Replaces: gulp 'update-robots-txt' task.
     */
    public function generate_robots_txt( $build_dir ) {
        $source_domain = $this->settings->get_source_domain();
        $content = $this->settings->get( 'robots_txt' );

        if ( ! empty( $content ) ) {
            $dest = $build_dir . '/' . $source_domain . '/robots.txt';
            file_put_contents( $dest, $content );
            $this->log( 'Generated robots.txt' );
        }
    }

    /**
     * Copy README.md to build directory.
     * Replaces: gulp 'copy-readme' task.
     */
    public function copy_readme( $build_dir ) {
        $source_domain = $this->settings->get_source_domain();
        $content = $this->settings->get( 'readme_content' );

        if ( ! empty( $content ) ) {
            $dest = $build_dir . '/' . $source_domain . '/README.md';
            file_put_contents( $dest, $content );
            $this->log( 'Generated README.md' );
        }
    }

    /**
     * Copy processed files from build directory to git repo.
     * Replaces: gulp 'move-to-static' task.
     */
    public function copy_to_repo( $build_dir, $repo_dir ) {
        $source_domain = $this->settings->get_source_domain();
        $source = $build_dir . '/' . $source_domain;

        if ( ! is_dir( $source ) ) {
            $this->log( "ERROR: Build source directory not found: $source" );
            return false;
        }

        $this->log( 'Copying build output to repo...' );
        $count = $this->recursive_copy( $source, $repo_dir );
        $this->log( "Copied $count files to repo" );

        return true;
    }

    /**
     * Clean numbered duplicate files (e.g. sitemap.xml.1, sitemap.xml.2).
     */
    public function clean_numbered_files( $repo_dir ) {
        $cmd = sprintf(
            'find %s -name "*.[0-9]" -type f -delete 2>/dev/null',
            escapeshellarg( $repo_dir )
        );
        exec( $cmd );
    }

    /**
     * Clean removed content from repo (files that exist in repo but not in build).
     * Only runs during full rebuilds to remove stale content.
     *
     * @param string $build_dir Build directory.
     * @param string $repo_dir  Git repo directory.
     */
    public function clean_removed_content( $build_dir, $repo_dir ) {
        $source_domain = $this->settings->get_source_domain();
        $build_source = $build_dir . '/' . $source_domain;

        if ( ! is_dir( $build_source ) ) {
            return;
        }

        $this->log( 'Checking for removed content...' );

        // Get all files and top-level directories in build
        $build_files = array();
        $build_dirs = array();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $build_source, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ( $iterator as $item ) {
            $rel_path = str_replace( $build_source . '/', '', $item->getPathname() );
            if ( $item->isFile() ) {
                $build_files[] = $rel_path;
                // Track top-level directories
                $parts = explode( '/', $rel_path );
                if ( count( $parts ) > 0 ) {
                    $build_dirs[ $parts[0] ] = true;
                }
            }
        }
        $build_files = array_flip( $build_files ); // Use as hashmap for fast lookup

        // Get all files and top-level directories in repo
        $repo_files = array();
        $repo_top_dirs = array();
        $repo_iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $repo_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ( $repo_iterator as $item ) {
            $rel_path = str_replace( $repo_dir . '/', '', $item->getPathname() );

            // Skip git metadata and special files
            if ( strpos( $rel_path, '.git' ) === 0 ||
                 $rel_path === 'README.md' ||
                 $rel_path === 'CNAME' ||
                 $rel_path === '.gitignore' ) {
                continue;
            }

            // Track top-level directories in repo
            $parts = explode( '/', $rel_path );
            if ( count( $parts ) > 0 && ! isset( $repo_top_dirs[ $parts[0] ] ) ) {
                $repo_top_dirs[ $parts[0] ] = $repo_dir . '/' . $parts[0];
            }

            if ( $item->isFile() && ! isset( $build_files[ $rel_path ] ) ) {
                $repo_files[] = $rel_path;
            }
        }

        // Check for entire directories that should be removed
        $dirs_to_remove = array();
        foreach ( $repo_top_dirs as $dir_name => $dir_path ) {
            if ( ! isset( $build_dirs[ $dir_name ] ) && is_dir( $dir_path ) ) {
                $dirs_to_remove[] = array( 'name' => $dir_name, 'path' => $dir_path );
            }
        }

        // Remove entire directories that don't exist in build
        if ( ! empty( $dirs_to_remove ) ) {
            foreach ( $dirs_to_remove as $dir_info ) {
                $this->log( sprintf( 'Removing directory: %s/', $dir_info['name'] ) );
                exec( sprintf( 'rm -rf %s 2>/dev/null', escapeshellarg( $dir_info['path'] ) ) );
            }
        }

        if ( empty( $repo_files ) && empty( $dirs_to_remove ) ) {
            $this->log( 'No removed content found' );
            return;
        }

        // Delete removed files
        if ( ! empty( $repo_files ) ) {
            $this->log( sprintf( 'Removing %d stale files...', count( $repo_files ) ) );
            foreach ( $repo_files as $file ) {
                $full_path = $repo_dir . '/' . $file;
                if ( file_exists( $full_path ) ) {
                    unlink( $full_path );
                }
            }
        }

        // Clean up empty directories
        exec( sprintf( 'find %s -type d -empty -delete 2>/dev/null', escapeshellarg( $repo_dir ) ) );

        $total_cleaned = count( $repo_files ) + count( $dirs_to_remove );
        $this->log( sprintf( 'Cleaned %d removed files and %d directories', count( $repo_files ), count( $dirs_to_remove ) ) );
    }

    /**
     * Run the complete post-processing pipeline.
     *
     * @param string     $build_dir      Build directory.
     * @param string     $repo_dir       Git repo directory.
     * @param array|null $selective_urls  URLs for selective build.
     * @return bool True on success.
     */
    public function run_pipeline( $build_dir, $repo_dir, $selective_urls = null ) {
        // 1. Clean wget cache
        $this->clean_wget_cache( $build_dir );

        // 2. Mirror site
        if ( ! $this->mirror_site( $build_dir, $selective_urls ) ) {
            return false;
        }

        // 3. Process feeds (update GUIDs, convert feed/index.html → all.rss)
        //    Must happen before HTML rewriting, because wget saves the RSS feed
        //    as feed/index.html and the HTML rewriter would corrupt the XML.
        $this->process_feeds( $build_dir );

        // 4. Clean old feed files (remove feed/index.html before HTML rewriter sees it)
        $this->clean_old_files( $build_dir );

        // 5. Rewrite URLs in HTML
        $this->rewrite_urls_html( $build_dir );

        // 6. Rewrite URLs in XML/RSS (handles domain swap in all.rss)
        $this->rewrite_urls_xml( $build_dir );

        // 7. Generate robots.txt
        $this->generate_robots_txt( $build_dir );

        // 8. Copy README
        $this->copy_readme( $build_dir );

        // 9. Copy to repo
        if ( ! $this->copy_to_repo( $build_dir, $repo_dir ) ) {
            return false;
        }

        // 10. Clean numbered duplicates
        $this->clean_numbered_files( $repo_dir );

        // 11. Clean removed content (only for full rebuilds)
        if ( null === $selective_urls ) {
            $this->clean_removed_content( $build_dir, $repo_dir );
        }

        // 12. Force remove category directory (legacy URLs that redirect)
        $category_dir = $repo_dir . '/category';
        if ( is_dir( $category_dir ) ) {
            $this->log( 'Removing legacy category directory...' );
            exec( sprintf( 'rm -rf %s 2>/dev/null', escapeshellarg( $category_dir ) ) );
        }

        return true;
    }

    /**
     * Find files matching a pattern recursively.
     */
    private function find_files( $dir, $pattern ) {
        $files = array();

        if ( ! is_dir( $dir ) ) {
            return $files;
        }

        // Handle glob patterns
        if ( strpos( $pattern, '*' ) !== false ) {
            $cmd = sprintf(
                'find %s -name %s -type f 2>/dev/null',
                escapeshellarg( $dir ),
                escapeshellarg( $pattern )
            );
            $output = shell_exec( $cmd );
            if ( $output ) {
                $files = array_filter( explode( "\n", trim( $output ) ) );
            }
        } else {
            // Handle path patterns like "feed/index.html"
            $cmd = sprintf(
                'find %s -path %s -type f 2>/dev/null',
                escapeshellarg( $dir ),
                escapeshellarg( '*/' . $pattern )
            );
            $output = shell_exec( $cmd );
            if ( $output ) {
                $files = array_filter( explode( "\n", trim( $output ) ) );
            }
        }

        return $files;
    }

    /**
     * Recursively copy directory contents.
     *
     * @return int Number of files copied.
     */
    private function recursive_copy( $source, $dest ) {
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $source, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $item ) {
            $target = $dest . '/' . $iterator->getSubPathname();

            if ( $item->isDir() ) {
                if ( ! is_dir( $target ) ) {
                    wp_mkdir_p( $target );
                }
            } else {
                $target_dir = dirname( $target );
                if ( ! is_dir( $target_dir ) ) {
                    wp_mkdir_p( $target_dir );
                }
                copy( $item->getPathname(), $target );
                $count++;
            }
        }

        return $count;
    }
}
