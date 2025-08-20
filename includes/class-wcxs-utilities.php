<?php
/**
 * WooCiviXeroSync Utilities Class
 * 
 * Shared utilities to eliminate code duplication
 * 
 * @package WooCiviXeroSync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WCXS_Utilities Class
 */
class WCXS_Utilities {
    
    /**
     * Initialize CiviCRM if needed
     */
    public static function init_civicrm() {
        if (!defined('CIVICRM_UF')) {
            civicrm_initialize();
        }
    }
    
    /**
     * Get CiviCRM Xero settings
     */
    public static function get_civicrm_xero_settings() {
        try {
            self::init_civicrm();
            
            $settings = array();
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
            error_log('WCXS_Utilities: Failed to get CiviCRM Xero settings: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get Xero API instance
     */
    public static function get_xero_api() {
        try {
            // Initialize CiviCRM if needed
            if (!defined('CIVICRM_UF')) {
                civicrm_initialize();
            }
            
            // Get CiviCRM Xero settings
            $settings = self::get_civicrm_xero_settings();
            if (!$settings) {
                return null;
            }
            
            // Use CiviXero's OAuth2 system to get a valid token (with automatic refresh)
            try {
                // Include CiviXero OAuth2 classes
                $oauth2_path = WP_CONTENT_DIR . '/uploads/civicrm/ext/nz.co.fuzion.civixero/CRM/Civixero/OAuth2/Xero.php';
                if (!file_exists($oauth2_path)) {
                    error_log('WCXS_Utilities: CiviXero OAuth2 file not found at: ' . $oauth2_path);
                    return null;
                }
                require_once $oauth2_path;
                
                // Create OAuth2 instance and get fresh token
                $oauth2 = CRM_Civixero_OAuth2_Xero::singleton(
                    0, // connector_id
                    $settings['xero_client_id'],
                    $settings['xero_client_secret'],
                    $settings['xero_tenant_id'],
                    $settings['xero_access_token']
                );
                
                // Get fresh token (this will automatically refresh if expired)
                $access_token = $oauth2->getToken();
                
                // Log token refresh information
                if ($access_token->hasExpired()) {
                    self::log_sync("Token was expired, refreshed automatically", null);
                } else {
                    self::log_sync("Using existing valid token", null);
                }
                
                // Include the Xero class
                $xero_path = WP_CONTENT_DIR . '/uploads/civicrm/ext/nz.co.fuzion.civixero/packages/Xero/Xero.php';
                if (!file_exists($xero_path)) {
                    error_log('WCXS_Utilities: Xero.php file not found at: ' . $xero_path);
                    return null;
                }
                require_once $xero_path;
                
                // Create Xero API instance with fresh token
                $xero_api = new Xero($access_token->getToken(), $settings['xero_tenant_id']);
                
                return $xero_api;
                
            } catch (Exception $oauth2_error) {
                // Fallback to direct settings if OAuth2 fails
                error_log('WCXS_Utilities: OAuth2 failed, falling back to direct settings: ' . $oauth2_error->getMessage());
                
                // Include the Xero class
                $xero_path = WP_CONTENT_DIR . '/uploads/civicrm/ext/nz.co.fuzion.civixero/packages/Xero/Xero.php';
                if (!file_exists($xero_path)) {
                    error_log('WCXS_Utilities: Xero.php file not found at: ' . $xero_path);
                    return null;
                }
                require_once $xero_path;
                
                // Create Xero API instance with direct settings
                $xero_api = new Xero($settings['xero_access_token']['access_token'], $settings['xero_tenant_id']);
                
                return $xero_api;
            }
            
        } catch (Exception $e) {
            error_log('WCXS_Utilities: Failed to get Xero API: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get CiviCRM contact ID from WordPress user ID
     */
    public static function get_civicrm_contact_id($user_id) {
        try {
            self::init_civicrm();
            
            $contact_id = null;
            
            // Use logged-in contact ID if not in admin area
            if (!is_admin()) {
                $contact_id = \CRM_Core_Session::singleton()->getLoggedInContactID();
            }
            
            // Fallback to UFMatch if no logged-in contact or in admin area
            if (!$contact_id) {
                $result = civicrm_api3('UFMatch', 'get', array(
                    'sequential' => 1,
                    'uf_id' => $user_id,
                    'return' => array('contact_id')
                ));
                
                if (!$result['is_error'] && !empty($result['values'])) {
                    $contact_id = $result['values'][0]['contact_id'];
                }
            }
            
            return $contact_id;
            
        } catch (Exception $e) {
            error_log('WCXS_Utilities: Failed to get CiviCRM contact ID: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get CiviCRM contact details
     */
    public static function get_civicrm_contact_details($contact_id) {
        try {
            self::init_civicrm();
            
            $contact_result = civicrm_api3('Contact', 'get', array(
                'sequential' => 1,
                'id' => $contact_id,
                'return' => array('first_name', 'last_name', 'email', 'phone', 'address')
            ));
            
            if (!$contact_result['is_error']) {
                return $contact_result['values'][0];
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log('WCXS_Utilities: Failed to get CiviCRM contact details: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Find Xero contact ID in account_contact table
     */
    public static function find_xero_contact_id($contact_id) {
        try {
            self::init_civicrm();
            
            $account_result = civicrm_api3('AccountContact', 'get', array(
                'sequential' => 1,
                'contact_id' => $contact_id,
                'plugin' => 'xero',
                'return' => array('accounts_contact_id')
            ));
            
            if ($account_result['is_error'] || empty($account_result['values'])) {
                return null;
            }
            
            return $account_result['values'][0]['accounts_contact_id'];
            
        } catch (Exception $e) {
            error_log('WCXS_Utilities: Failed to find Xero contact ID: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get Xero contact by ID
     */
    public static function get_xero_contact_by_id($xero_contact_id) {
        if (empty($xero_contact_id)) {
            return null;
        }
        try {
            $xero_api = self::get_xero_api();
            if (!$xero_api) {
                return null;
            }
            
            // Method 1: Try to get specific contact by ID (GET request)
            try {
                $xero_contacts = $xero_api->contacts($xero_contact_id);
                
                if (isset($xero_contacts['Contacts']['Contact'])) {
                    $contact_list = $xero_contacts['Contacts']['Contact'];
                    // Handle single contact vs array of contacts
                    if (!isset($contact_list[0])) {
                        $contact_list = array($contact_list);
                    }
                    
                    // Return the first (and should be only) contact
                    if (!empty($contact_list)) {
                        return $contact_list[0];
                    }
                }
            } catch (Exception $e) {
                // If direct ID lookup fails, try method 2
                error_log('WCXS_Utilities: Direct contact lookup failed, trying fallback: ' . $e->getMessage());
            }
            
            // Method 2: Get all contacts and find by ID (fallback)
            try {
                $xero_contacts = $xero_api->contacts();
                
                if (isset($xero_contacts['Contacts']['Contact'])) {
                    $contact_list = $xero_contacts['Contacts']['Contact'];
                    // Handle single contact vs array of contacts
                    if (!isset($contact_list[0])) {
                        $contact_list = array($contact_list);
                    }
                    
                    foreach ($contact_list as $contact) {
                        if ($contact['ContactID'] === $xero_contact_id) {
                            return $contact;
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('WCXS_Utilities: Fallback contact lookup also failed: ' . $e->getMessage());
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log('WCXS_Utilities: Failed to get Xero contact: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get best address from WooCommerce order
     */
    public static function get_best_address($order) {
        $order_id = $order->get_id();
        
        // Try billing address first
        $billing_address = $order->get_address('billing');
        if (!empty($billing_address['address_1'])) {
            self::log_sync("Using billing address for Order #{$order_id}: {$billing_address['address_1']}", $order_id);
            return $billing_address;
        }
        
        // Try shipping address as fallback
        $shipping_address = $order->get_address('shipping');
        if (!empty($shipping_address['address_1'])) {
            self::log_sync("Using shipping address for Order #{$order_id}: {$shipping_address['address_1']}", $order_id);
            return $shipping_address;
        }
        
        // If no address found, return billing address (may have email/phone even without address)
        self::log_sync("No address found for Order #{$order_id}, using billing address (may have email/phone)", $order_id);
        return $billing_address;
    }
    
    /**
     * Get address information for user (for debug purposes)
     */
    public static function get_address_info_for_user($user_id) {
        try {
            // Get user's most recent order
            $orders = wc_get_orders(array(
                'customer_id' => $user_id,
                'limit' => 1,
                'orderby' => 'date',
                'order' => 'DESC'
            ));
            
            if (empty($orders)) {
                return null;
            }
            
            $order = $orders[0];
            
            // Get billing address
            $billing_address = $order->get_address('billing');
            $shipping_address = $order->get_address('shipping');
            
            // Use billing address if available, otherwise shipping
            $address = !empty($billing_address['address_1']) ? $billing_address : $shipping_address;
            
            if (empty($address['address_1'])) {
                return null;
            }
            
            return array(
                'source' => !empty($billing_address['address_1']) ? 'Billing Address' : 'Shipping Address',
                'AddressLine1' => $address['address_1'],
                'AddressLine2' => $address['address_2'],
                'City' => $address['city'],
                'PostalCode' => $address['postcode'],
                'Country' => $address['country'],
                'Region' => $address['state']
            );
            
        } catch (Exception $e) {
            error_log('WCXS_Utilities: Failed to get address info for user: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Prepare contact data for Xero
     */
    public static function prepare_contact_data($order, $civicrm_contact = null) {
        $order_id = $order->get_id();
        
        // Get address with fallback logic
        $address = self::get_best_address($order);
        self::log_sync("Retrieved address for Order #{$order_id}: " . ($address['address_1'] ?? 'No address'), $order_id);
        
        // Get CiviCRM contact ID for better matching
        $civicrm_contact_id = null;
        if ($civicrm_contact && isset($civicrm_contact['id'])) {
            $civicrm_contact_id = $civicrm_contact['id'];
            self::log_sync("Using provided CiviCRM contact ID: {$civicrm_contact_id}", $order_id);
        } else {
            // Fallback: try to get contact ID from order user
            $user_id = $order->get_user_id();
            if ($user_id) {
                $civicrm_contact_id = self::get_civicrm_contact_id($user_id);
                if ($civicrm_contact_id) {
                    self::log_sync("Retrieved CiviCRM contact ID from user: {$civicrm_contact_id}", $order_id);
                } else {
                    self::log_sync("No CiviCRM contact ID found for user ID: {$user_id}", $order_id);
                }
            }
        }
        
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
        
        // Add CiviCRM contact ID and order ID to make name unique and traceable
        if ($civicrm_contact_id) {
            $name .= ' - CiviCRM:' . $civicrm_contact_id;
        }
        $name .= ' - Order:' . $order->get_id();
        
        $contact_data = array(
            'Name' => $name,
            'FirstName' => $first_name,
            'LastName' => $last_name,
            'EmailAddress' => $address['email'] ?? '',
            'ContactNumber' => $civicrm_contact_id ? $civicrm_contact_id : $order->get_id(),
        );
        
        self::log_sync("Prepared contact data for Order #{$order_id}: Name='{$name}', Email='{$contact_data['EmailAddress']}', ContactNumber='{$contact_data['ContactNumber']}'", $order_id);
        
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
     * Log sync activity
     */
    public static function log_sync($message, $order_id = null, $xero_contact_id = null) {
        $logs = get_option('wcxs_sync_logs', array());
        
        $log_entry = array(
            'timestamp' => current_time('Y-m-d H:i:s'),
            'type' => 'Success',
            'message' => $message,
            'order_id' => $order_id,
            'xero_contact_id' => $xero_contact_id
        );
        
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
     * Create new Xero contact
     */
    public static function create_xero_contact($xero_api, $contact_data) {
        try {
            // Structure contact data exactly like CiviXero does
            $new_contact = array(
                'Name' => $contact_data['Name'],
                'EmailAddress' => $contact_data['EmailAddress'],
                'ContactNumber' => $contact_data['ContactNumber'] ?? '',
            );
            
            // Add phone if available
            if (isset($contact_data['Phones'])) {
                $new_contact['Phones'] = $contact_data['Phones'];
            }
            
            // Add addresses if available
            if (isset($contact_data['Addresses'])) {
                $new_contact['Addresses'] = $contact_data['Addresses'];
            }
            
            // Wrap in array like CiviXero does
            $post_data = array($new_contact);
            
            $result = $xero_api->contacts($post_data);
            
            if (isset($result['Contacts']['Contact'])) {
                return $result['Contacts']['Contact'];
            }
            
            throw new Exception('Invalid response from Xero API');
            
        } catch (Exception $e) {
            throw new Exception('Failed to create contact in Xero: ' . $e->getMessage());
        }
    }
    
    /**
     * Update Xero contact
     */
    public static function update_xero_contact($xero_api, $contact_id, $contact_data) {
        try {
            // Structure contact data exactly like CiviXero does
            $new_contact = array(
                'ContactID' => $contact_id,
                'Name' => $contact_data['Name'],
                'EmailAddress' => $contact_data['EmailAddress'],
                'ContactNumber' => $contact_data['ContactNumber'] ?? '',
            );
            
            // Add phone if available
            if (isset($contact_data['Phones'])) {
                $new_contact['Phones'] = $contact_data['Phones'];
            }
            
            // Add addresses if available
            if (isset($contact_data['Addresses'])) {
                $new_contact['Addresses'] = $contact_data['Addresses'];
            }
            
            // Wrap in array like CiviXero does
            $post_data = array($new_contact);
            
            $result = $xero_api->contacts($post_data);
            
            if (isset($result['Contacts']['Contact'])) {
                return $result['Contacts']['Contact'];
            }
            
            throw new Exception('Invalid response from Xero API');
            
        } catch (Exception $e) {
            throw new Exception('Failed to update contact in Xero: ' . $e->getMessage());
        }
    }
    
    /**
     * Log error
     */
    public static function log_error($message, $order_id = null) {
        $logs = get_option('wcxs_sync_logs', array());
        
        $log_entry = array(
            'timestamp' => current_time('Y-m-d H:i:s'),
            'type' => 'Error',
            'message' => $message,
            'order_id' => $order_id
        );
        
        $logs[] = $log_entry;
        
        // Keep only last 100 log entries
        if (count($logs) > 100) {
            $logs = array_slice($logs, -100);
        }
        
        update_option('wcxs_sync_logs', $logs);
        
        // Also log to WordPress error log
        error_log('WooCiviXeroSync: ' . $message);
    }
}
