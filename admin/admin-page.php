<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Handle form submissions
if (isset($_POST['submit'])) {
    update_option('wcxs_enable_logging', isset($_POST['enable_logging']) ? true : false);
    echo '<div class="notice notice-success"><p>' . __('Settings saved successfully!', 'woo-civi-xero-sync') . '</p></div>';
}

$enable_logging = get_option('wcxs_enable_logging', true);
$logs = get_option('wcxs_sync_logs', array());
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <div class="nav-tab-wrapper">
        <a href="#settings" class="nav-tab nav-tab-active"><?php _e('Settings', 'woo-civi-xero-sync'); ?></a>
        <a href="#logs" class="nav-tab"><?php _e('Sync Logs', 'woo-civi-xero-sync'); ?></a>
        <a href="#test" class="nav-tab"><?php _e('Test Connection', 'woo-civi-xero-sync'); ?></a>
    </div>
    
    <div id="settings" class="tab-content">
        <form method="post" action="">
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="enable_logging"><?php _e('Enable Logging', 'woo-civi-xero-sync'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="enable_logging" value="1" <?php echo $enable_logging ? 'checked' : ''; ?>>
                            <?php _e('Log sync activities and errors', 'woo-civi-xero-sync'); ?>
                        </label>
                        <p class="description">
                            <?php _e('When enabled, all sync activities and errors will be logged for debugging purposes.', 'woo-civi-xero-sync'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="sync_behavior"><?php _e('Sync Behavior', 'woo-civi-xero-sync'); ?></label>
                    </th>
                    <td>
                        <p class="description">
                            <?php _e('Contacts are automatically synced to Xero as soon as an order is submitted. The plugin uses billing address information with fallback to shipping address if billing address is not available.', 'woo-civi-xero-sync'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="civicrm_integration"><?php _e('CiviCRM Integration', 'woo-civi-xero-sync'); ?></label>
                    </th>
                    <td>
                        <p class="description">
                            <?php _e('The plugin uses the order user ID to retrieve CiviCRM contact information and leverages existing CiviXero credentials for Xero API access.', 'woo-civi-xero-sync'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(); ?>
        </form>
    </div>
    
    <div id="logs" class="tab-content" style="display: none;">
        <h2><?php _e('Sync Logs', 'woo-civi-xero-sync'); ?></h2>
        
        <?php if (empty($logs)): ?>
            <p><?php _e('No sync logs found.', 'woo-civi-xero-sync'); ?></p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Timestamp', 'woo-civi-xero-sync'); ?></th>
                        <th><?php _e('Type', 'woo-civi-xero-sync'); ?></th>
                        <th><?php _e('Message', 'woo-civi-xero-sync'); ?></th>
                        <th><?php _e('Order ID', 'woo-civi-xero-sync'); ?></th>
                        <th><?php _e('Xero Contact ID', 'woo-civi-xero-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_reverse($logs) as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log['timestamp']); ?></td>
                            <td>
                                <span class="log-type log-type-<?php echo esc_attr($log['type']); ?>">
                                    <?php echo esc_html(ucfirst($log['type'])); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($log['message']); ?></td>
                            <td>
                                <?php if (!empty($log['order_id'])): ?>
                                    <a href="<?php echo admin_url('post.php?post=' . $log['order_id'] . '&action=edit'); ?>">
                                        #<?php echo esc_html($log['order_id']); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($log['xero_contact_id'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <p>
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=woo-civi-xero-sync&action=clear_logs'), 'clear_logs'); ?>" 
                   class="button button-secondary"
                   onclick="return confirm('<?php _e('Are you sure you want to clear all logs?', 'woo-civi-xero-sync'); ?>')">
                    <?php _e('Clear Logs', 'woo-civi-xero-sync'); ?>
                </a>
            </p>
        <?php endif; ?>
    </div>
    
    <div id="test" class="tab-content" style="display: none;">
        <h2><?php _e('Test Xero Connection', 'woo-civi-xero-sync'); ?></h2>
        
        <p><?php _e('Click the button below to test the connection to Xero using your CiviCRM credentials.', 'woo-civi-xero-sync'); ?></p>
        
        <button type="button" id="test-connection" class="button button-primary">
            <?php _e('Test Connection', 'woo-civi-xero-sync'); ?>
        </button>
        
        <div id="test-result" style="margin-top: 20px;"></div>
    </div>
</div>

<style>
.nav-tab-wrapper {
    margin-bottom: 20px;
}

.tab-content {
    background: #fff;
    padding: 20px;
    border: 1px solid #ccd0d4;
    border-top: none;
}

.log-type {
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
}

.log-type-sync {
    background: #d4edda;
    color: #155724;
}

.log-type-error {
    background: #f8d7da;
    color: #721c24;
}

#test-result {
    padding: 15px;
    border-radius: 4px;
    display: none;
}

#test-result.success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

#test-result.error {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Tab functionality
    $('.nav-tab').click(function(e) {
        e.preventDefault();
        
        // Update active tab
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        // Show corresponding content
        var target = $(this).attr('href').substring(1);
        $('.tab-content').hide();
        $('#' + target).show();
    });
    
    // Test connection
    $('#test-connection').click(function() {
        var button = $(this);
        var resultDiv = $('#test-result');
        
        button.prop('disabled', true).text('<?php _e('Testing...', 'woo-civi-xero-sync'); ?>');
        resultDiv.hide();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wcxs_test_connection',
                nonce: '<?php echo wp_create_nonce('wcxs_test_connection'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    resultDiv.removeClass('error').addClass('success')
                        .html('<strong><?php _e('Success!', 'woo-civi-xero-sync'); ?></strong> ' + response.data.message)
                        .show();
                } else {
                    resultDiv.removeClass('success').addClass('error')
                        .html('<strong><?php _e('Error!', 'woo-civi-xero-sync'); ?></strong> ' + response.data.message)
                        .show();
                }
            },
            error: function() {
                resultDiv.removeClass('success').addClass('error')
                    .html('<strong><?php _e('Error!', 'woo-civi-xero-sync'); ?></strong> <?php _e('Failed to test connection.', 'woo-civi-xero-sync'); ?>')
                    .show();
            },
            complete: function() {
                button.prop('disabled', false).text('<?php _e('Test Connection', 'woo-civi-xero-sync'); ?>');
            }
        });
    });
});
</script>
