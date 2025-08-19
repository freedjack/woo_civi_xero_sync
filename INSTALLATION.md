# Installation Guide

## Quick Start

### 1. Prerequisites

Before installing this plugin, ensure you have:

- ✅ WordPress 5.0+ installed
- ✅ WooCommerce 3.0+ installed and configured
- ✅ CiviCRM 5.0+ installed and configured
- ✅ CiviXero extension installed and configured with valid Xero credentials

### 2. Install the Plugin

#### Option A: Manual Installation
1. Download the plugin files
2. Upload to `/wp-content/plugins/woo-civi-xero-sync/`
3. Activate the plugin in WordPress Admin > Plugins

#### Option B: ZIP Upload
1. Create a ZIP file containing all plugin files
2. Go to WordPress Admin > Plugins > Add New > Upload Plugin
3. Upload and activate the plugin

### 3. Configure the Plugin

1. Go to **WooCommerce > Xero Sync** in your WordPress admin
2. Configure which order statuses should trigger sync (default: "completed")
3. Enable logging for debugging
4. Test the connection to Xero

### 4. Test the Integration

1. Create a test order in WooCommerce
2. Change the order status to "completed"
3. Check the sync logs to verify the contact was created/updated in Xero
4. Verify the contact appears in your Xero account

## File Structure

```
woo-civi-xero-sync/
├── woo_civi_xero_sync.php          # Main plugin file
├── admin/
│   └── admin-page.php              # Admin interface
├── packages/
│   └── Xero/
│       └── Xero.php                # Xero API class (from CiviXero)
├── README.md                       # Documentation
├── INSTALLATION.md                 # This file
└── LICENSE                         # License file
```

## Troubleshooting

### Plugin Not Appearing
- Ensure all files are uploaded to the correct directory
- Check file permissions (should be 644 for files, 755 for directories)
- Verify the main plugin file has the correct header

### CiviCRM Not Found
- Ensure CiviCRM is properly installed and configured
- Check that CiviCRM is accessible from WordPress
- Verify the CiviCRM database connection

### CiviXero Settings Not Found
- Install and configure the CiviXero extension
- Set up Xero OAuth credentials in CiviCRM
- Verify the Xero tenant ID and access token are configured

### WooCommerce Not Found
- Install and activate WooCommerce
- Ensure WooCommerce is properly configured
- Check that WooCommerce functions are available

### Connection Test Fails
- Verify Xero credentials in CiviCRM settings
- Check that Xero API access is enabled
- Ensure the access token hasn't expired
- Verify the tenant ID is correct

## Support

If you encounter issues during installation:

1. Check the WordPress error log
2. Enable WordPress debug mode
3. Check the plugin sync logs
4. Verify all prerequisites are met
5. Test the Xero connection manually

For additional support, please refer to the main README.md file or create an issue on the GitHub repository.
