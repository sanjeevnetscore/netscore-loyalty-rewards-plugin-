<?php
// class-lrp-admin.php - updated
if (!defined('ABSPATH')) {
    exit;
}
class LRP_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_menu', [$this, 'add_loyalty_customers_submenu']);
        add_action('admin_menu', [$this, 'add_gift_card_users_submenu']);
        add_action('admin_init', [$this, 'handle_authentication']);
        add_action('admin_init', [$this, 'save_config']);
        add_action('admin_init', [$this, 'handle_gift_card_export']);
        add_action('admin_init', [$this, 'manually_create_tables']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_meta']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('admin_notices', [$this, 'admin_notices']);
        add_filter('woocommerce_product_data_tabs', [$this, 'add_loyalty_product_tab'], 99);
        add_action('woocommerce_product_data_panels', [$this, 'add_loyalty_product_tab_content']);
    }

    private function is_authenticated() {
        $user_id = get_current_user_id();
        return $user_id && get_user_meta($user_id, 'lrp_authenticated', true) === '1';
    }

    private function get_customer_type() {
        $user_id = get_current_user_id();
        return get_user_meta($user_id, 'lrp_customer_type', true);
    }

    private function is_license_expired() {
        global $wpdb;
        $user_id = get_current_user_id();
        $customer_type = $this->get_customer_type();
        $plan_end_date = '';

        // Helper local function: valid Y-m-d
        $is_valid_ymd = function($d) {
            if (!$d) return false;
            $dt = DateTime::createFromFormat('Y-m-d', $d);
            return $dt && $dt->format('Y-m-d') === $d;
        };

        if ($customer_type === 'netsuite') {
            $license_url = get_user_meta($user_id, 'lrp_license_url', true);
            if (!empty($license_url)) {
                $netsuite_table = $wpdb->prefix . 'lrp_netsuite_customers';
                $row = $wpdb->get_row($wpdb->prepare("SELECT plan_end_date FROM $netsuite_table WHERE license_url = %s LIMIT 1", esc_url_raw($license_url)));
                if ($row && !empty($row->plan_end_date) && $is_valid_ymd($row->plan_end_date)) {
                    $plan_end_date = $row->plan_end_date;
                }
            }
        } elseif ($customer_type === 'loyalty') {
            $username = get_user_meta($user_id, 'lrp_username', true);
            if (!empty($username)) {
                $loyalty_table = $wpdb->prefix . 'lrp_loyalty_customers';
                $row = $wpdb->get_row($wpdb->prepare("SELECT plan_end_date FROM $loyalty_table WHERE username = %s LIMIT 1", sanitize_text_field($username)));
                if ($row && !empty($row->plan_end_date)) {
                    $normalized_date = $this->normalize_date($row->plan_end_date);
                    if ($is_valid_ymd($normalized_date)) {
                        $plan_end_date = $normalized_date;
                    } else {
                        error_log('Invalid plan_end_date format for username ' . $username . ': ' . $row->plan_end_date);
                    }
                } else {
                    error_log('No plan_end_date found for username: ' . $username);
                }
            }
        }

        if (!$is_valid_ymd($plan_end_date)) {
            return true; // Treat unknown/invalid date as expired
        }

        $end_ts = strtotime($plan_end_date . ' 23:59:59 UTC');
        $now_ts = current_time('timestamp');
        return !($end_ts && $end_ts > $now_ts); // true = expired or invalid
    }

    private function restrict_access() {
        if (!$this->is_authenticated()) {
            $current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
            $loyalty_pages = ['lrp-loyalty-customers', 'lrp-gift-card-users'];
            if (in_array($current_page, $loyalty_pages, true)) {
                error_log('Redirecting to lrp-settings from page: ' . $current_page);
                set_transient('lrp_admin_error', 'Please log in to access the Loyalty Rewards dashboard.', 30);
                wp_safe_redirect(admin_url('admin.php?page=lrp-settings'));
                exit;
            }
            return false;
        }
        if ($this->is_license_expired()) {
            return false; // Block access to pages that call restrict_access()
        }
        return true;
    }

    private function is_valid_ymd($d) {
        if (!$d) return false;
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return $dt && $dt->format('Y-m-d') === $d;
    }

    private function normalize_date($val) {
        if (!isset($val)) return '';
        $val = trim((string)$val);
        if ($val === '' || $val === 'null' || $val === '0') return '';
        if (strpos($val, '0001-01-01') === 0) return '';
        if (preg_match('#/Date\((\d+)\)/#', $val, $m)) {
            $ms = (int)$m[1];
            $ts = (int) round($ms / 1000);
            return $ts > 0 ? gmdate('Y-m-d', $ts) : '';
        }
        if (ctype_digit($val)) {
            $n = (int)$val;
            if ($n <= 0) return '';
            if ($n > 20000000000) $n = (int) round($n / 1000);
            return gmdate('Y-m-d', $n);
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $val, $m)) {
            $d=(int)$m[1]; $mo=(int)$m[2]; $y=(int)$m[3];
            if (checkdate($mo,$d,$y)) return sprintf('%04d-%02d-%02d', $y,$mo,$d);
            if (checkdate($d,$mo,$y)) return sprintf('%04d-%02d-%02d', $y,$d,$mo);
        }
        $ts = strtotime($val);
        return ($ts !== false && $ts > 0) ? gmdate('Y-m-d', $ts) : '';
    }

    private function extract_ns_expiry(array $payload) {
        $preferred = ['expirydate','expiredate','expirationdate','enddate','planenddate','validtill','validuntil','validto','licenseexpiry','licenseend','licenseexpirydate'];
        foreach ($payload as $k => $v) {
            $key = strtolower((string)$k);
            foreach ($preferred as $needle) {
                if (strpos($key, $needle) !== false) {
                    $d = $this->normalize_date($v);
                    if ($this->is_valid_ymd($d)) return $d;
                }
            }
            if (is_array($v)) {
                $d = $this->extract_ns_expiry($v);
                if ($this->is_valid_ymd($d)) return $d;
            }
        }
        $best = ''; $bestTs = 0;
        $it = new RecursiveIteratorIterator(new RecursiveArrayIterator($payload));
        foreach ($it as $val) {
            $d = $this->normalize_date($val);
            if ($this->is_valid_ymd($d)) {
                $ts = strtotime($d . ' 00:00:00 UTC');
                if ($ts > $bestTs) { $bestTs = $ts; $best = $d; }
            }
        }
        return $best;
    }

    private function call_netsuite_license($url, $license_code, $product_code, $account_id, $override_token = '') {
        $basic_token = !empty($override_token) ? $override_token : get_option('lrp_netsuite_auth_token');
        if (empty($basic_token) && defined('LRP_NETSUITE_BASIC')) {
            $basic_token = LRP_NETSUITE_BASIC;
        }
        if (empty($basic_token)) {
            return [
                'ok' => false, 'code' => 0,
                'error' => 'Missing NetSuite Authorization token. Provide Auth Code on form or set lrp_netsuite_auth_token option/constant.',
                'raw' => '', 'json' => null,
            ];
        }
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . $basic_token,
        ];
        $body = [
            'licenseCode' => $license_code,
            'productCode' => $product_code,
            'accountId' => $account_id,
        ];
        $args = [
            'method' => 'POST',
            'headers' => $headers,
            'body' => wp_json_encode($body),
            'timeout' => 20,
            'redirection' => 3,
        ];
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'code' => 0,
                'error' => $response->get_error_message(),
                'raw' => '',
                'json' => null,
            ];
        }
        $code = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);
        return [
            'ok' => ($code >= 200 && $code < 300),
            'code' => $code,
            'error' => ($code >= 200 && $code < 300) ? '' : 'HTTP ' . $code,
            'raw' => $raw,
            'json' => $json,
        ];
    }

    public function handle_authentication() {
        global $wpdb;
        $user_id = get_current_user_id();
        if (!$user_id) {
            set_transient('lrp_admin_error', 'Please log in to WordPress to access the Loyalty Rewards dashboard.', 30);
            wp_safe_redirect(admin_url('admin.php?page=lrp-settings'));
            exit;
        }

        // Allow clearing stored NetSuite token
        if (isset($_GET['clear_lrp_ns_token']) && $_GET['clear_lrp_ns_token'] === '1' && current_user_can('manage_options')) {
            if (!wp_verify_nonce($_REQUEST['_wpnonce'] ?? '', 'lrp_clear_ns_token')) {
                set_transient('lrp_admin_error', 'Invalid request to clear token.', 10);
            } else {
                delete_option('lrp_netsuite_auth_token');
                set_transient('lrp_admin_notice', 'Saved NetSuite Auth Code cleared.', 10);
            }
            wp_safe_redirect(admin_url('admin.php?page=lrp-settings'));
            exit;
        }

        if (isset($_POST['lrp_auth_nonce']) && wp_verify_nonce($_POST['lrp_auth_nonce'], 'lrp_auth_nonce')) {
            $customer_type = isset($_POST['customer_type']) ? sanitize_text_field($_POST['customer_type']) : '';
            $errors = [];
            if ($customer_type === 'netsuite') {
                $auth_code = isset($_POST['auth_code']) ? sanitize_text_field($_POST['auth_code']) : '';
                $license_key = isset($_POST['license_key']) ? sanitize_text_field($_POST['license_key']) : '';
                $product_code = isset($_POST['product_code']) ? sanitize_text_field($_POST['product_code']) : '';
                $account_id = isset($_POST['account_id']) ? sanitize_text_field($_POST['account_id']) : '';
                $license_url = isset($_POST['license_url']) && !empty($_POST['license_url']) ? esc_url_raw($_POST['license_url']) : 'https://license.netscoretech.com/api/Account/GetLicenseDeatails';
                if (empty($license_key) || empty($product_code) || empty($account_id) || empty($license_url)) {
                    $errors[] = 'All NetSuite fields are required.';
                } else {
                    $table_name = $wpdb->prefix . 'lrp_netsuite_customers';
                    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
                        $errors[] = 'NetSuite customers table does not exist. Please reactivate the plugin or create tables manually.';
                    } else {
                        $api = $this->call_netsuite_license($license_url, $license_key, $product_code, $account_id, $auth_code);
                        if (!$api['ok']) {
                            $details = $api['error'];
                            if (!empty($api['raw'])) $details .= ' - ' . substr($api['raw'], 0, 300);
                            $errors[] = 'NetSuite validation failed: ' . $details;
                        } else {
                            $payload = is_array($api['json']) ? $api['json'] : [];
                            $plan_end_date = $this->extract_ns_expiry($payload);
                            $existing_id = $wpdb->get_var($wpdb->prepare(
                                "SELECT id FROM $table_name WHERE license_key = %s AND product_code = %s AND account_id = %s AND license_url = %s",
                                $license_key, $product_code, $account_id, $license_url
                            ));
                            $row = [
                                'license_key' => $license_key,
                                'product_code' => $product_code,
                                'account_id' => $account_id,
                                'license_url' => $license_url,
                                'plan_end_date' => $plan_end_date,
                                'updated_at' => current_time('mysql'),
                            ];
                            if ($existing_id) {
                                $wpdb->update($table_name, $row, ['id' => (int) $existing_id]);
                            } else {
                                $row['created_at'] = current_time('mysql');
                                $wpdb->insert($table_name, $row);
                            }
                            if (!empty($auth_code)) {
                                update_option('lrp_netsuite_auth_token', $auth_code);
                            }
                            update_user_meta($user_id, 'lrp_authenticated', '1');
                            update_user_meta($user_id, 'lrp_customer_type', 'netsuite');
                            update_user_meta($user_id, 'lrp_license_key', $license_key);
                            update_user_meta($user_id, 'lrp_product_code', $product_code);
                            update_user_meta($user_id, 'lrp_account_id', $account_id);
                            update_user_meta($user_id, 'lrp_license_url', $license_url);
                            update_user_meta($user_id, 'lrp_plan_end_date', $plan_end_date);
                            $msg = 'Authentication successful with NetSuite.';
                            if ($plan_end_date) $msg .= ' Plan End: ' . $plan_end_date . '.';
                            set_transient('lrp_admin_notice', $msg, 30);
                            wp_safe_redirect(admin_url('admin.php?page=lrp-settings'));
                            exit;
                        }
                    }
                }
            } elseif ($customer_type === 'loyalty') {
                $license_key = sanitize_text_field($_POST['license_key']);
                $username = sanitize_text_field($_POST['username']);
                $password = $_POST['password'];
                if (empty($license_key) || empty($username) || empty($password)) {
                    $errors[] = 'All Loyalty Customer fields are required.';
                } else {
                    $table_name = $wpdb->prefix . 'lrp_loyalty_customers';
                    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
                        $errors[] = 'Loyalty customers table does not exist. Please reactivate the plugin or create tables manually.';
                    } else {
                        $result = $wpdb->get_row($wpdb->prepare(
                            "SELECT * FROM $table_name WHERE license_key = %s AND username = %s",
                            $license_key, $username
                        ));
                        if ($result && wp_check_password($password, $result->password)) {
                            $plan_end_date = $result->plan_end_date;
                            if (!empty($plan_end_date) && !$this->is_valid_ymd($plan_end_date)) {
                                $normalized_date = $this->normalize_date($plan_end_date);
                                if ($this->is_valid_ymd($normalized_date)) {
                                    $plan_end_date = $normalized_date;
                                } else {
                                    error_log('Invalid plan_end_date format for username ' . $username . ': ' . $plan_end_date);
                                    $plan_end_date = '';
                                }
                            }
                            update_user_meta($user_id, 'lrp_authenticated', '1');
                            update_user_meta($user_id, 'lrp_customer_type', 'loyalty');
                            update_user_meta($user_id, 'lrp_license_key', $license_key);
                            update_user_meta($user_id, 'lrp_username', $username);
                            update_user_meta($user_id, 'lrp_plan_end_date', $plan_end_date);
                            set_transient('lrp_admin_notice', 'Authentication successful. Welcome to the Loyalty Rewards dashboard.', 30);
                            wp_safe_redirect(admin_url('admin.php?page=lrp-settings'));
                            exit;
                        } else {
                            $errors[] = 'Credentials are not matched.';
                        }
                    }
                }
            } else {
                $errors[] = 'Please select a customer type.';
            }
            if (!empty($errors)) {
                set_transient('lrp_admin_error', implode(' ', $errors), 30);
                wp_safe_redirect(admin_url('admin.php?page=lrp-settings'));
                exit;
            }
        }

        if (isset($_GET['logout']) && $_GET['logout'] == '1') {
            delete_user_meta($user_id, 'lrp_authenticated');
            delete_user_meta($user_id, 'lrp_customer_type');
            delete_user_meta($user_id, 'lrp_license_key');
            delete_user_meta($user_id, 'lrp_username');
            delete_user_meta($user_id, 'lrp_product_code');
            delete_user_meta($user_id, 'lrp_account_id');
            delete_user_meta($user_id, 'lrp_license_url');
            delete_user_meta($user_id, 'lrp_plan_end_date');
            set_transient('lrp_admin_notice', 'You have been logged out.', 30);
            wp_safe_redirect(admin_url('admin.php?page=lrp-settings'));
            exit;
        }
    }

    public function add_admin_menu() {
        add_menu_page(
            'Loyalty Rewards',
            'Loyalty Rewards',
            'manage_options',
            'lrp-settings',
            [$this, 'settings_page'],
            'dashicons-awards',
            20
        );
    }

    public function add_loyalty_customers_submenu() {
        add_submenu_page(
            'lrp-settings',
            'Loyalty Customers',
            'Loyalty Customers',
            'manage_options',
            'lrp-loyalty-customers',
            [$this, 'display_loyalty_customers_table']
        );
    }

    public function add_gift_card_users_submenu() {
        add_submenu_page(
            'lrp-settings',
            'Gift Card Users',
            'Gift Card Users',
            'manage_options',
            'lrp-gift-card-users',
            [$this, 'display_gift_card_users_table']
        );
    }

    public function create_tables() {
        $activator = new LRP_Activator();
        $activator->activate();
        set_transient('lrp_admin_notice', 'Attempted to create database tables. Check error logs for details.', 30);
    }

    public function manually_create_tables() {
        if ($this->restrict_access() && isset($_GET['lrp_create_tables'])) {
            $this->create_tables();
            wp_safe_redirect(admin_url('admin.php?page=lrp-settings'));
            exit;
        }
    }

    /**
     * New helper: determine user's tier name from meta or by computing from points and tiers table.
     *
     * @param int $user_id
     * @return string
     */
    private function get_user_tier_name($user_id) {
        global $wpdb;

        // Try common meta keys first (backwards compatibility)
        $meta_keys = ['tier_type', 'lrp_tier', 'lrp_tier_type', 'tier'];
        foreach ($meta_keys as $key) {
            $val = get_user_meta($user_id, $key, true);
            if ($val !== '' && $val !== null) {
                return sanitize_text_field($val);
            }
        }

        // Fallback: compute from available_points (or total_points)
        $available = get_user_meta($user_id, 'available_points', true);
        if ($available === '' || !is_numeric($available)) {
            $available = get_user_meta($user_id, 'total_points', true);
        }
        $points = (int) max(0, $available);

        // If zero points, return empty — no tier
        if ($points <= 0) {
            return '';
        }

        $tiers_table = $wpdb->prefix . 'lrp_loyalty_tiers';
        // Prefer active tiers
        $tiers = $wpdb->get_results("SELECT name, threshold, active FROM $tiers_table WHERE active = 1 ORDER BY threshold DESC");
        if (empty($tiers)) {
            // fallback to any tiers
            $tiers = $wpdb->get_results("SELECT name, threshold FROM $tiers_table ORDER BY threshold DESC");
        }

        foreach ($tiers as $tier) {
            $threshold = isset($tier->threshold) ? intval($tier->threshold) : 0;
            if ($points >= $threshold) {
                return sanitize_text_field($tier->name);
            }
        }

        return '';
    }

    public function display_loyalty_customers_table() {
        if ($this->is_license_expired()) {
            echo '<div class="wrap"><h1>Loyalty Customers</h1>';
            $this->show_license_renewal_block();
            echo '</div>';
            return;
        }
        if (!$this->restrict_access()) {
            return;
        }
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 10;
        $offset = ($paged - 1) * $per_page;
        $user_query = new WP_User_Query([
            'role' => 'customer',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'paying_customer',
                    'value' => 1,
                    'compare' => '='
                ],
                [
                    'key' => 'total_points',
                    'value' => 0,
                    'compare' => '>'
                ],
            ],
            'number' => $per_page,
            'offset' => $offset,
        ]);
        $total_users = $user_query->get_total();
        $max_pages = ceil($total_users / $per_page);
        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">Loyalty Customers</h1>';
        if (!empty($user_query->results)) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>S.No</th><th>Customer Name</th><th>Customer Mail ID</th><th>Total Earned Points</th><th>Total Redeemed Points</th><th>Available Points</th><th>DOB</th><th>Anniversary</th><th>Tier Type</th></tr></thead>';
            echo '<tbody>';
            $sno = $offset + 1;
            foreach ($user_query->results as $user) {
                $name = $user->display_name ?: $user->user_nicename;
                $email = $user->user_email;
                $earned = get_user_meta($user->ID, 'total_points', true) ?: 0;
                $redeemed = get_user_meta($user->ID, 'redeemed_points', true) ?: 0;
                $available = get_user_meta($user->ID, 'available_points', true) ?: 0;
                $dob = get_user_meta($user->ID, 'birthday', true) ?: '';
                $anniversary = get_user_meta($user->ID, 'anniversary', true) ?: '';
                // Use helper to resolve tier reliably
                $tier_type = $this->get_user_tier_name($user->ID);
                echo '<tr>';
                echo '<td>' . esc_html($sno) . '</td>';
                echo '<td>' . esc_html($name) . '</td>';
                echo '<td>' . esc_html($email) . '</td>';
                echo '<td>' . esc_html($earned) . '</td>';
                echo '<td>' . esc_html($redeemed) . '</td>';
                echo '<td>' . esc_html($available) . '</td>';
                echo '<td>' . esc_html($dob) . '</td>';
                echo '<td>' . esc_html($anniversary) . '</td>';
                echo '<td>' . esc_html($tier_type) . '</td>';
                echo '</tr>';
                $sno++;
            }
            echo '</tbody></table>';
            $pagination_args = [
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => __('&laquo; Previous'),
                'next_text' => __('Next &raquo;'),
                'total' => $max_pages,
                'current' => $paged,
            ];
            echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links($pagination_args) . '</div></div>';
        } else {
            echo '<p>No loyalty customers found.</p>';
        }
        echo '</div>';
    }

    public function display_gift_card_users_table() {
        if ($this->is_license_expired()) {
            echo '<div class="wrap"><h1>Gift Card Users</h1>';
            $this->show_license_renewal_block();
            echo '</div>';
            return;
        }
        if (!$this->restrict_access()) {
            return;
        }
        global $wpdb;
        $table_name = $wpdb->prefix . 'lrp_gift_cards';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            echo '<div class="wrap">';
            echo '<h1 class="wp-heading-inline">Gift Card Users</h1>';
            echo '<div class="notice notice-error is-dismissible"><p>Database table ' . esc_html($table_name) . ' does not exist. <a href="' . esc_url(add_query_arg('lrp_create_tables', '1')) . '">Create tables now</a>.</p></div>';
            echo '</div>';
            return;
        }
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 10;
        $offset = ($paged - 1) * $per_page;
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT gc.*, u.display_name, u.user_email
             FROM $table_name gc
             LEFT JOIN {$wpdb->users} u ON gc.user_id = u.ID
             ORDER BY gc.created_date DESC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $max_pages = ceil($total_items / $per_page);
        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">Gift Card Users</h1>';
        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';
        echo '<a href="' . esc_url(add_query_arg('export', 'gift_cards')) . '" class="button">Export to CSV</a>';
        echo '</div>';
        echo '</div>';
        if (!empty($results)) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>S.No</th><th>Username</th><th>Email</th><th>Gift Card Sent Email</th><th>Gift Card Number</th><th>Created Date</th><th>Expiry Date</th></tr></thead>';
            echo '<tbody>';
            $sno = $offset + 1;
            foreach ($results as $row) {
                $username = $row->display_name ?: 'N/A';
                $email = $row->user_email ?: 'N/A';
                $sent_email = $row->sent_email ?: 'N/A';
                $gift_card_number = $row->gift_card_number ?: 'N/A';
                $created_date = $row->created_date ? date_i18n(get_option('date_format'), strtotime($row->created_date)) : 'N/A';
                $expiry_date = $row->expiry_date ? date_i18n(get_option('date_format'), strtotime($row->expiry_date)) : 'N/A';
                echo '<tr>';
                echo '<td>' . esc_html($sno) . '</td>';
                echo '<td>' . esc_html($username) . '</td>';
                echo '<td>' . esc_html($email) . '</td>';
                echo '<td>' . esc_html($sent_email) . '</td>';
                echo '<td>' . esc_html($gift_card_number) . '</td>';
                echo '<td>' . esc_html($created_date) . '</td>';
                echo '<td>' . esc_html($expiry_date) . '</td>';
                echo '</tr>';
                $sno++;
            }
            echo '</tbody></table>';
            $pagination_args = [
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => __('&laquo; Previous'),
                'next_text' => __('Next &raquo;'),
                'total' => $max_pages,
                'current' => $paged,
            ];
            echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links($pagination_args) . '</div></div>';
        } else {
            echo '<p>No gift card users found.</p>';
        }
        echo '</div>';
    }

    public function handle_gift_card_export() {
        if ($this->is_license_expired()) {
            if (isset($_GET['export']) && $_GET['export'] === 'gift_cards') {
                set_transient('lrp_admin_error', 'License expired — export disabled. Please renew license and contact NetScore support.', 30);
                wp_safe_redirect(admin_url('admin.php?page=lrp-gift-card-users'));
                exit;
            }
            return;
        }
        if (!$this->restrict_access()) {
            return;
        }
        if (isset($_GET['export']) && $_GET['export'] === 'gift_cards') {
            global $wpdb;
            $table_name = $wpdb->prefix . 'lrp_gift_cards';
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
                set_transient('lrp_admin_error', 'Error: Database table ' . esc_html($table_name) . ' does not exist. <a href="' . esc_url(add_query_arg('lrp_create_tables', '1')) . '">Create tables now</a>.', 30);
                wp_safe_redirect(admin_url('admin.php?page=lrp-gift-card-users'));
                exit;
            }
            $results = $wpdb->get_results(
                "SELECT gc.*, u.display_name, u.user_email
                 FROM $table_name gc
                 LEFT JOIN {$wpdb->users} u ON gc.user_id = u.ID
                 ORDER BY gc.created_date DESC"
            );
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=gift_card_users_' . date('Y-m-d_H-i-s') . '.csv');
            $output = fopen('php://output', 'w');
            fputcsv($output, ['S.No', 'Username', 'Email', 'Gift Card Sent Email', 'Gift Card Number', 'Created Date', 'Expiry Date']);
            $sno = 1;
            foreach ($results as $row) {
                $username = $row->display_name ?: 'N/A';
                $email = $row->user_email ?: 'N/A';
                $sent_email = $row->sent_email ?: 'N/A';
                $gift_card_number = $row->gift_card_number ?: 'N/A';
                $created_date = $row->created_date ? date_i18n(get_option('date_format'), strtotime($row->created_date)) : 'N/A';
                $expiry_date = $row->expiry_date ? date_i18n(get_option('date_format'), strtotime($row->expiry_date)) : 'N/A';
                fputcsv($output, [
                    $sno,
                    $username,
                    $email,
                    $sent_email,
                    $gift_card_number,
                    $created_date,
                    $expiry_date
                ]);
                $sno++;
            }
            fclose($output);
            exit;
        }
    }

    public function enqueue_admin_scripts($hook) {
        if (in_array($hook, ['toplevel_page_lrp-settings', 'product_page_product_attributes', 'loyalty-rewards_page_lrp-loyalty-customers', 'loyalty-rewards_page_lrp-gift-card-users', 'post.php', 'post-new.php'], true)) {
            wp_enqueue_style('lrp-admin-styles', LRP_PLUGIN_URL . 'assets/css/lrp-admin.css', [], '1.2.0');
            wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css', [], '5.15.4');
            wp_enqueue_script('lrp-admin-script', LRP_PLUGIN_URL . 'assets/js/lrp-admin.js', ['jquery'], '1.2.0', true);
            wp_localize_script('lrp-admin-script', 'lrp_admin_params', [
                'nonce' => wp_create_nonce('lrp_nonce'),
                'ajax_url' => admin_url('admin-ajax.php')
            ]);
        }
    }

    public function admin_notices() {
        $screen = get_current_screen();
        if (in_array($screen->id, ['toplevel_page_lrp-settings', 'loyalty-rewards_page_lrp-loyalty-customers', 'loyalty-rewards_page_lrp-gift-card-users', 'product'], true)) {
            if ($message = get_transient('lrp_admin_notice')) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
                delete_transient('lrp_admin_notice');
            } elseif ($error = get_transient('lrp_admin_error')) {
                echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses_post($error) . '</p></div>';
                delete_transient('lrp_admin_error');
            }
        }
    }

    private function show_license_renewal_block() {
        echo '<div class="notice notice-warning" style="padding:20px;margin-bottom:20px;">';
        echo '<h2 style="margin:0 0 6px 0">Loyalty Rewards - License Expired</h2>';
        echo '<p style="margin:0 0 6px 0">Your NetSuite license has expired or is not valid. Loyalty functionality is disabled until you renew.</p>';
        echo '<p style="margin:0">Please renew your license and contact <a href="mailto:support@netscoretech.com">NetScore support</a> for assistance.</p>';
        echo '</div>';
    }

    public function settings_page() {
        global $wpdb;
        $user_id = get_current_user_id();
        $tables = [
            'app_config' => $wpdb->prefix . 'lrp_app_configurations',
            'points_config' => $wpdb->prefix . 'lrp_points_configurations',
            'threshold_config' => $wpdb->prefix . 'lrp_threshold_configurations',
            'loyalty_tiers' => $wpdb->prefix . 'lrp_loyalty_tiers',
            'social_share_config' => $wpdb->prefix . 'lrp_social_share_configurations',
            'netsuite_customers' => $wpdb->prefix . 'lrp_netsuite_customers',
            'loyalty_customers' => $wpdb->prefix . 'lrp_loyalty_customers'
        ];
        foreach ($tables as $key => $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
                add_action('admin_notices', function() use ($table) {
                    echo '<div class="notice notice-error is-dismissible"><p>Database table ' . esc_html($table) . ' does not exist. <a href="' . esc_url(add_query_arg('lrp_create_tables', '1')) . '">Create tables now</a>.</p></div>';
                });
            }
        }

        if (!$this->is_authenticated()) {
            ?>
            <div class="wrap">
                <h1>Loyalty Rewards Login</h1>
                <div class="lrp-admin-container" style="display: block !important;">
                    <table class="form-table">
                        <tr>
                            <th><label for="netsuite_customer">Existing NetSuite Customer</label></th>
                            <td><input type="checkbox" id="netsuite_customer" name="customer_type_netsuite" value="netsuite"></td>
                        </tr>
                        <tr>
                            <th><label for="loyalty_customer">Existing Loyalty Customer</label></th>
                            <td><input type="checkbox" id="loyalty_customer" name="customer_type_loyalty" value="loyalty"></td>
                        </tr>
                    </table>
                    <form method="post" action="" id="netsuite_form" style="display: none;">
                        <?php wp_nonce_field('lrp_auth_nonce', 'lrp_auth_nonce'); ?>
                        <input type="hidden" name="customer_type" value="netsuite">
                        <h2>NetSuite Customer Login</h2>
                        <table class="form-table">
                            <tr>
                                <th><label for="auth_code">Auth Code (Base64)</label></th>
                                <td>
                                    <input type="text" id="auth_code" name="auth_code" value="" placeholder="" />
                                    <?php if (get_option('lrp_netsuite_auth_token')): ?>
                                        <?php
                                            $clear_url = wp_nonce_url( add_query_arg('clear_lrp_ns_token', '1', admin_url('admin.php?page=lrp-settings')), 'lrp_clear_ns_token' );
                                        ?>
                                        <p><strong>Saved token present.</strong> <a href="<?php echo esc_url($clear_url); ?>">Clear saved token</a></p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="license_key_netsuite">License Key</label></th>
                                <td><input type="text" id="license_key_netsuite" name="license_key" required></td>
                            </tr>
                            <tr>
                                <th><label for="product_code">Product Code</label></th>
                                <td><input type="text" id="product_code" name="product_code" required></td>
                            </tr>
                            <tr>
                                <th><label for="account_id">Account ID</label></th>
                                <td><input type="text" id="account_id" name="account_id" required></td>
                            </tr>
                            <tr>
                                <th><label for="license_url">License URL</label></th>
                                <td><input type="url" id="license_url" name="license_url" value="" required></td>
                            </tr>
                        </table>
                        <div id="submit-section" style="display: none;">
                            <?php submit_button('Login'); ?>
                        </div>
                    </form>
                    <form method="post" action="" id="loyalty_form" style="display: none;">
                        <?php wp_nonce_field('lrp_auth_nonce', 'lrp_auth_nonce'); ?>
                        <input type="hidden" name="customer_type" value="loyalty">
                        <h2>Loyalty Customer Login</h2>
                        <table class="form-table">
                            <tr>
                                <th><label for="license_key_loyalty">License Key</label></th>
                                <td><input type="text" id="license_key_loyalty" name="license_key" required></td>
                            </tr>
                            <tr>
                                <th><label for="username">Username</th>
                                <td><input type="text" id="username" name="username" required></td>
                            </tr>
                            <tr>
                                <th><label for="password">Password</label></th>
                                <td><input type="password" id="password" name="password" required></td>
                            </tr>
                        </table>
                        <div id="submit-section" style="display: none;">
                            <?php submit_button('Login'); ?>
                        </div>
                    </form>
                </div>
            </div>
            <script>
            jQuery(document).ready(function($) {
                $('#netsuite_customer').on('change', function() {
                    if ($(this).is(':checked')) {
                        $('#loyalty_customer').prop('checked', false);
                        $('#netsuite_form').show();
                        $('#loyalty_form').hide();
                        $('#netsuite_form input[name="customer_type"]').val('netsuite');
                    } else {
                        $('#netsuite_form').hide();
                    }
                });
                $('#loyalty_customer').on('change', function() {
                    if ($(this).is(':checked')) {
                        $('#netsuite_customer').prop('checked', false);
                        $('#loyalty_form').show();
                        $('#netsuite_form').hide();
                        $('#loyalty_form input[name="customer_type"]').val('loyalty');
                    } else {
                        $('#loyalty_form').hide();
                    }
                });
                $('#netsuite_form, #loyalty_form').on('show', function() {
                    $(this).find('#submit-section').show();
                });
                $('#netsuite_customer, #loyalty_customer').on('change', function() {
                    $('#submit-section').show();
                });
            });
            </script>
            <?php
            return;
        }

        $app_config_table = $wpdb->prefix . 'lrp_app_configurations';
        $points_config_table = $wpdb->prefix . 'lrp_points_configurations';
        $threshold_config_table = $wpdb->prefix . 'lrp_threshold_configurations';
        $loyalty_tiers_table = $wpdb->prefix . 'lrp_loyalty_tiers';
        $social_share_config_table = $wpdb->prefix . 'lrp_social_share_configurations';
        $app_config = $wpdb->get_row("SELECT * FROM $app_config_table LIMIT 1");
        if (!$app_config) {
            error_log('No app config row found in ' . $app_config_table . '. Reinserting defaults.');
            $wpdb->insert($app_config_table, [
                'customer_signup_points' => 50,
                'product_review_points' => 10,
                'referral_points' => 50,
                'birthday_points' => 25,
                'anniversary_points' => 25
            ]);
            $app_config = $wpdb->get_row("SELECT * FROM $app_config_table LIMIT 1");
        }
        $points_config = $wpdb->get_row("SELECT * FROM $points_config_table LIMIT 1");
        if (!$points_config) {
            error_log('No points config row found in ' . $points_config_table . '. Reinserting defaults.');
            $wpdb->insert($points_config_table, [
                'each_point_value' => 10,
                'loyalty_point_value'=> 1.00
            ]);
            $points_config = $wpdb->get_row("SELECT * FROM $points_config_table LIMIT 1");
        }
        $threshold_config = $wpdb->get_row("SELECT * FROM $threshold_config_table LIMIT 1");
        if (!$threshold_config) {
            error_log('No threshold config row found in ' . $threshold_config_table . '. Reinserting defaults.');
            $wpdb->insert($threshold_config_table, [
                'minimum_redemption_points' => 100
            ]);
            $threshold_config = $wpdb->get_row("SELECT * FROM $threshold_config_table LIMIT 1");
        }
        $loyalty_tiers = $wpdb->get_results("SELECT * FROM $loyalty_tiers_table ORDER BY level ASC");
        if (empty($loyalty_tiers)) {
            error_log('No loyalty tiers rows found in ' . $loyalty_tiers_table . '. Reinserting defaults.');
            $defaults = [
                ['name' => 'Silver', 'threshold' => 0, 'points' => 2.00, 'level' => 1, 'active' => 0],
                ['name' => 'Gold', 'threshold' => 1000, 'points' => 2.00, 'level' => 2, 'active' => 0],
                ['name' => 'Platinum', 'threshold' => 10000, 'points' => 2.00, 'level' => 3, 'active' => 0]
            ];
            foreach ($defaults as $default) {
                $wpdb->insert($loyalty_tiers_table, $default);
            }
            $loyalty_tiers = $wpdb->get_results("SELECT * FROM $loyalty_tiers_table ORDER BY level ASC");
        }
        $social_share_config = $wpdb->get_row("SELECT * FROM $social_share_config_table LIMIT 1");
        if (!$social_share_config) {
            error_log('No social share config row found in ' . $social_share_config_table . '. Reinserting defaults.');
            $wpdb->insert($social_share_config_table, [
                'email_share_points' => 20,
                'facebook_share_points' => 20
            ]);
            $social_share_config = $wpdb->get_row("SELECT * FROM $social_share_config_table LIMIT 1");
        }

        // ---------------- License expiry display ----------------
        $user_id = get_current_user_id();
        $customer_type = $this->get_customer_type();
        $plan_end_date = '';
        $user_data = [];
        if ($customer_type === 'netsuite') {
    $user_data = [
        'license_key' => get_user_meta($user_id, 'lrp_license_key', true),
        'product_code' => get_user_meta($user_id, 'lrp_product_code', true),
        'account_id' => get_user_meta($user_id, 'lrp_account_id', true),
        'license_url' => get_user_meta($user_id, 'lrp_license_url', true),
    ];
    // Try user meta first
    $plan_end_date = get_user_meta($user_id, 'lrp_plan_end_date', true);
    if (!$this->is_valid_ymd($plan_end_date)) {
        // Fallback to database query
        $netsuite_table = $wpdb->prefix . 'lrp_netsuite_customers';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT plan_end_date FROM $netsuite_table WHERE license_key = %s AND product_code = %s AND account_id = %s AND license_url = %s LIMIT 1",
            $user_data['license_key'], $user_data['product_code'], $user_data['account_id'], $user_data['license_url']
        ));
        if ($row && !empty($row->plan_end_date) && $this->is_valid_ymd($row->plan_end_date)) {
            $plan_end_date = $row->plan_end_date;
            update_user_meta($user_id, 'lrp_plan_end_date', $plan_end_date);
        } else {
            error_log('No valid plan_end_date found in wp_lrp_netsuite_customers for license_key: ' . $user_data['license_key']);
        }
    }
        } elseif ($customer_type === 'loyalty') {
            $user_data = [
                'license_key' => get_user_meta($user_id, 'lrp_license_key', true),
                'username' => get_user_meta($user_id, 'lrp_username', true),
            ];
            $username = !empty($user_data['username']) ? sanitize_text_field($user_data['username']) : '';
            if ($username) {
                $loyalty_table = $wpdb->prefix . 'lrp_loyalty_customers';
                $row = $wpdb->get_row($wpdb->prepare("SELECT plan_end_date FROM $loyalty_table WHERE username = %s LIMIT 1", $username));
                error_log('Loyalty customer query for username ' . $username . ': ' . print_r($row, true));
                if ($row && !empty($row->plan_end_date)) {
                    $normalized_date = $this->normalize_date($row->plan_end_date);
                    if ($this->is_valid_ymd($normalized_date)) {
                        $plan_end_date = $normalized_date;
                        update_user_meta($user_id, 'lrp_plan_end_date', $plan_end_date);
                    } else {
                        error_log('Invalid plan_end_date format for username ' . $username . ': ' . $row->plan_end_date);
                    }
                } else {
                    error_log('No plan_end_date found for username: ' . $username);
                }
            }
        }
        $user_data['plan_end_date'] = $plan_end_date;
        $days_remaining = 'Unknown';
        $formatted_end_date = 'Not Set';
        if ($this->is_valid_ymd($plan_end_date)) {
            $end_ts = strtotime($plan_end_date . ' 23:59:59 UTC');
            $now_ts = current_time('timestamp');
            if ($end_ts && $end_ts > $now_ts) {
                $days_remaining = (int) ceil(($end_ts - $now_ts) / (60 * 60 * 24));
            } else {
                $days_remaining = 'Expired';
            }
            $formatted_end_date = date_i18n(get_option('date_format'), strtotime($plan_end_date));
        }
        // --------------------------------------------------------
        ?>
        <div class="wrap">
            <h1>Loyalty Rewards Settings</h1>
             <p><a href="<?php echo esc_url(add_query_arg('logout', '1')); ?>" class="button">Logout</a></p> 
            <?php
            $is_expired = $this->is_license_expired();
            if ($is_expired) {
                echo '<div class="notice notice-warning" style="padding:18px;margin-bottom:20px;">';
                echo '<strong>License Expired:</strong> Loyalty features are currently disabled. ';
                echo 'Please renew your license and contact <a href="mailto:support@netscoretech.com">NetScore support</a>.';
                echo '</div>';
            }
            ?>
            <div class="lrp-license-expiration <?php echo esc_attr($days_remaining === 'Expired' ? 'expired' : ($days_remaining === 'Unknown' ? 'unknown' : 'valid')); ?>">
                <p><?php echo esc_html($days_remaining === 'Expired' ? 'License Expired' : ($days_remaining === 'Unknown' ? 'License End Date Not Set' : "Your License Expiring in $days_remaining days")); ?></p>
            </div>
            <div class="lrp-admin-container">
                <div class="lrp-nav-tabs">
                    <a href="#user-details" class="lrp-tab active">User Details</a>
                    <a href="#app-config" class="lrp-tab">App Configurations</a>
                    <a href="#points-config" class="lrp-tab">Points Configurations</a>
                    <a href="#threshold-config" class="lrp-tab">Threshold Configurations</a>
                    <a href="#loyalty-tiers" class="lrp-tab">Loyalty Tiers</a>
                    <a href="#social-share-config" class="lrp-tab">Social Share Configurations</a>
                </div>
                <div class="lrp-tab-content" style="position:relative;">
                    <div id="user-details" class="lrp-tab-pane active">
                        <div class="lrp-card">
                            <h2>User Details</h2>
                            <table class="form-table">
                                <?php if ($customer_type === 'loyalty') { ?>
                                    <tr>
                                        <th>License Key</th>
                                        <td>
                                            <span id="license_key" data-value="<?php echo esc_attr($user_data['license_key'] ?? ''); ?>" data-hidden="true">****</span>
                                            <span class="toggle-visibility" data-field="license_key"><i class="fas fa-eye"></i></span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Username</th>
                                        <td>
                                            <span id="username" data-value="<?php echo esc_attr($user_data['username'] ?? ''); ?>" data-hidden="true">****</span>
                                            <span class="toggle-visibility" data-field="username"><i class="fas fa-eye"></i></span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Plan End Date</th>
                                        <td><?php echo esc_html($formatted_end_date); ?></td>
                                    </tr>
                                <?php } elseif ($customer_type === 'netsuite') { ?>
                                    <tr>
                                        <th>License Key</th>
                                        <td>
                                            <span id="license_key" data-value="<?php echo esc_attr($user_data['license_key'] ?? ''); ?>" data-hidden="true">****</span>
                                            <span class="toggle-visibility" data-field="license_key"><i class="fas fa-eye"></i></span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Product Code</th>
                                        <td>
                                            <span id="product_code" data-value="<?php echo esc_attr($user_data['product_code'] ?? ''); ?>" data-hidden="true">****</span>
                                            <span class="toggle-visibility" data-field="product_code"><i class="fas fa-eye"></i></span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Account ID</th>
                                        <td>
                                            <span id="account_id" data-value="<?php echo esc_attr($user_data['account_id'] ?? ''); ?>" data-hidden="true">****</span>
                                            <span class="toggle-visibility" data-field="account_id"><i class="fas fa-eye"></i></span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>License URL</th>
                                        <td><?php echo esc_url($user_data['license_url'] ?? ''); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Plan End Date</th>
                                        <td><?php echo esc_html($formatted_end_date); ?></td>
                                    </tr>
                                <?php } else { ?>
                                    <tr><td colspan="2">No user details — please log in above.</td></tr>
                                <?php } ?>
                            </table>
                        </div>
                    </div>
                    <div id="app-config" class="lrp-tab-pane">
                        <div class="lrp-card">
                            <h2>App Configurations</h2>
                            <form method="post" action="">
                                <?php wp_nonce_field('lrp_nonce', 'lrp_nonce'); ?>
                                <table class="form-table">
                                    <tr>
                                        <th><label for="customer_signup_points">Customer Signup Points</label></th>
                                        <td><input type="number" id="customer_signup_points" name="customer_signup_points" value="<?php echo esc_attr($app_config ? $app_config->customer_signup_points : 50); ?>" min="0" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Points awarded on user signup.</p></td>
                                    </tr>
                                    <tr>
                                        <th><label for="product_review_points">Product Review Points</label></th>
                                        <td><input type="number" id="product_review_points" name="product_review_points" value="<?php echo esc_attr($app_config ? $app_config->product_review_points : 10); ?>" min="0" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Points per product review.</p></td>
                                    </tr>
                                    <tr>
                                        <th><label for="referral_points">Referral & Earn Points</label></th>
                                        <td><input type="number" id="referral_points" name="referral_points" value="<?php echo esc_attr($app_config ? $app_config->referral_points : 50); ?>" min="0" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Points for referrals.</p></td>
                                    </tr>
                                    <tr>
                                        <th><label for="birthday_points">Birthday Points</label></th>
                                        <td><input type="number" id="birthday_points" name="birthday_points" value="<?php echo esc_attr($app_config ? $app_config->birthday_points : 25); ?>" min="0" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Points for birthdays.</p></td>
                                    </tr>
                                    <tr>
                                        <th><label for="anniversary_points">Anniversary Points</label></th>
                                        <td><input type="number" id="anniversary_points" name="anniversary_points" value="<?php echo esc_attr($app_config ? $app_config->anniversary_points : 25); ?>" min="0" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Points for anniversaries.</p></td>
                                    </tr>
                                </table>
                                <?php if(!$is_expired) submit_button('Save App Config'); ?>
                            </form>
                        </div>
                    </div>
                    <div id="points-config" class="lrp-tab-pane">
                        <div class="lrp-card">
                            <h2>Points Configurations</h2>
                            <form method="post" action="">
                                <?php wp_nonce_field('lrp_nonce', 'lrp_nonce'); ?>
                                <table class="form-table">
                                    <tr>
                                        <th><label for="each_point_value"> Point Value</label></th>
                                        <td><input type="number" id="each_point_value" name="each_point_value" value="<?php echo esc_attr($points_config ? $points_config->each_point_value : 10); ?>" min="0" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Value of each point in cents.</p></td>
                                    </tr>
                                    <tr>
                                        <th><label for="loyalty_point_value">Loyalty Point Equivalent</label></th>
                                        <td><input type="number" id="loyalty_point_value" name="loyalty_point_value" value="<?php echo esc_attr($points_config ? $points_config->loyalty_point_value : 1); ?>" min="0" step="0.01" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Value of loyalty points in dollars.</p></td>
                                    </tr>
                                </table>
                                <?php if(!$is_expired) submit_button('Save Points Config'); ?>
                            </form>
                        </div>
                    </div>
                    <div id="threshold-config" class="lrp-tab-pane">
                        <div class="lrp-card">
                            <h2>Threshold Configurations</h2>
                            <form method="post" action="">
                                <?php wp_nonce_field('lrp_nonce', 'lrp_nonce'); ?>
                                <table class="form-table">
                                    <tr>
                                        <th><label for="minimum_redemption_points">Customer Minimum Points</label></th>
                                        <td><input type="number" id="minimum_redemption_points" name="minimum_redemption_points" value="<?php echo esc_attr($threshold_config ? $threshold_config->minimum_redemption_points : 100); ?>" min="0" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Minimum points required to apply and use rewards.</p></td>
                                    </tr>
                                </table>
                                <?php if(!$is_expired) submit_button('Save Threshold Config'); ?>
                            </form>
                        </div>
                    </div>
                    <div id="loyalty-tiers" class="lrp-tab-pane">
                        <div class="lrp-card">
                            <h2>Loyalty Tiers</h2>
                            <form method="post" action="">
                                <?php wp_nonce_field('lrp_nonce', 'lrp_nonce'); ?>
                                <table id="loyalty-tiers-table" class="form-table wp-list-table widefat fixed striped">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Threshold</th>
                                            <th>Points (per $)</th>
                                            <th>Level</th>
                                            <th>Active</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $index = 0; foreach ($loyalty_tiers as $tier) : ?>
                                        <tr data-index="<?php echo esc_attr($index); ?>">
                                            <td><input type="text" name="tier_data[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($tier->name); ?>" class="lrp-input" required <?php if($is_expired) echo 'disabled'; ?>></td>
                                            <td><input type="number" name="tier_data[<?php echo esc_attr($index); ?>][threshold]" value="<?php echo esc_attr($tier->threshold); ?>" class="lrp-input" min="0" required <?php if($is_expired) echo 'disabled'; ?>></td>
                                            <td><input type="number" name="tier_data[<?php echo esc_attr($index); ?>][points]" value="<?php echo esc_attr($tier->points); ?>" class="lrp-input" min="0" step="0.01" required <?php if($is_expired) echo 'disabled'; ?>></td>
                                            <td><input type="number" name="tier_data[<?php echo esc_attr($index); ?>][level]" value="<?php echo esc_attr($tier->level); ?>" class="lrp-input" min="1" required <?php if($is_expired) echo 'disabled'; ?>></td>
                                            <td>
                                                <input type="hidden" name="tier_data[<?php echo esc_attr($index); ?>][active]" value="0">
                                                <input type="checkbox" name="tier_data[<?php echo esc_attr($index); ?>][active]" value="1" <?php checked(1, $tier->active); ?> class="lrp-checkbox" <?php if($is_expired) echo 'disabled'; ?>>
                                            </td>
                                        </tr>
                                        <?php $index++; endforeach; ?>
                                    </tbody>
                                </table>
                                <p><?php if(!$is_expired) { ?><button type="button" class="button add-tier"><i class="fas fa-plus"></i> Add Tier</button><?php } ?></p>
                                <?php if(!$is_expired) submit_button('Save Loyalty Tiers'); ?>
                            </form>
                            <script>
                            jQuery(document).ready(function($) {
                                var isExpired = <?php echo json_encode($is_expired); ?>;
                                if (!isExpired) {
                                    $('.add-tier').on('click', function() {
                                        var nextIndex = $('#loyalty-tiers-table tbody tr').length;
                                        var newRow = '<tr data-index="' + nextIndex + '">' +
                                            '<td><input type="text" name="tier_data[' + nextIndex + '][name]" value="" class="lrp-input" required></td>' +
                                            '<td><input type="number" name="tier_data[' + nextIndex + '][threshold]" value="0" class="lrp-input" min="0" required></td>' +
                                            '<td><input type="number" name="tier_data[' + nextIndex + '][points]" value="2.00" class="lrp-input" min="0" step="0.01" required></td>' +
                                            '<td><input type="number" name="tier_data[' + nextIndex + '][level]" value="' + (nextIndex + 1) + '" class="lrp-input" min="1" required></td>' +
                                            '<td>' +
                                                '<input type="hidden" name="tier_data[' + nextIndex + '][active]" value="0">' +
                                                '<input type="checkbox" name="tier_data[' + nextIndex + '][active]" value="1" class="lrp-checkbox">' +
                                            '</td>' +
                                        '</tr>';
                                        $('#loyalty-tiers-table tbody').append(newRow);
                                    });
                                    $(document).on('click', '.remove-row', function() {
                                        $(this).closest('tr').remove();
                                    });
                                }
                            });
                            </script>
                        </div>
                    </div>
                    <div id="social-share-config" class="lrp-tab-pane">
                        <div class="lrp-card">
                            <h2>Social Share Configurations</h2>
                            <form method="post" action="">
                                <?php wp_nonce_field('lrp_nonce', 'lrp_nonce'); ?>
                                <table class="form-table">
                                    <tr>
                                        <th><label for="email_share_points">Email Share Points</label></th>
                                        <td><input type="number" id="email_share_points" name="email_share_points" value="<?php echo esc_attr($social_share_config ? $social_share_config->email_share_points : 20); ?>" min="0" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Points awarded for sharing via email.</p></td>
                                    </tr>
                                    <tr>
                                        <th><label for="facebook_share_points">Facebook Share Points</label></th>
                                        <td><input type="number" id="facebook_share_points" name="facebook_share_points" value="<?php echo esc_attr($social_share_config ? $social_share_config->facebook_share_points : 20); ?>" min="0" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Points awarded for sharing on Facebook.</p></td>
                                    </tr>
                                </table>
                                <?php if(!$is_expired) submit_button('Save Social Share Config'); ?>
                            </form>
                        </div>
                    </div>
                    <?php if($is_expired): ?>
                    <div class="lrp-freeze-overlay">
                        <div class="lrp-freeze-cloud">
                            <p><strong>License Expired:</strong> Loyalty features are currently unavailable. Please renew your license and contact <a href="mailto:support@netscoretech.com"> NetScore support</a>.</p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <script>
        jQuery(document).ready(function($) {
            var isExpired = <?php echo json_encode($is_expired); ?>;
            if (isExpired) {
                $('.lrp-nav-tabs .lrp-tab').not('[href="#user-details"]').on('click', function(e) {
                    e.preventDefault();
                });
                $('.lrp-tab-pane').removeClass('active');
                $('#user-details').addClass('active');
                $('.lrp-nav-tabs .lrp-tab').removeClass('active');
                $('.lrp-nav-tabs .lrp-tab[href="#user-details"]').addClass('active');
            }
        });
        </script>
        <?php
    }

    public function save_config() {
        if (!$this->restrict_access() && (!isset($_GET['page']) || sanitize_text_field($_GET['page']) !== 'lrp-settings')) {
            return;
        }
        global $wpdb;
        $app_config_table = $wpdb->prefix . 'lrp_app_configurations';
        $points_config_table = $wpdb->prefix . 'lrp_points_configurations';
        $threshold_config_table = $wpdb->prefix . 'lrp_threshold_configurations';
        $loyalty_tiers_table = $wpdb->prefix . 'lrp_loyalty_tiers';
        $social_share_config_table = $wpdb->prefix . 'lrp_social_share_configurations';
        if (isset($_POST['lrp_nonce']) && wp_verify_nonce($_POST['lrp_nonce'], 'lrp_nonce')) {
            error_log('Received POST data: ' . print_r($_POST, true));
            $errors = [];
            if (isset($_POST['customer_signup_points'])) {
                $customer_signup_points = sanitize_text_field($_POST['customer_signup_points']);
                $product_review_points = sanitize_text_field($_POST['product_review_points']);
                $referral_points = sanitize_text_field($_POST['referral_points']);
                $birthday_points = sanitize_text_field($_POST['birthday_points']);
                $anniversary_points = sanitize_text_field($_POST['anniversary_points']);
                if (empty($customer_signup_points) && $_POST['customer_signup_points'] !== '') { $errors[] = 'Customer Signup Points is required'; }
                elseif (!is_numeric($customer_signup_points) || $customer_signup_points < 0) { $errors[] = 'Invalid Customer Signup Points'; }
                if (empty($product_review_points) && $_POST['product_review_points'] !== '') { $errors[] = 'Product Review Points is required'; }
                elseif (!is_numeric($product_review_points) || $product_review_points < 0) { $errors[] = 'Invalid Product Review Points'; }
                if (empty($referral_points) && $_POST['referral_points'] !== '') { $errors[] = 'Referral Points is required'; }
                elseif (!is_numeric($referral_points) || $referral_points < 0) { $errors[] = 'Invalid Referral Points'; }
                if (empty($birthday_points) && $_POST['birthday_points'] !== '') { $errors[] = 'Birthday Points is required'; }
                elseif (!is_numeric($birthday_points) || $birthday_points < 0) { $errors[] = 'Invalid Birthday Points'; }
                if (empty($anniversary_points) && $_POST['anniversary_points'] !== '') { $errors[] = 'Anniversary Points is required'; }
                elseif (!is_numeric($anniversary_points) || $anniversary_points < 0) { $errors[] = 'Invalid Anniversary Points'; }
                if (empty($errors)) {
                    $wpdb->update(
                        $app_config_table,
                        [
                            'customer_signup_points' => (int)$customer_signup_points,
                            'product_review_points' => (int)$product_review_points,
                            'referral_points' => (int)$referral_points,
                            'birthday_points' => (int)$birthday_points,
                            'anniversary_points' => (int)$anniversary_points
                        ],
                        ['id' => 1]
                    );
                    if ($wpdb->last_error) {
                        error_log('Database Update Error for app_configurations: ' . $wpdb->last_error);
                        $errors[] = 'Failed to save App Configurations';
                    }
                }
            }
            if (isset($_POST['each_point_value'])) {
                $each_point_value = sanitize_text_field($_POST['each_point_value']);
                $loyalty_point_value= sanitize_text_field($_POST['loyalty_point_value']);
                if (empty($each_point_value) && $_POST['each_point_value'] !== '') { $errors[] = 'Each Point Value is required'; }
                elseif (!is_numeric($each_point_value) || $each_point_value <= 0) { $errors[] = 'Invalid Each Point Value'; }
                if (empty($loyalty_point_value) && $_POST['loyalty_point_value'] !== '') { $errors[] = 'Loyalty Point Value is required'; }
                elseif (!is_numeric($loyalty_point_value) || $loyalty_point_value <= 0) { $errors[] = 'Invalid Loyalty Point Value'; }
                if (empty($errors)) {
                    $wpdb->update(
                        $points_config_table,
                        [
                            'each_point_value' => (int)$each_point_value,
                            'loyalty_point_value'=> (float)$loyalty_point_value
                        ],
                        ['id' => 1]
                    );
                    if ($wpdb->last_error) {
                        error_log('Database Update Error for points_configurations: ' . $wpdb->last_error);
                        $errors[] = 'Failed to save Points Configurations';
                    }
                }
            }
            if (isset($_POST['minimum_redemption_points'])) {
                $minimum_redemption_points = sanitize_text_field($_POST['minimum_redemption_points']);
                if (empty($minimum_redemption_points) && $_POST['minimum_redemption_points'] !== '') {
                    $errors[] = 'Customer Minimum Points is required';
                } elseif (!is_numeric($minimum_redemption_points) || $minimum_redemption_points < 0) {
                    $errors[] = 'Invalid Customer Minimum Points';
                } else {
                    $wpdb->update(
                        $threshold_config_table,
                        ['minimum_redemption_points' => (int)$minimum_redemption_points],
                        ['id' => 1]
                    );
                    if ($wpdb->last_error) {
                        error_log('Database Update Error for threshold_configurations: ' . $wpdb->last_error);
                        $errors[] = 'Failed to save Threshold Configurations';
                    }
                }
            }
            if (isset($_POST['tier_data']) && is_array($_POST['tier_data'])) {
                $tier_data = $_POST['tier_data'];
                foreach ($tier_data as $tier) {
                    $name = sanitize_text_field($tier['name']);
                    $threshold = intval($tier['threshold']);
                    $points = floatval($tier['points']);
                    $level = intval($tier['level']);
                    $active = intval($tier['active']);
                    if (empty($name)) {
                        $errors[] = 'Tier name is required.';
                    }
                    if ($threshold < 0) {
                        $errors[] = 'Invalid threshold value.';
                    }
                    if ($points < 0) {
                        $errors[] = 'Invalid points value.';
                    }
                    if ($level < 1) {
                        $errors[] = 'Invalid level value.';
                    }
                    if (!in_array($active, [0, 1])) {
                        $errors[] = 'Invalid active status.';
                    }
                }
                if (empty($errors)) {
                    $wpdb->query("DELETE FROM $loyalty_tiers_table");
                    foreach ($tier_data as $tier) {
                        $result = $wpdb->insert(
                            $loyalty_tiers_table,
                            [
                                'name' => sanitize_text_field($tier['name']),
                                'threshold' => intval($tier['threshold']),
                                'points' => floatval($tier['points']),
                                'level' => intval($tier['level']),
                                'active' => intval($tier['active'])
                            ]
                        );
                        if ($wpdb->last_error) {
                            error_log('Database Insert Error for loyalty_tiers: ' . $wpdb->last_error);
                            $errors[] = 'Failed to save one or more Loyalty Tiers';
                        }
                    }
                }
            }

            // Social Share Configurations
            if (isset($_POST['email_share_points'])) {
                $email_share_points = sanitize_text_field($_POST['email_share_points']);
                $facebook_share_points = sanitize_text_field($_POST['facebook_share_points']);
                if (empty($email_share_points) && $_POST['email_share_points'] !== '') {
                    $errors[] = 'Email Share Points is required';
                } elseif (!is_numeric($email_share_points) || $email_share_points < 0) {
                    $errors[] = 'Invalid Email Share Points';
                }
                if (empty($facebook_share_points) && $_POST['facebook_share_points'] !== '') {
                    $errors[] = 'Facebook Share Points is required';
                } elseif (!is_numeric($facebook_share_points) || $facebook_share_points < 0) {
                    $errors[] = 'Invalid Facebook Share Points';
                }
                if (empty($errors)) {
                    $result = $wpdb->update(
                        $social_share_config_table,
                        [
                            'email_share_points' => (int)$email_share_points,
                            'facebook_share_points' => (int)$facebook_share_points
                        ],
                        ['id' => 1]
                    );
                    if ($wpdb->last_error) {
                        error_log('Database Update Error for social_share_configurations: ' . $wpdb->last_error);
                        $errors[] = 'Failed to save Social Share Configurations';
                    } else {
                        error_log('Updated social_share_configurations with values: ' . print_r([
                            'email_share_points' => $email_share_points,
                            'facebook_share_points' => $facebook_share_points
                        ], true));
                    }
                }
            }

            // Handle errors or success
            if (!empty($errors)) {
                set_transient('lrp_admin_error', implode('. ', $errors), 30);
            } else {
                set_transient('lrp_admin_notice', 'Configurations saved successfully.', 30);
            }
            wp_safe_redirect(admin_url('admin.php?page=lrp-settings'));
            exit;
        }
    }

    public function add_loyalty_product_tab($tabs) {
    // Always show the loyalty product tab, but if expired, only show message in content
    $tabs['lrp_loyalty'] = array(
        'label' => __('Loyalty', 'woocommerce'),
        'target' => 'lrp_loyalty_product_data',
        'priority' => 25,
    );
    return $tabs;
}

    public function add_loyalty_product_tab_content() {
        global $post, $wpdb;
        $table_name = $wpdb->prefix . 'lrp_product_loyalty';
        $product_id = $post->ID;
        // Fetch existing data from the database
        $loyalty_data = $wpdb->get_row($wpdb->prepare(
            "SELECT product_loyalty_points, frontend_visibility FROM $table_name WHERE product_id = %d",
            $product_id
        ));
        $eligible = $loyalty_data ? ($loyalty_data->frontend_visibility ? 'yes' : 'no') : 'no';
        $loyalty_points = $loyalty_data ? $loyalty_data->product_loyalty_points : '';
        $is_expired = $this->is_license_expired();
        ?>
        <div id="lrp_loyalty_product_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <?php if ($is_expired): ?>
                    <div style="padding:15px;border:1px solid #ffcc00;background:#fff8e1;margin-bottom:15px;">
                        <strong>Loyalty points disabled:</strong> Your license has expired. Please renew your license and click <a href="mailto:support@netscoretech.com">here</a> to contact support .
                    </div>
                <?php endif; ?>
                <?php if ($this->is_authenticated()): ?>
                    <?php
                    woocommerce_wp_checkbox(array(
                        'id' => '_lrp_eligible_loyalty',
                        'label' => __('Enable Loyalty Option', 'woocommerce'),
                        'description' => __('Check this to enable loyalty points for this product.', 'woocommerce'),
                        'value' => $eligible,
                        'cbvalue' => 'yes',
                        'custom_attributes' => $is_expired ? array('disabled' => 'disabled') : array(),
                    ));
                    ?>
                    <div id="lrp_loyalty_points_container" style="display: <?php echo $eligible === 'yes' ? 'block' : 'none'; ?>;">
                        <?php
                        woocommerce_wp_text_input(array(
                            'id' => '_lrp_loyalty_points',
                            'label' => __('Loyalty Points', 'woocommerce'),
                            'description' => __('Enter the number of loyalty points awarded for purchasing this product.', 'woocommerce'),
                            'type' => 'number',
                            'custom_attributes' => $is_expired ? array('min' => '0', 'step' => '1', 'disabled' => 'disabled') : array('min' => '0', 'step' => '1'),
                            'value' => $loyalty_points ? esc_attr($loyalty_points) : '',
                        ));
                        ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }


    public function save_product_meta($post_id) {
        // Prevent saving loyalty product data if license expired
        if ($this->is_license_expired()) {
            return;
        }
        if (!$this->is_authenticated()) {
            return;
        }
        global $wpdb;
        $table_name = $wpdb->prefix . 'lrp_product_loyalty';

        // Check if the table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            set_transient('lrp_admin_error', 'Loyalty product table does not exist. Please reactivate the plugin or create tables manually.', 30);
            return;
        }

        // Determine frontend_visibility based on checkbox
        $frontend_visibility = isset($_POST['_lrp_eligible_loyalty']) ? 1 : 0;

        // Get current points from the database
        $existing_data = $wpdb->get_row($wpdb->prepare(
            "SELECT product_loyalty_points FROM $table_name WHERE product_id = %d",
            $post_id
        ));
        $current_points = $existing_data ? (int)$existing_data->product_loyalty_points : 0;

        // Get and validate submitted points
        $points = isset($_POST['_lrp_loyalty_points']) ? sanitize_text_field($_POST['_lrp_loyalty_points']) : '';
        // Use submitted points if valid; otherwise, keep existing points
        $points = (is_numeric($points) && $points >= 0) ? (int)$points : $current_points;

        // Check if record exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE product_id = %d",
            $post_id
        ));

        if ($existing) {
            // Update existing record
            $result = $wpdb->update(
                $table_name,
                [
                    'product_loyalty_points' => $points,
                    'frontend_visibility' => $frontend_visibility
                ],
                ['product_id' => $post_id]
            );
            if ($result === false) {
                set_transient('lrp_admin_error', 'Failed to update loyalty points for product.', 30);
            }
        } else {
            // Insert new record
            $result = $wpdb->insert(
                $table_name,
                [
                    'product_id' => $post_id,
                    'product_loyalty_points' => $points,
                    'frontend_visibility' => $frontend_visibility
                ]
            );
            if ($result === false) {
                set_transient('lrp_admin_error', 'Failed to save loyalty points for product.', 30);
            }
        }
    }
}
?>