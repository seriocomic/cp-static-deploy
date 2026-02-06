<?php
/**
 * GitHub API integration for CP Static Deploy.
 *
 * Replaces the gh CLI with direct REST API calls using WordPress HTTP API.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPSD_GitHub {

    private $token;
    private $repo;
    private $api_base = 'https://api.github.com';

    public function __construct( CPSD_Settings $settings ) {
        $this->token = $settings->get( 'github_token' );
        $this->repo = $settings->get( 'github_repo' );
    }

    /**
     * Make an authenticated API request.
     *
     * @param string $method  HTTP method (GET, POST, PATCH).
     * @param string $endpoint API endpoint (e.g. /repos/{owner}/{repo}/pulls).
     * @param array  $body    Request body for POST/PATCH.
     * @return array|WP_Error Response array with 'code' and 'body' keys.
     */
    private function request( $method, $endpoint, $body = null ) {
        $url = $this->api_base . $endpoint;

        $args = array(
            'method'  => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
                'Accept'        => 'application/vnd.github+json',
                'User-Agent'    => 'CP-Static-Deploy/' . CPSD_VERSION,
            ),
            'timeout' => 30,
        );

        if ( $body ) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return array(
            'code' => wp_remote_retrieve_response_code( $response ),
            'body' => json_decode( wp_remote_retrieve_body( $response ), true ),
        );
    }

    /**
     * Create a pull request.
     *
     * @param string $title PR title.
     * @param string $body  PR body (markdown).
     * @param string $head  Head branch.
     * @param string $base  Base branch.
     * @return array|WP_Error PR data with 'number' and 'html_url' keys on success.
     */
    public function create_pull_request( $title, $body, $head, $base ) {
        $endpoint = sprintf( '/repos/%s/pulls', $this->repo );

        $result = $this->request( 'POST', $endpoint, array(
            'title' => $title,
            'body'  => $body,
            'head'  => $head,
            'base'  => $base,
        ) );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( 201 === $result['code'] ) {
            return array(
                'number'   => $result['body']['number'],
                'html_url' => $result['body']['html_url'],
            );
        }

        // PR might already exist (422 with "already exists" message)
        if ( 422 === $result['code'] ) {
            $errors = isset( $result['body']['errors'] ) ? $result['body']['errors'] : array();
            foreach ( $errors as $error ) {
                if ( isset( $error['message'] ) && strpos( $error['message'], 'already exists' ) !== false ) {
                    return $this->find_existing_pr( $head, $base );
                }
            }
        }

        return new WP_Error(
            'github_api_error',
            sprintf( 'GitHub API error (%d): %s', $result['code'], wp_json_encode( $result['body'] ) )
        );
    }

    /**
     * Find an existing open PR for given branches.
     *
     * @return array|WP_Error PR data on success.
     */
    public function find_existing_pr( $head, $base ) {
        $endpoint = sprintf( '/repos/%s/pulls?state=open&head=%s&base=%s',
            $this->repo,
            urlencode( explode( '/', $this->repo )[0] . ':' . $head ),
            urlencode( $base )
        );

        $result = $this->request( 'GET', $endpoint );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( 200 === $result['code'] && ! empty( $result['body'] ) ) {
            $pr = $result['body'][0];
            return array(
                'number'   => $pr['number'],
                'html_url' => $pr['html_url'],
                'existing' => true,
            );
        }

        return new WP_Error( 'no_pr_found', 'No existing PR found' );
    }

    /**
     * Add a label to a PR/issue.
     *
     * @param int    $pr_number PR number.
     * @param string $label     Label name.
     * @return bool True on success.
     */
    public function add_label( $pr_number, $label ) {
        $endpoint = sprintf( '/repos/%s/issues/%d/labels', $this->repo, $pr_number );

        $result = $this->request( 'POST', $endpoint, array(
            'labels' => array( $label ),
        ) );

        if ( is_wp_error( $result ) ) {
            return false;
        }

        return 200 === $result['code'];
    }

    /**
     * Test the GitHub connection.
     *
     * @return array Status with 'ok', 'message' keys.
     */
    public function test_connection() {
        if ( empty( $this->token ) || empty( $this->repo ) ) {
            return array(
                'ok'      => false,
                'message' => 'Token and repository must be configured.',
            );
        }

        $endpoint = sprintf( '/repos/%s', $this->repo );
        $result = $this->request( 'GET', $endpoint );

        if ( is_wp_error( $result ) ) {
            return array(
                'ok'      => false,
                'message' => $result->get_error_message(),
            );
        }

        if ( 200 === $result['code'] ) {
            return array(
                'ok'      => true,
                'message' => sprintf(
                    'Connected to %s (%s)',
                    $result['body']['full_name'],
                    $result['body']['private'] ? 'private' : 'public'
                ),
            );
        }

        if ( 401 === $result['code'] ) {
            return array(
                'ok'      => false,
                'message' => 'Authentication failed. Check the token.',
            );
        }

        if ( 404 === $result['code'] ) {
            return array(
                'ok'      => false,
                'message' => 'Repository not found. Check owner/repo format and token permissions.',
            );
        }

        return array(
            'ok'      => false,
            'message' => sprintf( 'Unexpected response (%d)', $result['code'] ),
        );
    }

    /**
     * Create a PR or update an existing one.
     *
     * @param string $title   PR title.
     * @param string $body    PR body.
     * @param string $head    Head branch.
     * @param string $base    Base branch.
     * @param string $label   Label to add.
     * @return array|WP_Error PR data on success.
     */
    public function create_or_update_pr( $title, $body, $head, $base, $label = '' ) {
        $result = $this->create_pull_request( $title, $body, $head, $base );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $is_existing = ! empty( $result['existing'] );
        $pr_number = $result['number'];

        if ( $is_existing ) {
            // Update existing PR title
            $this->request( 'PATCH', sprintf( '/repos/%s/pulls/%d', $this->repo, $pr_number ), array(
                'title' => $title,
            ) );
        }

        // Add label
        if ( ! empty( $label ) ) {
            $this->add_label( $pr_number, $label );
        }

        return $result;
    }
}
