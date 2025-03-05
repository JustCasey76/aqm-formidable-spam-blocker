<?php
// Load WordPress
define('WP_USE_THEMES', false);
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');

// Clear update cache
delete_site_transient('update_plugins');
delete_transient('update_plugins');
wp_clean_plugins_cache();

// Clear option that might store update info
delete_option('_site_transient_update_plugins');

echo "Cleared plugin update cache successfully!\n";
