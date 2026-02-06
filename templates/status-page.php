<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Deploy status page template.
 *
 * @var bool   $is_running     Whether a deploy is currently running
 * @var int    $last_deploy    Timestamp of last deploy
 * @var array  $last_result    Last deploy result array
 * @var string $recent_logs    Recent log content
 * @var array  $status         Status display data (message, color, html)
 * @var string $github_repo    GitHub repository (owner/repo)
 * @var string $production_url Production site URL
 */

$last_deploy_display = $last_deploy ? wp_date( 'Y-m-d H:i:s', $last_deploy ) : 'Never';
$time_ago = $last_deploy ? human_time_diff( $last_deploy, time() ) . ' ago' : '';
?>
<div class="wrap">
    <h1>Static Deploy</h1>

    <div class="card cpsd-card">
        <h2>Deploy Status</h2>

        <table class="form-table cpsd-status-table">
            <tbody>
                <tr>
                    <th>Last Deploy Triggered</th>
                    <td>
                        <strong id="cpsd-last-deploy-time"><?php echo esc_html( $last_deploy_display ); ?></strong>
                        <?php if ( $time_ago ) : ?>
                            <span style="color: #666;"> (<?php echo esc_html( $time_ago ); ?>)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Current Status</th>
                    <td id="cpsd-deploy-status"><?php echo $status['html']; ?></td>
                </tr>
                <?php if ( ! empty( $last_result['message'] ) ) : ?>
                <tr>
                    <th>Last Result</th>
                    <td id="cpsd-last-result"><em><?php echo esc_html( $last_result['message'] ); ?></em></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <p class="cpsd-actions">
            <button type="button" id="cpsd-deploy-btn" class="button button-primary" <?php echo $is_running ? 'disabled' : ''; ?>>
                Trigger Manual Deploy
            </button>
            <button type="button" id="cpsd-refresh-btn" class="button">
                Refresh Status
            </button>
        </p>

        <p class="cpsd-links">
            <?php if ( $github_repo ) : ?>
                <a href="https://github.com/<?php echo esc_attr( $github_repo ); ?>/pulls" target="_blank" class="button">
                    View GitHub PRs
                </a>
            <?php endif; ?>
            <?php if ( $production_url ) : ?>
                <a href="<?php echo esc_url( $production_url ); ?>" target="_blank" class="button">
                    View Live Site
                </a>
            <?php endif; ?>
            <a href="<?php echo esc_url( admin_url( 'options-general.php?page=cp-static-deploy-settings' ) ); ?>" class="button">
                Settings
            </a>
        </p>
    </div>

    <div class="card cpsd-card cpsd-card-log">
        <h2>Recent Deploy Log</h2>
        <pre id="cpsd-deploy-log" class="cpsd-log"><?php echo esc_html( $recent_logs ); ?></pre>
        <p><small>Showing recent log entries from deploy.log</small></p>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var refreshInterval = null;
    var completionInterval = null;
    var deployNonce = '<?php echo esc_js( wp_create_nonce( 'cpsd_deploy' ) ); ?>';
    var statusNonce = '<?php echo esc_js( wp_create_nonce( 'cpsd_status' ) ); ?>';

    function refreshStatus() {
        $.post(ajaxurl, {
            action: 'cpsd_get_status',
            _ajax_nonce: statusNonce
        }, function(response) {
            if (response.success) {
                var d = response.data;
                $('#cpsd-deploy-status').html(d.status_html);
                $('#cpsd-last-deploy-time').parent().html(d.last_deploy_html);
                $('#cpsd-deploy-log').text(d.logs);
                $('#cpsd-deploy-btn').prop('disabled', d.is_running);

                if (d.last_result_message) {
                    if ($('#cpsd-last-result').length === 0) {
                        $('#cpsd-deploy-status').closest('tr').after(
                            '<tr><th>Last Result</th><td id="cpsd-last-result"><em>' +
                            $('<em>').text(d.last_result_message).html() + '</em></td></tr>'
                        );
                    } else {
                        $('#cpsd-last-result em').text(d.last_result_message);
                    }
                }

                if (!d.is_running && refreshInterval) {
                    clearInterval(refreshInterval);
                    refreshInterval = null;
                }
                if (!d.is_running && completionInterval) {
                    clearInterval(completionInterval);
                    completionInterval = null;
                }
            }
        });
    }

    function startAutoRefresh() {
        if (!refreshInterval) {
            refreshInterval = setInterval(refreshStatus, 5000);
        }
    }

    $('#cpsd-deploy-btn').on('click', function() {
        if (!confirm('Trigger a manual deploy now?')) return;

        var $btn = $(this);
        $btn.prop('disabled', true).text('Deploying...');
        $('#cpsd-deploy-status').html('<span style="color: #d63638;">Build starting...</span>');

        $.post(ajaxurl, {
            action: 'cpsd_manual_deploy',
            _ajax_nonce: deployNonce
        }, function(response) {
            if (response.success) {
                $('#cpsd-deploy-status').html('<span style="color: #d63638;">Build in progress...</span>');
                $('#cpsd-last-deploy-time').parent().html(response.data.time_html);
                startAutoRefresh();
                completionInterval = setInterval(refreshStatus, 3000);
            } else {
                alert('Error: ' + response.data);
                $btn.prop('disabled', false).text('Trigger Manual Deploy');
            }
        });
    });

    $('#cpsd-refresh-btn').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Refreshing...');
        refreshStatus();
        setTimeout(function() {
            $btn.prop('disabled', false).text('Refresh Status');
        }, 1000);
    });

    <?php if ( $is_running ) : ?>
    startAutoRefresh();
    completionInterval = setInterval(refreshStatus, 3000);
    <?php endif; ?>
});
</script>
