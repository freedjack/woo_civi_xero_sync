<?php
/**
 * Plugin Name: WooCommerce CiviCRM Xero Sync
 * Plugin URI: https://github.com/your-org/woo-civi-xero-sync
 * Description: Syncs WooCommerce orders to Xero contacts using CiviXero credentials
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: woo-civi-xero-sync
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WCXS_PLUGIN_FILE', __FILE__);
define('WCXS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCXS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WCXS_VERSION', '1.0.0');

/**
 * Main plugin class
 */
class WooCiviXeroSync {
    
    /**
     * Plugin instance
     */
    private static $instance = null;
    
    /**
     * Xero API instance
     */
    private $xero_api = null;
    
    /**
     * Get plugin instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Check if CiviCRM and WooCommerce are active
        add_action('plugins_loaded', array($this, 'check_dependencies'));
        
        // Hook into WooCommerce order status changes
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 4);
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Add settings
        add_action('admin_init', array($this, 'init_settings'));
        
        // Add AJAX handlers
        add_action('wp_ajax_wcxs_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_wcxs_clear_logs', array($this, 'ajax_clear_logs'));
        add_action('wp_ajax_wcxs_debug_settings', array($this, 'ajax_debug_settings'));
        
        // Add activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Check if required plugins are active
     */
    public function check_dependencies() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        if (!function_exists('civicrm_initialize')) {
            add_action('admin_notices', array($this, 'civicrm_missing_notice'));
            return;
        }
        
