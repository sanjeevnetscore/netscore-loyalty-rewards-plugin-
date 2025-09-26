<?php
// includes/class-lrp-api.php
if (!defined('ABSPATH')) {
    exit;
}

class LRP_API {
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        $permission_callback = function() {
            // Allow WP Admins
            if (current_user_can('manage_options')) {
                return true;
            }
            // Allow external API calls via API key
            $headers = getallheaders();
            $api_key = isset($headers['X-LRP-API-Key']) ? sanitize_text_field($headers['X-LRP-API-Key']) : '';
            $stored_key = defined('LRP_API_KEY') ? LRP_API_KEY : '';
            if ($stored_key && hash_equals($stored_key, $api_key)) {
                return true;
            }
            return false;
        };

        // Existing Config API (unchanged)
        register_rest_route('lrp/v1', '/config', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_all_configs'),
            'permission_callback' => $permission_callback,
            'args' => array(
                'app_config' => array(
                    'type' => 'object',
                    'properties' => array(
                        'customer_signup_points' => array('type' => 'integer', 'sanitize_callback' => 'absint'),
                        'product_review_points' => array('type' => 'integer', 'sanitize_callback' => 'absint'),
                        'referral_points' => array('type' => 'integer', 'sanitize_callback' => 'absint'),
                        'birthday_points' => array('type' => 'integer', 'sanitize_callback' => 'absint'),
                        'anniversary_points' => array('type' => 'integer', 'sanitize_callback' => 'absint'),
                    ),
                ),
                'points_config' => array(
                    'type' => 'object',
                    'properties' => array(
                        'each_point_value' => array('type' => 'integer', 'sanitize_callback' => 'absint'),
                        'loyalty_point_value' => array('type' => 'number', 'sanitize_callback' => 'floatval'),
                    ),
                ),
                'threshold_config' => array(
                    'type' => 'object',
                    'properties' => array(
                        'minimum_redemption_points' => array('type' => 'integer', 'sanitize_callback' => 'absint'),
                    ),
                ),
                'tiers' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'name' => array('type' => 'string'),
                            'threshold' => array('type' => 'integer'),
                            'points' => array('type' => 'number'),
                            'level' => array('type' => 'integer'),
                            'active' => array('type' => 'integer', 'enum' => array(0, 1)),
                        ),
                    ),
                ),
                'social_share_config' => array(
                    'type' => 'object',
                    'properties' => array(
                        'email_share_points' => array('type' => 'integer', 'sanitize_callback' => 'absint'),
                        'facebook_share_points' => array('type' => 'integer', 'sanitize_callback' => 'absint'),
                    ),
                ),
                'user_points' => array(
                    'type' => 'object',
                    'properties' => array(
                        'user_id' => array('required' => true, 'type' => 'integer'),
                        'points' => array('required' => true, 'type' => 'integer'),
                        'type' => array('required' => true, 'type' => 'string', 'enum' => array('earn', 'redeem')),
                        'source' => array('type' => 'string'),
                    ),
                ),
                'user_profile' => array(
                    'type' => 'object',
                    'properties' => array(
                        'user_id' => array('required' => true, 'type' => 'integer'),
                        'birthday' => array('type' => 'string'),
                        'anniversary' => array('type' => 'string'),
                        'tier_type' => array('type' => 'string', 'enum' => array('silver', 'gold', 'platinum')),
                    ),
                ),
            ),
        ));

        // Product Points API (single and bulk operations)
        register_rest_route('lrp/v1', '/productpoints', array(
            array(
                'methods' => 'POST',
                'callback' => array($this, 'update_product_points'),
                'permission_callback' => $permission_callback,
                'args' => array(
                    'product_id' => array(
                        'type' => 'integer',
                        'sanitize_callback' => 'absint',
                        // Not required, as bulk operation uses 'products' array
                    ),
                    'frontend_visibility' => array(
                        'type' => 'integer',
                        'enum' => array(0, 1),
                        'sanitize_callback' => 'absint',
                    ),
                    'product_loyalty_points' => array(
                        'type' => 'integer',
                        'sanitize_callback' => 'absint',
                    ),
                    'products' => array(
                        'type' => 'array',
                        'items' => array(
                            'type' => 'object',
                            'properties' => array(
                                'product_id' => array(
                                    'type' => 'integer',
                                    'required' => true,
                                    'sanitize_callback' => 'absint',
                                ),
                                'frontend_visibility' => array(
                                    'type' => 'integer',
                                    'required' => true,
                                    'enum' => array(0, 1),
                                    'sanitize_callback' => 'absint',
                                ),
                                'product_loyalty_points' => array(
                                    'type' => 'integer',
                                    'required' => true,
                                    'sanitize_callback' => 'absint',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
            array(
                'methods' => 'DELETE',
                'callback' => array($this, 'delete_product_points'),
                'permission_callback' => $permission_callback,
                'args' => array(
                    'product_id' => array(
                        'type' => 'integer',
                        'required' => true,
                        'sanitize_callback' => 'absint',
                    ),
                ),
            ),
        ));
    }

    public function update_product_points(WP_REST_Request $request) {
        global $wpdb;
        $table = $wpdb->prefix . 'lrp_product_loyalty';

        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
        if (!$table_exists) {
            return new WP_Error('db_error', 'Product loyalty table does not exist', array('status' => 500));
        }

        // Check if it's a bulk update (products array) or single update
        $products = $request->get_param('products');
        if ($products && is_array($products)) {
            // Handle bulk update (reuse existing bulk logic)
            $response = array('success' => true, 'message' => 'Bulk product points processed', 'results' => array());
            $wpdb->query("START TRANSACTION");
            foreach ($products as $product) {
                $product_id = absint($product['product_id']);
                $frontend_visibility = absint($product['frontend_visibility']);
                $product_loyalty_points = absint($product['product_loyalty_points']);

                // Validate input
                if (!in_array($frontend_visibility, array(0, 1))) {
                    $response['results'][$product_id] = array('success' => false, 'message' => 'Invalid frontend_visibility value');
                    continue;
                }

                // Verify product exists in WooCommerce
                if (!wc_get_product($product_id)) {
                    $response['results'][$product_id] = array('success' => false, 'message' => 'Invalid product ID');
                    continue;
                }

                $data = array(
                    'product_id' => $product_id,
                    'frontend_visibility' => $frontend_visibility,
                    'product_loyalty_points' => $product_loyalty_points,
                );

                // Check if product_id exists
                $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE product_id = %d", $product_id));
                if ($exists) {
                    // Update existing record
                    $updated = $wpdb->update($table, $data, array('product_id' => $product_id));
                    if ($updated === false) {
                        $wpdb->query("ROLLBACK");
                        return new WP_Error('db_error', "Failed to update product points for product ID $product_id", array('status' => 500));
                    }
                    $response['results'][$product_id] = array('success' => true, 'message' => 'Product points updated');
                } else {
                    // Add new record
                    $inserted = $wpdb->insert($table, $data);
                    if ($inserted === false) {
                        $wpdb->query("ROLLBACK");
                        return new WP_Error('db_error', "Failed to add product points for product ID $product_id", array('status' => 500));
                    }
                    $response['results'][$product_id] = array('success' => true, 'message' => 'Product points added');
                }
            }
            $wpdb->query("COMMIT");
            return rest_ensure_response($response);
        } else {
            // Handle single product update
            $product_id = $request->get_param('product_id');
            $frontend_visibility = $request->get_param('frontend_visibility');
            $product_loyalty_points = $request->get_param('product_loyalty_points');

            // Validate input
            if (!$product_id || !wc_get_product($product_id)) {
                return new WP_Error('invalid_product', 'Invalid or missing product ID', array('status' => 400));
            }
            if (!in_array($frontend_visibility, array(0, 1))) {
                return new WP_Error('invalid_visibility', 'Invalid frontend_visibility value', array('status' => 400));
            }
            if (!is_numeric($product_loyalty_points) || $product_loyalty_points < 0) {
                return new WP_Error('invalid_points', 'Invalid product_loyalty_points value', array('status' => 400));
            }

            $data = array(
                'product_id' => absint($product_id),
                'frontend_visibility' => absint($frontend_visibility),
                'product_loyalty_points' => absint($product_loyalty_points),
            );

            // Check if product_id exists
            $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE product_id = %d", $product_id));
            if ($exists) {
                // Update existing record
                $updated = $wpdb->update($table, $data, array('product_id' => $product_id));
                if ($updated === false) {
                    return new WP_Error('db_error', 'Failed to update product points', array('status' => 500));
                }
                $response = array('success' => true, 'message' => 'Product points updated', 'product_id' => $product_id);
            } else {
                // Add new record
                $inserted = $wpdb->insert($table, $data);
                if ($inserted === false) {
                    return new WP_Error('db_error', 'Failed to add product points', array('status' => 500));
                }
                $response = array('success' => true, 'message' => 'Product points added', 'product_id' => $product_id);
            }
            return rest_ensure_response($response);
        }
    }

    public function delete_product_points(WP_REST_Request $request) {
        global $wpdb;
        $table = $wpdb->prefix . 'lrp_product_loyalty';
        $product_id = $request->get_param('product_id');

        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
        if (!$table_exists) {
            return new WP_Error('db_error', 'Product loyalty table does not exist', array('status' => 500));
        }

        // Validate product_id
        if (!$product_id || !wc_get_product($product_id)) {
            return new WP_Error('invalid_product', 'Invalid or missing product ID', array('status' => 400));
        }

        // Delete record
        $deleted = $wpdb->delete($table, array('product_id' => absint($product_id)));
        if ($deleted === false) {
            return new WP_Error('db_error', 'Failed to delete product points', array('status' => 500));
        }
        if ($deleted === 0) {
            return new WP_Error('not_found', 'Product points not found', array('status' => 404));
        }

        return rest_ensure_response(array('success' => true, 'message' => 'Product points deleted', 'product_id' => absint($product_id)));
    }

    // bulk_product_points method remains unchanged
    public function bulk_product_points(WP_REST_Request $request) {
        global $wpdb;
        $table = $wpdb->prefix . 'lrp_product_loyalty';
        $products = $request->get_param('products');

        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
        if (!$table_exists) {
            return new WP_Error('db_error', 'Product loyalty table does not exist', array('status' => 500));
        }

        $response = array('success' => true, 'message' => 'Bulk product points processed', 'results' => array());
        $wpdb->query("START TRANSACTION");
        foreach ($products as $product) {
            $product_id = absint($product['product_id']);
            $frontend_visibility = absint($product['frontend_visibility']);
            $product_loyalty_points = absint($product['product_loyalty_points']);

            // Validate input
            if (!in_array($frontend_visibility, array(0, 1))) {
                $response['results'][$product_id] = array('success' => false, 'message' => 'Invalid frontend_visibility value');
                continue;
            }

            $data = array(
                'product_id' => $product_id,
                'frontend_visibility' => $frontend_visibility,
                'product_loyalty_points' => $product_loyalty_points,
            );

            // Check if product_id exists
            $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE product_id = %d", $product_id));
            if ($exists) {
                // Update existing record
                $updated = $wpdb->update($table, $data, array('product_id' => $product_id));
                if ($updated === false) {
                    $wpdb->query("ROLLBACK");
                    return new WP_Error('db_error', "Failed to update product points for product ID $product_id", array('status' => 500));
                }
                $response['results'][$product_id] = array('success' => true, 'message' => 'Product points updated');
            } else {
                // Add new record
                $inserted = $wpdb->insert($table, $data);
                if ($inserted === false) {
                    $wpdb->query("ROLLBACK");
                    return new WP_Error('db_error', "Failed to add product points for product ID $product_id", array('status' => 500));
                }
                $response['results'][$product_id] = array('success' => true, 'message' => 'Product points added');
            }
        }
        $wpdb->query("COMMIT");
        return rest_ensure_response($response);
    }

    // update_all_configs method remains unchanged
    public function update_all_configs(WP_REST_Request $request) {
        global $wpdb;
        $response = array('success' => true, 'message' => 'Configurations updated', 'updated' => array());

        // Helper to check if table exists
        $table_exists = function($table) use ($wpdb) {
            return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
        };

        // Update App Configurations
        if ($app_config = $request->get_param('app_config')) {
            $table = $wpdb->prefix . 'lrp_app_configurations';
            if ($table_exists($table)) {
                $updated = $wpdb->update($table, $app_config, array('id' => 1));
                if ($updated === false) {
                    return new WP_Error('db_error', 'Failed to update app configurations', array('status' => 500));
                }
                $response['updated']['app_config'] = true;
            }
        }

        // Update Points Configurations
        if ($points_config = $request->get_param('points_config')) {
            $table = $wpdb->prefix . 'lrp_points_configurations';
            if ($table_exists($table)) {
                $updated = $wpdb->update($table, $points_config, array('id' => 1));
                if ($updated === false) {
                    return new WP_Error('db_error', 'Failed to update points configurations', array('status' => 500));
                }
                $response['updated']['points_config'] = true;
            }
        }

        // Update Threshold Configurations
        if ($threshold_config = $request->get_param('threshold_config')) {
            $table = $wpdb->prefix . 'lrp_threshold_configurations';
            if ($table_exists($table)) {
                $updated = $wpdb->update($table, $threshold_config, array('id' => 1));
                if ($updated === false) {
                    return new WP_Error('db_error', 'Failed to update threshold configurations', array('status' => 500));
                }
                $response['updated']['threshold_config'] = true;
            }
        }

        // Update Loyalty Tiers (safer handling)
        if ($tiers = $request->get_param('tiers')) {
            $table = $wpdb->prefix . 'lrp_loyalty_tiers';
            if ($table_exists($table)) {
                $wpdb->query("START TRANSACTION");
                $wpdb->query("DELETE FROM $table");
                $all_good = true;
                foreach ($tiers as $tier) {
                    $inserted = $wpdb->insert($table, $tier);
                    if ($inserted === false) {
                        $all_good = false;
                        break;
                    }
                }
                if ($all_good) {
                    $wpdb->query("COMMIT");
                    $response['updated']['tiers'] = true;
                } else {
                    $wpdb->query("ROLLBACK");
                    return new WP_Error('db_error', 'Failed to insert tiers', array('status' => 500));
                }
            }
        }

        // Update Social Share Configurations
        if ($social_share_config = $request->get_param('social_share_config')) {
            $table = $wpdb->prefix . 'lrp_social_share_configurations';
            if ($table_exists($table)) {
                $updated = $wpdb->update($table, $social_share_config, array('id' => 1));
                if ($updated === false) {
                    return new WP_Error('db_error', 'Failed to update social share configurations', array('status' => 500));
                }
                $response['updated']['social_share_config'] = true;
            }
        }

        // Update User Points
        if ($user_points = $request->get_param('user_points')) {
            $user_id = $user_points['user_id'];
            $points = $user_points['points'];
            $type = $user_points['type'];
            $source = $user_points['source'] ?? '';
            if (function_exists('lrp_add_points')) {
                lrp_add_points($user_id, $points, $type, $source);
                $response['updated']['user_points'] = true;
            } else {
                return new WP_Error('function_missing', 'lrp_add_points function not found', array('status' => 500));
            }
        }

        // Update User Profile
        if ($user_profile = $request->get_param('user_profile')) {
            $user_id = $user_profile['user_id'];
            $birthday = $user_profile['birthday'] ?? '';
            $anniversary = $user_profile['anniversary'] ?? '';
            $tier_type = $user_profile['tier_type'] ?? '';
            if ($birthday) update_user_meta($user_id, 'birthday', sanitize_text_field($birthday));
            if ($anniversary) update_user_meta($user_id, 'anniversary', sanitize_text_field($anniversary));
            if ($tier_type) update_user_meta($user_id, 'tier_type', sanitize_text_field($tier_type));
            $response['updated']['user_profile'] = true;
        }

        return rest_ensure_response($response);
    }
}
?>