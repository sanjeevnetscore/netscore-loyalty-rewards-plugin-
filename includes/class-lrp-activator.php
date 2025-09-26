<?php
if (!defined('ABSPATH')) {
    exit;
}

class LRP_Activator {
    public function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Include upgrade.php only once
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Array to store SQL queries for all tables
        $sql_queries = [];

        // Loyalty Points table
        $table_name = $wpdb->prefix . 'loyalty_points';
        $sql_queries[] = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            order_id BIGINT(20) UNSIGNED,
            points INT NOT NULL,
            type VARCHAR(50) NOT NULL,
            source VARCHAR(100),
            value DECIMAL(10,2),
            date DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id)
        ) $charset_collate;";

        // Referrals table
        $table_name = $wpdb->prefix . 'lrp_referrals';
        $sql_queries[] = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            referral_email VARCHAR(255) NOT NULL,
            referral_code VARCHAR(50) NOT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            created_date DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id)
        ) $charset_collate;";

        // NetSuite Customers table
        $table_name = $wpdb->prefix . 'lrp_netsuite_customers';
        $sql_queries[] = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            license_key VARCHAR(255) NOT NULL,
            product_code VARCHAR(255) NOT NULL,
            account_id VARCHAR(255) NOT NULL,
            license_url VARCHAR(255) NOT NULL,
            plan_start_date DATETIME NOT NULL,
            plan_end_date DATETIME NOT NULL,
            plan_active TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        // Loyalty Customers table
        $table_name = $wpdb->prefix . 'lrp_loyalty_customers';
        $sql_queries[] = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            license_key VARCHAR(255) NOT NULL,
            username VARCHAR(255) NOT NULL,
            password VARCHAR(255) NOT NULL,
            plan_start_date DATETIME NOT NULL,
            plan_end_date DATETIME NOT NULL,
            plan_active TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        // App Configurations table
        $table_name = $wpdb->prefix . 'lrp_app_configurations';
        $sql_queries[] = "CREATE TABLE $table_name (
            id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
            customer_signup_points INT NOT NULL DEFAULT 50,
            product_review_points INT NOT NULL DEFAULT 10,
            referral_points INT NOT NULL DEFAULT 50,
            birthday_points INT NOT NULL DEFAULT 25,
            anniversary_points INT NOT NULL DEFAULT 25,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        // Points Configurations table
        $table_name = $wpdb->prefix . 'lrp_points_configurations';
        $sql_queries[] = "CREATE TABLE $table_name (
            id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
            each_point_value INT NOT NULL DEFAULT 10,
            loyalty_point_value DECIMAL(10,2) NOT NULL DEFAULT 1.00,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        // Threshold Configurations table
        $table_name = $wpdb->prefix . 'lrp_threshold_configurations';
        $sql_queries[] = "CREATE TABLE $table_name (
            id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
            minimum_redemption_points INT NOT NULL DEFAULT 100,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        $table_name = $wpdb->prefix . 'lrp_loyalty_tiers';
        $sql_queries[] = "CREATE TABLE $table_name (
            id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
            name VARCHAR(50) NOT NULL,
            threshold INT NOT NULL DEFAULT 0,
            points DECIMAL(10,2) NOT NULL DEFAULT 2.00,
            level INT NOT NULL DEFAULT 1,
            active TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        // Social Share Configurations table
        $table_name = $wpdb->prefix . 'lrp_social_share_configurations';
        $sql_queries[] = "CREATE TABLE $table_name (
            id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
            email_share_points INT NOT NULL DEFAULT 20,
            facebook_share_points INT NOT NULL DEFAULT 20,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        
        $table_name = $wpdb->prefix . 'lrp_product_loyalty';
        $sql_queries[] = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            product_loyalty_points INT NOT NULL DEFAULT 0,
            frontend_visibility TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY product_id (product_id)
        ) $charset_collate;";


        // Gift Cards table
        $table_name = $wpdb->prefix . 'lrp_gift_cards';
        $sql_queries[] = "CREATE TABLE $table_name (
            id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            sent_email VARCHAR(255) NOT NULL,
            gift_card_number VARCHAR(50) NOT NULL,
            created_date DATETIME NOT NULL,
            expiry_date DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY gift_card_number (gift_card_number)
        ) $charset_collate;";

        // Execute all SQL queries and log results
        foreach ($sql_queries as $sql) {
            $result = dbDelta($sql);
            if (empty($result)) {
                error_log("Table creation failed for query: $sql");
            } else {
                error_log("Table creation/update successful: " . print_r($result, true));
            }
        }

        // Insert default data for netsuite_customers
        $table_name = $wpdb->prefix . 'lrp_netsuite_customers';
        if ($wpdb->get_var("SELECT COUNT(*) FROM $table_name") == 0) {
            $current_time = current_time('mysql');
            $end_date = date('Y-m-d H:i:s', strtotime('+1 year', strtotime($current_time)));
            $result = $wpdb->insert($table_name, [
                'license_key' => 'dummy_netsuite_key',
                'product_code' => 'dummy_product_code',
                'account_id' => 'dummy_account_id',
                'license_url' => 'https://dummy.netsuite.com',
                'plan_start_date' => $current_time,
                'plan_end_date' => $end_date,
                'plan_active' => 1,
                'created_at' => $current_time,
                'updated_at' => $current_time
            ]);
            if ($result === false) {
                error_log("Failed to insert default data into $table_name: " . $wpdb->last_error);
            } else {
                error_log("Inserted default data into $table_name");
            }
        }

        // Insert default data for loyalty_customers
        $table_name = $wpdb->prefix . 'lrp_loyalty_customers';
        if ($wpdb->get_var("SELECT COUNT(*) FROM $table_name") == 0) {
            $current_time = current_time('mysql');
            $end_date = date('Y-m-d H:i:s', strtotime('+1 year', strtotime($current_time)));
            $result = $wpdb->insert($table_name, [
                'license_key' => 'dummy_loyalty_key',
                'username' => 'bharathinetscore@gmail.com',
                'password' => wp_hash_password('dummy_password'),
                'plan_start_date' => $current_time,
                'plan_end_date' => $end_date,
                'plan_active' => 1,
                'created_at' => $current_time,
                'updated_at' => $current_time
            ]);
            if ($result === false) {
                error_log("Failed to insert default data into $table_name: " . $wpdb->last_error);
            } else {
                error_log("Inserted default data into $table_name");
            }
        }

        // Insert default data for app_configurations
        $table_name = $wpdb->prefix . 'lrp_app_configurations';
        if ($wpdb->get_var("SELECT COUNT(*) FROM $table_name") == 0) {
            $result = $wpdb->insert($table_name, [
                'customer_signup_points' => 50,
                'product_review_points' => 10,
                'referral_points' => 50,
                'birthday_points' => 25,
                'anniversary_points' => 25
            ]);
            if ($result === false) {
                error_log("Failed to insert default data into $table_name: " . $wpdb->last_error);
            } else {
                error_log("Inserted default data into $table_name");
            }
        }

        // Insert default data for points_configurations
        $table_name = $wpdb->prefix . 'lrp_points_configurations';
        if ($wpdb->get_var("SELECT COUNT(*) FROM $table_name") == 0) {
            $result = $wpdb->insert($table_name, [
                'each_point_value' => 10,
                'loyalty_point_value' => 1.00
            ]);
            if ($result === false) {
                error_log("Failed to insert default data into $table_name: " . $wpdb->last_error);
            } else {
                error_log("Inserted default data into $table_name");
            }
        }

        // Insert default data for threshold_configurations
        $table_name = $wpdb->prefix . 'lrp_threshold_configurations';
        if ($wpdb->get_var("SELECT COUNT(*) FROM $table_name") == 0) {
            $result = $wpdb->insert($table_name, [
                'minimum_redemption_points' => 100
            ]);
            if ($result === false) {
                error_log("Failed to insert default data into $table_name: " . $wpdb->last_error);
            } else {
                error_log("Inserted default data into $table_name");
            }
        }

        // Insert default data for loyalty_tiers
        $table_name = $wpdb->prefix . 'lrp_loyalty_tiers';
        if ($wpdb->get_var("SELECT COUNT(*) FROM $table_name") == 0) {
            $result = $wpdb->insert($table_name, [
                'silver_tier_min' => 0,
                'gold_tier_min' => 1000,
                'platinum_tier_min' => 10000,
                'silver_points' => 2.00,
                'gold_points' => 2.00,
                'platinum_points' => 2.00,
                'silver_active' => 0,
                'gold_active' => 0,
                'platinum_active' => 0,
                'silver_level' => 1,
                'gold_level' => 2,
                'platinum_level' => 3
            ]);
            if ($result === false) {
                error_log("Failed to insert default data into $table_name: " . $wpdb->last_error);
            } else {
                error_log("Inserted default data into $table_name");
            }
        }

        // Insert default data for social_share_configurations
        $table_name = $wpdb->prefix . 'lrp_social_share_configurations';
        if ($wpdb->get_var("SELECT COUNT(*) FROM $table_name") == 0) {
            $result = $wpdb->insert($table_name, [
                'email_share_points' => 20,
                'facebook_share_points' => 20
            ]);
            if ($result === false) {
                error_log("Failed to insert default data into $table_name: " . $wpdb->last_error);
            } else {
                error_log("Inserted default data into $table_name");
            }
        }

        // Create dummy WordPress users
        if (!email_exists('netsuite@dummy.com')) {
            $result = wp_insert_user([
                'user_login' => 'netsuite_dummy',
                'user_email' => 'netsuite@dummy.com',
                'user_pass' => 'netsuite_pass',
                'role' => 'administrator'
            ]);
            if (is_wp_error($result)) {
                error_log("Failed to create dummy NetSuite user: " . $result->get_error_message());
            } else {
                error_log("Created dummy NetSuite user");
            }
        }

        if (!email_exists('bharathinetscore@gmail.com')) {
            $result = wp_insert_user([
                'user_login' => 'loyalty_dummy',
                'user_email' => 'bharathinetscore@gmail.com',
                'user_pass' => 'loyalty_pass',
                'role' => 'administrator'
            ]);
            if (is_wp_error($result)) {
                error_log("Failed to create dummy loyalty user: " . $result->get_error_message());
            } else {
                error_log("Created dummy loyalty user");
            }
        }

        // Set initial authentication to false
        update_option('lrp_authenticated', false);
        error_log("Set lrp_authenticated option to false");

        flush_rewrite_rules();
        error_log("Plugin activated and rewrite rules flushed at " . date('Y-m-d H:i:s'));
    }

    public function deactivate() {
        flush_rewrite_rules();
        error_log("Rewrite rules flushed on deactivation at " . date('Y-m-d H:i:s'));
    }
}