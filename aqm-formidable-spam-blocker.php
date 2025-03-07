<?php
/**
 * Plugin Name: AQM Formidable Forms Spam Blocker
 * Plugin URI: https://aqmarketing.com
 * Description: Block form submissions based on IP geolocation and other criteria.
 * Version: 2.1.72
 * Author: AQ Marketing
 * Author URI: https://aqmarketing.com
 * Text Domain: aqm-formidable-spam-blocker
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Include the updater class
require_once plugin_dir_path(__FILE__) . 'plugin-updater.php';

// Initialize the updater
function aqm_form_security_updater() {
    // Only run in admin
    if (!is_admin()) {
        return;
    }
    
    $updater = new AQM_Plugin_Updater(
        __FILE__,
        'JustCasey76',
        'aqm-formidable-spam-blocker'
    );
}
add_action('init', 'aqm_form_security_updater');

class FormidableFormsBlocker {
    private $approved_states = array('CA', 'NY', 'TX'); // Default approved states
    private $approved_countries = array('US', 'CA'); // Default approved countries (United States)
    private $approved_zip_codes = array(); // Add allowed ZIPs here
    private $api_key = ''; // API key for ipapi.com - set in admin settings
    private $rate_limit_time = 10; // Time frame in seconds
    private $rate_limit_requests = 3; // Max requests per IP in timeframe
    private $blocked_ips = array(); // IPs to block for testing
    private $log_enabled = true; // Whether to log access attempts
    private $version = '2.1.72';
    private $geo_data = null;
    private $is_blocked = null;
    private $blocked_message = ''; // Blocked message
    private $diagnostic_mode = false; // Diagnostic mode

    public function __construct() {
        // Set version
        $this->version = '2.1.72';
        
        // Initialize properties
        $this->init_properties();
        
        // Add hooks
        $this->add_hooks();
        
        // Register AJAX endpoints
        add_action('wp_ajax_ffb_get_approved_states', array($this, 'ajax_get_approved_states'));
        add_action('wp_ajax_nopriv_ffb_get_approved_states', array($this, 'ajax_get_approved_states'));
    }

    private function init_properties() {
        // Load API key
        $this->api_key = get_option('ffb_api_key', '');
        
        // Load approved countries
        $approved_countries = get_option('ffb_approved_countries', array('US'));
        $this->approved_countries = is_array($approved_countries) && !empty($approved_countries) ? $approved_countries : array('US');
        
        // Log the approved countries for debugging
        error_log('FFB Debug: Approved countries: ' . print_r($this->approved_countries, true));
        
        // Load approved states
        $approved_states = get_option('ffb_approved_states', array());
        $this->approved_states = is_array($approved_states) ? $approved_states : array();
        
        // Log the approved states for debugging
        error_log('FFB Debug: Approved states: ' . print_r($this->approved_states, true));
        
        // Load approved ZIP codes
        $approved_zip_codes = get_option('ffb_approved_zip_codes', array());
        $this->approved_zip_codes = is_array($approved_zip_codes) ? $approved_zip_codes : array();
        
        // Load blocked message
        $this->blocked_message = get_option('ffb_blocked_message', 'We apologize, but forms are not available in your location.');
        
        // Load rate limiting settings
        $this->rate_limit_enabled = get_option('ffb_rate_limit_enabled', '1') === '1';
        $this->rate_limit_timeframe = get_option('ffb_rate_limit_timeframe', 3600);
        $this->rate_limit_requests = get_option('ffb_rate_limit_requests', 3);
        
        // Load blocked IPs for testing
        $blocked_ips = get_option('ffb_blocked_ips', '');
        $this->blocked_ips = is_array($blocked_ips) ? $blocked_ips : array();
        
        // Load IP whitelist
        $ip_whitelist = get_option('ffb_ip_whitelist', array());
        $this->ip_whitelist = is_array($ip_whitelist) ? $ip_whitelist : array();
        
        // Load IP blacklist
        $ip_blacklist = get_option('ffb_ip_blacklist', array());
        $this->ip_blacklist = is_array($ip_blacklist) ? $ip_blacklist : array();
        
        // Load logging settings
        $this->log_enabled = get_option('ffb_log_enabled', '1') === '1';
        
        // Load diagnostic mode setting
        $this->diagnostic_mode = get_option('ffb_diagnostic_mode', '0') === '1';
    }

    /**
     * Initialize the plugin
     */
    public function init() {
        // Create database table if it doesn't exist
        if (function_exists('ffb_create_log_table')) {
            ffb_create_log_table();
        }
        
        // Migrate any old HTML-formatted blocked messages to plain text
        $this->migrate_blocked_message_format();
        
        // Register shortcodes
        add_shortcode('ffb_check_location', array($this, 'shortcode_check_location'));
        
        // Check if we need to hook into Formidable Forms
        if (class_exists('FrmHooksController')) {
            // Add hooks for Formidable Forms
            add_filter('frm_validate_entry', array($this, 'validate_submission'), 10, 2);
            add_filter('frm_pre_create_entry', array($this, 'pre_create_entry'), 10, 2);
        }
    }
    
    /**
     * Migrate existing blocked message from HTML format to plain text
     */
    private function migrate_blocked_message_format() {
        $message = get_option('ffb_blocked_message', '');
        
        // If the message contains HTML, extract just the text content
        if (!empty($message) && strpos($message, '<div') !== false) {
            // Extract text from within HTML paragraph tags
            if (preg_match('/<p>(.*?)<\/p>/s', $message, $matches)) {
                $plain_text = $matches[1];
                // Save the plain text back to the database
                update_option('ffb_blocked_message', $plain_text);
                // Update the instance property
                $this->blocked_message = $plain_text;
            }
        }
    }

    /**
     * Create the access log table if it doesn't exist
     */
    private function create_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aqm_formidable_spam_blocker_log';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
            // Table already exists
            return;
        }
        
        // Create the table
        $charset_collate = $wpdb->get_charset_collate();
            
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            ip_address varchar(45) NOT NULL,
            country_code varchar(10),
            country varchar(100),
            region_name varchar(100),
            region varchar(100),
            city varchar(100),
            zip varchar(20),
            status varchar(20),
            reason text,
            form_id varchar(20),
            log_type varchar(20) DEFAULT 'form_load',
            geo_data text,
            PRIMARY KEY  (id),
            KEY ip_address (ip_address),
            KEY status (status),
            KEY log_type (log_type)
        ) $charset_collate;";
            
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
            
        // Update the DB version option to track that we've created the table
        update_option('ffb_db_version', $this->version);
        error_log('FFB Debug: Created access log table');
    }

    public function add_admin_menu() {
        add_menu_page(
            'Formidable Forms Spam Blocker',
            'FF Spam Blocker',
            'manage_options',
            'ff-spam-blocker',
            array($this, 'settings_page'),
            'dashicons-shield',
            100
        );
        
        add_submenu_page(
            'ff-spam-blocker',
            'Settings',
            'Settings',
            'manage_options',
            'ff-spam-blocker',
            array($this, 'settings_page')
        );
        
        add_submenu_page(
            'ff-spam-blocker',
            'Access Logs',
            'Access Logs',
            'manage_options',
            'ff-spam-blocker-logs',
            array($this, 'logs_page')
        );
        
        // Add action to handle form submission
        add_action('admin_post_ffb_save_settings', array($this, 'handle_save_settings'));
        
        // Add debug action to directly update API key (temporary)
        add_action('admin_post_ffb_update_api_key', array($this, 'update_api_key'));
        
        // Add action to handle clearing the access log
        add_action('admin_post_ffb_clear_access_log', array($this, 'clear_access_log'));
        
        // Add action to manually create/recreate the access log table
        add_action('admin_post_ffb_create_table', array($this, 'manual_create_table'));
    }
    
    /**
     * Emergency function to update API key directly
     */
    public function update_api_key() {
        // Only allow administrators
        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }
        
        // Get the API key from the URL
        $api_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        
        if (!empty($api_key)) {
            // Update the API key
            update_option('ffb_api_key', $api_key);
            error_log('FFB Debug: API key updated via emergency function');
            
            // Redirect back to settings page
            wp_redirect(admin_url('admin.php?page=ff-spam-blocker&settings-updated=true'));
            exit;
        }
        
        // If no key provided, redirect back with error
        wp_redirect(admin_url('admin.php?page=ff-spam-blocker&error=no-key'));
        exit;
    }

    public function logs_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Show success message if logs were cleared
        if (isset($_GET['ffb_logs_cleared']) && $_GET['ffb_logs_cleared'] === 'true') {
            echo '<div class="notice notice-success is-dismissible"><p>Access logs have been cleared successfully.</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>Access Logs</h1>
            
            <!-- Clear Logs Button -->
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom: 20px;">
                <?php wp_nonce_field('ffb_clear_logs', 'ffb_clear_logs_nonce'); ?>
                <input type="hidden" name="action" value="ffb_clear_access_log">
                <input type="hidden" name="redirect_to" value="<?php echo esc_url(admin_url('admin.php?page=ff-spam-blocker-logs')); ?>">
                <?php submit_button('Clear Access Logs', 'delete', 'submit', false, array(
                    'onclick' => 'return confirm("Are you sure you want to clear all access logs? This action cannot be undone.");'
                )); ?>
            </form>
            
            <!-- Insert Test Record Link -->
            <a href="<?php echo add_query_arg('ffb_insert_test', '1', admin_url('admin.php?page=ff-spam-blocker-logs')); ?>" class="button">Insert Test Record</a>

            <?php $this->display_access_logs(); ?>
        </div>
        <?php
    }

    private function display_access_logs() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aqm_formidable_spam_blocker_log';
        
        error_log('FFB Debug: Starting display_access_logs');
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            echo '<div class="notice notice-error"><p>Access log table does not exist. Please recreate the table.</p></div>';
            return;
        }
        
        // Get table columns to ensure we only query for existing columns
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name");
        $column_names = array();
        foreach ($columns as $column) {
            $column_names[] = $column->Field;
        }
        
        error_log('FFB Debug: Table columns: ' . implode(', ', $column_names));
        
        // Build WHERE clause based on filters
        $where_clauses = array();
        $query_args = array();
        
        // Date range filter
        if (!empty($_GET['start_date'])) {
            $where_clauses[] = "timestamp >= %s";
            $query_args[] = $_GET['start_date'] . ' 00:00:00';
        }
        
        if (!empty($_GET['end_date'])) {
            $where_clauses[] = "timestamp <= %s";
            $query_args[] = $_GET['end_date'] . ' 23:59:59';
        }
        
        // IP address filter
        if (!empty($_GET['ip_address'])) {
            $where_clauses[] = "ip_address LIKE %s";
            $query_args[] = '%' . $wpdb->esc_like($_GET['ip_address']) . '%';
        }
        
        // Country filter
        if (!empty($_GET['country']) && in_array('country_code', $column_names)) {
            $where_clauses[] = "country_code = %s";
            $query_args[] = $_GET['country'];
        }
        
        // Region filter
        if (!empty($_GET['region']) && in_array('region_name', $column_names)) {
            $where_clauses[] = "region_name = %s";
            $query_args[] = $_GET['region'];
        }
        
        // Status filter
        if (!empty($_GET['status']) && in_array('status', $column_names)) {
            $where_clauses[] = "status = %s";
            $query_args[] = $_GET['status'];
        }
        
        // Message filter
        if (!empty($_GET['message'])) {
            if (in_array('reason', $column_names)) {
                $where_clauses[] = "reason LIKE %s";
                $query_args[] = '%' . $wpdb->esc_like($_GET['message']) . '%';
            }
        }
        
        // Pagination
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Build the WHERE clause string
        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }
        
        // Get the total number of filtered records
        $total_query = "SELECT COUNT(*) FROM $table_name $where_sql";
        if (!empty($query_args)) {
            $total_query = $wpdb->prepare($total_query, $query_args);
        }
        $total_items = $wpdb->get_var($total_query);
        
        error_log('FFB Debug: Total filtered records: ' . $total_items);
        
        // Get the filtered records
        $query = "SELECT * FROM $table_name $where_sql ORDER BY timestamp DESC LIMIT %d OFFSET %d";
        $query_args[] = $per_page;
        $query_args[] = $offset;
        
        $prepared_query = $wpdb->prepare($query, $query_args);
        error_log('FFB Debug: Prepared query: ' . $prepared_query);
        
        $results = $wpdb->get_results($prepared_query);
        
        error_log('FFB Debug: Query results count: ' . count($results));
        
        // Continue with existing display code...
        if (empty($results)) {
            echo '<div class="notice notice-info"><p>No access logs found matching your filters. Try adjusting your search criteria.</p></div>';
            return;
        }

        // Display filter form
        ?>
        <form method="get" action="" class="ffb-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page']); ?>">
            
            <div class="ffb-filter-row">
                <label>
                    Date Range:
                    <input type="date" name="start_date" value="<?php echo esc_attr($_GET['start_date'] ?? ''); ?>">
                    to
                    <input type="date" name="end_date" value="<?php echo esc_attr($_GET['end_date'] ?? ''); ?>">
                </label>
                
                <label>
                    IP Address:
                    <input type="text" name="ip_address" value="<?php echo esc_attr($_GET['ip_address'] ?? ''); ?>" placeholder="Search IP...">
                </label>
                
                <label>
                    Country:
                    <select name="country">
                        <option value="">All Countries</option>
                        <?php 
                        // Get unique countries from the database
                        $countries = $wpdb->get_col("SELECT DISTINCT country_code FROM $table_name WHERE country_code != '' ORDER BY country_code");
                        foreach ($countries as $country): 
                            $country_lower = strtolower($country);
                        ?>
                            <option value="<?php echo esc_attr($country); ?>" <?php selected($_GET['country'] ?? '', $country); ?>>
                                <span class="fi fi-<?php echo esc_attr($country_lower); ?>"></span> <?php echo esc_html($country); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                
                <label>
                    State:
                    <select name="region">
                        <option value="">All States</option>
                        <?php 
                        // Get unique regions from the database
                        $regions = $wpdb->get_col("SELECT DISTINCT region_name FROM $table_name WHERE region_name != '' ORDER BY region_name");
                        foreach ($regions as $region): 
                        ?>
                            <option value="<?php echo esc_attr($region); ?>" <?php selected($_GET['region'] ?? '', $region); ?>>
                                <?php echo esc_html($region); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                
                <label>
                    Status:
                    <select name="status">
                        <option value="">All Statuses</option>
                        <option value="blocked" <?php selected($_GET['status'] ?? '', 'blocked'); ?>>Blocked</option>
                        <option value="allowed" <?php selected($_GET['status'] ?? '', 'allowed'); ?>>Allowed</option>
                    </select>
                </label>
                
                <label>
                    Message:
                    <input type="text" name="message" value="<?php echo esc_attr($_GET['message'] ?? ''); ?>" placeholder="Search message...">
                </label>
                
                <input type="submit" class="button" value="Apply Filters">
                <a href="<?php echo esc_url(remove_query_arg(array('start_date', 'end_date', 'ip_address', 'country', 'region', 'status', 'message', 'paged'))); ?>" class="button">Reset Filters</a>
            </div>
        </form>

        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>IP Address</th>
                    <th>Country</th>
                    <th>State</th>
                    <th>Status</th>
                    <th>Reason</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $log): ?>
                    <tr>
                        <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($log->timestamp))); ?></td>
                        <td><?php echo esc_html($log->ip_address); ?></td>
                        <td>
                            <?php 
                            if (isset($log->country_code) && !empty($log->country_code)):
                                $country_code = strtolower($log->country_code);
                                // Display country flag with country code
                                echo '<span class="fi fi-' . esc_attr($country_code) . '" title="' . esc_attr($log->country ?? $log->country_code) . '"></span> ';
                                echo esc_html($log->country_code);
                            else:
                                echo 'Unknown';
                            endif;
                            ?>
                        </td>
                        <td><?php echo isset($log->region_name) ? esc_html($log->region_name) : ''; ?></td>
                        <td><?php echo isset($log->status) ? esc_html($log->status) : ''; ?></td>
                        <td><?php echo isset($log->reason) ? esc_html($log->reason) : ''; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        // Add pagination
        if ($total_items > $per_page) {
            $total_pages = ceil($total_items / $per_page);
            
            echo '<div class="ffb-pagination">';
            echo paginate_links(array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => __('&laquo;'),
                'next_text' => __('&raquo;'),
                'total' => $total_pages,
                'current' => $current_page,
                'type' => 'list'
            ));
            echo '</div>';
        }
    }

    public function handle_clear_logs() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('ffb_clear_logs', 'ffb_clear_logs_nonce');
        
        $this->clear_access_logs();
        
        wp_redirect(add_query_arg('ffb_logs_cleared', 'true', admin_url('admin.php?page=ff-spam-blocker-logs')));
        exit;
    }

    public function clear_access_logs() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }

        check_admin_referer('ffb_clear_logs', 'ffb_clear_logs_nonce');

        global $wpdb;
        $table_name = $wpdb->prefix . 'aqm_formidable_spam_blocker_log';

        // Clear the access logs
        $wpdb->query("TRUNCATE TABLE $table_name");

        // Redirect back to the logs page
        wp_redirect(add_query_arg('ffb_logs_cleared', 'true', admin_url('admin.php?page=ff-spam-blocker-logs')));
        exit;
    }

    public function start_session() {
        // Only start session for admin pages or AJAX requests
        if (!is_admin() && !wp_doing_ajax()) {
            return;
        }
        
        // Check if headers have been sent
        if (headers_sent($filename, $linenum)) {
            error_log("FFB Debug: Headers already sent in $filename on line $linenum");
            return;
        }
        
        // Check if session is already active
        if (session_status() === PHP_SESSION_ACTIVE) {
            error_log('FFB Debug: Session already active');
            return;
        }
        
        // Try to start the session
        try {
            session_start();
            error_log('FFB Debug: Session started successfully');
        } catch (Exception $e) {
            error_log('FFB Error: Failed to start session - ' . $e->getMessage());
        }
    }

    public function check_location($ip_address = null) {
        if (!$ip_address) {
            $ip_address = $this->get_client_ip();
        }

        error_log('FFB Debug: Starting check_location for IP: ' . $ip_address);

        // Only check if we haven't already or if a specific IP is provided
        if ($this->geo_data === null || $ip_address !== null) {
            $this->geo_data = $this->get_geo_data($ip_address);
            if ($this->geo_data) {
                $this->is_blocked = $this->is_location_blocked($this->geo_data);
                error_log('FFB Debug: Location check - IP: ' . $ip_address . ' Blocked: ' . ($this->is_blocked ? 'Yes' : 'No'));
                error_log('FFB Debug: Geo Data: ' . print_r($this->geo_data, true));
                error_log('FFB Debug: Allowed States: ' . print_r($this->approved_states, true));
                error_log('FFB Debug: Allowed Countries: ' . print_r($this->approved_countries, true));
                
                // Log the access attempt if logging is enabled
                if ($this->log_enabled) {
                    $this->log_access_attempt(
                        $ip_address,
                        $this->is_blocked ? 'blocked' : 'allowed',
                        $this->is_blocked ? 'Access blocked' : 'Access allowed',
                        0, // form_id is 0 since we're just checking location
                        'location_check' // custom log type for location checks
                    );
                }
            }
        }
        
        return $this->geo_data;
    }

    public function get_geo_data($ip = null, $force_refresh = false) {
        // If no IP provided, get the current client IP
        if (empty($ip)) {
            $ip = $this->get_client_ip();
        }
        
        // Check if we already have the data in the instance
        if (!$force_refresh && $this->geo_data !== null) {
            return $this->geo_data;
        }
        
        // Check if the IP is private
        if ($this->is_private_ip($ip)) {
            error_log('FFB Debug: IP ' . $ip . ' is a private IP, skipping geolocation');
            return array();
        }
        
        // Check if we have cached data
        $cache_key = 'ffb_geo_' . md5($ip);
        $cached_data = get_transient($cache_key);
        
        if (!$force_refresh && $cached_data !== false) {
            // Store in instance variable
            $this->geo_data = $cached_data;
            error_log('FFB Debug: Using cached geolocation data for IP ' . $ip);
            return $cached_data;
        }
        
        // Get the API key
        $this->api_key = defined('FFB_API_KEY') ? FFB_API_KEY : get_option('ffb_api_key', '');
        
        // If no API key, return empty data
        if (empty($this->api_key)) {
            error_log('FFB Debug: No API key configured');
            return array();
        }
        
        // Make the API request
        error_log('FFB Debug: Making API request for IP ' . $ip);
        $api_url = 'https://api.ipapi.com/api/' . $ip . '?access_key=' . $this->api_key;
        
        // Add additional debug logging for the API URL
        error_log('FFB Debug: Full API URL: ' . $api_url);
        
        $response = wp_remote_get($api_url, array(
            'timeout' => 15,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
        ));
        
        if (is_wp_error($response)) {
            error_log('FFB Debug: API request error: ' . $response->get_error_message());
            return array();
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Debug the API response
        error_log('FFB Debug: Raw API response for IP ' . $ip . ': ' . $body);
        
        // Debug the exact structure
        if (is_array($data)) {
            error_log('FFB Debug: API response array keys: ' . implode(', ', array_keys($data)));
            
            // Log region-specific keys if they exist
            if (isset($data['region_name'])) {
                error_log('FFB Debug: Found region_name: ' . $data['region_name']);
            } elseif (isset($data['region_code'])) {
                error_log('FFB Debug: Found region_code: ' . $data['region_code']);
            } elseif (isset($data['region'])) {
                error_log('FFB Debug: Found region: ' . $data['region']);
            } elseif (isset($data['regionName'])) {
                error_log('FFB Debug: Found regionName: ' . $data['regionName']);
            } elseif (isset($data['regionCode'])) {
                error_log('FFB Debug: Found regionCode: ' . $data['regionCode']);
            } elseif (isset($data['state'])) {
                error_log('FFB Debug: Found state: ' . $data['state']);
            } elseif (isset($data['subdivision_1_name'])) {
                error_log('FFB Debug: Found subdivision_1_name: ' . $data['subdivision_1_name']);
            } elseif (isset($data['subdivision_1_code'])) {
                error_log('FFB Debug: Found subdivision_1_code: ' . $data['subdivision_1_code']);
            }
            
            // Log country-specific keys
            if (isset($data['country_name'])) {
                error_log('FFB Debug: Found country_name: ' . $data['country_name']);
            } elseif (isset($data['country_code'])) {
                error_log('FFB Debug: Found country_code: ' . $data['country_code']);
            } elseif (isset($data['countryName'])) {
                error_log('FFB Debug: Found countryName: ' . $data['countryName']);
            } elseif (isset($data['countryCode'])) {
                error_log('FFB Debug: Found countryCode: ' . $data['countryCode']);
            }
        }
        
        if (empty($data) || !is_array($data) || isset($data['status']) && $data['status'] === 'fail') {
            $error_msg = isset($data['message']) ? $data['message'] : 'Unknown error';
            error_log('FFB Debug: API returned error: ' . $error_msg);
            return array();
        }
        
        // Cache the data for 1 hour
        set_transient($cache_key, $data, 3600);
        
        // Store in instance variable
        $this->geo_data = $data;
        
        return $data;
    }

    /**
     * Check if an IP address is private
     */
    private function is_private_ip($ip) {
        // Check if IP is in private ranges
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false || 
               filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false;
    }

    public function get_client_ip() {
        // Check if FrmAppHelper exists and use its method
        if (class_exists('FrmAppHelper') && method_exists('FrmAppHelper', 'get_ip_address')) {
            $ip = FrmAppHelper::get_ip_address();
            
            // Log the IP detection process for debugging
            error_log('FFB Debug: Using Formidable Forms IP detection. Detected IP: ' . $ip);
            
            return $ip;
        }
        
        // Fallback to our own implementation if Formidable Forms' method is not available
        // Start with REMOTE_ADDR as the most reliable source
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        
        // Only consider forwarded headers if the request is from a trusted proxy
        if ($this->is_trusted_proxy($ip)) {
            $forwarded_headers = array(
                'HTTP_CLIENT_IP',
                'HTTP_CF_CONNECTING_IP', // Cloudflare
                'HTTP_X_FORWARDED_FOR',
                'HTTP_X_FORWARDED',
                'HTTP_X_CLUSTER_CLIENT_IP',
                'HTTP_X_REAL_IP',
                'HTTP_FORWARDED_FOR',
                'HTTP_FORWARDED'
            );
            
            foreach ($forwarded_headers as $header) {
                if (isset($_SERVER[$header])) {
                    // For X-Forwarded-For, the first IP is usually the client's real IP
                    $header_value = $_SERVER[$header];
                    $ips = explode(',', $header_value);
                    
                    foreach ($ips as $potential_ip) {
                        $potential_ip = trim($potential_ip);
                        
                        // Validate the IP format and ensure it's not a private/reserved range
                        if (filter_var($potential_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                            $ip = $potential_ip;
                            break 2; // Break both loops
                        }
                    }
                }
            }
        }
        
        // Log the IP detection process for debugging
        error_log('FFB Debug: Using fallback IP detection. Detected IP: ' . $ip . ' from ' . 
                 (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown User Agent'));
        
        return $ip;
    }
    
    /**
     * Check if an IP is a trusted proxy
     * This can be customized to include known proxy IPs for your server setup
     */
    private function is_trusted_proxy($ip) {
        // Define trusted proxies - customize this based on your server infrastructure
        // For example, if you're behind Cloudflare, you might want to include their IPs
        $trusted_proxies = array(
            '127.0.0.1',      // Localhost
            '::1'             // IPv6 localhost
            // Add your server's trusted proxies here
        );
        
        // Check if the IP is in the trusted proxies list
        return in_array($ip, $trusted_proxies);
    }

    public function is_location_blocked($geo_data) {
        if (empty($geo_data)) {
            error_log('FFB Debug: No geo data available, blocked by default');
            return true; // If no geo data, block by default for security
        }
        
        // Get country code from API response
        $country_code = isset($geo_data['country_code']) ? strtoupper($geo_data['country_code']) : '';
        
        if (empty($country_code)) {
            error_log('FFB Debug: No country code in geo data, blocked by default');
            return true; // If no country code, block by default for security
        }
        
        // Convert approved countries to uppercase for case-insensitive comparison
        $approved_countries_upper = array_map('strtoupper', $this->get_approved_countries());
        
        error_log('FFB Debug: Country code ' . $country_code . ' checking against approved list: ' . implode(',', $approved_countries_upper));
        
        // If approved countries list is empty, allow all countries
        if (empty($approved_countries_upper)) {
            error_log('FFB Debug: No approved countries configured, allowing all countries');
            return false;
        }
        
        // Check if country is in the approved list
        if (!in_array($country_code, $approved_countries_upper)) {
            error_log('FFB Debug: Country blocked: ' . $country_code);
            return true;
        }
        
        // If country is allowed, check state/region if it's US
        if ($country_code == 'US' && !empty($geo_data['region_code'])) {
            $region_code = strtoupper($geo_data['region_code']);
            $approved_states_upper = array_map('strtoupper', $this->get_approved_states());
            
            error_log('FFB Debug: Checking state: ' . $region_code . ' against approved states: ' . implode(',', $approved_states_upper));
            
            // If approved states list is empty, allow all states
            if (empty($approved_states_upper)) {
                error_log('FFB Debug: No approved states configured, allowing all states');
                return false;
            }
            
            if (!in_array($region_code, $approved_states_upper)) {
                error_log('FFB Debug: State blocked: ' . $region_code);
                return true;
            }
        }
        
        // If state is allowed, check ZIP code if we have it and ZIP restrictions are in place
        if (isset($geo_data['zip']) && !empty($geo_data['zip']) && !empty($this->approved_zip_codes)) {
            $zip = substr($geo_data['zip'], 0, 5);
            $approved_zip_codes = $this->get_approved_zip_codes();
            if (!in_array($zip, $approved_zip_codes)) {
                error_log('FFB Debug: ZIP code blocked: ' . $zip);
                return true;
            }
        }
        
        // If we've gotten here, all checks have passed
        error_log('FFB Debug: Location allowed: ' . $country_code);
        return false;
    }

    public function replace_forms_with_message($content, $message) {
        // Replace Formidable Forms shortcodes with message
        $pattern = '/\[formidable.*?\]/';
        $replacement = '<div class="ffb-blocked-message">' . $message . '</div>';
        $content = preg_replace($pattern, $replacement, $content);
        
        // Also handle Formidable Forms rendered via HTML - only target the form element, not its container
        $form_pattern = '/<form.*?class=".*?frm_pro_form.*?>.*?<\/form>/s';
        $content = preg_replace($form_pattern, $replacement, $content);
        
        // Add inline CSS to ensure the blocked message stays visible
        $css = '<style>
            .ffb-blocked-message {
                display: block !important;
                visibility: visible !important;
                opacity: 1 !important;
                position: static !important;
                z-index: 9999 !important;
            }
        </style>';
        
        // Add CSS to beginning of content
        $content = $css . $content;
        
        // Add server-side flag to indicate the form was blocked server-side
        $content .= '<script>
            if (typeof ffbServerBlocked === "undefined") {
                window.ffbServerBlocked = true;
            }
        </script>';
        
        return $content;
    }

    // Helper method to get approved states
    public function get_approved_states() {
        // Get approved states from options
        $approved_states = get_option('ffb_approved_states', $this->approved_states);
        
        // Make sure approved states are properly formatted
        if (!is_array($approved_states)) {
            $approved_states = explode(',', $approved_states);
        }
        
        // Clean up each state code
        $approved_states = array_map(function($state) {
            return trim(strtoupper($state));
        }, $approved_states);
        
        // Remove duplicates and empty values
        $approved_states = array_filter(array_unique($approved_states));
        
        // Debug log
        error_log('FFB: Passing approved states to JS: ' . implode(',', $approved_states));
        
        return $approved_states;
    }
    
    // Helper method to get approved zip codes
    public function get_approved_zip_codes() {
        // Get approved zip codes from options
        $approved_zip_codes = get_option('ffb_approved_zip_codes', $this->approved_zip_codes);
        
        // Make sure approved zip codes are properly formatted
        if (!is_array($approved_zip_codes)) {
            $approved_zip_codes = explode(',', $approved_zip_codes);
        }
        
        // Clean up each zip code
        $approved_zip_codes = array_map(function($zip) {
            return trim($zip);
        }, $approved_zip_codes);
        
        // Remove duplicates and empty values
        $approved_zip_codes = array_filter(array_unique($approved_zip_codes));
        
        return $approved_zip_codes;
    }

    // Helper method to get approved countries
    public function get_approved_countries() {
        return empty($this->approved_countries) ? array() : $this->approved_countries;
    }

    public function enqueue_scripts() {
        // Get the approved states and zip codes
        $approved_states = $this->get_approved_states();
        $approved_zip_codes = $this->get_approved_zip_codes();
        $approved_countries = $this->approved_countries;
        $zip_validation_enabled = get_option('ffb_zip_validation_enabled', '0') === '1';
        
        // Always enqueue the scripts and styles to ensure they're available when needed
        wp_enqueue_script('jquery');
        
        // Enqueue the geo-blocker script with cache busting
        $js_version = '2.1.72-' . time(); // Add timestamp for cache busting
        wp_enqueue_script('ffb-geo-blocker', plugin_dir_url(__FILE__) . 'geo-blocker.js', array('jquery'), $js_version, true);
        
        // Enqueue the styles
        wp_enqueue_style('ffb-styles', plugin_dir_url(__FILE__) . 'style.css', array(), '2.1.72');
        
        // Add honeypot CSS
        $honeypot_css = "
            .ffb-honeypot-field {
                position: absolute !important;
                left: -9999px !important;
                top: -9999px !important;
                opacity: 0 !important;
                height: 0 !important;
                width: 0 !important;
                z-index: -1 !important;
                pointer-events: none !important;
            }
        ";
        wp_add_inline_style('ffb-styles', $honeypot_css);
        
        // Add honeypot field to forms via JavaScript
        $honeypot_js = "
            jQuery(document).ready(function(\$) {
                // Add honeypot field to all Formidable forms
                \$('.frm_forms').each(function() {
                    var \$form = \$(this).find('form');
                    if (\$form.length && !\$form.find('.ffb-honeypot-field').length) {
                        $('<div class=\"ffb-honeypot-field\"><label for=\"ffb_website\">Website</label><input type=\"text\" name=\"ffb_website\" id=\"ffb_website\" autocomplete=\"off\"></div>').appendTo(\$form);
                    }
                });
                
                // Also handle dynamically loaded forms
                \$(document).on('frmFormComplete', function(event, form, response) {
                    var \$form = \$(form);
                    if (\$form.length && !\$form.find('.ffb-honeypot-field').length) {
                        $('<div class=\"ffb-honeypot-field\"><label for=\"ffb_website\">Website</label><input type=\"text\" name=\"ffb_website\" id=\"ffb_website\" autocomplete=\"off\"></div>').appendTo(\$form);
                    }
                });
            });
        ";
        wp_add_inline_script('ffb-geo-blocker', $honeypot_js);
        
        // Localize the script with necessary data
        wp_localize_script('ffb-geo-blocker', 'ffbGeoBlocker', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'api_url' => 'https://api.ipapi.com/api/', // Reverted back to ipapi.com
            'api_key' => defined('FFB_API_KEY') ? FFB_API_KEY : get_option('ffb_api_key', ''),
            'approved_states' => $approved_states,
            'approved_countries' => $approved_countries,
            'approved_zip_codes' => $approved_zip_codes,
            'zip_validation_enabled' => $zip_validation_enabled,
            'is_admin' => current_user_can('manage_options'),
            'testing_own_ip' => in_array($_SERVER['REMOTE_ADDR'], $this->blocked_ips),
            'blocked_message' => $this->get_blocked_message()
        ));
        
        // Localize the script with AJAX data
        wp_localize_script('ffb-geo-blocker', 'ffb_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ffb_admin_nonce')
        ));
    }

    public function admin_scripts($hook) {
        // Only load on our plugin pages
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'ff-spam-blocker') === false) {
            return;
        }

        // Enqueue jQuery and jQuery UI
        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-core');
        wp_enqueue_script('jquery-ui-tabs');
        wp_enqueue_script('jquery-ui-datepicker');

        // Enqueue Select2 for better dropdowns
        wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), '4.1.0', true);
        wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', array(), '4.1.0');
        
        // Enqueue country flag CSS for log page
        wp_enqueue_style('country-flags', 'https://cdn.jsdelivr.net/gh/lipis/flag-icons@6.6.6/css/flag-icons.min.css', array(), '6.6.6');

        // Enqueue our admin script
        wp_enqueue_script('ffb-admin', plugin_dir_url(__FILE__) . 'assets/js/admin.js', array('jquery', 'jquery-ui-tabs', 'select2'), '2.1.72', true);

        // Enqueue our admin styles
        wp_enqueue_style('ffb-admin-styles', plugin_dir_url(__FILE__) . 'assets/css/admin.css', array(), '2.1.72');
        
        // Pass data to the script
        wp_localize_script('ffb-admin', 'ffbAdminVars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ffb_admin_nonce'),
            'api_key' => get_option('ffb_api_key', ''),
            'approved_countries' => $this->approved_countries,
            'approved_states' => $this->approved_states,
            'approved_zip_codes' => $this->approved_zip_codes,
            'diagnostic_mode' => $this->diagnostic_mode ? '1' : '0',
            'refreshing_usage' => __('Refreshing...', 'aqm-formidable-spam-blocker'),
            'strings' => array(
                'testing' => __('Testing...', 'aqm-formidable-spam-blocker'),
                'test_success' => __('API key is valid!', 'aqm-formidable-spam-blocker'),
                'test_error' => __('Error testing API key', 'aqm-formidable-spam-blocker'),
                'confirm_delete' => __('Are you sure you want to delete this IP?', 'aqm-formidable-spam-blocker'),
                'confirm_clear' => __('Are you sure you want to clear all logs?', 'aqm-formidable-spam-blocker'),
                'searching' => __('Searching...', 'aqm-formidable-spam-blocker'),
                'no_results' => __('No results found', 'aqm-formidable-spam-blocker')
            )
        ));
    }

    public function validate_form_submission($errors, $values) {
        error_log('FFB Debug: validate_form_submission called');
        
        // Get client IP
        $ip = $this->get_client_ip();
        
        // Get form ID
        $form_id = isset($values['form_id']) ? $values['form_id'] : 'unknown';
        
        // Check if IP is in the whitelist
        if ($this->is_ip_whitelisted($ip)) {
            error_log('FFB Debug: IP ' . $ip . ' is whitelisted, allowing submission');
            $this->log_access_attempt($ip, 'allowed', 'IP whitelisted', $form_id, 'form_submission');
            return $errors;
        }
        
        // Check if IP is in the blacklist - this overrules all other checks
        if ($this->is_ip_blacklisted($ip)) {
            error_log('FFB Debug: IP ' . $ip . ' is blacklisted, blocking submission');
            $this->log_access_attempt($ip, 'blocked', 'IP blacklisted', $form_id, 'form_submission');
            $errors['security'] = $this->get_blocked_message();
            return $errors;
        }
        
        // Check rate limiting
        if ($this->rate_limit_enabled && $this->is_rate_limited($ip)) {
            error_log('FFB Debug: IP ' . $ip . ' is rate limited, blocking submission');
            $this->log_access_attempt($ip, 'blocked', 'Rate limited', $form_id, 'form_submission');
            $errors['security'] = $this->get_blocked_message();
            return $errors;
        }
        
        // Get geo data
        $geo_data = $this->get_geo_data($ip);
        
        // If geo data is null and diagnostic mode is enabled, allow the submission
        if (empty($geo_data) && $this->diagnostic_mode) {
            error_log('FFB Diagnostic: Allowing submission despite missing geo data');
            $this->log_access_attempt($ip, 'allowed', 'Diagnostic mode enabled', $form_id, 'form_submission');
            return $errors;
        }
        
        // If geo data is null, block the submission
        if (empty($geo_data)) {
            error_log('FFB Debug: No geo data available for IP ' . $ip . ', blocking submission');
            $this->log_access_attempt($ip, 'blocked', 'No geo data available', $form_id, 'form_submission');
            $errors['security'] = $this->get_blocked_message();
            return $errors;
        }
        
        // Check if location is blocked
        if ($this->is_location_blocked($geo_data)) {
            error_log('FFB Debug: Location is blocked for IP ' . $ip . ', blocking submission');
            $this->log_access_attempt($ip, 'blocked', 'Location blocked', $form_id, 'form_submission');
            $errors['security'] = $this->get_blocked_message();
            return $errors;
        }
        
        // Log the allowed access
        $country_name = isset($geo_data['country_name']) ? $geo_data['country_name'] : 'Unknown';
        $region_name = isset($geo_data['region_name']) ? $geo_data['region_name'] : 'Unknown';
        $this->log_access_attempt($ip, 'allowed', 'Location allowed', $form_id, 'form_submission');
        
        return $errors;
    }
    
    /**
     * Pre-create entry filter for Formidable Forms
     * 
     * @param array $values Form values
     * @param array $params Additional parameters
     * @return array Form values
     */
    public function pre_create_entry($values, $params = array()) {
        try {
            error_log('FFB Debug: pre_create_entry called for form ID: ' . (isset($values['form_id']) ? $values['form_id'] : 'unknown'));
            
            // Get client IP
            $ip = $this->get_client_ip();
            
            // Check if IP is in the whitelist
            if ($this->is_ip_whitelisted($ip)) {
                error_log('FFB Debug: IP ' . $ip . ' is whitelisted, allowing entry creation');
                $this->log_access_attempt($ip, 'allowed', 'IP whitelisted', $values['form_id'], 'pre_create_entry');
                return $values;
            }
            
            // Check if IP is in the blacklist - this overrules all other checks
            if ($this->is_ip_blacklisted($ip)) {
                error_log('FFB Debug: IP ' . $ip . ' is blacklisted, blocking entry creation');
                $this->log_access_attempt($ip, 'blocked', 'IP blacklisted', $values['form_id'], 'pre_create_entry');
                // Return empty array to prevent entry creation
                return array();
            }
            
            // Get geo data
            $geo_data = $this->get_geo_data($ip);
            
            // Check if location is blocked
            if ($this->is_location_blocked($geo_data)) {
                error_log('FFB Debug: Location is blocked in pre_create_entry');
                $this->log_access_attempt($ip, 'blocked', 'Location blocked', $values['form_id'], 'pre_create_entry');
                // Return empty array to prevent entry creation
                return array();
            }
            
            return $values;
        } catch (Exception $e) {
            error_log('FFB Error in pre_create_entry: ' . $e->getMessage());
            // Return values unchanged on error
            return $values;
        }
    }

    /**
     * Check if an IP address is in the whitelist
     * 
     * @param string $ip The IP address to check
     * @return bool True if IP is whitelisted, false otherwise
     */
    private function is_ip_whitelisted($ip) {
        // Load IP whitelist
        $ip_whitelist = get_option('ffb_ip_whitelist', array());
        $whitelist = is_array($ip_whitelist) ? $ip_whitelist : array();
        
        // Check if IP is in whitelist
        return in_array($ip, $whitelist);
    }
    
    /**
     * Check if an IP address is in the blacklist
     * 
     * @param string $ip The IP address to check
     * @return bool True if IP is blacklisted, false otherwise
     */
    public function is_ip_blacklisted($ip) {
        if (empty($ip) || !is_array($this->ip_blacklist)) {
            return false;
        }
        
        // Check for exact match
        if (in_array($ip, $this->ip_blacklist)) {
            return true;
        }
        
        // Check for wildcard matches (e.g., 192.168.*)
        foreach ($this->ip_blacklist as $blacklisted_ip) {
            if (strpos($blacklisted_ip, '*') !== false) {
                $pattern = '/^' . str_replace('*', '.*', $blacklisted_ip) . '$/';
                if (preg_match($pattern, $ip)) {
                    return true;
                }
            }
        }
        
        return false;
    }

    public function block_form($content) {
        // Check if we're on a form page
        if (!$this->is_form_page($content)) {
            return $content;
        }
        
        // Get client IP
        $ip = $this->get_client_ip();
        
        // Check if IP is in the whitelist
        if ($this->is_ip_whitelisted($ip)) {
            error_log('FFB Debug: IP ' . $ip . ' is whitelisted, allowing form display');
            $this->log_access_attempt($ip, 'allowed', 'IP whitelisted', $this->get_form_id_from_content($content), 'form_load');
            return $content;
        }
        
        // Check if IP is in the blacklist - this overrules all other checks
        if ($this->is_ip_blacklisted($ip)) {
            error_log('FFB Debug: IP ' . $ip . ' is blacklisted, blocking form');
            $this->log_access_attempt($ip, 'blocked', 'IP blacklisted', $this->get_form_id_from_content($content), 'form_load');
            return $this->replace_forms_with_message($content, $this->get_blocked_message());
        }
        
        // Get geo data
        $geo_data = $this->get_geo_data($ip);
        
        // Debug the geo data
        error_log('FFB Debug: Geo data for IP ' . $ip . ': ' . print_r($geo_data, true));
        
        // Check if location is allowed
        if (!$this->is_location_allowed($geo_data)) {
            error_log('FFB Debug: Location not allowed for IP ' . $ip . ', blocking form');
            $this->log_access_attempt($ip, 'blocked', 'Location not allowed', $this->get_form_id_from_content($content), 'form_load');
            return $this->replace_forms_with_message($content, $this->get_blocked_message());
        }
        
        // Log the allowed access
        $country_name = isset($geo_data['country_name']) ? $geo_data['country_name'] : 'Unknown';
        $region_name = isset($geo_data['region_name']) ? $geo_data['region_name'] : 'Unknown';
        $this->log_access_attempt($ip, 'allowed', 'Location allowed', $this->get_form_id_from_content($content), 'form_load');
        
        // Allow the form to be displayed
        return $content;
    }
    
    /**
     * Debug function to output detailed geolocation data and settings
     */
    private function debug_geo_data($ip, $geo_data) {
        // Create a detailed log message
        $debug = "FFB DETAILED DEBUG INFO:\n";
        $debug .= "IP: " . $ip . "\n";
        $debug .= "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";
        
        // Geo data
        $debug .= "GEOLOCATION DATA:\n";
        if (empty($geo_data)) {
            $debug .= "No geolocation data available\n";
        } else {
            foreach ($geo_data as $key => $value) {
                $debug .= "$key: $value\n";
            }
        }
        $debug .= "\n";
        
        // Plugin settings
        $debug .= "PLUGIN SETTINGS:\n";
        $debug .= "Approved Countries: " . implode(', ', $this->approved_countries) . "\n";
        $debug .= "Approved States: " . implode(', ', $this->approved_states) . "\n";
        $debug .= "Approved ZIP Codes: " . implode(', ', $this->approved_zip_codes) . "\n";
        $debug .= "DB Option - ffb_approved_countries: " . get_option('ffb_approved_countries', 'NOT SET') . "\n";
        $debug .= "DB Option - ffb_approved_states: " . get_option('ffb_approved_states', 'NOT SET') . "\n";
        $debug .= "DB Option - ffb_approved_zip_codes: " . get_option('ffb_approved_zip_codes', 'NOT SET') . "\n\n";
        
        // Decision process
        $debug .= "DECISION PROCESS:\n";
        
        // Country check
        if (!empty($geo_data['country_code'])) {
            $country_code = strtoupper($geo_data['country_code']);
            $approved_countries = array_map('strtoupper', $this->approved_countries);
            
            error_log('FFB Debug: Checking country: ' . $country_code . ' against approved countries: ' . implode(',', $approved_countries));
            
            // If approved countries list is empty, allow all countries
            if (empty($approved_countries)) {
                error_log('FFB Debug: No approved countries configured, allowing all countries');
                return;
            }
            
            if (!in_array($country_code, $approved_countries)) {
                error_log('FFB Debug: Country blocked: ' . $country_code);
                return;
            }
        }
        
        // State check
        if (isset($geo_data['country_code']) && strtoupper($geo_data['country_code']) == 'US' && 
            !empty($geo_data['region_code'])) {
            $region_code = strtoupper($geo_data['region_code']);
            $approved_states_upper = array_map('strtoupper', $this->get_approved_states());
            
            error_log('FFB Debug: Checking state: ' . $region_code . ' against approved states: ' . implode(',', $approved_states_upper));
            
            // If approved states list is empty, allow all states
            if (empty($approved_states_upper)) {
                error_log('FFB Debug: No approved states configured, allowing all states');
                return;
            }
            
            if (!in_array($region_code, $approved_states_upper)) {
                error_log('FFB Debug: State blocked: ' . $region_code);
                return;
            } else {
                error_log('FFB Debug: State allowed: ' . $region_code);
            }
        }
        
        // ZIP check
        if (!empty($geo_data['zip']) && !empty($this->approved_zip_codes)) {
            $zip = substr($geo_data['zip'], 0, 5);
            $approved_zip_codes = $this->get_approved_zip_codes();
            if (!in_array($zip, $approved_zip_codes)) {
                error_log('FFB Debug: ZIP code blocked: ' . $zip);
                return;
            }
        }
        
        // Log the debug info
        error_log($debug);
    }

    public function display_api_limit_warning() {
        // Only show in admin area
        if (!is_admin()) {
            return;
        }
        
        // Only show to users who can manage options
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get API usage data
        $usage = get_option('ffb_api_usage', array());
        
        // If we don't have usage data or it's demo data, don't show warning
        if (empty($usage) || empty($usage['requests']) || empty($usage['limit']) || !empty($usage['is_demo'])) {
            return;
        }
        
        // Calculate usage percentage
        $percentage = ($usage['requests'] / $usage['limit']) * 100;
        
        // If usage is over 90%, show a warning
        if ($percentage >= 90) {
            $remaining = $usage['limit'] - $usage['requests'];
            $message = sprintf(
                'Warning: You have used %d%% of your monthly ipapi.com API limit (%d of %d requests). You have %d requests remaining this month. <a href="%s">View Settings</a>',
                round($percentage),
                $usage['requests'],
                $usage['limit'],
                $remaining,
                admin_url('admin.php?page=ff-spam-blocker')
            );
            
            echo '<div class="notice notice-warning is-dismissible"><p>' . $message . '</p></div>';
        }
        // If usage is over 75%, show a notice
        else if ($percentage >= 75) {
            $remaining = $usage['limit'] - $usage['requests'];
            $message = sprintf(
                'Notice: You have used %d%% of your monthly ipapi.com API limit (%d of %d requests). You have %d requests remaining this month. <a href="%s">View Settings</a>',
                round($percentage),
                $usage['requests'],
                $usage['limit'],
                $remaining,
                admin_url('admin.php?page=ff-spam-blocker')
            );
            
            echo '<div class="notice notice-info is-dismissible"><p>' . $message . '</p></div>';
        }
    }

    public function ajax_test_api_key() {
        // Verify nonce
        check_ajax_referer('ffb_admin_nonce', 'nonce');
        
        // Get the API key from the request
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        
        if (empty($api_key)) {
            wp_send_json_error('API key is required');
            return;
        }
        
        // Basic validation of API key format
        $api_key = trim($api_key); // Remove any whitespace
        if (!preg_match('/^[a-zA-Z0-9]{32}$/', $api_key)) {
            error_log('FFB Debug: API key format appears invalid: ' . substr($api_key, 0, 5) . '...');
            wp_send_json_error('API Error: The API key format appears to be invalid. It should be a 32-character alphanumeric string without spaces or special characters.');
            return;
        }
        
        // Test the API key with a sample IP
        $test_ip = $this->get_client_ip(); // Use admin's actual IP instead of hardcoded value
        
        // Updated API URL format to use access_key parameter instead of key
        $api_url = "https://api.ipapi.com/api/{$test_ip}?access_key={$api_key}";
        
        // Log the API request for debugging
        error_log('FFB Debug: Testing API key with URL: ' . $api_url);
        
        $response = wp_remote_get($api_url, array(
            'timeout' => 15,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('FFB Debug: API request error: ' . $error_message);
            wp_send_json_error('API Error: ' . $error_message);
            return;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Log the response for debugging
        error_log('FFB Debug: API response status code: ' . $status_code);
        error_log('FFB Debug: API response body: ' . (strlen($body) > 1000 ? substr($body, 0, 1000) . '...' : $body));
        
        // Try to decode the JSON response
        $data = json_decode($body, true);
        
        // Check if JSON parsing failed
        if (json_last_error() !== JSON_ERROR_NONE) {
            $json_error = json_last_error_msg();
            error_log('FFB Debug: JSON parsing error: ' . $json_error);
            wp_send_json_error('API Error: Invalid response format. JSON parsing error: ' . $json_error);
            return;
        }
        
        // Check if the API returned an error
        if (isset($data['success']) && $data['success'] === false) {
            $error_type = isset($data['error']['type']) ? $data['error']['type'] : 'Unknown error';
            $error_code = isset($data['error']['code']) ? $data['error']['code'] : '';
            $error_info = isset($data['error']['info']) ? $data['error']['info'] : '';
            
            $error_message = $error_info ? $error_info : $error_type;
            if ($error_code) {
                $error_message .= ' (Code: ' . $error_code . ')';
            }
            
            error_log('FFB Debug: API returned error: ' . $error_message);
            wp_send_json_error('API Error: ' . $error_message);
            return;
        }
        
        // Check if we have the expected data
        if (empty($data) || !isset($data['country_code'])) {
            $error_msg = isset($data['message']) ? $data['message'] : 'Unknown error';
            error_log('FFB Debug: API returned error: ' . $error_msg);
            wp_send_json_error('API Error: Response missing expected data');
            return;
        }
        
        // If we got here, the API key is valid
        error_log('FFB Debug: API key test successful');
        wp_send_json_success(array(
            'message' => 'API key is valid. Location data received for ' . $test_ip . ': ' . $data['country_name'],
            'data' => $data
        ));
    }
    
    /**
     * AJAX handler for refreshing API usage
     * @deprecated 2.1.72 API usage functionality has been removed
     */
    public function ajax_refresh_api_usage() {
        // This function has been deprecated in v2.1.72
        wp_send_json_error('API usage functionality has been removed');
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Save settings if form was submitted
        if (isset($_POST['ffb_save_settings']) && check_admin_referer('ffb_save_settings', 'ffb_nonce')) {
            try {
                // API key
                if (isset($_POST['ffb_api_key'])) {
                    update_option('ffb_api_key', sanitize_text_field($_POST['ffb_api_key']));
                    $this->api_key = sanitize_text_field($_POST['ffb_api_key']);
                }
                
                // Approved countries
                if (isset($_POST['ffb_approved_countries'])) {
                    $countries = array_map('sanitize_text_field', explode(',', $_POST['ffb_approved_countries']));
                    $countries = array_map('trim', $countries);
                    $countries = array_filter($countries);
                    update_option('ffb_approved_countries', $countries);
                    $this->approved_countries = $countries;
                } else {
                    update_option('ffb_approved_countries', array());
                    $this->approved_countries = array();
                }
                
                // Approved states
                if (isset($_POST['ffb_approved_states'])) {
                    $states = array_map('sanitize_text_field', explode(',', $_POST['ffb_approved_states']));
                    $states = array_map('trim', $states);
                    update_option('ffb_approved_states', $states);
                    $this->approved_states = $states;
                } else {
                    update_option('ffb_approved_states', array());
                    $this->approved_states = array();
                }
                
                // Approved ZIP codes
                if (isset($_POST['ffb_approved_zip_codes'])) {
                    $zip_codes = array_map('sanitize_text_field', explode(',', $_POST['ffb_approved_zip_codes']));
                    $zip_codes = array_map('trim', $zip_codes);
                    update_option('ffb_approved_zip_codes', $zip_codes);
                    $this->approved_zip_codes = $zip_codes;
                } else {
                    update_option('ffb_approved_zip_codes', array());
                    $this->approved_zip_codes = array();
                }
                
                // Blocked message
                if (isset($_POST['ffb_blocked_message'])) {
                    update_option('ffb_blocked_message', wp_kses_post($_POST['ffb_blocked_message']));
                    $this->blocked_message = wp_kses_post($_POST['ffb_blocked_message']);
                }
                
                // Logging enabled
                $log_enabled = isset($_POST['ffb_log_enabled']) ? '1' : '0';
                update_option('ffb_log_enabled', $log_enabled);
                $this->log_enabled = $log_enabled === '1';
                
                // Diagnostic mode
                $diagnostic_mode = isset($_POST['ffb_diagnostic_mode']) ? '1' : '0';
                update_option('ffb_diagnostic_mode', $diagnostic_mode);
                $this->diagnostic_mode = $diagnostic_mode === '1';
                
                // Rate limiting settings
                $rate_limit_enabled = isset($_POST['ffb_rate_limit_enabled']) ? '1' : '0';
                update_option('ffb_rate_limit_enabled', $rate_limit_enabled);
                $this->rate_limit_enabled = $rate_limit_enabled === '1';
                
                $rate_limit_timeframe = isset($_POST['ffb_rate_limit_timeframe']) ? intval($_POST['ffb_rate_limit_timeframe']) : 3600;
                update_option('ffb_rate_limit_timeframe', $rate_limit_timeframe);
                $this->rate_limit_timeframe = $rate_limit_timeframe;
                
                $rate_limit_requests = isset($_POST['ffb_rate_limit_requests']) ? intval($_POST['ffb_rate_limit_requests']) : 3;
                update_option('ffb_rate_limit_requests', $rate_limit_requests);
                $this->rate_limit_requests = $rate_limit_requests;
                
                // Blocked IPs
                if (isset($_POST['ffb_blocked_ips'])) {
                    $blocked_ips = explode("\n", $_POST['ffb_blocked_ips']);
                    $blocked_ips = array_map('trim', $blocked_ips);
                    $blocked_ips = array_filter($blocked_ips);
                    update_option('ffb_blocked_ips', $blocked_ips);
                    $this->blocked_ips = $blocked_ips;
                } else {
                    update_option('ffb_blocked_ips', array());
                    $this->blocked_ips = array();
                }
                
                // IP whitelist
                if (isset($_POST['ffb_ip_whitelist'])) {
                    $whitelist = explode("\n", $_POST['ffb_ip_whitelist']);
                    $whitelist = array_map('trim', $whitelist);
                    $whitelist = array_filter($whitelist);
                    update_option('ffb_ip_whitelist', $whitelist);
                    $this->ip_whitelist = $whitelist;
                } else {
                    update_option('ffb_ip_whitelist', array());
                    $this->ip_whitelist = array();
                }
                
                // IP blacklist
                if (isset($_POST['ffb_ip_blacklist'])) {
                    $blacklist = explode("\n", $_POST['ffb_ip_blacklist']);
                    $blacklist = array_map('trim', $blacklist);
                    $blacklist = array_filter($blacklist);
                    update_option('ffb_ip_blacklist', $blacklist);
                    $this->ip_blacklist = $blacklist;
                } else {
                    update_option('ffb_ip_blacklist', array());
                    $this->ip_blacklist = array();
                }
                
                // Clear geolocation cache
                error_log('FFB Debug: Clearing geolocation cache');
                clear_geo_cache();
                
                // Set transient to show success message
                error_log('FFB Debug: Setting success transient');
                set_transient('ffb_settings_saved', true, 30);
                
                // Define the redirect URL
                $redirect_url = admin_url('admin.php') . '?page=ff-spam-blocker&settings-updated=true';
                error_log('FFB Debug: About to redirect to: ' . $redirect_url);
                
                // Clean any output to prevent headers already sent issues
                if (ob_get_length()) {
                    ob_end_clean();
                }
                
                // Try different redirect approaches
                if (!headers_sent()) {
                    error_log('FFB Debug: Performing standard redirect using wp_safe_redirect');
                    wp_safe_redirect($redirect_url);
                    exit;
                } else {
                    error_log('FFB Debug: Headers already sent, using JavaScript redirect');
                    ?>
                    <script type="text/javascript">
                        window.location.href = "<?php echo $redirect_url; ?>";
                    </script>
                    <noscript><meta http-equiv="refresh" content="0;url=<?php echo $redirect_url; ?>"></noscript>
                    <p>If you are not redirected automatically, please <a href="<?php echo $redirect_url; ?>">click here</a>.</p>
                    <?php
                    exit;
                }
                
            } catch (Exception $e) {
                // Log any exceptions that occur during saving
                error_log('FFB Debug: Exception in save_settings: ' . $e->getMessage());
                error_log('FFB Debug: Exception trace: ' . $e->getTraceAsString());
                
                // Clean any output buffers
                while (ob_get_level()) {
                    ob_end_clean();
                }
                
                // Force JavaScript redirect as a final fallback
                ?>
                <script type="text/javascript">
                    console.log("FFB Debug: Using JavaScript redirect after exception");
                    window.location.href = "<?php echo admin_url('admin.php?page=ff-spam-blocker&error=exception&message=' . urlencode($e->getMessage())); ?>";
                </script>
                <noscript><meta http-equiv="refresh" content="0;url=<?php echo admin_url('admin.php?page=ff-spam-blocker&error=exception&message=' . urlencode($e->getMessage())); ?>"></noscript>
                <p>If you are not redirected automatically, please <a href="<?php echo admin_url('admin.php?page=ff-spam-blocker&error=exception&message=' . urlencode($e->getMessage())); ?>">click here</a>.</p>
                <?php
                exit;
            }
        }
        
        // Prepare data for the template
        $api_key = $this->api_key;
        $approved_countries = $this->approved_countries;
        $approved_states = $this->approved_states;
        $approved_zip_codes = $this->approved_zip_codes;
        $blocked_message = $this->get_blocked_message();
        $log_enabled = $this->log_enabled ? '1' : '0';
        $diagnostic_mode = $this->diagnostic_mode ? '1' : '0';
        $rate_limit_enabled = $this->rate_limit_enabled ? '1' : '0';
        $rate_limit_timeframe = $this->rate_limit_timeframe;
        $rate_limit_requests = $this->rate_limit_requests;
        $blocked_ips = implode("\n", $this->blocked_ips);
        $ip_whitelist = implode("\n", $this->ip_whitelist);
        $ip_blacklist = implode("\n", $this->ip_blacklist);
        
        // Include the settings template
        include_once(plugin_dir_path(__FILE__) . 'templates/settings.php');
    }

    public function manual_create_table() {
        // Security check
        if (!current_user_can('manage_options') || !check_admin_referer('ffb_create_table', 'ffb_table_nonce')) {
            wp_die('Unauthorized');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'aqm_formidable_spam_blocker_log';

        // Drop the table if it exists
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        error_log('FFB Debug: Manually dropped access log table');
        
        // Create the table
        ffb_create_log_table();
        
        // Update the DB version option to track that we've created the table
        update_option('ffb_db_version', $this->version);
        error_log('FFB Debug: Created access log table with updated structure');
    }

    public function is_location_allowed($geo_data) {
        // If no geo data, default to block (fail closed) for security
        if (empty($geo_data)) {
            error_log('FFB Debug: No geo data available, blocking access for security');
            return false;
        }
        
        // Check country first
        if (!empty($geo_data['country_code'])) {
            $country_code = strtoupper($geo_data['country_code']);
            $approved_countries = array_map('strtoupper', $this->get_approved_countries());
            
            error_log('FFB Debug: Country code ' . $country_code . ' checking against approved list: ' . implode(',', $approved_countries));
            
            // If approved countries list is empty, allow all countries
            if (empty($approved_countries)) {
                error_log('FFB Debug: No approved countries configured, allowing all countries');
                return true;
            }
            
            if (!in_array($country_code, $approved_countries)) {
                error_log('FFB Debug: Country blocked: ' . $country_code);
                return false;
            }
        }
        
        // If country is allowed, check state/region if it's US
        if ($country_code == 'US' && !empty($geo_data['region_code'])) {
            $region_code = strtoupper($geo_data['region_code']);
            $approved_states_upper = array_map('strtoupper', $this->get_approved_states());
            
            error_log('FFB Debug: Checking state: ' . $region_code . ' against approved states: ' . implode(',', $approved_states_upper));
            
            // If approved states list is empty, allow all states
            if (empty($approved_states_upper)) {
                error_log('FFB Debug: No approved states configured, allowing all states');
                return true;
            }
            
            if (!in_array($region_code, $approved_states_upper)) {
                error_log('FFB Debug: State blocked: ' . $region_code);
                return false;
            }
        }
        
        // If state is allowed, check ZIP code if we have it and ZIP restrictions are in place
        if (isset($geo_data['zip']) && !empty($geo_data['zip']) && !empty($this->approved_zip_codes)) {
            $zip = substr($geo_data['zip'], 0, 5);
            $approved_zip_codes = $this->get_approved_zip_codes();
            if (!in_array($zip, $approved_zip_codes)) {
                error_log('FFB Debug: ZIP code blocked: ' . $zip);
                return false;
            }
        }
        
        // If we've gotten here, all checks have passed
        error_log('FFB Debug: Location allowed: ' . $country_code);
        return true;
    }
    
    /**
     * Convert a region name to a region code
     */
    private function get_region_code_from_name($region_name, $country_code) {
        // If empty, return empty
        if (empty($region_name)) {
            return '';
        }
        
        // If it's already a 2-letter code, return as is
        if (strlen($region_name) == 2 && ctype_alpha($region_name)) {
            return strtoupper($region_name);
        }
        
        // For US states
        if ($country_code == 'US') {
            $states = array(
                'Alabama' => 'AL',
                'Alaska' => 'AK',
                'Arizona' => 'AZ',
                'Arkansas' => 'AR',
                'California' => 'CA',
                'Colorado' => 'CO',
                'Connecticut' => 'CT',
                'Delaware' => 'DE',
                'Florida' => 'FL',
                'Georgia' => 'GA',
                'Hawaii' => 'HI',
                'Idaho' => 'ID',
                'Illinois' => 'IL',
                'Indiana' => 'IN',
                'Iowa' => 'IA',
                'Kansas' => 'KS',
                'Kentucky' => 'KY',
                'Louisiana' => 'LA',
                'Maine' => 'ME',
                'Maryland' => 'MD',
                'Massachusetts' => 'MA',
                'Michigan' => 'MI',
                'Minnesota' => 'MN',
                'Mississippi' => 'MS',
                'Missouri' => 'MO',
                'Montana' => 'MT',
                'Nebraska' => 'NE',
                'Nevada' => 'NV',
                'New Hampshire' => 'NH',
                'New Jersey' => 'NJ',
                'New Mexico' => 'NM',
                'New York' => 'NY',
                'North Carolina' => 'NC',
                'North Dakota' => 'ND',
                'Ohio' => 'OH',
                'Oklahoma' => 'OK',
                'Oregon' => 'OR',
                'Pennsylvania' => 'PA',
                'Rhode Island' => 'RI',
                'South Carolina' => 'SC',
                'South Dakota' => 'SD',
                'Tennessee' => 'TN',
                'Texas' => 'TX',
                'Utah' => 'UT',
                'Vermont' => 'VT',
                'Virginia' => 'VA',
                'Washington' => 'WA',
                'West Virginia' => 'WV',
                'Wisconsin' => 'WI',
                'Wyoming' => 'WY',
                'District of Columbia' => 'DC',
                'American Samoa' => 'AS',
                'Guam' => 'GU',
                'Northern Mariana Islands' => 'MP',
                'Puerto Rico' => 'PR',
                'United States Minor Outlying Islands' => 'UM',
                'U.S. Virgin Islands' => 'VI',
                // Add common abbreviations and alternate names
                'Mass' => 'MA',
                'Mass.' => 'MA',
                'MA' => 'MA',
                'Ma' => 'MA',
                'ma' => 'MA',
                'Massachusetts' => 'MA',
                'MASSACHUSETTS' => 'MA',
            );
            
            // Direct lookup
            if (isset($states[$region_name])) {
                return $states[$region_name];
            }
            
            // Check for case-insensitive match
            foreach ($states as $name => $code) {
                if (strtolower($name) == strtolower($region_name)) {
                    return $code;
                }
            }
            
            // Check for partial match (e.g., "Mass" for "Massachusetts")
            foreach ($states as $name => $code) {
                if (stripos($name, $region_name) === 0 || stripos($region_name, $name) === 0) {
                    return $code;
                }
            }
        }
        
        // If no match found or not US, return the region name as is
        return strtoupper($region_name);
    }

    /**
     * Check if the current content contains a Formidable Form
     */
    private function is_form_page($content) {
        // Check for Formidable Forms shortcode
        if (strpos($content, '[formidable') !== false) {
            return true;
        }
        
        // Check for Formidable Forms div
        if (strpos($content, 'class="frm_forms') !== false) {
            return true;
        }
        
        // Check for Formidable Forms block
        if (strpos($content, '<!-- wp:formidable/simple-form') !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Extract form ID from content if possible
     */
    private function get_form_id_from_content($content) {
        // Try to extract form ID from shortcode
        if (preg_match('/\[formidable.*?id="?(\d+)"?/i', $content, $matches)) {
            return $matches[1];
        }
        
        // Try to extract form ID from div
        if (preg_match('/class="frm_forms[^"]*" id="frm_form_(\d+)_container/i', $content, $matches)) {
            return $matches[1];
        }
        
        // Try to extract form ID from block
        if (preg_match('/<!-- wp:formidable\/simple-form {"formId":"?(\d+)"?/i', $content, $matches)) {
            return $matches[1];
        }
        
        return '';
    }

    /**
     * Shortcode to check location and display status
     */
    public function shortcode_check_location($atts) {
        $atts = shortcode_atts(array(
            'ip' => '',
        ), $atts);
        
        // Get the IP to check
        $ip = !empty($atts['ip']) ? $atts['ip'] : $this->get_client_ip();
        
        // Get geolocation data
        $geo_data = $this->get_geo_data($ip);
        
        // Check if location is blocked
        $is_blocked = $this->is_location_blocked($geo_data);
        
        // Format the output
        $output = '<div class="ffb-location-check">';
        $output .= '<p><strong>IP:</strong> ' . esc_html($ip) . '</p>';
        
        if (!empty($geo_data)) {
            $output .= '<p><strong>Country:</strong> ' . esc_html($geo_data['country_name'] ?? 'Unknown') . ' (' . esc_html($geo_data['country_code'] ?? '') . ')</p>';
            $output .= '<p><strong>State:</strong> ' . esc_html($geo_data['region_name'] ?? 'Unknown') . ' (' . esc_html($geo_data['region_code'] ?? '') . ')</p>';
            $output .= '<p><strong>City:</strong> ' . esc_html($geo_data['city'] ?? 'Unknown') . '</p>';
            $output .= '<p><strong>ZIP:</strong> ' . esc_html($geo_data['zip'] ?? 'Unknown') . '</p>';
        } else {
            $output .= '<p>Could not retrieve geolocation data for this IP.</p>';
        }
        
        $status_class = $is_blocked ? 'blocked' : 'allowed';
        $status_text = $is_blocked ? 'Blocked' : 'Allowed';
        
        $output .= '<p class="ffb-status ffb-status-' . $status_class . '"><strong>Status:</strong> ' . $status_text . '</p>';
        $output .= '</div>';
        
        return $output;
    }

    /**
     * Get the blocked message to display when forms are blocked
     * 
     * @return string The message to display
     */
    public function get_blocked_message() {
        // Get the plain text message from database with a simple default message
        $message = get_option('ffb_blocked_message', 'We apologize, but we are currently not accepting submissions from your location.');
        // Remove backslashes from message to fix apostrophes and other characters
        $message = stripslashes($message);
        
        // Filter the message
        $message = apply_filters('ffb_blocked_message', $message);
        
        // Wrap the message in HTML 
        $formatted_message = '<div class="frm_error_style" style="text-align:center;"><p>' . $message . '</p></div>';
        
        // Add version tag for troubleshooting
        $formatted_message .= "\n<!-- FFB v{$this->version} -->";
        
        return $formatted_message;
    }

    /**
     * Filter the content to check for forms and block them if necessary
     * 
     * @param string $content The content to filter
     * @return string The filtered content
     */
    public function filter_content($content) {
        // Check if we're on a form page
        if (!$this->is_form_page($content)) {
            return $content;
        }
        
        // Get client IP
        $ip = $this->get_client_ip();
        
        // Check if IP is in the whitelist
        if ($this->is_ip_whitelisted($ip)) {
            error_log('FFB Debug: IP ' . $ip . ' is whitelisted, allowing form display');
            $this->log_access_attempt($ip, 'allowed', 'IP whitelisted', $this->get_form_id_from_content($content), 'form_load');
            return $content;
        }
        
        // Check if IP is in the blacklist - this overrules all other checks
        if ($this->is_ip_blacklisted($ip)) {
            error_log('FFB Debug: IP ' . $ip . ' is blacklisted, blocking form');
            $this->log_access_attempt($ip, 'blocked', 'IP blacklisted', $this->get_form_id_from_content($content), 'form_load');
            return $this->replace_forms_with_message($content, $this->get_blocked_message());
        }
        
        // Get geo data
        $geo_data = $this->get_geo_data($ip);
        
        // Debug the geo data
        error_log('FFB Debug: Geo data for IP ' . $ip . ': ' . print_r($geo_data, true));
        
        // Check if location is allowed
        if (!$this->is_location_allowed($geo_data)) {
            error_log('FFB Debug: Location not allowed for IP ' . $ip . ', blocking form');
            $this->log_access_attempt($ip, 'blocked', 'Location not allowed', $this->get_form_id_from_content($content), 'form_load');
            return $this->replace_forms_with_message($content, $this->get_blocked_message());
        }
        
        // Log the allowed access
        $country_name = isset($geo_data['country_name']) ? $geo_data['country_name'] : 'Unknown';
        $region_name = isset($geo_data['region_name']) ? $geo_data['region_name'] : 'Unknown';
        $this->log_access_attempt($ip, 'allowed', 'Location allowed', $this->get_form_id_from_content($content), 'form_load');
        
        // Allow the form to be displayed
        return $content;
    }
    
    /**
     * Log an access attempt to the database
     * 
     * @param string $ip The IP address
     * @param string $status The status of the attempt (allowed/blocked)
     * @param string $reason The reason for the status
     * @param int $form_id The ID of the form
     * @param string $log_type The type of log (form_load/form_submission)
     * @return bool True if logged successfully, false otherwise
     */
    private function log_access_attempt($ip, $status, $reason, $form_id = 0, $log_type = 'form_load') {
        // Check if logging is enabled
        if (!$this->log_enabled) {
            return false;
        }
        
        // Get geo data
        $geo_data = $this->get_geo_data($ip);
        
        // Debug the received geo data
        error_log('FFB Debug: Received geo data for logging: ' . print_r($geo_data, true));
        
        // Prepare data for logging
        // Different APIs use different keys, so check all possible variations
        $country = isset($geo_data['country_name']) ? $geo_data['country_name'] : 
                  (isset($geo_data['country']) ? $geo_data['country'] : 'Unknown');
                  
        $country_code = isset($geo_data['country_code']) ? $geo_data['country_code'] : 
                       (isset($geo_data['countryCode']) ? $geo_data['countryCode'] : 'Unknown');
                       
        // Region handling - check all possible variations of region keys
        $region = 'Unknown';
        $region_name = 'Unknown';
        
        // Check for various ways region might be stored
        if (isset($geo_data['region_name'])) {
            $region = $geo_data['region_name'];
            $region_name = $geo_data['region_name'];
        } elseif (isset($geo_data['regionName'])) {
            $region = $geo_data['regionName'];
            $region_name = $geo_data['regionName'];
        } elseif (isset($geo_data['region'])) {
            $region = $geo_data['region'];
            $region_name = $geo_data['region'];
        } elseif (isset($geo_data['state'])) {
            $region = $geo_data['state'];
            $region_name = $geo_data['state'];
        } elseif (isset($geo_data['subdivision_1_name'])) {
            $region = $geo_data['subdivision_1_name'];
            $region_name = $geo_data['subdivision_1_name'];
        } elseif (isset($geo_data['subdivision_1_code'])) {
            $region = $geo_data['subdivision_1_code'];
            $region_name = $geo_data['subdivision_1_code'];
        }
        
        $city = isset($geo_data['city']) ? $geo_data['city'] : 'Unknown';
        $zip = isset($geo_data['postal']) ? $geo_data['postal'] : 
              (isset($geo_data['zip']) ? $geo_data['zip'] : 'Unknown');
        
        // Log geo data for debugging
        error_log('FFB Debug: Logging access attempt with country_code: ' . $country_code . ', region_name: ' . $region_name);
        
        // Get the WordPress database object
        global $wpdb;
        
        // Define the table name
        $table_name = $wpdb->prefix . 'aqm_formidable_spam_blocker_log';
        
        // Insert the log entry
        $result = $wpdb->insert(
            $table_name,
            array(
                'ip_address' => $ip,
                'status' => $status,
                'reason' => $reason,
                'country' => $country,
                'country_code' => $country_code,
                'region' => $region,
                'region_name' => $region_name,
                'city' => $city,
                'zip' => $zip,
                'form_id' => $form_id,
                'log_type' => $log_type,
                'timestamp' => current_time('mysql')
            ),
            array(
                '%s', // ip_address
                '%s', // status
                '%s', // reason
                '%s', // country
                '%s', // country_code
                '%s', // region
                '%s', // region_name
                '%s', // city
                '%s', // zip
                '%d', // form_id
                '%s', // log_type
                '%s'  // timestamp
            )
        );
        
        // Check if the insert was successful
        if ($result === false) {
            error_log('FFB Error: Failed to log access attempt. DB Error: ' . $wpdb->last_error);
            return false;
        }
        
        return true;
    }

    /**
     * AJAX endpoint to get the current approved states list
     */
    public function ajax_get_approved_states() {
        // Verify nonce for security
        if (!isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'ffb_admin_nonce')) {
            wp_send_json_error('Invalid security token');
            return;
        }
        
        // Get the current approved states
        $approved_states = $this->get_approved_states();
        
        // Log for debugging
        error_log('FFB Debug: AJAX request for approved states, returning: ' . implode(',', $approved_states));
        
        // Return the approved states
        wp_send_json_success(array(
            'approved_states' => $approved_states,
            'timestamp' => current_time('timestamp')
        ));
    }
    
    /**
     * Add all hooks and filters
     */
    private function add_hooks() {
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_filter('the_content', array($this, 'filter_content'), 99);
        
        // AJAX handlers
        add_action('wp_ajax_ffb_test_ip', array($this, 'ajax_test_ip'));
        add_action('wp_ajax_ffb_refresh_counts', array($this, 'ajax_refresh_counts'));
        add_action('wp_ajax_ffb_clear_logs', array($this, 'ajax_clear_logs'));
        add_action('wp_ajax_ffb_refresh_allowed_states', array($this, 'ajax_refresh_allowed_states'));
        add_action('wp_ajax_ffb_refresh_allowed_countries', array($this, 'ajax_refresh_allowed_countries'));
        add_action('wp_ajax_ffb_refresh_blocked_messages', array($this, 'ajax_refresh_blocked_messages'));
        // The API usage AJAX handler was removed in v2.1.72
        
        // Add action for manual table creation
        add_action('admin_post_ffb_create_table', array($this, 'manual_create_table'));
    }
}

// Initialize the plugin
$formidable_forms_blocker = new FormidableFormsBlocker();

// Create the access log table when the plugin is activated
register_activation_hook(__FILE__, 'ffb_create_log_table');

function ffb_create_log_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'aqm_formidable_spam_blocker_log';
    
    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
        // Table already exists
        return;
    }
    
    // Create the table
    $charset_collate = $wpdb->get_charset_collate();
        
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        ip_address varchar(45) NOT NULL,
        country_code varchar(10),
        country varchar(100),
        region_name varchar(100),
        region varchar(100),
        city varchar(100),
        zip varchar(20),
        status varchar(20),
        reason text,
        form_id varchar(20),
        log_type varchar(20) DEFAULT 'form_load',
        geo_data text,
        PRIMARY KEY  (id),
        KEY ip_address (ip_address),
        KEY status (status),
        KEY log_type (log_type)
    ) $charset_collate;";
        
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
        
    // Update the DB version option to track that we've created the table
    update_option('ffb_db_version', '2.1.72');
    error_log('FFB Debug: Created access log table');
}

