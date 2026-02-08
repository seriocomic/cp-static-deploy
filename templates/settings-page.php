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
        <a href="#tab-help" class="nav-tab" data-tab="help">Help</a>
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
            <div class="notice notice-info" style="margin: 15px 0; padding: 12px;">
                <p><strong>External API Usage & Consent:</strong> By configuring a GitHub Personal Access Token below, you authorize this plugin to communicate with GitHub's API (api.github.com) to create and manage pull requests for your static site deployments. The plugin will transmit repository metadata (branch names, commit messages, PR titles) to GitHub's servers. Your token is stored encrypted and only used for GitHub API calls. <a href="https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement" target="_blank">GitHub's Privacy Policy</a></p>
            </div>
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

        <!-- Help Tab -->
        <div class="cpsd-tab" id="tab-help" style="display:none;">
            <div style="max-width: 800px;">

                <h3>Setup Checklist</h3>
                <p>Complete these steps in order before triggering the first deploy. Each step can be verified on the <strong>Prerequisites</strong> tab.</p>
                <ol>
                    <li><strong>Install the plugin</strong> &mdash; Upload to <code>wp-content/plugins/</code> and activate. The working directory (<code>wp-content/static-deploy/</code>) is created automatically.</li>
                    <li><strong>Set working directory permissions</strong> &mdash; The deploy user must own the directory, and the web server group must have read/write access for logs and status:
                        <pre><code>sudo chown -R DEPLOY_USER:www-data /path/to/wp-content/static-deploy
sudo chmod -R 775 /path/to/wp-content/static-deploy</code></pre>
                    </li>
                    <li><strong>Clone the git repository</strong> &mdash; Clone the static site repo into the working directory as the deploy user:
                        <pre><code>cd /path/to/wp-content/static-deploy
sudo -u DEPLOY_USER git clone git@github.com:owner/repo.git repo
cd repo
sudo -u DEPLOY_USER git checkout staging
sudo -u DEPLOY_USER git config user.name "Deploy Bot"
sudo -u DEPLOY_USER git config user.email "deploy@example.com"</code></pre>
                    </li>
                    <li><strong>Configure SSH key</strong> &mdash; The deploy user needs an SSH key registered as a GitHub deploy key (with write access) for git push:
                        <pre><code># Generate key (as deploy user)
sudo -u DEPLOY_USER ssh-keygen -t ed25519 -f ~/.ssh/id_deploy -N ""

# Add public key to GitHub repo > Settings > Deploy keys
# Test: sudo -u DEPLOY_USER ssh -T git@github.com</code></pre>
                    </li>
                    <li><strong>Configure sudoers</strong> &mdash; Allow the web server to run the deploy script as the deploy user (see Sudoers section below).</li>
                    <li><strong>Configure plugin settings</strong> &mdash; Fill in Source URL, Production URL, GitHub repo, and token on the General and GitHub tabs.</li>
                    <li><strong>Create GitHub label</strong> &mdash; Create an <code>auto-merge</code> label in the GitHub repo (Issues &gt; Labels &gt; New label).</li>
                    <li><strong>Add GitHub Actions workflow</strong> &mdash; Create <code>.github/workflows/auto-merge.yml</code> in the static site repo (see GitHub Actions section below).</li>
                </ol>

                <hr />

                <h3>Sudoers Configuration</h3>
                <p><strong>Why:</strong> ClassicPress runs as <code>www-data</code>. The deploy process must run as the deploy user (the user configured in General settings) because that user owns the working directory, the git repo, and the SSH keys for pushing to GitHub. The sudoers entry allows <code>www-data</code> to execute the deploy script as the deploy user without a password prompt.</p>
                <p><strong>What gets allowed:</strong> Two specific commands &mdash; the deploy script itself, and <code>/usr/bin/true</code> (used by the Prerequisites check to verify sudo is configured).</p>
                <pre><code># Create the sudoers file (replace DEPLOY_USER and paths)
echo 'www-data ALL=(DEPLOY_USER) NOPASSWD: /usr/bin/bash /path/to/wp-content/static-deploy/run-deploy.sh, /usr/bin/true' \
  | sudo tee /etc/sudoers.d/static-deploy

# Required: set permissions (sudo ignores files that aren't 0440)
sudo chmod 440 /etc/sudoers.d/static-deploy

# Required: validate syntax
sudo visudo -cf /etc/sudoers.d/static-deploy
# Expected output: parsed OK</code></pre>
                <p><strong>Verify:</strong></p>
                <pre><code># Should produce no output and exit 0
sudo -u www-data sudo -n -u DEPLOY_USER true</code></pre>

                <hr />

                <h3>GitHub Actions Workflow</h3>
                <p>Create this file at <code>.github/workflows/auto-merge.yml</code> in the static site repository. It automatically merges PRs that have the <code>auto-merge</code> label.</p>
                <pre><code>name: Auto-Merge Staging to Master

on:
  pull_request:
    types: [labeled, synchronize, opened, reopened]

