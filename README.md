# WooCommerce CiviCRM Xero Sync

A WordPress plugin that automatically syncs WooCommerce orders to Xero contacts using existing CiviXero credentials.

## Description

This plugin bridges the gap between WooCommerce and Xero by automatically creating or updating contacts in Xero when WooCommerce orders are placed. It leverages the existing CiviXero extension's credentials and API integration to ensure seamless synchronization.

## Features

- **Automatic Contact Sync**: Creates or updates Xero contacts as soon as WooCommerce orders are submitted
- **CiviCRM Integration**: Uses order user ID to retrieve CiviCRM contact information
- **CiviXero Integration**: Uses existing CiviXero credentials and settings
- **Smart Address Handling**: Uses billing address with fallback to shipping address
- **Contact Matching**: Intelligently matches existing contacts by email address
- **Comprehensive Logging**: Detailed logs for debugging and monitoring
- **Admin Interface**: User-friendly settings page with connection testing
- **Error Handling**: Robust error handling with WordPress error logging

## Requirements

- WordPress 5.0 or higher
- WooCommerce 3.0 or higher
- CiviCRM 5.0 or higher
- CiviXero extension installed and configured
- PHP 7.4 or higher

## Installation

1. Upload the plugin files to `/wp-content/plugins/woo-civi-xero-sync/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure the plugin settings under WooCommerce > Xero Sync

## Configuration

### Prerequisites

Before using this plugin, ensure that:

1. **CiviCRM is installed and configured** on your WordPress site
2. **CiviXero extension is installed and configured** with valid Xero credentials
3. **WooCommerce is installed and configured** with at least one order status

### Plugin Settings

Navigate to **WooCommerce > Xero Sync** to configure the plugin:

#### Enable Logging
When enabled, all sync activities and errors are logged for debugging purposes. Logs are stored in the WordPress database and can be viewed in the admin interface.

#### Sync Behavior
Contacts are automatically synced to Xero as soon as an order is submitted. The plugin uses billing address information with fallback to shipping address if billing address is not available.

#### CiviCRM Integration
The plugin uses the order user ID to retrieve CiviCRM contact information and leverages existing CiviXero credentials for Xero API access.

### Testing Connection

Use the "Test Connection" tab to verify that the plugin can successfully connect to Xero using your CiviXero credentials.

## How It Works

### Contact Creation/Update Process

1. **Order Submission**: When a WooCommerce order is submitted
2. **CiviCRM Contact Retrieval**: Uses order user ID to get CiviCRM contact information
3. **Address Selection**: Uses billing address with fallback to shipping address
4. **Contact Matching**: Searches for existing contacts in Xero by email address
5. **Contact Creation/Update**: 
   - If contact exists: Updates the contact with new information
   - If contact doesn't exist: Creates a new contact
6. **Logging**: Records the sync activity for monitoring

### Contact Data Mapping

The plugin maps WooCommerce billing information to Xero contact fields:

| WooCommerce Field | Xero Field | Notes |
|-------------------|------------|-------|
| First Name | FirstName | |
| Last Name | LastName | |
| Company | Name (prefix) | Used as primary name if available |
| Email | EmailAddress | Used for contact matching |
| Phone | Phones.Phone.PhoneNumber | |
| Address 1 | Addresses.Address.AddressLine1 | |
| Address 2 | Addresses.Address.AddressLine2 | |
| City | Addresses.Address.City | |
| State | Addresses.Address.Region | |
| Postcode | Addresses.Address.PostalCode | |
| Country | Addresses.Address.Country | |
| Order ID | ContactNumber | Used as unique identifier |

### Address Type

All addresses are set with `AddressType` = `'POBOX'`, which is the default mailing address type for invoices in Xero.

## Troubleshooting

### Common Issues

1. **"CiviXero settings not found"**
   - Ensure CiviXero extension is installed and configured
   - Verify Xero credentials are set in CiviCRM settings

2. **"Failed to initialize Xero API"**
   - Check that CiviXero access token and tenant ID are valid
   - Verify Xero API credentials haven't expired

3. **"Contact not syncing"**
   - Check that the order status is configured for sync
   - Verify the order has valid billing information
   - Check the sync logs for specific error messages

### Debugging

1. **Enable Logging**: Turn on logging in the plugin settings
2. **Check Logs**: View sync logs in the admin interface
3. **Test Connection**: Use the connection test feature
4. **WordPress Error Log**: Check for additional error messages

## API Reference

### Hooks

The plugin provides several WordPress hooks for customization:

#### Actions

- `wcxs_before_contact_sync($order, $contact_data)`: Fired before syncing contact to Xero
- `wcxs_after_contact_sync($order, $xero_contact, $is_new)`: Fired after syncing contact to Xero
- `wcxs_contact_sync_error($order, $error_message)`: Fired when sync fails

#### Filters

- `wcxs_contact_data($contact_data, $order)`: Modify contact data before syncing
- `wcxs_sync_statuses($statuses)`: Modify which order statuses trigger sync

### Example Usage

```php
// Modify contact data before syncing
add_filter('wcxs_contact_data', function($contact_data, $order) {
    // Add custom field
    $contact_data['CustomField'] = 'Custom Value';
    return $contact_data;
}, 10, 2);

// Log sync activities
add_action('wcxs_after_contact_sync', function($order, $xero_contact, $is_new) {
    error_log("Contact synced: " . ($is_new ? 'Created' : 'Updated') . " - Order #" . $order->get_id());
}, 10, 3);
```

## Changelog

### Version 1.0.0
- Initial release
- Basic contact sync functionality
- Admin interface with settings and logs
- Connection testing
- Comprehensive error handling

## Support

For support and feature requests, please create an issue on the GitHub repository.

## License

This plugin is licensed under the GPL v2 or later.

## Credits

This plugin builds upon the excellent work of the CiviXero extension and uses the Xero PHP SDK for API integration.
