<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add / subtract points for a user and write to loyalty_points table.
 *
 * $type:
 *  - 'earn'    : user earns points (increments total_points & available_points)
 *  - 'redeem'  : user redeems points (increments redeemed_points, decrements available_points)
 *  - 'restore' : restore points to available (decrement redeemed_points, increment available_points)
 *  - any other type will still be logged in the DB but meta handling is conservative
 */
 
 // Ensure referrals are awarded when a WooCommerce customer is created.
// Use woocommerce_created_customer so it runs after WooCommerce saves registration data.
add_action('woocommerce_created_customer', 'lrp_award_referral_points', 25, 1);

// For non-WooCommerce registration flows that trigger user_register, also attach as a fallback:
add_action('user_register', 'lrp_award_referral_points', 25, 1);

function lrp_add_points($user_id, $points, $type, $source = '') {
    if (empty($user_id) || $points <= 0) {
        return false;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'loyalty_points';

    $inserted = $wpdb->insert($table_name, array(
        'user_id' => $user_id,
        'points'   => $points,
        'type'     => $type,
        'source'   => $source,
        'date'     => current_time('mysql')
    ), array('%d','%d','%s','%s','%s'));

    if ($inserted === false) {
        return false;
    }

    // Fetch existing meta values
    $total_points     = (int) get_user_meta($user_id, 'total_points', true);
    $available_points = (int) get_user_meta($user_id, 'available_points', true);
    $redeemed_points  = (int) get_user_meta($user_id, 'redeemed_points', true);

    if ($type === 'earn') {
        $total_points     += $points;
        $available_points += $points;
    } elseif ($type === 'redeem') {
        // Prevent negative available points
        $available_points = max(0, $available_points - $points);
        $redeemed_points  += $points;
        // total_points should remain lifetime earned (do not subtract)
    } elseif ($type === 'restore') {
        // Restore redeemed points back to available (used for cancellation / removing redemption)
        $available_points += $points;
        $redeemed_points  = max(0, $redeemed_points - $points);
    } else {
        // For any other custom type, treat as earn (safe default)
        $total_points     += $points;
        $available_points += $points;
    }

    update_user_meta($user_id, 'total_points', $total_points);
    update_user_meta($user_id, 'available_points', $available_points);
    update_user_meta($user_id, 'redeemed_points', $redeemed_points);

    return true;
}

/*
 * Referral AJAX actions
 * Note: the frontend should send action: 'lrp_refer_friend', refer_email or email, and security (nonce)
 */
add_action('wp_ajax_lrp_refer_friend', 'lrp_refer_friend_callback');
add_action('wp_ajax_nopriv_lrp_refer_friend', 'lrp_refer_friend_callback');

/**
 * Referral AJAX handler — plain, unstyled email content (basic tags only).
 * Ensures site name is always present and used safely in headers and body.
 */
/**
 * Referral AJAX handler — plain, unstyled email content (no inline CSS).
 */
function lrp_refer_friend_callback() {
    $nonce_field = isset($_POST['security']) ? 'security' : (isset($_POST['nonce']) ? 'nonce' : '');
    if ( empty($nonce_field) || ! check_ajax_referer('lrp_refer_nonce', $nonce_field, false) ) {
        wp_send_json_error(array('message' => 'Invalid or missing security token.'));
        wp_die();
    }

    $email = isset($_POST['refer_email']) ? sanitize_email($_POST['refer_email']) : (isset($_POST['email']) ? sanitize_email($_POST['email']) : '');
    if ( empty($email) || ! is_email($email) ) {
        wp_send_json_error(array('message' => 'Please provide a valid email address.'));
        wp_die();
    }

    $referrer_id = get_current_user_id();
    if ( ! $referrer_id ) {
        wp_send_json_error(array('message' => 'You must be logged in to refer a friend.'));
        wp_die();
    }

    $referrer_userdata = get_userdata($referrer_id);
    if ( $referrer_userdata && strtolower($referrer_userdata->user_email) === strtolower($email) ) {
        wp_send_json_error(array('message' => 'You cannot refer your own email address.'));
        wp_die();
    }

    if ( email_exists($email) ) {
        wp_send_json_error(array('message' => 'This email is already registered.'));
        wp_die();
    }

    $meta_key = 'referred_email_' . md5(strtolower($email));
    $already_recorded = (bool) get_user_meta($referrer_id, $meta_key, true);

    if ( ! $already_recorded ) {
        $saved = update_user_meta($referrer_id, $meta_key, $email);
        if ($saved === false) {
            wp_send_json_error(array('message' => 'Failed to record referral. Please try again later.'));
            wp_die();
        }
    }

    // Ensure referral_code exists
    $referral_code = get_user_meta($referrer_id, 'referral_code', true);
    if ( empty($referral_code) ) {
        $referral_code = 'LR' . $referrer_id . 'DC' . date('YmdHis');
        update_user_meta($referrer_id, 'referral_code', $referral_code);
    }

    $site_name = get_option('blogname', 'Website');
    $signup_url = add_query_arg( array( 'ref' => $referral_code ), wc_get_page_permalink( 'myaccount' ) );
    $referral_points = intval( get_option('lrp_referral_points', 50) );

    // Plain, unstyled HTML content (basic tags only)
    $html_message = '';
    $html_message .= '<p>Hello,</p>';
    $html_message .= '<p>Your friend  has invited you to join.</p>';
    $html_message .= '<p>Referral Code: <strong>' . esc_html($referral_code) . '</strong></p>';
    $html_message .= '<p>Sign up here: <a href="' . esc_url($signup_url) . '">' . esc_url($signup_url) . '</a></p>';
    $html_message .= '<p>When you sign up with this code you will receive ' . intval($referral_points) . ' points.</p>';
    $html_message .= '<p>Thanks,<br>' . esc_html($site_name) . ' Team</p>';

    // Plain-text fallback
    $plain_message = sprintf(
        "Hello,\n\nYour friend  has invited you to join.\n\nReferral Code: %s\n\nSign up: %s\n\nWhen you sign up with this code you will receive %d points.\n\nThanks,\n%s Team",
        $site_name,
        $referral_code,
        $signup_url,
        $referral_points,
        $site_name
    );

    $from_email = get_option('admin_email', 'no-reply@' . wp_parse_url(home_url(), PHP_URL_HOST));
    $headers_html = array('Content-Type: text/html; charset=UTF-8', 'From: ' . wp_specialchars_decode($site_name) . ' <' . $from_email . '>', 'Reply-To: ' . $from_email);

    $sent = @wp_mail( $email, wp_strip_all_tags( $site_name . ' - Invitation' ), $html_message, $headers_html );

    if ( ! $sent ) {
        // Try plain-text if HTML failed
        $headers_text = array('Content-Type: text/plain; charset=UTF-8', 'From: ' . wp_specialchars_decode($site_name) . ' <' . $from_email . '>', 'Reply-To: ' . $from_email);
        $sent = @wp_mail( $email, wp_strip_all_tags( $site_name . ' - Invitation' ), $plain_message, $headers_text );
    }

    if ( $sent ) {
        wp_send_json_success( array( 'status' => 'email_sent', 'message' => 'Invitation sent to ' . esc_html( $email ) . '.' ) );
    } else {
        // keep silent on UI; log for debugging
        error_log( 'LRP: referral email failed to send to ' . $email . ' (referrer ' . $referrer_id . ').' );
        wp_send_json_success( array( 'status' => 'mail_failed_silent', 'message' => '' ) );
    }

    wp_die();
}

/**
 * Award referral points on user registration and notify both users.
 * Notifications are simple, unstyled HTML (basic tags only) and plain-text fallback.
 */
function lrp_award_referral_points($new_user_id) {
    global $wpdb;
    $new_user = get_userdata($new_user_id);
    if (!$new_user) {
        error_log("lrp_award_referral_points: No user found for ID {$new_user_id}");
        return;
    }

    $new_user_email = strtolower($new_user->user_email);
    $referral_code_used = get_user_meta($new_user_id, 'referral_code_used', true);
    if (empty($referral_code_used)) {
        error_log("lrp_award_referral_points: No referral code used for user ID {$new_user_id}");
        return;
    }

    // Get referral points, with fallback to wp_lrp_app_configurations
    $referral_points = intval(get_option('lrp_referral_points', 0));
    if ($referral_points <= 0) {
        $config_table = $wpdb->prefix . 'lrp_app_configurations';
        $referral_points = $wpdb->get_var("SELECT referral_points FROM $config_table LIMIT 1");
        $referral_points = intval($referral_points);
        if ($referral_points <= 0) {
            $referral_points = 50; // Default fallback
            error_log("lrp_award_referral_points: No valid referral points in wp_options or wp_lrp_app_configurations, using default: 50");
        } else {
            error_log("lrp_award_referral_points: Using referral_points {$referral_points} from wp_lrp_app_configurations");
        }
    } else {
        error_log("lrp_award_referral_points: Using referral_points {$referral_points} from wp_options");
    }

    if ($referral_points <= 0) {
        error_log("lrp_award_referral_points: Referral points invalid after fallback, skipping");
        return;
    }

    // Check wp_lrp_referrals table for matching referral code and email
    $table_name = $wpdb->prefix . 'lrp_referrals';
    $referral = $wpdb->get_row($wpdb->prepare(
        "SELECT user_id FROM $table_name WHERE referral_code = %s AND referral_email = %s AND status IN ('pending', 'sent')",
        $referral_code_used,
        $new_user_email
    ));

    if (!$referral) {
        error_log("lrp_award_referral_points: No referral found in wp_lrp_referrals for email {$new_user_email} and code {$referral_code_used}");
        // Fallback to user meta check for backward compatibility
        $meta_key = 'referred_email_' . md5($new_user_email);
        $users = get_users([
            'meta_query' => [
                ['key' => $meta_key, 'compare' => 'EXISTS']
            ],
            'number' => 1
        ]);
        if (empty($users)) {
            error_log("lrp_award_referral_points: No referrer found in user meta for {$meta_key}");
            return;
        }
        $referrer = $users[0];
        $referrer_id = $referrer->ID;
        $stored = get_user_meta($referrer_id, $meta_key, true);
        if (empty($stored) || strtolower($stored) !== $new_user_email) {
            error_log("lrp_award_referral_points: Email mismatch in user meta. Stored: {$stored}, Expected: {$new_user_email}");
            return;
        }
    } else {
        $referrer_id = $referral->user_id;
        // Update referral status to 'completed'
        $wpdb->update(
            $table_name,
            ['status' => 'completed', 'used_date' => current_time('mysql')],
            ['referral_code' => $referral_code_used, 'referral_email' => $new_user_email],
            ['%s', '%s'],
            ['%s', '%s']
        );
        if ($wpdb->last_error) {
            error_log("lrp_award_referral_points: Failed to update referral status: {$wpdb->last_error}");
        }
    }

    // Award points to referrer and new user
    $referrer_points_added = lrp_add_points($referrer_id, $referral_points, 'earn', 'Referral');
  //  $new_user_points_added = lrp_add_points($new_user_id, $referral_points, 'earn', 'Referral Signup');

    if (!$referrer_points_added) {
        error_log("lrp_award_referral_points: Failed to add points. Referrer ID: {$referrer_id}, New User ID: {$new_user_id}, Points: {$referral_points}");
    } else {
        error_log("lrp_award_referral_points: Successfully awarded {$referral_points} points to referrer ID {$referrer_id} and new user ID {$new_user_id}");
    }

    // Clean up user meta (if used)
    if (isset($meta_key)) {
        delete_user_meta($referrer_id, $meta_key);
    }

    // Send notification emails
    $site_name = get_option('blogname', 'Website');
    $from_email = get_option('admin_email', 'no-reply@' . wp_parse_url(home_url(), PHP_URL_HOST));
    $headers_html = ['Content-Type: text/html; charset=UTF-8', 'From: ' . wp_specialchars_decode($site_name) . ' <' . $from_email . '>', 'Reply-To: ' . $from_email];
    $headers_text = ['Content-Type: text/plain; charset=UTF-8', 'From: ' . wp_specialchars_decode($site_name) . ' <' . $from_email . '>', 'Reply-To: ' . $from_email];

    // Referrer notification
    $referrer = get_userdata($referrer_id);
    $ref_subject = $site_name . ' — Your referral signed up';
    $ref_html = "<p>Hello,</p><p>Your referral {$new_user->user_email} has signed up. You have been awarded {$referral_points} points.</p><p>Thank you for referring a friend.</p>";
    $ref_text = "Hello,\n\nYour referral {$new_user->user_email} has signed up. You have been awarded {$referral_points} points.\n\nThank you for referring a friend.\n";
    $ref_sent = wp_mail($referrer->user_email, $ref_subject, $ref_html, $headers_html);
    if (!$ref_sent) {
        wp_mail($referrer->user_email, $ref_subject, $ref_text, $headers_text); // Fallback
        error_log("lrp_award_referral_points: Failed to send referrer notification to {$referrer->user_email}");
    }

    // New user notification
    $new_subject = 'Welcome to ' . $site_name;
    $new_html = "<p>Hello,</p><p>Welcome to {$site_name}. As part of a referral, you have been awarded {$referral_points} points.</p><p>You can manage your account and view points from your account dashboard.</p>";
    $new_text = "Hello,\n\nWelcome to {$site_name}. You have been awarded {$referral_points} referral points.\n\nVisit your account to view your balance.\n";
    $new_sent = wp_mail($new_user->user_email, $new_subject, $new_html, $headers_html);
    if (!$new_sent) {
        wp_mail($new_user->user_email, $new_subject, $new_text, $headers_text); // Fallback
        error_log("lrp_award_referral_points: Failed to send new user notification to {$new_user->user_email}");
    }
}
/**
 * Scheduled tasks for birthdays and anniversaries
 * - schedule only if hook not scheduled
 */
add_action('wp', 'lrp_schedule_daily_tasks');
function lrp_schedule_daily_tasks() {
    if (! wp_next_scheduled('lrp_check_birthdays')) {
        wp_schedule_event(time(), 'daily', 'lrp_check_birthdays');
    }
    if (! wp_next_scheduled('lrp_check_anniversaries')) {
        wp_schedule_event(time(), 'daily', 'lrp_check_anniversaries');
    }
}

add_action('lrp_check_birthdays', 'lrp_award_birthday_points');
function lrp_award_birthday_points() {
    $users = get_users(array('fields' => array('ID')));
    $year_marker = date('Y');
    foreach ($users as $u) {
        $birthday = get_user_meta($u->ID, 'birthday', true);
        if ($birthday && date('m-d') === date('m-d', strtotime($birthday))) {
            $meta_year_key = 'birthday_points_awarded_' . $year_marker;
            if (! get_user_meta($u->ID, $meta_year_key, true)) {
                $points = intval(get_option('lrp_birthday_points', 25));
                if ($points > 0) {
                    lrp_add_points($u->ID, $points, 'earn', 'Birthday');
                    update_user_meta($u->ID, $meta_year_key, 1);
                }
            }
        }
    }
}

add_action('lrp_check_anniversaries', 'lrp_award_anniversary_points');
function lrp_award_anniversary_points() {
    $users = get_users(array('fields' => array('ID')));
    $year_marker = date('Y');
    foreach ($users as $u) {
        $anniversary = get_user_meta($u->ID, 'anniversary', true);
        if ($anniversary && date('m-d') === date('m-d', strtotime($anniversary))) {
            $meta_year_key = 'anniversary_points_awarded_' . $year_marker;
            if (! get_user_meta($u->ID, $meta_year_key, true)) {
                $points = intval(get_option('lrp_anniversary_points', 25));
                if ($points > 0) {
                    lrp_add_points($u->ID, $points, 'earn', 'Anniversary');
                    update_user_meta($u->ID, $meta_year_key, 1);
                }
            }
        }
    }
}

/**
 * Refresh points AJAX (returns available & redeemed)
 */
add_action('wp_ajax_lrp_refresh_points', 'lrp_refresh_points_callback');
add_action('wp_ajax_nopriv_lrp_refresh_points', 'lrp_refresh_points_callback');

function lrp_refresh_points_callback() {
    $nonce_field = isset($_POST['security']) ? 'security' : (isset($_POST['nonce']) ? 'nonce' : '');
    if (empty($nonce_field) || !check_ajax_referer('lrp_nonce', $nonce_field, false)) {
        wp_send_json_error(array('message' => 'Invalid security token.'));
        wp_die();
    }

    $user_id = get_current_user_id();
    if (!$user_id) {
        wp_send_json_error(array('message' => 'Not logged in.'));
        wp_die();
    }

    $available_points = (int) get_user_meta($user_id, 'available_points', true);
    $redeemed_points  = (int) get_user_meta($user_id, 'redeemed_points', true);

    wp_send_json_success(array(
        'available' => $available_points,
        'redeemed'  => $redeemed_points
    ));
    wp_die();
}