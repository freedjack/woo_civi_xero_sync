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

// Include utilities class
require_once WCXS_PLUGIN_DIR . 'includes/class-wcxs-utilities.php';

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
        add_action('wp_ajax_wcxs_debug_get_users', array($this, 'ajax_debug_get_users'));
        add_action('wp_ajax_wcxs_debug_get_user_info', array($this, 'ajax_debug_get_user_info'));
        add_action('wp_ajax_wcxs_debug_update_xero_contact', array($this, 'ajax_debug_update_xero_contact'));
        add_action('wp_ajax_wcxs_get_invoice_details', array($this, 'ajax_get_invoice_details'));
        add_action('wp_ajax_nopriv_wcxs_get_invoice_details', array($this, 'ajax_get_invoice_details'));
        
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
        // Check if sync is enabled
        $enable_sync = get_option('wcxs_enable_sync', true);
        if (!$enable_sync) {
            WCXS_Utilities::log_sync("Sync disabled - skipping contact sync for Order #{$order_id}", $order_id);
            return;
        }
        
        // Sync contact to Xero as soon as order is submitted
        $this->sync_contact_to_xero($order);
    }
    
    /**
     * Sync WooCommerce order contact to Xero
     */
    public function sync_contact_to_xero($order) {
        $order_id = $order->get_id();
        $user_id = $order->get_user_id();
        
        WCXS_Utilities::log_sync("Starting sync for Order #{$order_id} (User ID: {$user_id})", $order_id);
        
        try {
            // Get Xero API instance
            $xero_api = WCXS_Utilities::get_xero_api();
            if (!$xero_api) {
                throw new Exception('Unable to initialize Xero API');
            }
            WCXS_Utilities::log_sync("Xero API initialized successfully", $order_id);
            
            // Get CiviCRM contact data for better matching
            $civicrm_contact = $this->get_civicrm_contact($order);
            if ($civicrm_contact) {
                WCXS_Utilities::log_sync("Found CiviCRM contact: ID {$civicrm_contact['id']}, Name: {$civicrm_contact['first_name']} {$civicrm_contact['last_name']}", $order_id);
            } else {
                WCXS_Utilities::log_sync("No CiviCRM contact found for user ID {$user_id}", $order_id);
            }
            
            // Prepare contact data with CiviCRM contact information
            $contact_data = WCXS_Utilities::prepare_contact_data($order, $civicrm_contact);
            WCXS_Utilities::log_sync("Prepared contact data: Name: {$contact_data['Name']}, Email: {$contact_data['EmailAddress']}, ContactNumber: {$contact_data['ContactNumber']}", $order_id);
            
            // Check if contact already exists in Xero
            $contact_number = isset($contact_data['ContactNumber']) ? $contact_data['ContactNumber'] : null;
            $existing_contact = $this->find_existing_contact($xero_api, $contact_data['EmailAddress'], $contact_number);
            
            if ($existing_contact) {
                // Validate that the existing contact has a valid ContactID
                if (empty($existing_contact['ContactID'])) {
                    WCXS_Utilities::log_sync("Found existing contact but ContactID is empty, creating new contact", $order_id);
                    
                    // Create new contact since the existing one has no valid ID
                    $new_contact = $this->create_xero_contact($xero_api, $contact_data);
                    
                    // Validate the new contact was created successfully
                    if ($new_contact && isset($new_contact['ContactID']) && isset($new_contact['Name'])) {
                        WCXS_Utilities::log_sync("Successfully created new Xero contact: ID {$new_contact['ContactID']}, Name: {$new_contact['Name']}", $order_id, $new_contact['ContactID']);
                        
                        // Update account_contact table with the new Xero contact ID
                        if ($civicrm_contact && isset($civicrm_contact['id'])) {
                            $this->update_account_contact_table($civicrm_contact['id'], $new_contact['ContactID'], $new_contact['Name']);
                        }
                    } else {
                        WCXS_Utilities::log_error("Failed to create new Xero contact - invalid response", $order_id);
                        throw new Exception("Failed to create new Xero contact - invalid response");
                    }
                } else {
                    
                    WCXS_Utilities::log_sync("Found existing Xero contact: ID {$existing_contact['ContactID']}, Name: {$existing_contact['EmailAddress']}", $order_id);
                    
                    // Update existing contact
                    $this->update_xero_contact($xero_api, $existing_contact['ContactID'], $contact_data);
                    WCXS_Utilities::log_sync("Successfully updated existing Xero contact: ID {$existing_contact['ContactID']}", $order_id, $existing_contact['ContactID']);
                    
                    // Check if this Xero contact is already in account_contact table, if not add it
                    if ($civicrm_contact && isset($civicrm_contact['id'])) {
                        $this->ensure_account_contact_exists($civicrm_contact['id'], $existing_contact['ContactID'], $existing_contact['EmailAddress']);
                    }
                }
            } else {
                WCXS_Utilities::log_sync("No existing Xero contact found, creating new contact", $order_id);
                
                // Create new contact
                $new_contact = $this->create_xero_contact($xero_api, $contact_data);
                
                // Validate the new contact was created successfully
                if ($new_contact && isset($new_contact['ContactID']) && isset($new_contact['Name'])) {
                    WCXS_Utilities::log_sync("Successfully created new Xero contact: ID {$new_contact['ContactID']}, Name: {$new_contact['Name']}", $order_id, $new_contact['ContactID']);
                    
                    // Update account_contact table with the new Xero contact ID
                    if ($civicrm_contact && isset($civicrm_contact['id'])) {
                        $this->update_account_contact_table($civicrm_contact['id'], $new_contact['ContactID'], $new_contact['Name']);
                    }
                } else {
                    WCXS_Utilities::log_error("Failed to create new Xero contact - invalid response", $order_id);
                    throw new Exception("Failed to create new Xero contact - invalid response");
                }
            }
            
            WCXS_Utilities::log_sync("Sync completed successfully for Order #{$order_id}", $order_id);
            
        } catch (Exception $e) {
            WCXS_Utilities::log_error("Failed to sync contact to Xero for Order #{$order_id}: " . $e->getMessage(), $order_id);
        }
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
            WCXS_Utilities::log_error('Failed to get CiviCRM contact: ' . $e->getMessage(), $order->get_id());
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
     * Find existing contact in Xero by email or contact number
     */
    private function find_existing_contact($xero_api, $email, $contact_number = null) {
        try {
            if (empty($email)) {
                WCXS_Utilities::log_sync("No email provided for contact search", null);
                return null;
            }
            
            WCXS_Utilities::log_sync("Searching for existing contact with email: {$email}", null);
            
            // First, try to find contact in civicrm_account_contact table
            $existing_contact = $this->find_contact_in_account_table($email);
            if ($existing_contact) {
                WCXS_Utilities::log_sync("Found contact in account table: Xero ID {$existing_contact['ContactID']}", null);
                return $existing_contact;
            }
            
            WCXS_Utilities::log_sync("Contact not found in account table, searching Xero API", null);
            
            // Try searching by contact number first (if available)
            if ($contact_number) {
                $where_clause = "ContactNumber==\"$contact_number\"";
                $contacts = $xero_api->contacts(null, null, $where_clause);
                
                if (isset($contacts['Contacts']['Contact'])) {
                    $contact_list = $contacts['Contacts']['Contact'];
                    // Handle single contact vs array of contacts
                    if (!isset($contact_list[0])) {
                        $contact_list = array($contact_list);
                    }
                    
                    WCXS_Utilities::log_sync("Retrieved " . count($contact_list) . " contacts from Xero API using contact number filter", null);
                    
                    // Should only be one contact with this contact number, return the first one
                    if (!empty($contact_list)) {
                        $contact = $contact_list[0];
                        WCXS_Utilities::log_sync("Found matching contact in Xero API by contact number: ID {$contact['ContactID']}, Name: {$contact['Name']}", null);
                        return $contact;
                    }
                }
            }
            
            // If not found by contact number, search by email
            $where_clause = "EmailAddress==\"$email\"";
            $contacts = $xero_api->contacts(null, null, $where_clause);
            
            if (isset($contacts['Contacts']['Contact'])) {
                $contact_list = $contacts['Contacts']['Contact'];
                // Handle single contact vs array of contacts
                if (!isset($contact_list[0])) {
                    $contact_list = array($contact_list);
                }
                
                WCXS_Utilities::log_sync("Retrieved " . count($contact_list) . " contacts from Xero API using email filter", null);
                
                // Should only be one contact with this email, return the first one
                if (!empty($contact_list)) {
                    $contact = $contact_list[0];
                    WCXS_Utilities::log_sync("Found matching contact in Xero API by email: ID {$contact['ContactID']}, Name: {$contact['Name']}", null);
                    return $contact;
                }
                
                WCXS_Utilities::log_sync("No matching contact found in Xero API", null);
            } else {
                WCXS_Utilities::log_sync("No contacts returned from Xero API for email: $email", null);
            }
            
            return null;
            
        } catch (Exception $e) {
            WCXS_Utilities::log_error('Failed to find existing contact: ' . $e->getMessage());
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
            
            WCXS_Utilities::log_sync("Searching for CiviCRM contact with email: {$email}", null);
            
            // Get contact ID from email
            $contact_result = civicrm_api3('Contact', 'get', array(
                'sequential' => 1,
                'email' => $email,
                'return' => array('id')
            ));
            
            if ($contact_result['is_error'] || empty($contact_result['values'])) {
                WCXS_Utilities::log_sync("No CiviCRM contact found with email: {$email}", null);
                return null;
            }
            
            $contact_id = $contact_result['values'][0]['id'];
            WCXS_Utilities::log_sync("Found CiviCRM contact ID: {$contact_id} for email: {$email}", null);
            
            // Check if contact exists in account_contact table
            $account_contact_result = civicrm_api3('AccountContact', 'get', array(
                'sequential' => 1,
                'contact_id' => $contact_id,
                'plugin' => 'xero',
                'return' => array('accounts_contact_id')
            ));
            
            if ($account_contact_result['is_error'] || empty($account_contact_result['values'])) {
                WCXS_Utilities::log_sync("No account_contact record found for CiviCRM contact ID: {$contact_id}", null);
                return null;
            }
            
            $account_contact = $account_contact_result['values'][0];
            
            // Check if accounts_contact_id key exists
            if (!isset($account_contact['accounts_contact_id'])) {
                WCXS_Utilities::log_sync("Found account_contact record but accounts_contact_id key is missing for CiviCRM contact ID: {$contact_id}", null);
                return null;
            }
            
            $xero_contact_id = $account_contact['accounts_contact_id'];
            
            // Validate that the Xero contact ID is not empty
            if (empty($xero_contact_id)) {
                WCXS_Utilities::log_sync("Found account_contact record but Xero contact ID is empty for CiviCRM contact ID: {$contact_id}", null);
                return null;
            }
            
            WCXS_Utilities::log_sync("Found Xero contact ID: {$xero_contact_id} in account_contact table for CiviCRM contact ID: {$contact_id}", null);
            
            // Return contact data in Xero format
            return array(
                'ContactID' => $xero_contact_id,
                'EmailAddress' => $email,
                'found_in_account_table' => true
            );
            
        } catch (Exception $e) {
            WCXS_Utilities::log_error('Failed to find contact in account table: ' . $e->getMessage());
            return null;
        }
    }
    

    
    /**
     * Create new contact in Xero
     */
    private function create_xero_contact($xero_api, $contact_data) {
        try {
            WCXS_Utilities::log_sync("Creating new Xero contact: {$contact_data['Name']}", null);
            
            // Use the utilities method for consistency
            $result = WCXS_Utilities::create_xero_contact($xero_api, $contact_data);
            
            // Validate the result
            if ($result && isset($result['ContactID']) && isset($result['Name'])) {
                WCXS_Utilities::log_sync("Successfully created Xero contact: ID {$result['ContactID']}, Name: {$result['Name']}", null);
                return $result;
            } else {
                WCXS_Utilities::log_error("Invalid response from Xero API when creating contact", null);
                throw new Exception('Invalid response from Xero API when creating contact');
            }
        } catch (Exception $e) {
            WCXS_Utilities::log_error("Failed to create Xero contact: {$contact_data['Name']} - " . $e->getMessage());
            throw new Exception('Failed to create contact in Xero: ' . $e->getMessage());
        }
    }
    
    /**
     * Update existing contact in Xero
     */
    private function update_xero_contact($xero_api, $contact_id, $contact_data) {
        try {
            WCXS_Utilities::log_sync("Updating existing Xero contact: ID {$contact_id}, Name: {$contact_data['Name']}", null);
            
            // Use the utilities method for consistency
            $result = WCXS_Utilities::update_xero_contact($xero_api, $contact_id, $contact_data);
            
            // Validate the result
            if ($result && isset($result['ContactID']) && isset($result['Name'])) {
                WCXS_Utilities::log_sync("Successfully updated Xero contact: ID {$result['ContactID']}, Name: {$result['Name']}", null);
                return $result;
            } else {
                WCXS_Utilities::log_error("Invalid response from Xero API when updating contact", null);
                throw new Exception('Invalid response from Xero API when updating contact');
            }
        } catch (Exception $e) {
            WCXS_Utilities::log_error("Failed to update Xero contact: ID {$contact_id} - " . $e->getMessage());
            throw new Exception('Failed to update contact in Xero: ' . $e->getMessage());
        }
    }
    
    /**
     * Ensure account_contact record exists for the given CiviCRM contact and Xero contact
     */
    private function ensure_account_contact_exists($civicrm_contact_id, $xero_contact_id, $xero_contact_name) {
        try {
            // Initialize CiviCRM if needed
            if (!defined('CIVICRM_UF')) {
                civicrm_initialize();
            }
            
            WCXS_Utilities::log_sync("Checking if account_contact record exists for CiviCRM contact ID: {$civicrm_contact_id} with Xero contact ID: {$xero_contact_id}", null);
            
            // Check if account_contact record already exists for this CiviCRM contact
            $existing_result = civicrm_api3('AccountContact', 'get', array(
                'sequential' => 1,
                'contact_id' => $civicrm_contact_id,
                'plugin' => 'xero',
                'return' => array('id', 'accounts_contact_id')
            ));
            
            if ($existing_result['is_error']) {
                WCXS_Utilities::log_error("Failed to check existing account_contact record: " . $existing_result['error_message']);
                return false;
            }
            
            // If no record exists, create one
            if (empty($existing_result['values'])) {
                WCXS_Utilities::log_sync("No account_contact record found, creating new one", null);
                return $this->update_account_contact_table($civicrm_contact_id, $xero_contact_id, $xero_contact_name);
            }
            
            // If record exists but has different Xero contact ID, update it
            $existing_record = $existing_result['values'][0];
            
            // Check if accounts_contact_id key exists
            if (!isset($existing_record['accounts_contact_id'])) {
                WCXS_Utilities::log_sync("Account_contact record exists but accounts_contact_id key is missing, updating", null);
                return $this->update_account_contact_table($civicrm_contact_id, $xero_contact_id, $xero_contact_name);
            }
            
            if (empty($existing_record['accounts_contact_id']) || $existing_record['accounts_contact_id'] !== $xero_contact_id) {
                WCXS_Utilities::log_sync("Account_contact record exists but Xero contact ID is different or empty, updating", null);
                return $this->update_account_contact_table($civicrm_contact_id, $xero_contact_id, $xero_contact_name);
            }
            
            // Record already exists and matches
            WCXS_Utilities::log_sync("Account_contact record already exists and matches Xero contact ID", null);
            return true;
            
        } catch (Exception $e) {
            WCXS_Utilities::log_error("Failed to ensure account_contact exists: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update account_contact table with Xero contact information
     */
    private function update_account_contact_table($civicrm_contact_id, $xero_contact_id, $xero_contact_name) {
        try {
            // Initialize CiviCRM if needed
            if (!defined('CIVICRM_UF')) {
                civicrm_initialize();
            }
            
            WCXS_Utilities::log_sync("Updating account_contact table for CiviCRM contact ID: {$civicrm_contact_id} with Xero contact ID: {$xero_contact_id}", null);
            
            // Check if account_contact record already exists
            $existing_result = civicrm_api3('AccountContact', 'get', array(
                'sequential' => 1,
                'contact_id' => $civicrm_contact_id,
                'plugin' => 'xero',
                'return' => array('id')
            ));
            
            if ($existing_result['is_error']) {
                WCXS_Utilities::log_error("Failed to check existing account_contact record: " . $existing_result['error_message']);
                return false;
            }
            
            $account_contact_data = array(
                'contact_id' => $civicrm_contact_id,
                'plugin' => 'xero',
                'connector_id' => 0, // Default connector ID
                'accounts_contact_id' => $xero_contact_id,
                'accounts_display_name' => $xero_contact_name,
                'accounts_modified_date' => current_time('Y-m-d H:i:s'),
                'accounts_needs_update' => FALSE,
                'accounts_data' => json_encode(array(
                    'ContactID' => $xero_contact_id,
                    'Name' => $xero_contact_name
                ))
            );
            
            if (!empty($existing_result['values'])) {
                // Update existing record
                $account_contact_data['id'] = $existing_result['values'][0]['id'];
                $result = civicrm_api3('AccountContact', 'update', $account_contact_data);
                WCXS_Utilities::log_sync("Updated existing account_contact record ID: {$existing_result['values'][0]['id']}", null);
            } else {
                // Create new record
                $result = civicrm_api3('AccountContact', 'create', $account_contact_data);
                WCXS_Utilities::log_sync("Created new account_contact record ID: {$result['id']}", null);
            }
            
            if ($result['is_error']) {
                WCXS_Utilities::log_error("Failed to update account_contact table: " . $result['error_message']);
                return false;
            }
            
            WCXS_Utilities::log_sync("Successfully updated account_contact table for CiviCRM contact ID: {$civicrm_contact_id}", null);
            return true;
            
        } catch (Exception $e) {
            WCXS_Utilities::log_error("Failed to update account_contact table: " . $e->getMessage());
            return false;
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
        
        // Add debug page
        add_submenu_page(
            'woocommerce',
            __('Xero Sync Debug', 'woo-civi-xero-sync'),
            __('Xero Sync Debug', 'woo-civi-xero-sync'),
            'manage_woocommerce',
            'woo-civi-xero-sync-debug',
            array($this, 'debug_page')
        );
    }
    
    /**
     * Initialize settings
     */
    public function init_settings() {
        register_setting('wcxs_settings', 'wcxs_enable_logging');
        register_setting('wcxs_settings', 'wcxs_enable_sync');
    }
    
    /**
     * Admin page
     */
    public function admin_page() {
        include WCXS_PLUGIN_DIR . 'admin/admin-page.php';
    }
    
    /**
     * Debug page
     */
    public function debug_page() {
        include WCXS_PLUGIN_DIR . 'admin/debug-page.php';
        $debug_page = new WooCiviXeroSyncDebugPage();
        $debug_page->render();
    }
    
    /**
     * AJAX handler for testing Xero connection
     */
    public function ajax_test_connection() {
        // Log the AJAX request for debugging
        error_log('WCXS: Test connection AJAX request received');
        
        try {
            check_ajax_referer('wcxs_test_connection', 'nonce');
        } catch (Exception $e) {
            error_log('WCXS: Nonce verification failed: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Security check failed: ' . $e->getMessage()));
            return;
        }
        
        if (!current_user_can('manage_woocommerce')) {
            error_log('WCXS: User does not have required permissions');
            wp_die(__('You do not have permission to perform this action.', 'woo-civi-xero-sync'));
        }
        
        try {
            error_log('WCXS: Starting test connection process');
            
            // First, let's debug the settings
            error_log('WCXS: Attempting to get CiviCRM Xero settings');
            $settings = WCXS_Utilities::get_civicrm_xero_settings();
            if (!$settings) {
                error_log('WCXS: Failed to retrieve CiviCRM Xero settings');
                WCXS_Utilities::log_error('Test connection failed: Could not retrieve CiviCRM Xero settings');
                wp_send_json_error(array('message' => __('Failed to retrieve CiviCRM Xero settings. Check the error logs for details.', 'woo-civi-xero-sync')));
            }
            
            WCXS_Utilities::log_sync('Test connection: Retrieved CiviCRM Xero settings successfully', null);
            
            $xero_api = WCXS_Utilities::get_xero_api();
            if (!$xero_api) {
                WCXS_Utilities::log_error('Test connection failed: Could not initialize Xero API');
                wp_send_json_error(array('message' => __('Failed to initialize Xero API.', 'woo-civi-xero-sync')));
            }
            
            WCXS_Utilities::log_sync('Test connection: Initialized Xero API successfully', null);
            
            // Test connection by getting organization info
            $org_info = $xero_api->organisation();
            
            WCXS_Utilities::log_sync('Test connection: Retrieved organization info from Xero', null);
            
            if (isset($org_info['Organisations']['Organisation'])) {
                $org = $org_info['Organisations']['Organisation'];
                $message = sprintf(
                    __('Successfully connected to Xero organization: %s', 'woo-civi-xero-sync'),
                    $org['Name'] ?? 'Unknown'
                );
                WCXS_Utilities::log_sync('Test connection: Successfully connected to Xero organization: ' . ($org['Name'] ?? 'Unknown'), null);
                wp_send_json_success(array('message' => $message));
            } else {
                WCXS_Utilities::log_error('Test connection failed: Connected to Xero but could not retrieve organization information');
                wp_send_json_error(array('message' => __('Connected to Xero but could not retrieve organization information.', 'woo-civi-xero-sync')));
            }
            
        } catch (Exception $e) {
            error_log('WCXS: Test connection exception: ' . $e->getMessage());
            error_log('WCXS: Exception trace: ' . $e->getTraceAsString());
            WCXS_Utilities::log_error('Test connection failed: ' . $e->getMessage());
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
        if (!get_option('wcxs_enable_sync')) {
            update_option('wcxs_enable_sync', true);
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up if needed
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
    
    /**
     * Render invoice details as HTML
     */
    private function render_invoice_details_html($invoice) {
        $html = '';
        
        if (isset($invoice['Invoices']['Invoice'])) {
            $invoice_data = $invoice['Invoices']['Invoice'];
            if (!isset($invoice_data[0])) {
                $invoice_data = array($invoice_data);
            }
            $invoice_data = $invoice_data[0]; // Get the first invoice
            
            $html .= '<h5>Complete Invoice Information:</h5>';
            
            // Invoice Details
            $html .= '<table class="wcxs-info-table">';
            $html .= '<tr><th>Field</th><th>Value</th></tr>';
            $html .= '<tr><td>Invoice ID</td><td>' . esc_html($invoice_data['InvoiceID']) . '</td></tr>';
            $html .= '<tr><td>Invoice Number</td><td>' . esc_html($invoice_data['InvoiceNumber']) . '</td></tr>';
            $html .= '<tr><td>Date</td><td>' . esc_html($invoice_data['Date']) . '</td></tr>';
            $html .= '<tr><td>Due Date</td><td>' . esc_html($invoice_data['DueDate']) . '</td></tr>';
            $html .= '<tr><td>Status</td><td>' . esc_html($invoice_data['Status']) . '</td></tr>';
            $html .= '<tr><td>Type</td><td>' . esc_html($invoice_data['Type']) . '</td></tr>';
            $html .= '<tr><td>Sub Total</td><td>' . esc_html($invoice_data['SubTotal']) . '</td></tr>';
            $html .= '<tr><td>Total Tax</td><td>' . esc_html($invoice_data['TotalTax']) . '</td></tr>';
            $html .= '<tr><td>Total</td><td>' . esc_html($invoice_data['Total']) . '</td></tr>';
            $html .= '<tr><td>Amount Due</td><td>' . esc_html($invoice_data['AmountDue']) . '</td></tr>';
            $html .= '<tr><td>Amount Paid</td><td>' . esc_html($invoice_data['AmountPaid']) . '</td></tr>';
            $html .= '<tr><td>Currency Code</td><td>' . esc_html($invoice_data['CurrencyCode']) . '</td></tr>';
            $html .= '</table>';
            
            // Contact Information
            if (isset($invoice_data['Contact'])) {
                $html .= '<h5>Contact Information:</h5>';
                $html .= '<table class="wcxs-info-table">';
                $html .= '<tr><th>Field</th><th>Value</th></tr>';
                $html .= '<tr><td>Contact ID</td><td>' . esc_html($invoice_data['Contact']['ContactID']) . '</td></tr>';
                $html .= '<tr><td>Name</td><td>' . esc_html($invoice_data['Contact']['Name']) . '</td></tr>';
                $html .= '</table>';
            }
           
            
            // Line Items
            if (isset($invoice_data['LineItems']['LineItem'])) {
                $html .= '<h5>Line Items:</h5>';
                $html .= '<table class="wcxs-info-table">';
                $html .= '<tr><th>Description</th><th>Quantity</th><th>Unit Amount</th><th>Line Amount</th><th>Tax Amount</th><th>Tax Type</th><th>Account Code</th></tr>';
                
                $line_items = $invoice_data['LineItems']['LineItem'];
                if (!isset($line_items[0])) {
                    $line_items = array($line_items);
                }
                
                foreach ($line_items as $line_item) {
                    $html .= '<tr>';
                    $html .= '<td>' . esc_html($line_item['Description'] ?? 'N/A') . '</td>';
                    $html .= '<td>' . esc_html($line_item['Quantity'] ?? 'N/A') . '</td>';
                    $html .= '<td>' . esc_html($line_item['UnitAmount'] ?? 'N/A') . '</td>';
                    $html .= '<td>' . esc_html($line_item['LineAmount'] ?? 'N/A') . '</td>';
                    $html .= '<td>' . esc_html($line_item['TaxAmount'] ?? 'N/A') . '</td>';
                    $html .= '<td>' . esc_html($line_item['TaxType'] ?? 'N/A') . '</td>';
                    $html .= '<td>' . esc_html($line_item['AccountCode'] ?? 'N/A') . '</td>';
                    $html .= '</tr>';
                }
                $html .= '</table>';
            }
            
        } else {
            $html = '<div class="wcxs-error">No invoice data found</div>';
        }
        
        return $html;
    }
}

// Initialize the plugin
WooCiviXeroSync::get_instance();
