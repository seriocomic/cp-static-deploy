<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Settings page template.
 *
 * @var CPSD_Settings $settings
 * @var array         $checks Prerequisites check results
 * @var string        $saved  Whether settings were just saved
 */
?>
<div class="wrap">
    <h1>CP Static Deploy - Settings</h1>

    <?php if ( ! empty( $saved ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
    <?php endif; ?>

    <h2 class="nav-tab-wrapper">
        <a href="#tab-general" class="nav-tab nav-tab-active" data-tab="general">General</a>
        <a href="#tab-github" class="nav-tab" data-tab="github">GitHub</a>
        <a href="#tab-build" class="nav-tab" data-tab="build">Build</a>
        <a href="#tab-prerequisites" class="nav-tab" data-tab="prerequisites">Prerequisites</a>
    </h2>

    <form method="post" action="">
        <?php wp_nonce_field( 'cpsd_save_settings', 'cpsd_nonce' ); ?>

        <!-- General Tab -->
        <div class="cpsd-tab" id="tab-general">
            <table class="form-table">
                <tr>
                    <th><label for="cpsd_source_url">Source URL</label></th>
                    <td>
                        <input type="url" id="cpsd_source_url" name="cpsd_source_url" value="<?php echo esc_attr( $settings->get( 'source_url' ) ); ?>" class="regular-text" />
                        <p class="description">Dev site URL to crawl (e.g. https://dev.example.com)</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="cpsd_production_url">Production URL</label></th>
                    <td>
                        <input type="url" id="cpsd_production_url" name="cpsd_production_url" value="<?php echo esc_attr( $settings->get( 'production_url' ) ); ?>" class="regular-text" />
                        <p class="description">URL to rewrite dev URLs to (e.g. https://www.example.com)</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="cpsd_exclude_domains">Exclude Domains</label></th>
                    <td>
                        <input type="text" id="cpsd_exclude_domains" name="cpsd_exclude_domains" value="<?php echo esc_attr( $settings->get( 'exclude_domains' ) ); ?>" class="regular-text" />
                        <p class="description">Comma-separated domains to exclude from wget crawl</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="cpsd_deploy_user">Deploy User</label></th>
                    <td>
                        <input type="text" id="cpsd_deploy_user" name="cpsd_deploy_user" value="<?php echo esc_attr( $settings->get( 'deploy_user' ) ); ?>" class="regular-text" />
                        <p class="description">System user that runs the deploy process</p>
                    </td>
                </tr>
                <tr>
                    <th>Auto Deploy</th>
                    <td>
                        <label>
                            <input type="checkbox" name="cpsd_auto_deploy" value="1" <?php checked( $settings->get( 'auto_deploy' ), '1' ); ?> />
                            Trigger deploy when posts/pages are published or updated
                        </label>
                    </td>
                </tr>
            </table>
        </div>

        <!-- GitHub Tab -->
        <div class="cpsd-tab" id="tab-github" style="display:none;">
            <table class="form-table">
                <tr>
                    <th><label for="cpsd_github_repo">Repository</label></th>
                    <td>
                        <input type="text" id="cpsd_github_repo" name="cpsd_github_repo" value="<?php echo esc_attr( $settings->get( 'github_repo' ) ); ?>" class="regular-text" placeholder="owner/repo" />
                        <p class="description">GitHub repository in owner/repo format</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="cpsd_github_token">Personal Access Token</label></th>
                    <td>
                        <input type="password" id="cpsd_github_token" name="cpsd_github_token" value="" class="regular-text" placeholder="<?php echo $settings->get( 'github_token' ) ? '********' : ''; ?>" />
                        <p class="description">Token with <code>repo</code> scope. Leave empty to keep existing. Stored encrypted.</p>
                        <?php if ( $settings->get( 'github_token' ) ) : ?>
                            <p><span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span> Token is configured</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="cpsd_git_staging_branch">Staging Branch</label></th>
                    <td>
                        <input type="text" id="cpsd_git_staging_branch" name="cpsd_git_staging_branch" value="<?php echo esc_attr( $settings->get( 'git_staging_branch' ) ); ?>" class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th><label for="cpsd_git_production_branch">Production Branch</label></th>
                    <td>
                        <input type="text" id="cpsd_git_production_branch" name="cpsd_git_production_branch" value="<?php echo esc_attr( $settings->get( 'git_production_branch' ) ); ?>" class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th><label for="cpsd_pr_auto_merge_label">Auto-Merge Label</label></th>
                    <td>
                        <input type="text" id="cpsd_pr_auto_merge_label" name="cpsd_pr_auto_merge_label" value="<?php echo esc_attr( $settings->get( 'pr_auto_merge_label' ) ); ?>" class="regular-text" />
                        <p class="description">Label added to PRs to trigger auto-merge via GitHub Actions</p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Build Tab -->
        <div class="cpsd-tab" id="tab-build" style="display:none;">
            <table class="form-table">
                <tr>
                    <th><label for="cpsd_cache_clean_pages">Cache Clean Pages</label></th>
                    <td>
                        <textarea id="cpsd_cache_clean_pages" name="cpsd_cache_clean_pages" rows="4" class="large-text code"><?php echo esc_textarea( $settings->get( 'cache_clean_pages' ) ); ?></textarea>
                        <p class="description">Comma-separated paths to delete from wget cache before each build</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="cpsd_selective_threshold">Selective Threshold</label></th>
                    <td>
                        <input type="number" id="cpsd_selective_threshold" name="cpsd_selective_threshold" value="<?php echo esc_attr( $settings->get( 'selective_threshold' ) ); ?>" class="small-text" min="1" />
                        <p class="description">If more items changed than this, use full rebuild instead of selective</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="cpsd_wget_extra_args">Extra wget Arguments</label></th>
                    <td>
                        <input type="text" id="cpsd_wget_extra_args" name="cpsd_wget_extra_args" value="<?php echo esc_attr( $settings->get( 'wget_extra_args' ) ); ?>" class="regular-text" />
                        <p class="description">Additional arguments for wget (e.g. --no-check-certificate)</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="cpsd_robots_txt">Robots.txt</label></th>
                    <td>
                        <textarea id="cpsd_robots_txt" name="cpsd_robots_txt" rows="5" class="large-text code"><?php echo esc_textarea( get_option( 'cpsd_robots_txt', CPSD_Settings::get_specs()['robots_txt']['default'] ) ); ?></textarea>
                        <p class="description">Use <code>{{production_url}}</code> as placeholder for the production URL</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="cpsd_readme_content">README.md</label></th>
                    <td>
                        <textarea id="cpsd_readme_content" name="cpsd_readme_content" rows="4" class="large-text code"><?php echo esc_textarea( $settings->get( 'readme_content' ) ); ?></textarea>
                        <p class="description">Content for README.md in the repo. Leave empty to skip.</p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Prerequisites Tab -->
        <div class="cpsd-tab" id="tab-prerequisites" style="display:none;">
            <table class="widefat striped" style="max-width: 700px;">
                <thead>
                    <tr>
                        <th>Check</th>
                        <th>Status</th>
                        <th>Detail</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $checks as $check ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
                            <td>
                                <?php if ( $check['ok'] ) : ?>
                                    <span style="color: #00a32a;">&#10003; OK</span>
                                <?php else : ?>
                                    <span style="color: #d63638;">&#10007; Failed</span>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo esc_html( $check['detail'] ); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top: 15px;">
                <strong>Working Directory:</strong> <code><?php echo esc_html( CPSD_WORKING_DIR ); ?></code>
            </p>
        </div>

        <?php submit_button( 'Save Settings' ); ?>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        var tab = $(this).data('tab');
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.cpsd-tab').hide();
        $('#tab-' + tab).show();
    });
});
</script>