        // Initialize CiviCRM if not already done
        if (!defined('CIVICRM_UF')) {
            civicrm_initialize();
        }
    }
    
    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        echo '<div class="notice notice-error"><p>';
        echo __('WooCommerce CiviCRM Xero Sync requires WooCommerce to be installed and activated.', 'woo-civi-xero-sync');
        echo '</p></div>';
    }
    
    /**
     * CiviCRM missing notice
     */
    public function civicrm_missing_notice() {
        echo '<div class="notice notice-error"><p>';
        echo __('WooCommerce CiviCRM Xero Sync requires CiviCRM to be installed and activated.', 'woo-civi-xero-sync');
        echo '</p></div>';
    }
    
    /**
     * Handle order status change
     */
    public function handle_order_status_change($order_id, $old_status, $new_status, $order) {
        // Sync contact to Xero as soon as order is submitted
        $this->sync_contact_to_xero($order);
    }
    
    /**
     * Sync WooCommerce order contact to Xero
     */
    public function sync_contact_to_xero($order) {
        try {
            // Get Xero API instance
            $xero_api = $this->get_xero_api();
            if (!$xero_api) {
                throw new Exception('Unable to initialize Xero API');
            }
            
            // Prepare contact data
            $contact_data = $this->prepare_contact_data($order);
            
            // Check if contact already exists in Xero
            $existing_contact = $this->find_existing_contact($xero_api, $contact_data['EmailAddress']);
            
            if ($existing_contact) {
                // Update existing contact
                $this->update_xero_contact($xero_api, $existing_contact['ContactID'], $contact_data);
                $this->log_sync('Contact updated in Xero', $order->get_id(), $existing_contact['ContactID']);
                
                // Update account_contact table if contact was found there
                if (isset($existing_contact['found_in_account_table'])) {
                    $this->update_account_contact_table($existing_contact['ContactID'], $order->get_id());
                }
            } else {
                // Create new contact
                $new_contact = $this->create_xero_contact($xero_api, $contact_data);
                $this->log_sync('Contact created in Xero', $order->get_id(), $new_contact['ContactID']);
                
                // Add to account_contact table
                $this->add_to_account_contact_table($new_contact['ContactID'], $order->get_id());
            }
            
        } catch (Exception $e) {
            $this->log_error('Failed to sync contact to Xero: ' . $e->getMessage(), $order->get_id());
        }
    }
    
    /**
     * Get Xero API instance using CiviXero credentials
     */
    private function get_xero_api() {
        if ($this->xero_api) {
            return $this->xero_api;
        }
        
        try {
            // Get CiviCRM settings
            $settings = $this->get_civicrm_xero_settings();
            
            if (!$settings) {
                throw new Exception('CiviXero settings not found');
            }
            
            // Include the Xero class from CiviXero extension
            $civixero_path = WP_CONTENT_DIR . '/uploads/civicrm/ext/nz.co.fuzion.civixero/packages/Xero/Xero.php';
            if (!file_exists($civixero_path)) {
                throw new Exception('CiviXero Xero class not found at: ' . $civixero_path);
            }
            
            require_once $civixero_path;
            
            // Create Xero API instance
            $this->xero_api = new Xero(
                $settings['xero_access_token']['access_token'],
                $settings['xero_tenant_id']
            );
            
            return $this->xero_api;
            
        } catch (Exception $e) {
            $this->log_error('Failed to initialize Xero API: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get CiviCRM Xero settings
     */
    private function get_civicrm_xero_settings() {
        try {
            // Initialize CiviCRM if needed
            if (!defined('CIVICRM_UF')) {
                civicrm_initialize();
            }
            
            // Use CiviXero's own settings class to get the correct settings
            $settings = array();
            
            // Get basic settings
            $settings['xero_client_id'] = \Civi::settings()->get('xero_client_id');
            $settings['xero_client_secret'] = \Civi::settings()->get('xero_client_secret');
            $settings['xero_tenant_id'] = \Civi::settings()->get('xero_tenant_id');
            
            // Get access token components (CiviXero stores them separately)
            $access_token = \Civi::settings()->get('xero_access_token_access_token');
            $refresh_token = \Civi::settings()->get('xero_access_token_refresh_token');
            $expires = \Civi::settings()->get('xero_access_token_expires');
            
            // Construct the access token array as CiviXero expects it
            if ($access_token && $refresh_token) {
                $settings['xero_access_token'] = array(
                    'access_token' => $access_token,
                    'refresh_token' => $refresh_token,
                    'expires' => $expires,
                    'token_type' => 'Bearer',
                );
            }
            
            // Check if we have the required settings
            if (empty($settings['xero_access_token']) || empty($settings['xero_tenant_id'])) {
                throw new Exception('Xero credentials not found in CiviCRM settings. Please ensure CiviXero is properly configured.');
            }
            
            return $settings;
            
        } catch (Exception $e) {
            $this->log_error('Failed to get CiviCRM Xero settings: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Prepare contact data from WooCommerce order
     */
    private function prepare_contact_data($order) {
        // Get CiviCRM contact using order user ID
        $civicrm_contact = $this->get_civicrm_contact($order);
        
        // Get address with fallback logic
        $address = $this->get_best_address($order);
        
        // Build contact name
        $first_name = $address['first_name'] ?? '';
        $last_name = $address['last_name'] ?? '';
        $company = $address['company'] ?? '';
        
        if (!empty($company)) {
            $name = $company;
            if (!empty($first_name) || !empty($last_name)) {
                $name .= ' - ' . trim($first_name . ' ' . $last_name);
            }
        } else {
            $name = trim($first_name . ' ' . $last_name);
        }
        
        // Add order ID to make name unique
        $name .= ' - ' . $order->get_id();
        
        $contact_data = array(
            'Name' => $name,
            'FirstName' => $first_name,
            'LastName' => $last_name,
            'EmailAddress' => $address['email'] ?? '',
            'ContactNumber' => $order->get_id(),
        );
        
        // Add phone if available
        if (!empty($address['phone'])) {
            $contact_data['Phones'] = array(
                'Phone' => array(
                    'PhoneType' => 'DEFAULT',
                    'PhoneNumber' => $address['phone']
                )
            );
        }
        
        // Add address if available
        if (!empty($address['address_1'])) {
            $contact_data['Addresses'] = array(
                'Address' => array(
                    array(
                        'AddressType' => 'POBOX', // Default mailing address for invoices
                        'AddressLine1' => $address['address_1'] ?? '',
                        'AddressLine2' => $address['address_2'] ?? '',
                        'City' => $address['city'] ?? '',
                        'PostalCode' => $address['postcode'] ?? '',
                        'Country' => $address['country'] ?? '',
                        'Region' => $address['state'] ?? '',
                    )
                )
            );
        }
        
        return $contact_data;
    }
    
    /**
     * Get CiviCRM contact using order user ID
     */
    private function get_civicrm_contact($order) {
        try {
            $user_id = $order->get_user_id();
            if (!$user_id) {
                return null;
            }
            
            // Initialize CiviCRM if needed
            if (!defined('CIVICRM_UF')) {
                civicrm_initialize();
            }
            
            $contact_id = null;
            
            // Use logged-in contact ID if not in admin area
            if (!is_admin()) {
                $contact_id = \CRM_Core_Session::singleton()->getLoggedInContactID();
            }
            
            // Fallback to UFMatch if no logged-in contact or in admin area
            if (!$contact_id) {
                // Get CiviCRM contact by WordPress user ID
                $result = civicrm_api3('UFMatch', 'get', array(
                    'sequential' => 1,
                    'uf_id' => $user_id,
                    'return' => array('contact_id')
                ));
                
                if ($result['is_error'] || empty($result['values'])) {
                    return null;
                }
                
                $contact_id = $result['values'][0]['contact_id'];
            }
            
            // Get contact details
            $contact_result = civicrm_api3('Contact', 'get', array(
                'sequential' => 1,
                'id' => $contact_id,
                'return' => array('first_name', 'last_name', 'email', 'phone', 'address')
            ));
            
            if ($contact_result['is_error']) {
                return null;
            }
            
            return $contact_result['values'][0] ?? null;
            
        } catch (Exception $e) {
            $this->log_error('Failed to get CiviCRM contact: ' . $e->getMessage(), $order->get_id());
            return null;
        }
    }
    
    /**
     * Get best available address with fallback logic
     */
    private function get_best_address($order) {
        // Try billing address first
        $billing_address = $order->get_address('billing');
        if (!empty($billing_address['address_1'])) {
            return $billing_address;
        }
        
        // Try shipping address as fallback
        $shipping_address = $order->get_address('shipping');
        if (!empty($shipping_address['address_1'])) {
            return $shipping_address;
        }
        
        // If no address found, return billing address (may have email/phone even without address)
        return $billing_address;
    }
    
    /**
     * Find existing contact in Xero by email
     */
    private function find_existing_contact($xero_api, $email) {
        try {
            if (empty($email)) {
                return null;
            }
            
            // First, try to find contact in civicrm_account_contact table
            $existing_contact = $this->find_contact_in_account_table($email);
            if ($existing_contact) {
                return $existing_contact;
            }
            
            // If not found in account table, search Xero API
            // Use the correct API call format for searching contacts
            $contacts = $xero_api->contacts();
            
            if (isset($contacts['Contacts']['Contact'])) {
                $contact_list = $contacts['Contacts']['Contact'];
                // Handle single contact vs array of contacts
                if (!isset($contact_list[0])) {
                    $contact_list = array($contact_list);
                }
                
                // Search through contacts for matching email
                foreach ($contact_list as $contact) {
                    if (isset($contact['EmailAddress']) && $contact['EmailAddress'] === $email) {
                        return $contact;
                    }
                }
            }
            
            return null;
            
        } catch (Exception $e) {
            $this->log_error('Failed to find existing contact: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Find contact in civicrm_account_contact table
     */
    private function find_contact_in_account_table($email) {
        try {
            // Initialize CiviCRM if needed
            if (!defined('CIVICRM_UF')) {
                civicrm_initialize();
            }
            
            // Get contact ID from email
            $contact_result = civicrm_api3('Contact', 'get', array(
                'sequential' => 1,
                'email' => $email,
                'return' => array('id')
            ));
            
            if ($contact_result['is_error'] || empty($contact_result['values'])) {
                return null;
            }
            
            $contact_id = $contact_result['values'][0]['id'];
            
            // Check if contact exists in account_contact table
            $account_contact_result = civicrm_api3('AccountContact', 'get', array(
                'sequential' => 1,
                'contact_id' => $contact_id,
                'plugin' => 'xero',
                'return' => array('accounts_contact_id')
            ));
            
            if ($account_contact_result['is_error'] || empty($account_contact_result['values'])) {
                return null;
            }
            
            $account_contact = $account_contact_result['values'][0];
            
            // Return contact data in Xero format
            return array(
                'ContactID' => $account_contact['accounts_contact_id'],
                'EmailAddress' => $email,
                'found_in_account_table' => true
            );
            
        } catch (Exception $e) {
            $this->log_error('Failed to find contact in account table: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Add contact to account_contact table
     */
    private function add_to_account_contact_table($xero_contact_id, $order_id) {
        try {
            // Initialize CiviCRM if needed
            if (!defined('CIVICRM_UF')) {
                civicrm_initialize();
            }
            
            // Get CiviCRM contact ID from order
            $order = wc_get_order($order_id);
            $user_id = $order->get_user_id();
            
            if (!$user_id) {
                return;
            }
            
            $contact_id = null;
            
            // Use logged-in contact ID if not in admin area
            if (!is_admin()) {
                $contact_id = \CRM_Core_Session::singleton()->getLoggedInContactID();
            }
            
            // Fallback to UFMatch if no logged-in contact or in admin area
            if (!$contact_id) {
                // Get CiviCRM contact ID
                $ufmatch_result = civicrm_api3('UFMatch', 'get', array(
                    'sequential' => 1,
                    'uf_id' => $user_id,
                    'return' => array('contact_id')
                ));
                
                if ($ufmatch_result['is_error'] || empty($ufmatch_result['values'])) {
                    return;
                }
                
                $contact_id = $ufmatch_result['values'][0]['contact_id'];
            }
            
            // Add to account_contact table
            civicrm_api3('AccountContact', 'create', array(
                'contact_id' => $contact_id,
                'plugin' => 'xero',
                'accounts_contact_id' => $xero_contact_id,
                'accounts_needs_update' => 0,
                'accounts_modified_date' => date('Y-m-d H:i:s')
            ));
            
        } catch (Exception $e) {
            $this->log_error('Failed to add contact to account table: ' . $e->getMessage(), $order_id);
        }
    }
    
    /**
     * Update contact in account_contact table
     */
    private function update_account_contact_table($xero_contact_id, $order_id) {
        try {
            // Initialize CiviCRM if needed
            if (!defined('CIVICRM_UF')) {
                civicrm_initialize();
            }
            
            // Get CiviCRM contact ID from order
            $order = wc_get_order($order_id);
            $user_id = $order->get_user_id();
            
            if (!$user_id) {
                return;
            }
            
            $contact_id = null;
            
            // Use logged-in contact ID if not in admin area
            if (!is_admin()) {
                $contact_id = \CRM_Core_Session::singleton()->getLoggedInContactID();
            }
            
            // Fallback to UFMatch if no logged-in contact or in admin area
            if (!$contact_id) {
                // Get CiviCRM contact ID
                $ufmatch_result = civicrm_api3('UFMatch', 'get', array(
                    'sequential' => 1,
                    'uf_id' => $user_id,
                    'return' => array('contact_id')
                ));
                
                if ($ufmatch_result['is_error'] || empty($ufmatch_result['values'])) {
                    return;
                }
                
                $contact_id = $ufmatch_result['values'][0]['contact_id'];
            }
            
            // Update account_contact table
            civicrm_api3('AccountContact', 'create', array(
                'contact_id' => $contact_id,
                'plugin' => 'xero',
                'accounts_contact_id' => $xero_contact_id,
                'accounts_needs_update' => 0,
                'accounts_modified_date' => date('Y-m-d H:i:s')
            ));
            
        } catch (Exception $e) {
            $this->log_error('Failed to update contact in account table: ' . $e->getMessage(), $order_id);
        }
    }
    
    /**
     * Create new contact in Xero
     */
    private function create_xero_contact($xero_api, $contact_data) {
        try {
            $result = $xero_api->contacts(array($contact_data));
            
            if (isset($result['Contacts']['Contact'])) {
                return $result['Contacts']['Contact'];
            }
            
            throw new Exception('Invalid response from Xero API');
            
        } catch (Exception $e) {
            throw new Exception('Failed to create contact in Xero: ' . $e->getMessage());
        }
    }
    
    /**
     * Update existing contact in Xero
     */
    private function update_xero_contact($xero_api, $contact_id, $contact_data) {
        try {
            // Add contact ID for update
            $contact_data['ContactID'] = $contact_id;
            
            $result = $xero_api->contacts(array($contact_data));
            
            if (isset($result['Contacts']['Contact'])) {
                return $result['Contacts']['Contact'];
            }
            
            throw new Exception('Invalid response from Xero API');
            
        } catch (Exception $e) {
            throw new Exception('Failed to update contact in Xero: ' . $e->getMessage());
        }
    }
    
    /**
     * Log sync activity
     */
    private function log_sync($message, $order_id, $xero_contact_id = null) {
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'message' => $message,
            'order_id' => $order_id,
            'xero_contact_id' => $xero_contact_id,
            'type' => 'sync'
        );
        
        $logs = get_option('wcxs_sync_logs', array());
        $logs[] = $log_entry;
        
        // Keep only last 100 log entries
        if (count($logs) > 100) {
            $logs = array_slice($logs, -100);
        }
        
        update_option('wcxs_sync_logs', $logs);
    }
    
    /**
     * Log error
     */
    private function log_error($message, $order_id = null) {
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'message' => $message,
            'order_id' => $order_id,
            'type' => 'error'
        );
        
        $logs = get_option('wcxs_sync_logs', array());
        $logs[] = $log_entry;
        
        // Keep only last 100 log entries
        if (count($logs) > 100) {
            $logs = array_slice($logs, -100);
        }
        
        update_option('wcxs_sync_logs', $logs);
        
        // Also log to WordPress error log
        error_log('WooCiviXeroSync: ' . $message);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Xero Sync', 'woo-civi-xero-sync'),
            __('Xero Sync', 'woo-civi-xero-sync'),
            'manage_woocommerce',
            'woo-civi-xero-sync',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Initialize settings
     */
    public function init_settings() {
        register_setting('wcxs_settings', 'wcxs_enable_logging');
    }
    
    /**
     * Admin page
     */
    public function admin_page() {
        include WCXS_PLUGIN_DIR . 'admin/admin-page.php';
    }
    
    /**
     * AJAX handler for testing Xero connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('wcxs_test_connection', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to perform this action.', 'woo-civi-xero-sync'));
        }
        
        try {
            // First, let's debug the settings
            $settings = $this->get_civicrm_xero_settings();
            if (!$settings) {
                wp_send_json_error(array('message' => __('Failed to retrieve CiviCRM Xero settings. Check the error logs for details.', 'woo-civi-xero-sync')));
            }
            
            $xero_api = $this->get_xero_api();
            if (!$xero_api) {
                wp_send_json_error(array('message' => __('Failed to initialize Xero API.', 'woo-civi-xero-sync')));
            }
            
            // Test connection by getting organization info
            $org_info = $xero_api->organisation();
            
            if (isset($org_info['Organisations']['Organisation'])) {
                $org = $org_info['Organisations']['Organisation'];
                $message = sprintf(
                    __('Successfully connected to Xero organization: %s', 'woo-civi-xero-sync'),
                    $org['Name'] ?? 'Unknown'
                );
                wp_send_json_success(array('message' => $message));
            } else {
                wp_send_json_error(array('message' => __('Connected to Xero but could not retrieve organization information.', 'woo-civi-xero-sync')));
            }
            
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * Debug method to help troubleshoot settings issues
     */
    public function debug_settings() {
        try {
            // Initialize CiviCRM if needed
            if (!defined('CIVICRM_UF')) {
                civicrm_initialize();
            }
            
            $debug_info = array();
            
            // Method 1: Direct CiviCRM settings access
            $debug_info['method1'] = array(
                'xero_client_id' => \Civi::settings()->get('xero_client_id') ? 'Set' : 'Not set',
                'xero_client_secret' => \Civi::settings()->get('xero_client_secret') ? 'Set' : 'Not set',
                'xero_tenant_id' => \Civi::settings()->get('xero_tenant_id') ? 'Set' : 'Not set',
                'xero_access_token_access_token' => \Civi::settings()->get('xero_access_token_access_token') ? 'Set' : 'Not set',
                'xero_access_token_refresh_token' => \Civi::settings()->get('xero_access_token_refresh_token') ? 'Set' : 'Not set',
                'xero_access_token_expires' => \Civi::settings()->get('xero_access_token_expires') ? 'Set' : 'Not set',
            );
            
            // Method 2: Try CiviCRM API
            try {
                $result = civicrm_api3('Setting', 'get', array(
                    'sequential' => 1,
                    'return' => array(
                        'xero_client_id',
                        'xero_client_secret', 
                        'xero_tenant_id',
                        'xero_access_token_access_token',
                        'xero_access_token_refresh_token',
                        'xero_access_token_expires'
                    )
                ));
                $debug_info['method2'] = $result;
            } catch (Exception $e) {
                $debug_info['method2'] = 'Error: ' . $e->getMessage();
            }
            
            // Method 3: Check if CiviXero settings class exists
            try {
                if (class_exists('CRM_Civixero_Settings')) {
                    $civixero_settings = new CRM_Civixero_Settings(0);
                    $debug_info['method3'] = array(
                        'xero_client_id' => $civixero_settings->get('xero_client_id') ? 'Set' : 'Not set',
                        'xero_tenant_id' => $civixero_settings->get('xero_tenant_id') ? 'Set' : 'Not set',
                        'xero_access_token' => $civixero_settings->get('xero_access_token') ? 'Set' : 'Not set',
                    );
                } else {
                    $debug_info['method3'] = 'CRM_Civixero_Settings class not found';
                }
            } catch (Exception $e) {
                $debug_info['method3'] = 'Error: ' . $e->getMessage();
            }
            
            return $debug_info;
            
        } catch (Exception $e) {
            return array('error' => $e->getMessage());
        }
    }
    
    /**
     * AJAX handler for clearing logs
     */
    public function ajax_clear_logs() {
        check_ajax_referer('clear_logs', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to perform this action.', 'woo-civi-xero-sync'));
        }
        
        delete_option('wcxs_sync_logs');
        wp_send_json_success(array('message' => __('Logs cleared successfully.', 'woo-civi-xero-sync')));
    }
    
    /**
     * AJAX handler for debugging settings
     */
    public function ajax_debug_settings() {
        check_ajax_referer('wcxs_debug_settings', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to perform this action.', 'woo-civi-xero-sync'));
        }
        
        try {
            $debug_info = $this->debug_settings();
            wp_send_json_success($debug_info);
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        if (!get_option('wcxs_enable_logging')) {
            update_option('wcxs_enable_logging', true);
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up if needed
    }
}

// Initialize the plugin
WooCiviXeroSync::get_instance();