jobs:
  auto-merge:
    runs-on: ubuntu-latest
    if: |
      contains(github.event.pull_request.labels.*.name, 'auto-merge') &&
      github.event.pull_request.base.ref == 'master'
    steps:
      - uses: actions/checkout@v4
      - uses: lewagon/wait-on-check-action@v1.3.1
        with:
          ref: ${{ github.event.pull_request.head.sha }}
          check-name: 'auto-merge'
          repo-token: ${{ secrets.GITHUB_TOKEN }}
          wait-interval: 10
          allowed-conclusions: success,skipped,neutral
        continue-on-error: true
      - uses: pascalgn/automerge-action@v0.16.3
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
          MERGE_LABELS: "auto-merge"
          MERGE_METHOD: "squash"
          MERGE_COMMIT_MESSAGE: "pull-request-title"
          MERGE_DELETE_BRANCH: false

  notify-failure:
    runs-on: ubuntu-latest
    needs: auto-merge
    if: failure()
    steps:
      - uses: actions/github-script@v7
        with:
          script: |
            github.rest.issues.createComment({
              issue_number: context.issue.number,
              owner: context.repo.owner,
              repo: context.repo.repo,
              body: 'Auto-merge failed. Check workflow logs and merge manually.'
            })</code></pre>

                <hr />

                <h3>Troubleshooting</h3>
                <table class="widefat striped" style="max-width: 800px;">
                    <thead>
                        <tr><th>Problem</th><th>Cause</th><th>Solution</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Prerequisites: "Sudo not configured"</td>
                            <td>No sudoers entry, or entry is missing <code>/usr/bin/true</code></td>
                            <td>Create <code>/etc/sudoers.d/static-deploy</code> as shown above. Include both the deploy script path and <code>/usr/bin/true</code>.</td>
                        </tr>
                        <tr>
                            <td>Deploy triggered but nothing happens</td>
                            <td>Lock file from previous failed deploy, or permission error</td>
                            <td>Check <code>logs/trigger.log</code>. Delete <code>.lock</code> if no deploy is running. Verify directory permissions (775, owned by deploy user).</td>
                        </tr>
                        <tr>
                            <td>Deploy stuck / lock file persists</td>
                            <td>Deploy process crashed or timed out</td>
                            <td>Check if process is running: <code>ps aux | grep run-deploy</code>. If not, delete the <code>.lock</code> file and retry.</td>
                        </tr>
                        <tr>
                            <td>PR not auto-merging</td>
                            <td>Missing label, no Actions workflow, or workflow error</td>
                            <td>Verify <code>auto-merge</code> label exists in the repo. Check that <code>.github/workflows/auto-merge.yml</code> exists. Review Actions tab for errors.</td>
                        </tr>
                        <tr>
                            <td>wget fails or times out</td>
                            <td>Source URL unreachable, disk full, or SSL error</td>
                            <td>Test with <code>curl -I SOURCE_URL</code>. Check disk space. Add <code>--no-check-certificate</code> to wget args for self-signed certs.</td>
                        </tr>
                        <tr>
                            <td>GitHub API error in deploy log</td>
                            <td>Expired token, wrong repo format, or network issue</td>
                            <td>Update the token on the GitHub tab. Verify repo is in <code>owner/repo</code> format. Test connection from Prerequisites tab.</td>
                        </tr>
                        <tr>
                            <td>Git push rejected</td>
                            <td>SSH key not configured or not authorized</td>
                            <td>Test: <code>sudo -u DEPLOY_USER ssh -T git@github.com</code>. Verify the deploy key has write access in GitHub.</td>
                        </tr>
                        <tr>
                            <td>"No changes detected" on every deploy</td>
                            <td>REST API not returning modified content</td>
                            <td>Delete <code>.last-build-time</code> to force a full rebuild. Verify the REST API is accessible at <code>SOURCE_URL/wp-json/wp/v2/posts</code>.</td>
                        </tr>
                    </tbody>
                </table>

                <hr />

                <h3>FAQ</h3>

                <p><strong>What is the deploy user?</strong><br />
                A system user that owns the working directory and has SSH access to GitHub. The web server (<code>www-data</code>) delegates to this user via sudo for deploy operations.</p>

                <p><strong>Why are two branches needed?</strong><br />
                The <code>staging</code> branch receives automated commits from each deploy. A PR is created from staging to the production branch (e.g. <code>master</code>), providing an audit trail and allowing GitHub Actions to auto-merge. The hosting platform (Cloudflare Pages, GitHub Pages) deploys from the production branch.</p>

                <p><strong>What is selective vs full build?</strong><br />
                Selective builds query the WordPress REST API for content modified since the last deploy, then only download those specific URLs plus dependencies (homepage, feeds, archives). If more items changed than the threshold setting, or no previous build exists, a full wget mirror is used instead.</p>

                <p><strong>Where are the logs?</strong><br />
                Deploy logs are at <code>wp-content/static-deploy/logs/deploy.log</code> (main pipeline) and <code>trigger.log</code> (ClassicPress events). Both are viewable from <strong>Tools &gt; Static Deploy</strong>.</p>

                <p><strong>How is the GitHub token stored?</strong><br />
                Encrypted with AES-256-CBC using keys derived from <code>AUTH_KEY</code> and <code>AUTH_SALT</code> in <code>wp-config.php</code>. The token is only decrypted in memory when needed for API calls.</p>

                <p><strong>Can deploys be triggered from the command line?</strong><br />
                Yes: <code>bash /path/to/wp-content/static-deploy/run-deploy.sh</code> (run as the deploy user). The admin status page detects externally triggered deploys automatically.</p>

            </div>
        </div>

        <div id="cpsd-submit-wrap">
            <?php submit_button( 'Save Settings' ); ?>
        </div>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    var noSubmitTabs = ['prerequisites', 'help'];

    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        var tab = $(this).data('tab');
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.cpsd-tab').hide();
        $('#tab-' + tab).show();

        if (noSubmitTabs.indexOf(tab) !== -1) {
            $('#cpsd-submit-wrap').hide();
        } else {
            $('#cpsd-submit-wrap').show();
        }
    });
});
</script>