/**
 * Handle database migration for table name change
 */
function ffb_handle_db_migration() {
    global $wpdb;
    $old_table_name = $wpdb->prefix . 'aqm_ffb_access_log';
    $new_table_name = $wpdb->prefix . 'aqm_formidable_spam_blocker_log';
    
    // Check if old table exists
    $old_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$old_table_name'") === $old_table_name;
    
    // Check if new table exists
    $new_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$new_table_name'") === $new_table_name;
    
    // If old table exists but new one doesn't, rename it
    if ($old_table_exists && !$new_table_exists) {
        error_log('FFB Debug: Migrating database table from ' . $old_table_name . ' to ' . $new_table_name);
        $wpdb->query("RENAME TABLE $old_table_name TO $new_table_name");
        error_log('FFB Debug: Database table migration complete');
    }
    
    // Update DB version
    update_option('ffb_db_version', '2.1.72');
}

// Handle database migration on plugin load
add_action('plugins_loaded', 'ffb_handle_db_migration');

/**
 * Process settings form submission via admin-post.php
 */
function handle_save_settings() {
    // Debug log to track if this function is being called
    error_log('FFB Debug: Standalone handle_save_settings function called');
    error_log('FFB Debug: POST data: ' . print_r($_POST, true));
    
    // Verify capabilities
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    // Verify nonce
    if (!isset($_POST['ffb_nonce']) || !wp_verify_nonce($_POST['ffb_nonce'], 'ffb_save_settings')) {
        wp_die('Security check failed. Please refresh the page and try again.');
    }
    
    // Get redirect URL
    $redirect_url = isset($_POST['redirect_to']) ? esc_url_raw($_POST['redirect_to']) : admin_url('admin.php?page=ff-spam-blocker');
    
    // Save settings
    // Save API key
    if (isset($_POST['ffb_api_key'])) {
        update_option('ffb_api_key', sanitize_text_field($_POST['ffb_api_key']));
    }
    
    // Save approved countries
    if (isset($_POST['ffb_approved_countries'])) {
        $countries = array_map('sanitize_text_field', explode(',', $_POST['ffb_approved_countries']));
        $countries = array_map('trim', $countries);
        $countries = array_filter($countries);
        update_option('ffb_approved_countries', $countries);
    } else {
        update_option('ffb_approved_countries', array());
    }
    
    // Save approved states
    if (isset($_POST['ffb_approved_states'])) {
        $states = array_map('sanitize_text_field', explode(',', $_POST['ffb_approved_states']));
        $states = array_map('trim', $states);
        update_option('ffb_approved_states', $states);
    } else {
        update_option('ffb_approved_states', array());
    }
    
    // Save approved ZIP codes
    if (isset($_POST['ffb_approved_zip_codes'])) {
        $zip_codes = array_map('sanitize_text_field', explode(',', $_POST['ffb_approved_zip_codes']));
        $zip_codes = array_map('trim', $zip_codes);
        update_option('ffb_approved_zip_codes', $zip_codes);
    } else {
        update_option('ffb_approved_zip_codes', array());
    }
    
    // Save blocked message
    if (isset($_POST['ffb_blocked_message'])) {
        update_option('ffb_blocked_message', wp_kses_post($_POST['ffb_blocked_message']));
    }
    
    // Save IP whitelist
    if (isset($_POST['ffb_ip_whitelist'])) {
        $whitelist = explode("\n", $_POST['ffb_ip_whitelist']);
        $whitelist = array_map('trim', $whitelist);
        $whitelist = array_filter($whitelist);
        update_option('ffb_ip_whitelist', $whitelist);
    }
    
    // Save all forms selection
    update_option('ffb_block_all_forms', isset($_POST['ffb_block_all_forms']) ? '1' : '0');
    
    // Save specific forms
    if (isset($_POST['ffb_specific_forms'])) {
        update_option('ffb_specific_forms', $_POST['ffb_specific_forms']);
    } else {
        update_option('ffb_specific_forms', array());
    }
    
    // Save debug mode
    update_option('ffb_debug_mode', isset($_POST['ffb_debug_mode']) ? '1' : '0');
    
    // Save disable geolocation
    update_option('ffb_disable_geolocation', isset($_POST['ffb_disable_geolocation']) ? '1' : '0');
    
    // Save log access attempts
    update_option('ffb_log_access', isset($_POST['ffb_log_access']) ? '1' : '0');
    
    // Log settings update
    error_log('FFB Debug: Settings updated via admin-post.php');
    
    // Set transient to show success message
    set_transient('ffb_settings_saved', true, 30);
    
    // Define the redirect URL
    $redirect_url = admin_url('admin.php') . '?page=ff-spam-blocker&settings-updated=true';
    error_log('FFB Debug: About to redirect to: ' . $redirect_url);
    
    // Clean any output to prevent headers already sent issues
    if (ob_get_length()) {
        ob_end_clean();
    }
    
    // Try different redirect approaches
    if (!headers_sent()) {
        error_log('FFB Debug: Performing standard redirect using wp_safe_redirect');
        wp_safe_redirect($redirect_url);
        exit;
    } else {
        error_log('FFB Debug: Headers already sent, using JavaScript redirect');
        ?>
        <script type="text/javascript">
            window.location.href = "<?php echo $redirect_url; ?>";
        </script>
        <noscript><meta http-equiv="refresh" content="0;url=<?php echo $redirect_url; ?>"></noscript>
        <p>If you are not redirected automatically, please <a href="<?php echo $redirect_url; ?>">click here</a>.</p>
        <?php
        exit;
    }
}

// Register the standalone settings handler
// add_action('admin_post_ffb_save_settings', 'handle_save_settings');

// Clear update cache to prevent update notices
function aqm_clear_update_cache() {
    // Only run in admin
    if (!is_admin()) {
        return;
    }
    
    // Delete the transients that store update information
    delete_site_transient('update_plugins');
    delete_transient('update_plugins');
    
    // Clear the plugin update data
    wp_clean_plugins_cache();
}
// add_action('admin_init', 'aqm_clear_update_cache');

/**
 * Remove update notification for this plugin
 */
function aqm_remove_update_notifications($value) {
    if (isset($value) && is_object($value)) {
        // Get the current plugin basename
        $plugin_basename = plugin_basename(__FILE__);
        
        // Remove this plugin from the update list
        if (isset($value->response[$plugin_basename])) {
            unset($value->response[$plugin_basename]);
        }
    }
    return $value;
}
// add_filter('site_transient_update_plugins', 'aqm_remove_update_notifications');
