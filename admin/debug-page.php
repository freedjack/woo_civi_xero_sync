<?php
/**
 * Debug Page for WooCiviXeroSync
 * 
 * @package WooCiviXeroSync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include utilities class
require_once WCXS_PLUGIN_DIR . 'includes/class-wcxs-utilities.php';

class WooCiviXeroSyncDebugPage {
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Add AJAX action for invoice details
        add_action('wp_ajax_wcxs_get_invoice_details', array($this, 'ajax_get_invoice_details'));
        add_action('wp_ajax_nopriv_wcxs_get_invoice_details', array($this, 'ajax_get_invoice_details'));
    }
    
    /**
     * Render the debug page
     */
    public function render() {
        // Handle form submissions
        $user_data = null;
        $debug_logs = null;
        $status_message = '';
        
        if (isset($_POST['load_user_info']) && wp_verify_nonce($_POST['wcxs_debug_nonce'], 'wcxs_debug_action')) {
            $user_id = intval($_POST['selected_user_id']);
            if ($user_id) {
                $user_data = $this->get_user_debug_info($user_id);
            }
        }
        
        if (isset($_POST['update_xero_contact']) && wp_verify_nonce($_POST['wcxs_debug_nonce'], 'wcxs_debug_action')) {
            $user_id = intval($_POST['selected_user_id']);
            if ($user_id) {
                $result = $this->update_xero_contact_debug($user_id);
                $debug_logs = $result['logs'];
                $status_message = $result['message'];
                $user_data = $this->get_user_debug_info($user_id);
            }
        }
        
        ?>
        <div class="wrap">
            <h1><?php _e('WooCiviXeroSync Debug', 'woo-civi-xero-sync'); ?></h1>
            
            <?php if ($status_message): ?>
                <div class="notice notice-<?php echo strpos($status_message, 'successfully') !== false ? 'success' : 'error'; ?> is-dismissible">
                    <p><?php echo esc_html($status_message); ?></p>
                </div>
            <?php endif; ?>
            
            <div class="wcxs-debug-container">
                <!-- User Selection -->
                <div class="wcxs-debug-section">
                    <h2><?php _e('User Selection', 'woo-civi-xero-sync'); ?></h2>
                    <p><?php _e('Select a user to debug their CiviCRM and Xero contact information:', 'woo-civi-xero-sync'); ?></p>
                    
                    <form method="post" action="">
                        <?php wp_nonce_field('wcxs_debug_action', 'wcxs_debug_nonce'); ?>
                        <select name="selected_user_id" id="wcxs-debug-user-select">
                            <option value=""><?php _e('Select a user...', 'woo-civi-xero-sync'); ?></option>
                            <?php
                            $users = get_users(array(
                                'orderby' => 'display_name',
                                'order' => 'ASC',
                                'number' => 100
                            ));
                            foreach ($users as $user) {
                                $selected = (isset($_POST['selected_user_id']) && $_POST['selected_user_id'] == $user->ID) ? 'selected' : '';
                                  echo '<option value="' . $user->ID . '" ' . $selected . '>' . $user->display_name . ' (' . $user->user_login . ')</option>';
                            }
                            ?>
                        </select>
                        
                        <input type="submit" name="load_user_info" value="<?php _e('Load User Info', 'woo-civi-xero-sync'); ?>" class="button button-primary">
                        
                        <?php if (isset($_POST['selected_user_id']) && !empty($_POST['selected_user_id'])): ?>
                            <input type="submit" name="update_xero_contact" value="<?php _e('Update Contact in Xero', 'woo-civi-xero-sync'); ?>" class="button button-secondary">
                        <?php endif; ?>
                    </form>
                </div>
                
                <!-- User Information Display -->
                <?php if ($user_data): ?>
                <div class="wcxs-debug-section">
                    <h2><?php _e('User Information', 'woo-civi-xero-sync'); ?></h2>
                    
                    <!-- WordPress User Info -->
                    <div class="wcxs-debug-subsection">
                        <h3><?php _e('WordPress User', 'woo-civi-xero-sync'); ?></h3>
                        <table class="wcxs-info-table">
                            <tr><th>Field</th><th>Value</th></tr>
                            <tr><td>User ID</td><td><?php echo esc_html($user_data['wp_user']['ID']); ?></td></tr>
                            <tr><td>Username</td><td><?php echo esc_html($user_data['wp_user']['user_login']); ?></td></tr>
                            <tr><td>Display Name</td><td><?php echo esc_html($user_data['wp_user']['display_name']); ?></td></tr>
                            <tr><td>Email</td><td><?php echo esc_html($user_data['wp_user']['user_email']); ?></td></tr>
                            <tr><td>Registered</td><td><?php echo esc_html($user_data['wp_user']['user_registered']); ?></td></tr>
                        </table>
                    </div>
                    
                    <!-- CiviCRM Contact Info -->
                    <div class="wcxs-debug-subsection">
                        <h3><?php _e('CiviCRM Contact', 'woo-civi-xero-sync'); ?></h3>
                        <?php if ($user_data['civicrm_contact']): ?>
                            <table class="wcxs-info-table">
                                <tr><th>Field</th><th>Value</th></tr>
                                <tr><td>Contact ID</td><td><?php echo esc_html($user_data['civicrm_contact']['id']); ?></td></tr>
                                <tr><td>First Name</td><td><?php echo esc_html($user_data['civicrm_contact']['first_name'] ?? 'N/A'); ?></td></tr>
                                <tr><td>Last Name</td><td><?php echo esc_html($user_data['civicrm_contact']['last_name'] ?? 'N/A'); ?></td></tr>
                                <tr><td>Email</td><td><?php echo esc_html($user_data['civicrm_contact']['email'] ?? 'N/A'); ?></td></tr>
                                <tr><td>Phone</td><td><?php echo esc_html($user_data['civicrm_contact']['phone'] ?? 'N/A'); ?></td></tr>
                            </table>
                        <?php else: ?>
                            <p class="wcxs-status-error">No CiviCRM contact found for this user.</p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Xero Contact Info -->
                    <div class="wcxs-debug-subsection">
                        <h3><?php _e('Xero Contact', 'woo-civi-xero-sync'); ?></h3>
                        <?php if (isset($user_data['xero_contact']) && $user_data['xero_contact']): ?>
                            <table class="wcxs-info-table">
                                <tr><th>Field</th><th>Value</th></tr>
                                <tr><td>Contact ID</td><td><?php echo esc_html($user_data['xero_contact']['ContactID']); ?> <a href="https://go.xero.com/app/!q1vD1/contacts/contact/<?php echo esc_html($user_data['xero_contact']['ContactID']); ?>/files" target="_blank">View in Xero</a></td></tr>
                                <tr><td>Name</td><td><?php echo esc_html($user_data['xero_contact']['Name']); ?></td></tr>
                                <tr><td>Email Address</td><td><?php echo esc_html($user_data['xero_contact']['EmailAddress'] ?? 'N/A'); ?></td></tr>
                                <tr><td>Account Number</td><td><?php echo esc_html($user_data['xero_contact']['ContactNumber'] ?? 'N/A'); ?></td></tr>
                                <tr><td>Contact Status</td><td><?php echo esc_html($user_data['xero_contact']['ContactStatus'] ?? 'N/A'); ?></td></tr>
                                
                            </table>
                            
                            <?php if (isset($user_data['xero_contact']['Addresses']['Address'])): ?>
                                <h4>Current Addresses:</h4>
                                <table class="wcxs-info-table">
                                    <tr><th>Type</th><th>Address Line 1</th><th>City</th><th>Postal Code</th><th>Country</th></tr>
                                    <?php foreach ($user_data['xero_contact']['Addresses']['Address'] as $address): ?>
                                        <tr>
                                            
                                            <td><?php echo esc_html($address['AddressType']); ?></td>
                                            <td><?php echo esc_html($address['AddressLine1'] ?? 'N/A'); ?></td>
                                            <td><?php echo esc_html($address['City'] ?? 'N/A'); ?></td>
                                            <td><?php echo esc_html($address['PostalCode'] ?? 'N/A'); ?></td>
                                            <td><?php echo esc_html($address['Country'] ?? 'N/A'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="wcxs-status-info">No Xero contact found for this user.</p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Xero Invoices -->
                    <div class="wcxs-debug-subsection">
                        <h3><?php _e('Last 5 Xero Invoices', 'woo-civi-xero-sync'); ?></h3>
                        <?php 
                        // Debug output
                        echo '<p><strong>Debug:</strong> Available keys in user_data: ' . implode(', ', array_keys($user_data)) . '</p>';
                        
                        if (isset($user_data['xero_contact'])) {
                            echo '<p><strong>Debug:</strong> xero_contact exists with keys: ' . implode(', ', array_keys($user_data['xero_contact'])) . '</p>';
                        } else {
                            echo '<p><strong>Debug:</strong> xero_contact key does not exist</p>';
                        }
                        
                        if (isset($user_data['xero_invoices'])) {
                            echo '<p><strong>Debug:</strong> xero_invoices key exists with ' . count($user_data['xero_invoices']) . ' items</p>';
                        } else {
                            echo '<p><strong>Debug:</strong> xero_invoices key does not exist</p>';
                        }
                        ?>
                        <?php if (isset($user_data['xero_invoices']) && !empty($user_data['xero_invoices'])): ?>
                            <?php foreach ($user_data['xero_invoices'] as $invoice): ?>
                                <div class="wcxs-invoice-item">
                                    <div class="wcxs-invoice-header">
                                        <h4>Invoice #<?php echo esc_html($invoice['InvoiceNumber']); ?> - <?php echo esc_html($invoice['Date']); ?></h4>
                                        <button type="button" class="button button-secondary wcxs-view-invoice-btn" 
                                                data-invoice-id="<?php echo esc_attr($invoice['InvoiceID']); ?>"
                                                data-invoice-number="<?php echo esc_attr($invoice['InvoiceNumber']); ?>">
                                            <?php _e('View Full Invoice Details', 'woo-civi-xero-sync'); ?>
                                        </button>
                                    </div>
                                    
                                    <div class="wcxs-invoice-summary">
                                        <table class="wcxs-info-table">
                                            <tr><th>Field</th><th>Value</th></tr>
                                            <tr><td>Invoice ID</td><td><?php echo esc_html($invoice['InvoiceID']); ?></td></tr>
                                            <tr><td>Status</td><td><?php echo esc_html($invoice['Status']); ?></td></tr>
                                            <tr><td>Type</td><td><?php echo esc_html($invoice['Type']); ?></td></tr>
                                            <tr><td>Total</td><td><?php echo esc_html($invoice['Total']); ?></td></tr>
                                            <tr><td>Amount Due</td><td><?php echo esc_html($invoice['AmountDue']); ?></td></tr>
                                        </table>
                                    </div>
                                    
                                    <!-- Invoice Details Container (hidden by default) -->
                                    <div class="wcxs-invoice-details" id="invoice-details-<?php echo esc_attr($invoice['InvoiceID']); ?>" style="display: none;">
                                        <div class="wcxs-loading">Loading invoice details...</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="wcxs-status-info">No Xero invoices found for this contact.</p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Address Information -->
                    <div class="wcxs-debug-subsection">
                        <h3><?php _e('Address Information', 'woo-civi-xero-sync'); ?></h3>
                        <h4>Address that will be used for Xero update:</h4>
                        <?php if (isset($user_data['address_info']) && $user_data['address_info']): ?>
                            <table class="wcxs-info-table">
                                <tr><th>Field</th><th>Value</th></tr>
                                <tr><td>Source</td><td><?php echo esc_html($user_data['address_info']['source']); ?></td></tr>
                                <tr><td>Address Line 1</td><td><?php echo esc_html($user_data['address_info']['AddressLine1'] ?? 'N/A'); ?></td></tr>
                                <tr><td>Address Line 2</td><td><?php echo esc_html($user_data['address_info']['AddressLine2'] ?? 'N/A'); ?></td></tr>
                                <tr><td>City</td><td><?php echo esc_html($user_data['address_info']['City'] ?? 'N/A'); ?></td></tr>
                                <tr><td>Postal Code</td><td><?php echo esc_html($user_data['address_info']['PostalCode'] ?? 'N/A'); ?></td></tr>
                                <tr><td>Country</td><td><?php echo esc_html($user_data['address_info']['Country'] ?? 'N/A'); ?></td></tr>
                                <tr><td>Region</td><td><?php echo esc_html($user_data['address_info']['Region'] ?? 'N/A'); ?></td></tr>
                            </table>
                        <?php else: ?>
                            <p class="wcxs-status-error">No address information available.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Debug Logs -->
                <?php if ($debug_logs): ?>
                <div class="wcxs-debug-section">
                    <h2><?php _e('Debug Logs', 'woo-civi-xero-sync'); ?></h2>
                    <div class="wcxs-debug-log">
                        <?php echo nl2br(esc_html($debug_logs)); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
        .wcxs-debug-container {
            max-width: 1200px;
        }
        .wcxs-debug-section {
            background: #fff;
            border: 1px solid #ccd0d4;
            padding: 20px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .wcxs-debug-subsection {
            background: #f9f9f9;
            border: 1px solid #e5e5e5;
            padding: 15px;
            margin: 15px 0;
            border-radius: 3px;
        }
        .wcxs-debug-subsection h3 {
            margin-top: 0;
            color: #23282d;
        }
        .wcxs-info-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        .wcxs-info-table th,
        .wcxs-info-table td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .wcxs-info-table th {
            background-color: #f1f1f1;
            font-weight: bold;
        }
        .wcxs-debug-log {
            background: #f4f4f4;
            border: 1px solid #ddd;
            padding: 15px;
            margin: 10px 0;
            border-radius: 3px;
            font-family: monospace;
            white-space: pre-wrap;
            max-height: 400px;
            overflow-y: auto;
        }
        .wcxs-status-success {
            color: #46b450;
            font-weight: bold;
        }
        .wcxs-status-error {
            color: #dc3232;
            font-weight: bold;
        }
        .wcxs-status-info {
            color: #0073aa;
            font-weight: bold;
        }
        .wcxs-invoice-item {
            background: #fff;
            border: 1px solid #ddd;
            padding: 15px;
            margin: 15px 0;
            border-radius: 3px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .wcxs-invoice-item h4 {
            margin-top: 0;
            color: #23282d;
            border-bottom: 2px solid #0073aa;
            padding-bottom: 5px;
        }
        .wcxs-invoice-item h5 {
            color: #0073aa;
            margin: 15px 0 10px 0;
        }
        .wcxs-invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .wcxs-invoice-header h4 {
            margin: 0;
            flex: 1;
        }
        .wcxs-view-invoice-btn {
            margin-left: 15px;
        }
        .wcxs-invoice-summary {
            margin-bottom: 15px;
        }
        .wcxs-invoice-details {
            background: #f9f9f9;
            border: 1px solid #e5e5e5;
            padding: 15px;
            margin-top: 15px;
            border-radius: 3px;
        }
        .wcxs-loading {
            text-align: center;
            color: #666;
            font-style: italic;
        }
        .wcxs-error {
            color: #dc3232;
            font-weight: bold;
            text-align: center;
            padding: 10px;
            background: #fef7f7;
            border: 1px solid #dc3232;
            border-radius: 3px;
        }
        </style>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            console.log('WCXS Debug: JavaScript loaded');
            
            $('.wcxs-view-invoice-btn').on('click', function() {
                console.log('WCXS Debug: Button clicked');
                
                var button = $(this);
                var invoiceId = button.data('invoice-id');
                var invoiceNumber = button.data('invoice-number');
                var detailsContainer = $('#invoice-details-' + invoiceId);
                
                console.log('WCXS Debug: Invoice ID:', invoiceId);
                console.log('WCXS Debug: Invoice Number:', invoiceNumber);
                console.log('WCXS Debug: Details container:', detailsContainer.length);
                
                // Toggle button state
                if (detailsContainer.is(':visible')) {
                    detailsContainer.hide();
                    button.text('<?php _e('View Full Invoice Details', 'woo-civi-xero-sync'); ?>');
                    return;
                }
                
                // Show loading state
                detailsContainer.show();
                button.prop('disabled', true).text('<?php _e('Loading...', 'woo-civi-xero-sync'); ?>');
                
                console.log('WCXS Debug: Making AJAX request');
                console.log('WCXS Debug: AJAX URL:', ajaxurl);
                
                // Make AJAX request
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wcxs_get_invoice_details',
                        nonce: '<?php echo wp_create_nonce('wcxs_invoice_details'); ?>',
                        invoice_id: invoiceId,
                        invoice_number: invoiceNumber
                    },
                    success: function(response) {
                        console.log('WCXS Debug: AJAX success response:', response);
                        if (response.success) {
                            detailsContainer.html(response.data.html);
                        } else {
                            detailsContainer.html('<div class="wcxs-error">Error: ' + response.data.message + '</div>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.log('WCXS Debug: AJAX error:', status, error);
                        console.log('WCXS Debug: XHR response:', xhr.responseText);
                        detailsContainer.html('<div class="wcxs-error"><?php _e('Failed to load invoice details', 'woo-civi-xero-sync'); ?></div>');
                    },
                    complete: function() {
                        button.prop('disabled', false).text('<?php _e('Hide Invoice Details', 'woo-civi-xero-sync'); ?>');
                    }
                });
            });
        });
        </script>
        

        <?php
    }
    

    

    
    /**
     * Get user debug information
     */
    private function get_user_debug_info($user_id) {
        try {
            $data = array();
            
            // Get WordPress user info
            $wp_user = get_userdata($user_id);
            if (!$wp_user) {
                return null;
            }
            $data['wp_user'] = $wp_user->to_array();
            
            // Get CiviCRM contact info
            $contact_id = WCXS_Utilities::get_civicrm_contact_id($user_id);
            if ($contact_id) {
                $data['civicrm_contact'] = WCXS_Utilities::get_civicrm_contact_details($contact_id);
            }
            
            // Get Xero contact info
            $contact_id = WCXS_Utilities::get_civicrm_contact_id($user_id);
            if ($contact_id) {
                $xero_contact_id = WCXS_Utilities::find_xero_contact_id($contact_id);
                if ($xero_contact_id) {
                    error_log("WCXS Debug: Found Xero contact ID in account table: " . $xero_contact_id);
                    $xero_contact = WCXS_Utilities::get_xero_contact_by_id($xero_contact_id);
                    if ($xero_contact) {
                        error_log("WCXS Debug: Retrieved Xero contact with ContactID: " . $xero_contact['ContactID']);
                        $data['xero_contact'] = $xero_contact;
                    } else {
                        error_log("WCXS Debug: Failed to retrieve Xero contact by ID: " . $xero_contact_id);
                    }
                } else {
                    error_log("WCXS Debug: No Xero contact ID found in account table for CiviCRM contact: " . $contact_id);
                }
            }
            
            // Get address information that would be used for sync
            $address_info = WCXS_Utilities::get_address_info_for_user($user_id);
            if ($address_info) {
                $data['address_info'] = $address_info;
            }
      
            // Get last 5 Xero invoices for this contact
            error_log("WCXS Debug: === STARTING INVOICE RETRIEVAL ===");
            error_log("WCXS Debug: Checking if Xero contact exists for invoice retrieval");
            if (isset($data['xero_contact'])) {
                error_log("WCXS Debug: xero_contact key exists");
                if (isset($data['xero_contact']['ContactID'])) {
                    error_log("WCXS Debug: ContactID exists: " . $data['xero_contact']['ContactID']);
                    error_log("WCXS Debug: About to call get_xero_invoices method");
                    $invoices = $this->get_xero_invoices($data['xero_contact']['ContactID']);
                    error_log("WCXS Debug: get_xero_invoices returned: " . ($invoices ? count($invoices) . " invoices" : "null"));
                    if ($invoices) {
                        error_log("WCXS Debug: Successfully retrieved " . count($invoices) . " invoices");
                        $data['xero_invoices'] = $invoices;
                        error_log("WCXS Debug: Added xero_invoices to data array");
                    } else {
                        error_log("WCXS Debug: No invoices returned for contact ID: " . $data['xero_contact']['ContactID']);
                    }
                } else {
                    error_log("WCXS Debug: ContactID key does not exist in xero_contact");
                    error_log("WCXS Debug: xero_contact keys: " . json_encode(array_keys($data['xero_contact'])));
                }
            } else {
                error_log("WCXS Debug: No Xero contact found, cannot retrieve invoices");
            }
            error_log("WCXS Debug: === FINISHED INVOICE RETRIEVAL ===");
            
            return $data;
            
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Get last 5 Xero invoices for a contact
     */
    private function get_xero_invoices($contact_id) {
        try {
            $xero_api = WCXS_Utilities::get_xero_api();
            if (!$xero_api) {
                error_log("WCXS Debug: Failed to get Xero API for invoice retrieval");
                return null;
            }
            
            error_log("WCXS Debug: Getting all invoices to search for contact ID: $contact_id");
            
            // Get all invoices and filter manually (most reliable approach)
            $all_invoices = $xero_api->invoices();
            
            if (!$all_invoices || !is_array($all_invoices)) {
                error_log("WCXS Debug: Failed to get invoices from Xero API");
                return null;
            }
            
            if (!isset($all_invoices['Invoices']['Invoice'])) {
                error_log("WCXS Debug: No invoices found in response");
                return null;
            }
            
            $invoice_list = $all_invoices['Invoices']['Invoice'];
            if (!isset($invoice_list[0])) {
                $invoice_list = array($invoice_list);
            }
            
            error_log("WCXS Debug: Retrieved " . count($invoice_list) . " total invoices from Xero");
            
            // Filter invoices by contact ID
            $contact_invoices = array();
            foreach ($invoice_list as $invoice) {
                if (isset($invoice['Contact']['ContactID']) && $invoice['Contact']['ContactID'] === $contact_id) {
                    $contact_invoices[] = $invoice;
                    error_log("WCXS Debug: Found invoice for contact: " . $invoice['InvoiceNumber']);
                }
            }
            
            error_log("WCXS Debug: Found " . count($contact_invoices) . " invoices for contact $contact_id");
            
            // Sort by date (newest first) and limit to 5
            usort($contact_invoices, function($a, $b) {
                return strtotime($b['Date']) - strtotime($a['Date']);
            });
            
            $contact_invoices = array_slice($contact_invoices, 0, 5);
            
            return $contact_invoices;
            
        } catch (Exception $e) {
            error_log("WCXS Debug: Exception getting invoices: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update Xero contact for debug
     */
    private function update_xero_contact_debug($user_id) {
        try {
            $logs = array();
            
            // Get Xero API
            $xero_api = WCXS_Utilities::get_xero_api();
            if (!$xero_api) {
                return array(
                    'message' => 'Failed to initialize Xero API',
                    'logs' => 'Error: Could not initialize Xero API'
                );
            }
            
            // Get existing Xero contact
            $contact_id = WCXS_Utilities::get_civicrm_contact_id($user_id);
            if (!$contact_id) {
                return array(
                    'message' => 'No CiviCRM contact found for this user',
                    'logs' => 'Error: No CiviCRM contact found'
                );
            }
            
            $xero_contact_id = WCXS_Utilities::find_xero_contact_id($contact_id);
            if (!$xero_contact_id) {
                return array(
                    'message' => 'No Xero contact found for this user',
                    'logs' => 'Error: No Xero contact found'
                );
            }
            
            $existing_contact = WCXS_Utilities::get_xero_contact_by_id($xero_contact_id);
            if (!$existing_contact) {
                return array(
                    'message' => 'No Xero contact found for this user',
                    'logs' => 'Error: No Xero contact found'
                );
            }
            
            // Get address information
            $address_info = WCXS_Utilities::get_address_info_for_user($user_id);
            if (!$address_info) {
                return array(
                    'message' => 'No address information available',
                    'logs' => 'Error: No address information available'
                );
            }
            
            // Prepare contact data exactly like CiviXero does
            $new_contact = array(
                'ContactID' => $existing_contact['ContactID'],
                'Name' => $existing_contact['Name'],
                'EmailAddress' => $existing_contact['EmailAddress'],
                'Addresses' => array(
                    'Address' => array(
                        array(
                            'AddressType' => 'POBOX',
                            'AddressLine1' => $address_info['AddressLine1'] ?? '',
                            'AddressLine2' => $address_info['AddressLine2'] ?? '',
                            'AddressLine3' => $address_info['AddressLine3'] ?? '',
                            'AddressLine4' => $address_info['AddressLine4'] ?? '',
                            'City' => $address_info['City'] ?? '',
                            'PostalCode' => $address_info['PostalCode'] ?? '',
                            'Country' => $address_info['Country'] ?? '',
                            'Region' => $address_info['Region'] ?? '',
                        )
                    )
                )
            );
            
            // Wrap in array like CiviXero does
            $contact_data = array($new_contact);
            
            $logs[] = 'Request Data:';
            $logs[] = json_encode($contact_data, JSON_PRETTY_PRINT);
            
            // Update contact in Xero
            $response = $xero_api->contacts($contact_data);
            
            $logs[] = 'Response:';
            $logs[] = json_encode($response, JSON_PRETTY_PRINT);
            
            return array(
                'message' => 'Contact updated successfully!',
                'logs' => implode("\n", $logs)
            );
            
        } catch (Exception $e) {
            $logs[] = 'Error: ' . $e->getMessage();
            return array(
                'message' => 'Failed to update contact: ' . $e->getMessage(),
                'logs' => implode("\n", $logs)
            );
        }
    }
    
    /**
     * AJAX handler for getting invoice details
     */
    public function ajax_get_invoice_details() {
        try {
            error_log("WCXS Debug: AJAX invoice details request received");
            error_log("WCXS Debug: POST data: " . json_encode($_POST));
            
            // Verify nonce
            if (!wp_verify_nonce($_POST['nonce'], 'wcxs_invoice_details')) {
                error_log("WCXS Debug: Nonce verification failed");
                wp_send_json_error(array('message' => 'Security check failed'));
                return;
            }
            
            // Check permissions
            if (!current_user_can('manage_woocommerce')) {
                error_log("WCXS Debug: Permission check failed");
                wp_send_json_error(array('message' => 'Insufficient permissions'));
                return;
            }
            
            $invoice_id = sanitize_text_field($_POST['invoice_id']);
            $invoice_number = sanitize_text_field($_POST['invoice_number']);
            
            error_log("WCXS Debug: Invoice ID: $invoice_id, Invoice Number: $invoice_number");
            
            if (empty($invoice_id)) {
                error_log("WCXS Debug: Invoice ID is empty");
                wp_send_json_error(array('message' => 'Invoice ID is required'));
                return;
            }
            
            // Get invoice details from Xero
            error_log("WCXS Debug: Calling get_invoice_details");
            $invoice_details = $this->get_invoice_details($invoice_id);
            
            error_log("WCXS Debug: Invoice details result: " . ($invoice_details ? 'success' : 'null'));
            
            if ($invoice_details) {
                error_log("WCXS Debug: Rendering invoice HTML");
                $html = $this->render_invoice_details_html($invoice_details);
                error_log("WCXS Debug: HTML length: " . strlen($html));
                wp_send_json_success(array('html' => $html));
            } else {
                error_log("WCXS Debug: Failed to retrieve invoice details");
                wp_send_json_error(array('message' => 'Failed to retrieve invoice details'));
            }
            
        } catch (Exception $e) {
            error_log("WCXS Debug: Exception in AJAX handler: " . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * Get detailed invoice information from Xero using direct method only
     */
    private function get_invoice_details($invoice_id) {
        try {
            error_log("WCXS Debug: get_invoice_details called with ID: $invoice_id");
            
            $xero_api = WCXS_Utilities::get_xero_api();
            if (!$xero_api) {
                error_log("WCXS Debug: Failed to get Xero API");
                return null;
            }
            
            error_log("WCXS Debug: Using direct invoice ID method");
            
            // Use direct method only
            $invoice = $xero_api->invoices($invoice_id);
            error_log("WCXS Debug: Direct method result: " . json_encode($invoice));
            
            if (!$invoice || !is_array($invoice)) {
                error_log("WCXS Debug: Direct method returned null or not array");
                return null;
            }
            
            error_log("WCXS Debug: Successfully retrieved invoice");
            return $invoice;
            
        } catch (Exception $e) {
            error_log("WCXS Debug: Exception getting invoice details: " . $e->getMessage());
            return null;
        }
    }
}
