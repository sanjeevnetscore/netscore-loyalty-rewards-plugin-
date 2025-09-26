<?php

// class-lrp-frontend.php
if (!defined('ABSPATH')) exit;

class LRP_Frontend {
    private $applied_discount = 0;

    public function __construct() {
        add_action('init', array($this, 'add_endpoints'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_menu_items'));
        add_action('woocommerce_single_product_summary', array($this, 'display_loyalty_points'), 25);
        add_action('woocommerce_before_checkout_form', array($this, 'checkout_points'));
        add_action('wp_ajax_lrp_apply_points', array($this, 'apply_points_callback'));
        add_action('wp_ajax_nopriv_lrp_apply_points', array($this, 'apply_points_callback'));
        add_action('wp_ajax_lrp_remove_points', array($this, 'remove_points_callback'));
        add_action('wp_ajax_nopriv_lrp_remove_points', array($this, 'remove_points_callback'));
        add_action('wp_ajax_lrp_generate_gift_card', array($this, 'generate_gift_card_callback'));
        add_action('wp_ajax_nopriv_lrp_generate_gift_card', array($this, 'generate_gift_card_callback'));
        add_action('wp_ajax_lrp_refer_friend', array($this, 'refer_friend_callback'));
        add_action('wp_ajax_nopriv_lrp_refer_friend', array($this, 'refer_friend_callback'));
        add_action('user_register', array($this, 'award_signup_points'));
       // add_action('user_register', array($this, 'award_referral_points'), 20, 1);
        add_action('comment_post', array($this, 'award_review_points'), 10, 2);
        add_action('woocommerce_order_status_completed', array($this, 'award_purchase_points'), 10, 1);
        add_action('woocommerce_account_loyalty-points-earned_endpoint', array($this, 'loyalty_points_earned'));
        add_action('woocommerce_account_redeem-points-history_endpoint', array($this, 'redeem_points_history'));
        add_action('woocommerce_account_refer-your-friend_endpoint', array($this, 'refer_friend'));
        add_action('woocommerce_account_generate-gift-card_endpoint', array($this, 'generate_gift_card'));
        add_action('woocommerce_account_gift-card-history_endpoint', array($this, 'gift_card_history'));
        add_action('woocommerce_account_update-profile_endpoint', array($this, 'update_profile'));
        add_action('woocommerce_account_loyalty-tiers_endpoint', array($this, 'loyalty_tiers'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        // add_action('woocommerce_cart_calculated_totals', array($this, 'apply_loyalty_discount'), 1000);
        add_action('woocommerce_review_order_before_order_total', array($this, 'display_loyalty_discount'));
        add_action('woocommerce_before_cart', array($this, 'clear_discount_on_cart'), 5);
        add_action('woocommerce_checkout_update_order_review', array($this, 'apply_discount_on_checkout'), 10);
        add_action('woocommerce_checkout_create_order', array($this, 'save_loyalty_discount_to_order'), 10);
        add_action('admin_head', array($this, 'custom_admin_styles'));
        add_action('woocommerce_edit_account_form', array($this, 'add_special_fields_to_my_account'));
        add_action('woocommerce_save_account_details', array($this, 'save_special_fields'), 10);
        add_action('woocommerce_register_form', array($this, 'add_referral_code_field'));
        add_action('woocommerce_created_customer', array($this, 'save_referral_code_field'));
        add_action('wp_ajax_lrp_update_profile', array($this, 'update_profile_ajax'));
        add_action('wp_ajax_nopriv_lrp_update_profile', array($this, 'update_profile_ajax'));
        add_action('woocommerce_single_product_summary', array($this, 'display_social_share_buttons'), 40);
        add_action('wp_ajax_lrp_share_social', array($this, 'share_social_callback'));
        // add_action('woocommerce_checkout_order_processed', array($this, 'clear_loyalty_session_after_order'));
        add_action('woocommerce_checkout_order_processed', array($this, 'process_loyalty_points_redemption'));
        add_action('wp_ajax_nopriv_lrp_share_social', array($this, 'share_social_callback')); // Optional for guests, but points only for logged-in

        add_action('wp_head', array($this, 'add_dropdown_styles'));
        add_action('wp_footer', array($this, 'add_dropdown_script'));
        add_action('woocommerce_cart_calculate_fees', function($cart) {
            if (is_admin() && !defined('DOING_AJAX')) {
                return;
            }
            $discount = WC()->session->get('lrp_applied_discount', 0);
            if ($discount > 0) {
                $cart->add_fee(__('Loyalty Points Discount', 'lrp'), -$discount);
            }
        });
    }
    // public function clear_loyalty_session_after_order($order_id) {
    //     WC()->session->set('lrp_applied_discount', 0);
    //     WC()->session->set('lrp_applied_points', 0);
    // }

     public function add_endpoints() {
        if (!LRP_Utils::is_site_license_expired()) {
            add_rewrite_endpoint('loyalty-points-earned', EP_ROOT | EP_PAGES);
            add_rewrite_endpoint('redeem-points-history', EP_ROOT | EP_PAGES);
            add_rewrite_endpoint('refer-your-friend', EP_ROOT | EP_PAGES);
            add_rewrite_endpoint('generate-gift-card', EP_ROOT | EP_PAGES);
            add_rewrite_endpoint('gift-card-history', EP_ROOT | EP_PAGES);
            add_rewrite_endpoint('loyalty-tiers', EP_ROOT | EP_PAGES);
            add_rewrite_endpoint('update-profile', EP_ROOT | EP_PAGES);
            add_rewrite_rule(
                'my-account/loyalty-points-earned/page/([0-9]+)/?$',
                'index.php?pagename=my-account&loyalty-points-earned=1&paged=$matches[1]',
                'top'
            );
            add_rewrite_rule(
                'my-account/redeem-points-history/page/([0-9]+)/?$',
                'index.php?pagename=my-account&redeem-points-history=1&paged=$matches[1]',
                'top'
            );
        }
    }

    public function add_menu_items($items) {
        $new_items = [];
        $license_expired = LRP_Utils::is_site_license_expired();
        foreach ($items as $key => $label) {
            $new_items[$key] = $label;
            if ($key === 'dashboard' && !$license_expired) {
                $new_items['lrp_heading'] = 'Loyalty Rewards Information';
                $new_items['loyalty-points-earned'] = 'Loyalty Points Earned';
                $new_items['redeem-points-history'] = 'Redeem Points History';
                $new_items['refer-your-friend'] = 'Refer Your Friend';
                $new_items['generate-gift-card'] = 'Generate Gift Card';
                $new_items['loyalty-tiers'] = 'Loyalty Tiers';
                $new_items['update-profile'] = 'Update Profile';
            }
        }
        return $new_items;
    }

    public function add_dropdown_styles() {
        if (!is_account_page()) return;
        ?>
        <style>
        .woocommerce-MyAccount-navigation ul li.lrp-submenu-item {
            display: none;
            padding-left: 20px;
        }
        .woocommerce-MyAccount-navigation ul li.lrp-submenu-item.show {
            display: block;
        }
        .woocommerce-MyAccount-navigation ul li.lrp-dropdown {
            cursor: pointer;
            position: relative;
        }
        .woocommerce-MyAccount-navigation ul li.lrp-dropdown a::after {
           /* content: "▼";*/
            float: right;
            font-size: 12px;
            transition: transform 0.3s ease;
        }
        .woocommerce-MyAccount-navigation ul li.lrp-dropdown.expanded a::after {
            transform: rotate(-180deg);
        }
        .woocommerce-MyAccount-navigation ul li.lrp-submenu-item:hover {
            background-color: #e8f4f8;
        }
        .woocommerce-MyAccount-navigation ul li.lrp-submenu-item a {
            padding-left: 10px;
            font-size: 14px;
            color: #555;
        }
        .woocommerce-MyAccount-navigation ul li.lrp-submenu-item a:hover {
            color: #007cba;
        }
        </style>
        <?php
    }

    public function add_dropdown_script() {
        if (!is_account_page()) return;
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Add classes to menu items
            $('.woocommerce-MyAccount-navigation ul li').each(function() {
                var $this = $(this);
                var classes = $this.attr('class') || '';
                if (classes.includes('woocommerce-MyAccount-navigation-link--lrp_heading')) {
                    $this.addClass('lrp-dropdown');
                }
                if (classes.includes('loyalty-points-earned') ||
                    classes.includes('redeem-points-history') ||
                    classes.includes('refer-your-friend') ||
                    classes.includes('generate-gift-card') ||
                    classes.includes('gift-card-history') ||
                    classes.includes('loyalty-tiers') ||
                    classes.includes('update-profile')) {
                    $this.addClass('lrp-submenu-item');
                }
            });

            // Get dropdown and submenu items
            var $dropdown = $('.lrp-dropdown');
            var $subItems = $('.lrp-submenu-item');

            // Check if a submenu item is active based on body classes
            var isSubmenuActive = $('body').attr('class').split(/\s+/).some(function(cls) {
                return cls.includes('loyalty-points-earned') ||
                       cls.includes('redeem-points-history') ||
                       cls.includes('refer-your-friend') ||
                       cls.includes('generate-gift-card') ||
                       cls.includes('gift-card-history') ||
                       cls.includes('loyalty-tiers') ||
                       cls.includes('update-profile');
            });

            // Load saved state from localStorage
            var isExpanded = localStorage.getItem('lrp_dropdown_expanded') === 'true' || isSubmenuActive;

            // Set initial state
            if (isExpanded) {
                $dropdown.addClass('expanded');
                $subItems.addClass('show');
            } else {
                $dropdown.removeClass('expanded');
                $subItems.removeClass('show');
            }

            // Toggle dropdown only on click
            $('.lrp-dropdown a').on('click', function(e) {
                e.preventDefault();
                var $parent = $(this).closest('li');
                $parent.toggleClass('expanded');
                var isNowExpanded = $parent.hasClass('expanded');
                $subItems.toggleClass('show', isNowExpanded);
                // Save state to localStorage
                localStorage.setItem('lrp_dropdown_expanded', isNowExpanded);
            });

            // Prevent closing when clicking submenu items
            $('.lrp-submenu-item a').on('click', function(e) {
                // Allow normal navigation, don't toggle dropdown
                localStorage.setItem('lrp_dropdown_expanded', 'true');
            });
        });
        </script>
        <?php
    }

    public function enqueue_scripts() {
    if (!defined('LRP_PLUGIN_URL') || empty(LRP_PLUGIN_URL)) {
        error_log('LRP_PLUGIN_URL is not defined or empty.');
        return;
    }
    // Enqueue styles
    wp_enqueue_style('lrp-styles', LRP_PLUGIN_URL . 'assets/css/lrp-styles.css', array(), '1.0.0');
    wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css', array(), '5.15.4');
    // Enqueue checkout script only on relevant pages
    if (is_checkout() || is_page('my-account/generate-gift-card') || is_account_page()) {
        wp_enqueue_script('lrp-checkout-script', LRP_PLUGIN_URL . 'assets/js/checkout.js', array('jquery'), '1.0.0', true);

        global $wpdb;
        $config_table = $wpdb->prefix . 'lrp_points_configurations';
        $config = $wpdb->get_row("SELECT each_point_value, loyalty_point_value FROM $config_table LIMIT 1");
        $each_point_value = !empty($config->each_point_value) ? floatval($config->each_point_value) : 1;
        $loyalty_point_value = !empty($config->loyalty_point_value) ? floatval($config->loyalty_point_value) : 1;
        $user_id = get_current_user_id();
        $available_points = $user_id ? (int)(get_user_meta($user_id, 'available_points', true) ?: 0) : 0;

        wp_localize_script('lrp-checkout-script', 'lrp_checkout_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'point_value' => $each_point_value,
            'loyalty_value' => $loyalty_point_value,
            'available_points' => $available_points,
            'nonce' => wp_create_nonce('lrp_checkout_nonce'),
            'refer_nonce' => wp_create_nonce('lrp_refer_nonce'),
            'share_nonce' => wp_create_nonce('lrp_share_nonce')
        ));
    }
}

    public function display_loyalty_points() {
        // Check if license is expired
        if (LRP_Utils::is_site_license_expired()) {

            return;
        }

        global $product, $wpdb;
        if (empty($product) || !is_object($product)) {
            return;
        }

        $product_id = (int) $product->get_id();
        $table_name = $wpdb->prefix . 'lrp_product_loyalty';
        $loyalty_data = $wpdb->get_row($wpdb->prepare(
            "SELECT product_loyalty_points, frontend_visibility FROM $table_name WHERE product_id = %d",
            $product_id
        ));

        // Check if data exists, frontend_visibility is true, and points are positive
        if ($loyalty_data && $loyalty_data->frontend_visibility === '1' && intval($loyalty_data->product_loyalty_points) > 0) {
            $points = intval($loyalty_data->product_loyalty_points);
            ?>
            <div class="lrp-rewards-badge-div" style="margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <div class="lrp-rewards-badge" aria-hidden="true">
                    <span class="lrp-points-circle"><?php echo esc_html($points); ?></span>
                    <span class="lrp-points-label">PTS</span>
                </div>
                <div>
                    <p><?php printf(esc_html__('Earn %d points with this purchase', 'lrp'), $points); ?></p>
                </div>
            </div>
            <?php
        }
    }
    public function checkout_points() {
    if (LRP_Utils::is_site_license_expired()) {
        return;
    }
    global $wpdb;
    $user_id = get_current_user_id();
    $available_points = get_user_meta($user_id, 'available_points', true) ?: 0;
    $cart = WC()->cart;
    $total_cart_value = $cart ? $cart->get_subtotal() : 0;
    $config_table = $wpdb->prefix . 'lrp_points_configurations';
    $config = $wpdb->get_row("SELECT each_point_value, loyalty_point_value FROM $config_table LIMIT 1");
    $each_point_value = !empty($config->each_point_value) ? floatval($config->each_point_value) : 1;
    $loyalty_point_value = !empty($config->loyalty_point_value) ? floatval($config->loyalty_point_value) : 1;

    // Calculate max_amount using lrp_points_configurations only
    $max_amount = ($each_point_value != 0) ? ($available_points / $each_point_value) * $loyalty_point_value : 0;

    // Calculate max redeemable points using lrp_points_configurations only
    $max_redeemable_points = floor(($total_cart_value / $loyalty_point_value) * $each_point_value);
    $max_redeemable_points = min($available_points, $max_redeemable_points);

    // Get applied points from session
    $applied_points = (int) WC()->session->get('lrp_applied_points', 0);

    // Calculate applied saving using lrp_points_configurations only
    $applied_saving = ($each_point_value != 0) ? ($applied_points / $each_point_value) * $loyalty_point_value : 0;

    // Ensure applied points don't exceed max redeemable points
    if ($applied_points > $max_redeemable_points) {
        $applied_points = $max_redeemable_points;
        $applied_saving = ($each_point_value != 0) ? ($applied_points / $each_point_value) * $loyalty_point_value : 0;
        WC()->session->set('lrp_applied_points', $applied_points);
    }

    // Enqueue JavaScript and pass data
    wp_enqueue_script('lrp-checkout-script', plugin_dir_url(__FILE__) . 'js/lrp-checkout.js', ['jquery'], null, true);
    wp_localize_script('lrp-checkout-script', 'lrp_checkout_params', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('lrp_checkout_nonce'),
        'point_value' => $each_point_value,
        'loyalty_value' => $loyalty_point_value,
        'tier' => null, // No tier data
        'available_points' => $available_points,
        'max_redeemable_points' => $max_redeemable_points,
        'points_per_dollar' => ($each_point_value != 0 ? $each_point_value / $loyalty_point_value : 1)
    ]);

    // Raw cart total (numeric)
    $cart_total = $cart ? $cart->get_total('edit') : 0;
    ?>
    <div class="lrp-checkout-rewards">
        <h3>Spend Your Loyalty Rewards Points</h3>
        <p>
            <span>Points Available: <strong><?php echo esc_html($available_points); ?></strong></span>
            <span>Max Amount: <strong>$<?php echo esc_html(number_format($max_amount, 2)); ?></strong></span>
        </p>
        <p>Order Amount that You Could Redeem: <?php echo wc_price($cart_total); ?></p>
        <div class="lrp-points-input-div">
            <!-- Row 1: Checkbox + label -->
            <div class="lrp-row">
                <input type="checkbox" id="lrp_use_all" name="lrp_use_all" value="1"
                    <?php checked($applied_points == $max_redeemable_points && $applied_points > 0); ?>>
                <label for="lrp_use_all" style="margin-left: 20px;">Use all available Loyalty Points</label>
            </div>
            <!-- Row 2: Label + input + text -->
            <div class="lrp-row">
                <label for="lrp_points">Apply Points:</label>
                <input type="number" id="lrp_points" name="lrp_points" min="0"
                       max="<?php echo esc_attr($max_redeemable_points); ?>"
                       value="<?php echo esc_attr($applied_points); ?>"
                       placeholder="Enter points">
                <span class="lrp-info">
                    You will be spending <?php echo esc_html($applied_points); ?> points
                    (SAVING $<?php echo esc_html(number_format($applied_saving, 2)); ?>)
                </span>
            </div>
            <!-- Row 3: Buttons side by side -->
            <div class="lrp-row lrp-buttons">
                <button type="button" id="apply_points" <?php echo $max_redeemable_points > 0 ? '' : 'disabled'; ?>>Apply</button>
                <button type="button" id="remove_points" style="display: <?php echo $applied_points > 0 ? 'inline-block' : 'none'; ?>;">Remove</button>
            </div>
        </div>
    </div>
    <?php
}

public function apply_points_callback() {
    global $wpdb;
    check_ajax_referer('lrp_checkout_nonce', 'nonce');
    
    $points = isset($_POST['points']) ? absint($_POST['points']) : 0;
    $user_id = get_current_user_id();
    $available_points = get_user_meta($user_id, 'available_points', true) ?: 0;

    // Fetch configuration from database
    $config_table = $wpdb->prefix . 'lrp_points_configurations';
    $config = $wpdb->get_row("SELECT each_point_value, loyalty_point_value FROM $config_table LIMIT 1");
    $each_point_value = !empty($config->each_point_value) ? floatval($config->each_point_value) : 1;
    $loyalty_point_value = !empty($config->loyalty_point_value) ? floatval($config->loyalty_point_value) : 1;

    // Calculate discount
    $discount = ($each_point_value != 0) ? ($points / $each_point_value) * $loyalty_point_value : 0;

    // Validate
    if ($points <= 0 || $points > $available_points) {
        wp_send_json_error(['message' => 'Invalid points amount or insufficient points']);
        return;
    }

    $cart = WC()->cart;
    if (!$cart) {
        wp_send_json_error(['message' => 'Cart not available']);
        return;
    }

    $total_cart_value = $cart->get_subtotal();
    $max_redeemable_points = floor(($total_cart_value / $loyalty_point_value) * $each_point_value);
    $max_redeemable_points = min($available_points, $max_redeemable_points);

    if ($points > $max_redeemable_points) {
        wp_send_json_error(['message' => 'Points exceed redeemable amount for this order']);
        return;
    }

    // Save points and discount to session (do not deduct points or log redemption yet)
    WC()->session->set('lrp_applied_discount', $discount);
    WC()->session->set('lrp_applied_points', $points);

    // Apply discount as a fee
    if ($points > 0) {
        WC()->cart->fees_api()->remove_all_fees(); // Clear existing fees
        WC()->cart->add_fee('Loyalty Points Discount', -$discount, false);
    } else {
        WC()->cart->fees_api()->remove_all_fees();
    }

    // Recalculate cart totals
    WC()->cart->calculate_totals();

    // Refresh checkout fragments
    ob_start();
    wc_get_template('checkout/review-order.php', ['checkout' => WC()->checkout()]);
    $order_review = ob_get_clean();

    wp_send_json_success([
        'message' => 'Points applied successfully. Discount: $' . number_format($discount, 2),
        'available_points' => $available_points,
        'discount' => $discount,
        'fragments' => apply_filters('woocommerce_update_order_review_fragments', [
            '.woocommerce-checkout-review-order-table' => $order_review
        ])
    ]);
}
public function remove_points_callback() {
    global $wpdb;
    check_ajax_referer('lrp_checkout_nonce', 'nonce');
    $applied_points = (int) WC()->session->get('lrp_applied_points', 0);
    $user_id = get_current_user_id();
    $available_points = (int) get_user_meta($user_id, 'available_points', true);
    $redeemed_points = (int) get_user_meta($user_id, 'redeemed_points', true);

    // Clear session
    WC()->session->set('lrp_applied_discount', 0);
    WC()->session->set('lrp_applied_points', 0);

    // Restore user meta
    $updated_points = $available_points + $applied_points;
    $updated_redeemed = max(0, $redeemed_points - $applied_points);
    update_user_meta($user_id, 'available_points', $updated_points);
    update_user_meta($user_id, 'redeemed_points', $updated_redeemed);

    // Log transaction
    $table_name = $wpdb->prefix . 'loyalty_points';
    $wpdb->insert($table_name, [
        'user_id' => $user_id,
        'points' => $applied_points,
        'type' => 'restore',
        'value' => 0,
        'source' => 'Checkout'
    ]);

    // Remove discount
    if (WC()->cart) {
        WC()->cart->fees_api()->remove_all_fees();
        WC()->cart->calculate_totals();
    }

    // Refresh checkout fragments
    ob_start();
    wc_get_template('checkout/review-order.php', ['checkout' => WC()->checkout()]);
    $order_review = ob_get_clean();
    wp_send_json_success([
        'message' => 'Points removed successfully.',
        'updated_points' => $updated_points,
        'fragments' => apply_filters('woocommerce_update_order_review_fragments', [
            '.woocommerce-checkout-review-order-table' => $order_review
        ])
    ]);
}

public function process_loyalty_points_redemption($order_id) {
    global $wpdb;
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    $user_id = $order->get_user_id();
    if (!$user_id) {
        return; // Skip for guest orders
    }

    $points = WC()->session->get('lrp_applied_points', 0);
    $discount = WC()->session->get('lrp_applied_discount', 0);

    if ($points <= 0 || $discount <= 0) {
        // Clear session even if no points were applied
        WC()->session->set('lrp_applied_discount', 0);
        WC()->session->set('lrp_applied_points', 0);
        return;
    }

    $available_points = get_user_meta($user_id, 'available_points', true) ?: 0;
    $redeemed_points = get_user_meta($user_id, 'redeemed_points', true) ?: 0;

    // Validate points
    if ($points > $available_points) {
        error_log("Loyalty Points Error: Attempted to redeem $points points, but only $available_points available for user $user_id on order $order_id");
        return;
    }

    // Update user meta
    $new_points = $available_points - $points;
    $new_redeemed = $redeemed_points + $points;
    update_user_meta($user_id, 'available_points', $new_points);
    update_user_meta($user_id, 'redeemed_points', $new_redeemed);

    // Log redemption in loyalty_points table
    $table_name = $wpdb->prefix . 'loyalty_points';
    $wpdb->insert($table_name, [
        'user_id' => $user_id,
        'points' => $points,
        'type' => 'redeem',
        'value' => $discount,
        'source' => 'Checkout',
        'order_id' => $order_id,
        'date' => current_time('mysql')
    ]);

    if ($wpdb->last_error) {
        error_log("Loyalty Points Error: Failed to record redemption for order $order_id: " . $wpdb->last_error);
    }

    // Clear session data
    WC()->session->set('lrp_applied_discount', 0);
    WC()->session->set('lrp_applied_points', 0);
}
    public function award_signup_points($user_id) {
        $points = get_option('lrp_customer_signup_points', 50);
        if ($points > 0) {
            lrp_add_points($user_id, $points, 'earn', 'Signup');
        }
    }

    public function award_review_points($comment_id, $comment_approved) {
        if (LRP_Utils::is_site_license_expired()) {
            return;
        }
        global $wpdb;
        if ($comment_approved === 1) {
            $comment = get_comment($comment_id);
            if ($comment && $comment->comment_type === 'review') {
                $user_id = $comment->user_id;
                if ($user_id) {
                    // Fetch points from wp_lrp_app_configurations table
                    $points = $wpdb->get_var("SELECT product_review_points FROM wp_lrp_app_configurations");
                    $points = (int)$points ?: 10; // Default to 10 if no value or invalid
                    if ($points > 0) {
                        lrp_add_points($user_id, $points, 'earn', 'Product Review');
                    }
                }
            }
        }
    }

public function award_purchase_points($order_id) {
    if (!$order_id) {
        error_log("LRP: award_purchase_points called with invalid order_id");
        return;
    }

    // Prevent re-entry
    if (get_post_meta($order_id, '_lrp_points_awarded', true)) {
        error_log("LRP: Points already awarded (post_meta) for order $order_id");
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        error_log("LRP: Invalid order object for order_id $order_id");
        return;
    }

    $user_id = $order->get_user_id();
    // Try map guest by billing email
    if (!$user_id || $user_id == 0) {
        $billing_email = $order->get_billing_email();
        if ($billing_email) {
            $user_obj = get_user_by('email', $billing_email);
            if ($user_obj) {
                $user_id = $user_obj->ID;
            } else {
                update_post_meta($order_id, '_lrp_points_pending', 1);
                error_log("LRP: No WP user for billing email {$billing_email} on order {$order_id}. Marked pending.");
                return;
            }
        } else {
            update_post_meta($order_id, '_lrp_points_pending', 1);
            error_log("LRP: No billing email for order $order_id, marked pending");
            return;
        }
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'loyalty_points';
    $product_loyalty_table = $wpdb->prefix . 'lrp_product_loyalty';
    $total_awarded = 0;

    // Sum points for all items
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if (!$product) continue;
        $product_id = $product->get_id();
        $loyalty_data = $wpdb->get_row($wpdb->prepare(
            "SELECT product_loyalty_points, frontend_visibility FROM {$product_loyalty_table} WHERE product_id = %d",
            $product_id
        ));
        if (!$loyalty_data || intval($loyalty_data->frontend_visibility) !== 1 || intval($loyalty_data->product_loyalty_points) <= 0) {
            continue;
        }
        $points = (int) $loyalty_data->product_loyalty_points;
        $qty = max(1, intval($item->get_quantity()));
        $total_awarded += ($points * $qty);
    }

    if ($total_awarded <= 0) {
        // mark as processed so we don't re-check this order repeatedly
        update_post_meta($order_id, '_lrp_points_awarded', 1);
        update_post_meta($order_id, '_lrp_points_awarded_amount', 0);
        error_log("LRP: No points to award for order $order_id");
        return;
    }

    // If a row already exists for this exact order_id, bail out
    $exists = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d AND order_id = %d AND type = %s AND source = %s",
        $user_id, $order_id, 'earn', 'Purchase'
    ));
    if ($exists > 0) {
        update_post_meta($order_id, '_lrp_points_awarded', 1);
        update_post_meta($order_id, '_lrp_points_awarded_amount', $total_awarded);
        error_log("LRP: Found existing loyalty_points row for order $order_id. Skipping.");
        return;
    }

    // Mark early to reduce race-conditions
    update_post_meta($order_id, '_lrp_points_awarded', 1);

    $did_helper = false;
    if (function_exists('lrp_add_points')) {
        // Prefer helper (may update user meta and optionally log)
        try {
            lrp_add_points($user_id, $total_awarded, 'earn', 'Purchase', $order_id);
            $did_helper = true;
            error_log("LRP: Called lrp_add_points for order {$order_id}, user {$user_id}, points {$total_awarded}");
        } catch (Throwable $t) {
            // In older PHP versions Throwable may not exist; this is defensive
            error_log("LRP: lrp_add_points failed for order {$order_id}: " . $t->getMessage());
            $did_helper = false;
        }
    }

    // Verify whether an order-linked row exists after helper call
    $exists_after = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d AND order_id = %d AND type = %s AND source = %s",
        $user_id, $order_id, 'earn', 'Purchase'
    ));

    if ($exists_after === 0) {
        // Helper didn't create order-linked row -> insert exactly one row
        $inserted = $wpdb->insert(
            $table_name,
            [
                'user_id'  => $user_id,
                'points'   => $total_awarded,
                'type'     => 'earn',
                'value'    => 0,
                'source'   => 'Purchase',
                'order_id' => $order_id,
                'date'     => current_time('mysql'),
            ],
            ['%d','%d','%s','%f','%s','%d','%s']
        );
        if ($inserted === false) {
            error_log("LRP: Failed manual insert for order {$order_id}: " . $wpdb->last_error);
            // rollback marker to allow admin retry
            delete_post_meta($order_id, '_lrp_points_awarded');
            return;
        }
        error_log("LRP: Manual insert created loyalty_points row for order {$order_id}");
    } else {
        error_log("LRP: Verified loyalty_points row exists after helper for order {$order_id} (count={$exists_after})");
    }

    // Record awarded amount
    update_post_meta($order_id, '_lrp_points_awarded_amount', $total_awarded);

    // Clean up orphan duplicates for same user/points/day that lack order_id (safe)
    $today = date('Y-m-d', current_time('timestamp'));
    $cleanup_sql = $wpdb->prepare(
        "DELETE lp FROM {$table_name} lp
         WHERE lp.user_id = %d
           AND lp.points = %d
           AND (lp.order_id IS NULL OR lp.order_id = 0)
           AND DATE(lp.date) = %s
           AND lp.type = 'earn'
           AND lp.source = 'Purchase'",
        $user_id, $total_awarded, $today
    );
    $deleted = $wpdb->query($cleanup_sql);
    if ($deleted !== false && $deleted > 0) {
        error_log("LRP: Cleaned up {$deleted} orphan loyalty_points rows for user {$user_id} on {$today}");
    }   
}

    // ---------- UPDATED refer_friend() - outputs button that sends 'security' and 'refer_email' ----------
    public function refer_friend() {
        if (LRP_Utils::is_site_license_expired()) {
            ?>
            <div class="lrp-license-expired-frontend" style="padding:10px;border:1px solid #f5c2c7;background:#fff2f2;margin-bottom:15px;">
                <strong>Loyalty temporarily disabled:</strong> Our loyalty features are currently suspended due to license expiration. Please contact site admin or NetScore support to renew the license.
            </div>
            <?php
            return;
        }
        $user_id = get_current_user_id();
        $referral_points = get_option('lrp_referral_points', 50);
        $referral_code = get_user_meta($user_id, 'referral_code', true);
        if (empty($referral_code)) {
            $referral_code = 'LR' . $user_id . 'DC' . date('YmdHis');
            update_user_meta($user_id, 'referral_code', $referral_code);
        }
        ?>
        <div class="lrp-refer-friend">
            <img src="<?php echo esc_url( home_url('/') . 'wp-content/uploads/2025/09/pexels-photo-670061.webp' ); ?>" style="width:350px; height:250px;" alt="Refer a Friend" onerror="this.src='https://via.placeholder.com/350x250';">
            <p>Share your code with your friend. On signup, you can get <strong><?php echo esc_html($referral_points); ?></strong> points & they can get <strong><?php echo esc_html($referral_points); ?></strong> points too.</p>
            <p>Your Code: <span class="lrp-referral-code"><?php echo esc_html($referral_code); ?></span></p>
            <center><input type="email" id="refer_email" name="refer_email" placeholder="Enter email here..."></center>
            <button type="button" id="share_earn">Share & Earn</button>
            <div id="refer-message" role="status" aria-live="polite"></div>
        </div>
        <?php
        
    }

    // ---------- UPDATED refer_friend_callback() ----------
    public function refer_friend_callback() {
    global $wpdb;

    // Accept either 'security' or 'nonce' for flexibility
    $passed = isset($_POST['security']) ? 'security' : (isset($_POST['nonce']) ? 'nonce' : false);
    if (!$passed) {
        wp_send_json_error(['message' => 'Missing security token.']);
        wp_die();
    }
    if (!check_ajax_referer('lrp_refer_nonce', $passed, false)) {
        wp_send_json_error(['message' => 'Invalid security token.']);
        wp_die();
    }

    $user_id = get_current_user_id();
    if (!$user_id) {
        wp_send_json_error(['message' => 'You must be logged in to refer a friend.']);
        wp_die();
    }

    // Accept either 'email' or 'refer_email' from client
    $refer_email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : (isset($_POST['refer_email']) ? sanitize_email(wp_unslash($_POST['refer_email'])) : '');
    if (!is_email($refer_email)) {
        wp_send_json_error(['message' => 'Please enter a valid email address.']);
        wp_die();
    }

    // Disallowed characters: + - * / , $ % & ( ) ! #
    if (preg_match('/[+\-\*\/,\$%&()!#]/', $refer_email)) {
        wp_send_json_error(['message' => 'This email contains invalid characters. Please remove + - * / , $ % & ( ) ! # and try again.']);
        wp_die();
    }

    // Prevent self-referral
    $current_user = wp_get_current_user();
    if ($current_user && !empty($current_user->user_email) && $current_user->user_email === $refer_email) {
        wp_send_json_error(['message' => 'You cannot refer your own email.']);
        wp_die();
    }

    // Prevent referring already-registered email
    if (email_exists($refer_email)) {
        wp_send_json_error(['message' => 'This user is already registered.']);
        wp_die();
    }

    // Ensure referral code exists for referrer
    $referral_points = intval(get_option('lrp_referral_points', 50));
    $referral_code = get_user_meta($user_id, 'referral_code', true);
    if (empty($referral_code)) {
        $referral_code = 'LR' . $user_id . 'DC' . date('YmdHis');
        update_user_meta($user_id, 'referral_code', $referral_code);
    }

    // Insert referral record into DB
    $table_name = $wpdb->prefix . 'lrp_referrals';
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
    if ($table_exists !== $table_name) {
        error_log('Referral table does not exist: ' . $table_name);
        wp_send_json_error(['message' => 'Database error: referral table does not exist. Please contact support.']);
        wp_die();
    }

    $inserted = $wpdb->insert(
        $table_name,
        [
            'user_id' => $user_id,
            'referral_email' => $refer_email,
            'referral_code' => $referral_code,
            'status' => 'pending',
            'created_date' => current_time('mysql'),
        ],
        ['%d', '%s', '%s', '%s', '%s']
    );

    if ($inserted === false) {
        error_log('Failed to insert referral into ' . $table_name . ': ' . $wpdb->last_error);
        wp_send_json_error(['message' => 'Failed to process referral. Please try again.']);
        wp_die();
    }

    // Build email (HTML)
    $site_name = get_option('blogname', 'Your Site');
    $admin_email = get_option('admin_email', 'no-reply@' . wp_parse_url(home_url(), PHP_URL_HOST));
    $subject = sprintf(__('You\'ve Been Referred to %s!', 'lrp'), $site_name);
    $message = '<html><body>';
    $message .= '<h2>' . sprintf(__('You\'ve Been Referred to %s!', 'lrp'), esc_html($site_name)) . '</h2>';
    $message .= '<p>' . sprintf(__('Your friend has invited you to join %1$s! Sign up using the referral code below to earn %2$d points:', 'lrp'), esc_html($site_name), $referral_points) . '</p>';
    $message .= '<p><strong>' . esc_html($referral_code) . '</strong></p>';
    $message .= '<p><a href="' . esc_url(wc_get_page_permalink('myaccount')) . '">' . esc_html__('Sign up here', 'lrp') . '</a></p>';
    $message .= '<p>' . sprintf(__('Thank you,<br>%s Team', 'lrp'), esc_html($site_name)) . '</p>';
    $message .= '</body></html>';

    // Headers: HTML and From
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $site_name . ' <' . $admin_email . '>',
        'Reply-To: ' . $admin_email
    ];

    // Send the email
    $sent = wp_mail($refer_email, $subject, $message, $headers);

    if ($sent) {
        // Update DB record status to 'sent'
        $wpdb->update(
            $table_name,
            ['status' => 'sent'],
            ['referral_email' => $refer_email, 'user_id' => $user_id],
            ['%s'],
            ['%s', '%d']
        );
        wp_send_json_success(['message' => 'Referral sent successfully to ' . esc_html($refer_email) . '!']);
    } else {
        // Log the failure for debugging
        error_log('Failed to send referral email to ' . $refer_email . '. Check SMTP configuration or server mail settings.');
        wp_send_json_error(['message' => 'Failed to send referral email. but please try again or contact support.']);
    }
    wp_die();
}

    public function add_referral_code_field() {
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="referral_code"><?php _e('Referral Code (Optional)', 'woocommerce'); ?></label>
            <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="referral_code" id="referral_code" value="<?php echo esc_attr(isset($_GET['ref']) ? $_GET['ref'] : ''); ?>">
        </p>
        <?php
    }

    public function save_referral_code_field($customer_id) {
        if (isset($_POST['referral_code']) && !empty($_POST['referral_code'])) {
            update_user_meta($customer_id, 'referral_code_used', sanitize_text_field($_POST['referral_code']));
        }
    }

public function loyalty_points_earned() {
    $user_id = get_current_user_id();
    $available_points = get_user_meta($user_id, 'available_points', true) ?: 0;
    $total_earned = 0;
    $total_redeemed = 0;
    global $wpdb;
    $table_name = $wpdb->prefix . 'loyalty_points';

    // Calculate total earned and redeemed points
    $earned_points = $wpdb->get_results($wpdb->prepare("SELECT points, type FROM $table_name WHERE user_id = %d", $user_id));
    foreach ($earned_points as $point) {
        if ($point->type === 'earn') {
            $total_earned += $point->points;
        } elseif ($point->type === 'redeem') {
            $total_redeemed += $point->points;
        }
    }

    // Pagination setup
    $per_page = 10;
    $current_page = get_query_var('paged') ? max(1, intval(get_query_var('paged'))) : 1;
    $offset = ($current_page - 1) * $per_page;
    $total_items = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE user_id = %d AND type = 'earn'", $user_id));
    $total_pages = ceil($total_items / $per_page);

    // Fetch paginated results
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT date, source, points, order_id FROM $table_name WHERE user_id = %d AND type = 'earn' ORDER BY date DESC LIMIT %d OFFSET %d",
        $user_id,
        $per_page,
        $offset
    ));

    ?>
    <div class="lrp-points-summary">
        <div>
            <strong><?php echo esc_html($total_earned); ?></strong>
            <span>TOTAL POINTS EARNED</span>
        </div>
        <div>
            <strong><?php echo esc_html($available_points); ?></strong>
            <span>AVAILABLE POINTS</span>
        </div>
        <div>
            <strong><?php echo esc_html($total_redeemed); ?></strong>
            <span>TOTAL POINTS REDEEMED</span>
        </div>
    </div>
    <div class="lrp-table-container">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Activity Performed</th>
                    <th>Reference ID</th>
                    <th>Points Earned</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($results) {
                    foreach ($results as $row) {
                        echo '<tr>';
                        echo '<td>' . esc_html(date('d/m/Y', strtotime($row->date))) . '</td>';
                        echo '<td>' . esc_html($row->source ? $row->source : 'Manual') . '</td>';
                        echo '<td>' . esc_html($row->order_id ? $row->order_id : '-') . '</td>';
                        echo '<td>' . esc_html($row->points) . '</td>';
                        echo '</tr>';
                    }
                } else {
                    echo '<tr><td colspan="4">No points earned yet</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php
    if ($total_pages > 1) {
        $base_url = trailingslashit(wc_get_account_endpoint_url('loyalty-points-earned'));
        ?>
        <div class="lrp-pagination">
            <ul style="list-style: none; display: flex; gap: 10px; justify-content: center; margin-top: 20px;">
                <?php if ($current_page > 1) : ?>
                    <li><a href="<?php echo esc_url($base_url . 'page/' . ($current_page - 1) . '/'); ?>" style="padding: 5px 10px; border: 1px solid #ccc; text-decoration: none;">Previous</a></li>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++) : ?>
                    <li><a href="<?php echo esc_url($base_url . 'page/' . $i . '/'); ?>" style="padding: 5px 10px; border: 1px solid #ccc; text-decoration: none; <?php echo $i === $current_page ? 'background-color: #0071a1; color: white;' : ''; ?>"><?php echo $i; ?></a></li>
                <?php endfor; ?>
                <?php if ($current_page < $total_pages) : ?>
                    <li><a href="<?php echo esc_url($base_url . 'page/' . ($current_page + 1) . '/'); ?>" style="padding: 5px 10px; border: 1px solid #ccc; text-decoration: none;">Next</a></li>
                <?php endif; ?>
            </ul>
        </div>
        <?php
    }
}
    
    public function redeem_points_history() {
    $user_id = get_current_user_id();
    $available_points = get_user_meta($user_id, 'available_points', true) ?: 0;
    $total_earned = 0;
    $total_redeemed = 0;
    global $wpdb;
    $table_name = $wpdb->prefix . 'loyalty_points';
    $earned_points = $wpdb->get_results($wpdb->prepare("SELECT points, type FROM $table_name WHERE user_id = %d", $user_id));
    foreach ($earned_points as $point) {
        if ($point->type === 'earn') {
            $total_earned += $point->points;
        } elseif ($point->type === 'redeem') {
            $total_redeemed += $point->points;
        }
    }
    $per_page = 10;
    $current_page = get_query_var('paged') ? max(1, intval(get_query_var('paged'))) : 1;
    $offset = ($current_page - 1) * $per_page;
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name WHERE user_id = %d AND type = 'redeem' ORDER BY date DESC LIMIT %d OFFSET %d",
        $user_id, $per_page, $offset
    ));
    $total_items = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE user_id = %d AND type = 'redeem'", $user_id));
    ?>
    <table class="lrp-redeem-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Activity Performed</th>
                <th>Reference ID</th>
                <th>Points Redeemed</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if ($results) {
                foreach ($results as $row) {
                    echo '<tr>';
                    echo '<td>' . esc_html(date('d/m/Y', strtotime($row->date))) . '</td>';
                    echo '<td>' . esc_html($row->source ? $row->source : 'Manual') . '</td>';
                    // Determine Reference ID based on source
                    $reference = '-';
                    if ($row->source === 'Checkout' && !empty($row->order_id)) {
                        // For Checkout redemptions, use the order_id
                        $reference = $row->order_id;
                    } elseif ($row->source === 'Gift Card Redemption') {
                        // For Gift Card redemptions, fetch gift card number
                        $gc_table = $wpdb->prefix . 'lrp_gift_cards';
                        // Try exact timestamp match
                        $gift_card = $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT gift_card_number FROM {$gc_table} WHERE user_id = %d AND created_date = %s LIMIT 1",
                                $user_id,
                                $row->date
                            )
                        );
                        // Fallback: same day match
                        if (!$gift_card) {
                            $gift_card = $wpdb->get_var(
                                $wpdb->prepare(
                                    "SELECT gift_card_number FROM {$gc_table} WHERE user_id = %d AND DATE(created_date) = DATE(%s) ORDER BY created_date DESC LIMIT 1",
                                    $user_id,
                                    $row->date
                                )
                            );
                        }
                        // Fallback: last LRP- prefixed code for this user
                        if (!$gift_card) {
                            $gift_card = $wpdb->get_var(
                                $wpdb->prepare(
                                    "SELECT gift_card_number FROM {$gc_table} WHERE user_id = %d AND gift_card_number LIKE %s ORDER BY created_date DESC LIMIT 1",
                                    $user_id,
                                    'LRP-%'
                                )
                            );
                        }
                        if ($gift_card) {
                            $reference = $gift_card;
                        }
                    }
                    echo '<td>' . esc_html($reference) . '</td>';
                    echo '<td>' . esc_html($row->points) . '</td>';
                    echo '<td>' . esc_html(number_format($row->value, 2)) . '</td>';
                    echo '</tr>';
                }
            } else {
                echo '<tr><td colspan="5">No redemption history</td></tr>';
            }
            ?>
        </tbody>
    </table>
    <?php
    $total_pages = ceil($total_items / $per_page);
    if ($total_pages > 1) {
        $base_url = trailingslashit(wc_get_account_endpoint_url('redeem-points-history'));
        echo '<nav class="lrp-pagination" aria-label="Redeem Points Pagination">';
        echo '<ul style="list-style: none; display: flex; gap: 10px; justify-content: center; margin-top: 20px;">';
        if ($current_page > 1) {
            echo '<li><a href="' . esc_url($base_url . 'page/' . ($current_page - 1) . '/') . '" style="padding: 5px 10px; border: 1px solid #ccc; text-decoration: none;">Previous</a></li>';
        }
        for ($i = 1; $i <= $total_pages; $i++) {
            $active = $i === $current_page ? ' style="background-color: #0071a1; color: white; padding: 5px 10px; border: 1px solid #ccc; text-decoration: none;"' : ' style="padding: 5px 10px; border: 1px solid #ccc; text-decoration: none;"';
            echo '<li><a href="' . esc_url($base_url . 'page/' . $i . '/') . '"' . $active . '>' . $i . '</a></li>';
        }
        if ($current_page < $total_pages) {
            echo '<li><a href="' . esc_url($base_url . 'page/' . ($current_page + 1) . '/') . '" style="padding: 5px 10px; border: 1px solid #ccc; text-decoration: none;">Next</a></li>';
        }
        echo '</ul>';
        echo '</nav>';
    }
}

    public function generate_gift_card() {
    if (LRP_Utils::is_site_license_expired()) {
            ?>
            <div class="lrp-license-expired-frontend" style="padding:10px;border:1px solid #f5c2c7;background:#fff2f2;margin-bottom:15px;">
                <strong>Loyalty temporarily disabled:</strong> Our loyalty features are currently suspended due to license expiration. Please contact site admin or NetScore support to renew the license.
            </div>
            <?php
            return;
        }
        global $wpdb;
        $user_id = get_current_user_id();
        $available_points = get_user_meta($user_id, 'available_points', true) ?: 0;
        $config_table = $wpdb->prefix . 'lrp_points_configurations';
        $config = $wpdb->get_row("SELECT each_point_value, loyalty_point_value FROM $config_table LIMIT 1");
        $each_point_value = !empty($config->each_point_value) ? floatval($config->each_point_value) : 1;
        $loyalty_point_value = !empty($config->loyalty_point_value) ? floatval($config->loyalty_point_value) : 1;
        $max_amount = ($each_point_value != 0) ? ($available_points / $each_point_value) * $loyalty_point_value : 0;
        ?>
        <div class="lrp-gift-card">
            <h2>GIFT CERTIFICATE</h2>
            <p>Congratulations! You can turn your loyalty points into a gift card!</p>
            <div>
                <span>Points Available: <strong><?php echo esc_html($available_points); ?></strong></span>
                <span>Max Amount: <strong>$<?php echo esc_html(number_format($max_amount, 2)); ?></strong></span>
            </div>
            <input type="number" id="points_to_redeem" max="<?php echo esc_attr($available_points); ?>" min="1" placeholder="Points to redeem">
            <input type="email" id="receiver_email" placeholder="Receiver's Email">
            <div id="redeemAmountDisplay"></div>
            <button type="button" id="generate_gift_card">Generate Gift Card</button>
        </div>
        <?php
}

public function generate_gift_card_callback() {
    check_ajax_referer('lrp_checkout_nonce', 'security');

    $user_id = get_current_user_id();
    if (!$user_id) {
        wp_send_json_error(['message' => 'You must be logged in to generate a gift card.']);
        wp_die();
    }

    $points = isset($_POST['points']) ? intval($_POST['points']) : 0;
    $receiver_email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';

    if ($points <= 0 || !is_email($receiver_email)) {
        wp_send_json_error(['message' => 'Invalid input']);
        wp_die();
    }

    // Disallowed characters: + - * / , $ % & ( ) ! #
    if (preg_match('/[+\-\*\/,\$%&()!#]/', $receiver_email)) {
        wp_send_json_error(['message' => 'Receiver email contains invalid characters. Remove + - * / , $ % & ( ) ! # and try again.']);
        wp_die();
    }

    $available_points = get_user_meta($user_id, 'available_points', true) ?: 0;
    if ($points > $available_points) {
        wp_send_json_error(['message' => 'Not enough points']);
        wp_die();
    }
    global $wpdb;
    $table_name = $wpdb->prefix . 'lrp_gift_cards';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
        wp_send_json_error(['message' => 'Database error: Gift card table does not exist']);
        wp_die();
    }
    // Fetch values from lrp_points_configurations table
    $config_table = $wpdb->prefix . 'lrp_points_configurations';
    $config = $wpdb->get_row("SELECT each_point_value, loyalty_point_value FROM $config_table LIMIT 1");
    $each_point_value = !empty($config->each_point_value) ? floatval($config->each_point_value) : 1;
    $loyalty_point_value = !empty($config->loyalty_point_value) ? floatval($config->loyalty_point_value) : 1;
    // Calculate amount using lrp_points_configurations only
    $amount = ($each_point_value != 0) ? ($points / $each_point_value) * $loyalty_point_value : 0;
    $coupon_code = strtoupper('LRP-' . wp_generate_password(8, false));
    $coupon = new WC_Coupon();
    $coupon->set_code($coupon_code);
    $coupon->set_amount($amount);
    $coupon->set_discount_type('fixed_cart');
    $coupon->set_usage_limit(1);
    $coupon->set_usage_limit_per_user(1);
    $coupon->set_individual_use(true);
    $coupon->set_description("Gift Card Redemption for user {$user_id}");
    $coupon->set_date_expires(date('Y-m-d', strtotime('+30 days')));
    $coupon->save();
    update_user_meta($user_id, 'available_points', $available_points - $points);
    $wpdb->insert($wpdb->prefix . 'loyalty_points', [
        'user_id' => $user_id,
        'points' => $points,
        'type' => 'redeem',
        'value' => $amount,
        'source' => 'Gift Card Redemption'
    ]);
    $wpdb->insert($table_name, [
        'user_id' => $user_id,
        'sent_email' => $receiver_email,
        'gift_card_number' => $coupon_code,
        'created_date' => current_time('mysql'),
        'expiry_date' => date('Y-m-d H:i:s', strtotime('+30 days'))
    ]);
    if ($wpdb->last_error) {
        wp_send_json_error(['message' => 'Failed to save gift card data']);
        wp_die();
    }
    $subject = 'Your Loyalty Gift Card Coupon Code';
    $message = "Hello,\n\nYou've received a Loyalty Gift Card!\n\nCoupon Code: {$coupon_code}\nAmount: $" . number_format($amount, 2) . "\nExpires: " . date('Y-m-d', strtotime('+30 days')) . "\n\nUse it at checkout to redeem your discount.\n\nThank you!";
    wp_mail($receiver_email, $subject, $message);
    wp_send_json_success(['message' => "Gift card code {$coupon_code} generated and emailed to {$receiver_email}"]);
    wp_die();
}

    public function gift_card_history() {
        $user_id = get_current_user_id();
        $per_page = 10;
        $current_page = isset($_GET['paged']) && is_numeric($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        global $wpdb;
        $table_name = $wpdb->prefix . 'lrp_gift_cards';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            echo '<div>Error: Gift card database table does not exist. Please contact support.</div>';
            return;
        }
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT gc.*, u.display_name, u.user_email
             FROM $table_name gc
             LEFT JOIN {$wpdb->users} u ON gc.user_id = u.ID
             WHERE gc.user_id = %d
             ORDER BY gc.created_date DESC
             LIMIT %d OFFSET %d",
            $user_id, $per_page, $offset
        ));
        $total_items = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE user_id = %d", $user_id));
        ?>
        <div class="lrp-table-container">
            <h2>Gift Card History</h2>
            <table>
                <thead>
                    <tr>
                        <th>S.No</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Gift Card Sent Email</th>
                        <th>Gift Card Number</th>
                        <th>Created Date</th>
                        <th>Expiry Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($results) {
                        $sno = $offset + 1;
                        foreach ($results as $row) {
                            $username = $row->display_name ? $row->display_name : 'N/A';
                            $email = $row->user_email ? $row->user_email : 'N/A';
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
                    } else {
                        echo '<tr><td colspan="7">No gift cards generated yet</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <?php
        $total_pages = ceil($total_items / $per_page);
        if ($total_pages > 1) {
            $base_url = wc_get_account_endpoint_url('gift-card-history');
            echo '<nav class="lrp-pagination" aria-label="Gift Card History Pagination">';
            echo '<ul>';
            if ($current_page > 1) {
                echo '<li><a href="' . esc_url(add_query_arg('paged', $current_page - 1, $base_url)) . '">Previous</a></li>';
            }
            for ($i = 1; $i <= $total_pages; $i++) {
                $active = $i === $current_page ? ' class="active"' : '';
                echo '<li><a href="' . esc_url(add_query_arg('paged', $i, $base_url)) . '"' . $active . '>' . $i . '</a></li>';
            }
            if ($current_page < $total_pages) {
                echo '<li><a href="' . esc_url(add_query_arg('paged', $current_page + 1, $base_url)) . '">Next</a></li>';
            }
            echo '</ul>';
            echo '</nav>';
        }
    }

    public function update_profile() {
        global $wpdb;
        $user_id = get_current_user_id();
        $dob = get_user_meta($user_id, 'birthday', true);
        $anniversary = get_user_meta($user_id, 'anniversary', true);
        $tyre_type = get_user_meta($user_id, 'tyre_type', true);
        $updated = false;

        // Fetch points configuration from wp_lrp_app_configurations
        $table_name = $wpdb->prefix . 'lrp_app_configurations';
        $config = $wpdb->get_row("SELECT birthday_points, anniversary_points FROM $table_name LIMIT 1");
        $birthday_points = $config ? (int)$config->birthday_points : 25;
        $anniversary_points = $config ? (int)$config->anniversary_points : 25;

        if (isset($_POST['save_profile']) && wp_verify_nonce($_POST['update_profile_nonce'], 'update_profile_action')) {
            if (!empty($_POST['birthday']) && !$dob) { // Only award points if birthday was not previously set
                update_user_meta($user_id, 'birthday', sanitize_text_field($_POST['birthday']));
                $dob = sanitize_text_field($_POST['birthday']);
                lrp_add_points($user_id, $birthday_points, 'earn', 'Birthday Submission');
                $updated = true;
            }
            if (!empty($_POST['anniversary']) && !$anniversary) { // Only award points if anniversary was not previously set
                update_user_meta($user_id, 'anniversary', sanitize_text_field($_POST['anniversary']));
                $anniversary = sanitize_text_field($_POST['anniversary']);
                lrp_add_points($user_id, $anniversary_points, 'earn', 'Anniversary Submission');
                $updated = true;
            }
            if ($updated) {
                echo '<div class="woocommerce-message" role="alert">Profile updated successfully!</div>';
            }
        }
        ?>
        <h2>Update Profile</h2>
        <div id="lrp-profile-display">
            <?php if ($dob || $anniversary): ?>
                <div class="lrp-profile-info">
                    <?php if ($dob): ?>
                        <p><strong>Date of Birth:</strong> <span id="display-birthday"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($dob))); ?></span></p>
                    <?php endif; ?>
                    <?php if ($anniversary): ?>
                        <p><strong>Anniversary:</strong> <span id="display-anniversary"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($anniversary))); ?></span></p>
                    <?php endif; ?>
                    <?php if ($tyre_type): ?>
                        <p><strong>Tyre Type:</strong> <?php echo esc_html(ucfirst($tyre_type)); ?> <small>(Determined automatically by your Loyalty Tier)</small></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <form method="post" id="update-profile-form" class="woocommerce-EditAccountForm edit-account">
                    <?php wp_nonce_field('update_profile_action', 'update_profile_nonce'); ?>
                    <p class="form-row form-row-wide">
                        <label for="birthday">Date of Birth</label>
                        <input type="date" name="birthday" id="birthday" value="<?php echo esc_attr($dob); ?>">
                    </p>
                    <p class="form-row form-row-wide">
                        <label for="anniversary">Anniversary</label>
                        <input type="date" name="anniversary" id="anniversary" value="<?php echo esc_attr($anniversary); ?>">
                    </p>
                    <p class="form-row form-row-wide">
                        <label for="tyre_type">Tyre Type</label>
                        <input type="text" name="tyre_type" id="tyre_type" value="<?php echo esc_attr($tyre_type); ?>" readonly>
                        <small>Tyre Type is determined automatically by your Loyalty Tier.</small>
                    </p>
                    <p>
                        <button type="submit" id="save-profile-btn" name="save_profile" class="button">Save Changes</button>
                    </p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    public function update_profile_ajax() {
        global $wpdb;
        check_ajax_referer('update_profile_action', 'update_profile_nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => 'You must be logged in to update your profile.']);
            wp_die();
        }

        // Fetch points configuration from wp_lrp_app_configurations
        $table_name = $wpdb->prefix . 'lrp_app_configurations';
        $config = $wpdb->get_row("SELECT birthday_points, anniversary_points FROM $table_name LIMIT 1");
        $birthday_points = $config ? (int)$config->birthday_points : 25;
        $anniversary_points = $config ? (int)$config->anniversary_points : 25;

        $updated = false;
        $dob = !empty($_POST['birthday']) ? sanitize_text_field($_POST['birthday']) : '';
        $anniversary = !empty($_POST['anniversary']) ? sanitize_text_field($_POST['anniversary']) : '';
        $tyre_type = !empty($_POST['tyre_type']) ? sanitize_text_field($_POST['tyre_type']) : get_user_meta($user_id, 'tyre_type', true);

        $existing_dob = get_user_meta($user_id, 'birthday', true);
        $existing_anniversary = get_user_meta($user_id, 'anniversary', true);

        if ($dob && !$existing_dob) { // Only award points if birthday was not previously set
            update_user_meta($user_id, 'birthday', $dob);
            lrp_add_points($user_id, $birthday_points, 'earn', 'Birthday Submission');
            $updated = true;
        }
        if ($anniversary && !$existing_anniversary) { // Only award points if anniversary was not previously set
            update_user_meta($user_id, 'anniversary', $anniversary);
            lrp_add_points($user_id, $anniversary_points, 'earn', 'Anniversary Submission');
            $updated = true;
        }
        if ($tyre_type) {
            update_user_meta($user_id, 'tyre_type', $tyre_type);
        }

        if ($updated) {
            $dob_formatted = $dob ? date_i18n(get_option('date_format'), strtotime($dob)) : '';
            $anniversary_formatted = $anniversary ? date_i18n(get_option('date_format'), strtotime($anniversary)) : '';
            $points_added = ($dob && !$existing_dob ? $birthday_points : 0) + ($anniversary && !$existing_anniversary ? $anniversary_points : 0);
            wp_send_json_success([
                'message' => 'Profile updated successfully!',
                'dob' => $dob_formatted,
                'anniversary' => $anniversary_formatted,
                'tyre_type' => ucfirst($tyre_type),
                'points_added' => $points_added
            ]);
        } else {
            wp_send_json_error(['message' => 'No changes were made or profile already updated.']);
        }

        wp_die();
    }

    public function loyalty_tiers() {
        global $wpdb;
        $user_id = get_current_user_id();
        $available_points = get_user_meta($user_id, 'available_points', true) ?: 0;

        // Fetch active tiers from wp_lrp_loyalty_tiers table
        $table_name = $wpdb->prefix . 'lrp_loyalty_tiers';
        $tiers = $wpdb->get_results("SELECT name, threshold, points, level FROM $table_name WHERE active = 1 ORDER BY threshold ASC");

        if (!$tiers) {
            echo '<div>No active loyalty tiers found. Please contact support.</div>';
            return;
        }

        // Determine current tier
        $current_tier = null;
        $highest_tier = end($tiers);
        foreach ($tiers as $index => $tier) {
            if ($available_points >= $tier->threshold) {
                $current_tier = $tier;
                // Check if next tier exists and points are less than next tier's threshold
                if (isset($tiers[$index + 1]) && $available_points < $tiers[$index + 1]->threshold) {
                    break;
                }
            }
        }

        // If no tier matches (points exceed all thresholds), set to highest active tier
        if (!$current_tier && $available_points >= $highest_tier->threshold) {
            $current_tier = $highest_tier;
        }

        // Determine next tier and progress
        $next_tier = null;
        $progress_percentage = 0;
        foreach ($tiers as $index => $tier) {
            if ($available_points < $tier->threshold) {
                $next_tier = $tier;
                if ($index == 0) {
                    // First tier, progress is points / threshold
                    $progress_percentage = ($tier->threshold > 0) ? ($available_points / $tier->threshold) * 100 : 0;
                } else {
                    // Progress between previous tier and next tier
                    $prev_tier = $tiers[$index - 1];
                    $progress_percentage = ($tier->threshold - $prev_tier->threshold > 0) ? 
                        (($available_points - $prev_tier->threshold) / ($tier->threshold - $prev_tier->threshold)) * 100 : 0;
                }
                break;
            }
        }

        // If user is in the highest tier, no next tier
        if ($current_tier && $current_tier->name === $highest_tier->name) {
            $next_tier = null;
            $progress_percentage = 100; // Full progress for highest tier
        }

        ?>
        <div class="lrp-loyalty-tiers">
            <h2>Your Loyalty Tier</h2>
            <div class="lrp-tier-summary">
                <h3>Current Tier: <?php echo esc_html($current_tier ? $current_tier->name : 'None'); ?></h3>
                <!-- <p>
                    <?php 
                    echo $current_tier 
                        ? sprintf('Level %d: %s points multiplier', esc_html($current_tier->level), esc_html(number_format($current_tier->points, 2))) 
                        : 'No benefits yet. Earn points to unlock tiers!';
                    ?>
                </p>
                <p>Your Points: <strong><?php echo esc_html($available_points); ?></strong></p> -->
            </div>
            <?php if ($next_tier): ?>
                <div class="lrp-tier-progress">
                    <h4>Progress to <?php echo esc_html($next_tier->name); ?> Tier</h4>
                    <div class="lrp-progress-bar">
                        <div style="width: <?php echo esc_attr(min(100, $progress_percentage)); ?>%;"></div>
                    </div>
                    <p>Need <?php echo esc_html($next_tier->threshold - $available_points); ?> more points to reach <?php echo esc_html($next_tier->name); ?>!</p>
                </div>
            <?php endif; ?>
            <h4>Available Tiers</h4>
            <div class="lrp-tiers-list">
                <?php foreach ($tiers as $tier): ?>
                    <div class="<?php echo ($current_tier && $tier->name === $current_tier->name) ? 'active' : ''; ?>">
                        <strong><?php echo esc_html($tier->name); ?> Tier</strong>
                        <p>Level <?php echo esc_html($tier->level); ?>: <?php echo esc_html($tier->threshold); ?> Points and above</p>
                        <p><?php echo esc_html(number_format($tier->points, 2)); ?>x Points Multiplier</p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    // public function apply_loyalty_discount($cart) {
    //     $discount = WC()->session->get('lrp_applied_discount', 0);
    //     error_log('apply_loyalty_discount: Discount = ' . $discount);
    //     if ($discount > 0) {
    //         $cart->add_fee('Loyalty Discount', -$discount, true, 'standard');
    //         error_log('apply_loyalty_discount: Fee added with amount = ' . -$discount);
    //         $cart->calculate_totals(); // Ensure totals are recalculated
    //         error_log('apply_loyalty_discount: Cart total after fee = ' . $cart->get_total());
    //     } else {
    //         error_log('apply_loyalty_discount: No discount applied');
    //     }
    // }

    public function display_loyalty_discount() {
        $discount = WC()->session->get('lrp_applied_discount', 0);
        if ($discount > 0) {
           // echo '<tr class="order-total lrp-discount"><th>Loyalty Discount</th><td data-title="Loyalty Discount">-' . wc_price($discount) . '</td></tr>';
        }
    }

    public function clear_discount_on_cart() {
        $this->applied_discount = 0;
        WC()->session->set('lrp_applied_discount', 0);
        WC()->session->set('lrp_applied_points', 0);
        $cart = WC()->cart;
        if ($cart && method_exists($cart, 'remove_fee')) {
            $cart->remove_fee('Loyalty Discount');
        } else {
            $fees = $cart->get_fees();
            foreach ($fees as $fee_key => $fee) {
                if ($fee->name === 'Loyalty Discount') {
                    unset($fees[$fee_key]);
                }
            }
            $cart->fees_api()->set_fees($fees);
        }
    }

    public function apply_discount_on_checkout($post_data) {
        parse_str($post_data, $data);
        if (!isset($data['lrp_points']) || !is_numeric($data['lrp_points'])) {
            return;
        }
        $points = intval($data['lrp_points']);
        if ($points <= 0) {
            $this->applied_discount = 0;
            WC()->session->set('lrp_applied_discount', 0);
            WC()->session->set('lrp_applied_points', 0);
            return;
        }
        $user_id = get_current_user_id();
        $available_points = get_user_meta($user_id, 'available_points', true) ?: 0;
        $point_value = get_option('lrp_each_point_value', 1);
        $loyalty_value = get_option('lrp_loyalty_point_value', 1);
        $discount = ($points * $point_value) / $loyalty_value;
        $cart = WC()->cart;
        $total_cart_value = $cart->get_subtotal();
        $max_redeemable = min($available_points, floor($total_cart_value * ($loyalty_value / $point_value)));
        if ($points <= $available_points && $points <= $max_redeemable && $discount > 0) {
            $this->applied_discount = $discount;
            WC()->session->set('lrp_applied_discount', $discount);
            WC()->session->set('lrp_applied_points', $points);
        } else {
            $this->applied_discount = 0;
            WC()->session->set('lrp_applied_discount', 0);
            WC()->session->set('lrp_applied_points', 0);
        }
    }

public function save_loyalty_discount_to_order($order) {
    if ($this->applied_discount > 0) {
        $order->add_meta_data('lrp_loyalty_discount', $this->applied_discount, true);
        $points = WC()->session->get('lrp_applied_points', 0);
        $order->add_meta_data('lrp_applied_points', $points, true);
    }
}
    public function custom_admin_styles() {
        ?>
        <style>
            .lrp-product-field { margin-bottom: 20px; }
            .lrp-admin-container { max-width: 800px; margin: 0 auto; }
            .lrp-nav-tabs { display: flex; border-bottom: 1px solid #ddd; margin-bottom: 20px; }
            .lrp-nav-tabs a { padding: 10px 15px; text-decoration: none; color: #0073aa; font-weight: bold; }
            .lrp-nav-tabs a.active { border-bottom: 3px solid #0073aa; }
            .lrp-tab-pane { display: none; }
            .lrp-tab-pane.active { display: block; }
            .lrp-card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        </style>
        <?php
    }

    public function add_special_fields_to_my_account() {
        $user_id = get_current_user_id();
        $birthday = get_user_meta($user_id, 'birthday', true);
        $anniversary = get_user_meta($user_id, 'anniversary', true);
        $tyre_type = get_user_meta($user_id, 'tyre_type', true);
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="birthday"><?php _e('Birthday', 'woocommerce'); ?> <span class="required">*</span></label>
            <input type="date" class="woocommerce-Input woocommerce-Input--text input-text" name="birthday" id="birthday" value="<?php echo esc_attr($birthday); ?>" required>
        </p>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="anniversary"><?php _e('Anniversary', 'woocommerce'); ?> <span class="required">*</span></label>
            <input type="date" class="woocommerce-Input woocommerce-Input--text input-text" name="anniversary" id="anniversary" value="<?php echo esc_attr($anniversary); ?>" required>
        </p>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="tyre_type"><?php _e('Tyre Type', 'woocommerce'); ?> <span class="required">*</span></label>
            <select class="woocommerce-Input woocommerce-Input--text input-text" name="tyre_type" id="tyre_type" required>
                <option value="">Select Tyre Type</option>
                <option value="silver" <?php selected($tyre_type, 'silver'); ?>>Silver</option>
                <option value="gold" <?php selected($tyre_type, 'gold'); ?>>Gold</option>
                <option value="platinum" <?php selected($tyre_type, 'platinum'); ?>>Platinum</option>
            </select>
        </p>
        <?php
    }

    public function save_special_fields($user_id) {
        if (isset($_POST['birthday']) && !empty($_POST['birthday'])) {
            update_user_meta($user_id, 'birthday', sanitize_text_field($_POST['birthday']));
        }
        if (isset($_POST['anniversary']) && !empty($_POST['anniversary'])) {
            update_user_meta($user_id, 'anniversary', sanitize_text_field($_POST['anniversary']));
        }
        if (isset($_POST['tyre_type']) && !empty($_POST['tyre_type'])) {
            update_user_meta($user_id, 'tyre_type', sanitize_text_field($_POST['tyre_type']));
        }
    }

public function display_social_share_buttons() {

    if (LRP_Utils::is_site_license_expired()) {
            return;
        }
    global $wpdb, $product;
    
    // Social Share Buttons
    $table = $wpdb->prefix . 'lrp_social_share_configurations';
    $config = $wpdb->get_row("SELECT email_share_points, facebook_share_points FROM $table LIMIT 1");
    $email_points = $config ? intval($config->email_share_points) : 10; // Default to 10
    $fb_points = $config ? intval($config->facebook_share_points) : 20; // Default to 20
    $product_url = get_permalink($product->get_id());
    $product_title = $product->get_title();
    $user_id = get_current_user_id();
    $fb_profile = get_user_meta($user_id, 'facebook_profile', true); // Assuming FB profile URL is stored in user meta
    $fb_share_url = $fb_profile ? esc_url($fb_profile) : 'https://www.facebook.com'; // Fallback to Facebook homepage
    ?>
    <div class="lrp-social-share" style="margin: 20px;">
        <h4>Share this product!</h4>
        <a href="#" class="lrp-share-btn lrp-share-facebook" data-type="facebook" data-points="<?php echo esc_attr($fb_points); ?>" data-url="<?php echo esc_attr($product_url); ?>" data-title="<?php echo esc_attr($product_title); ?>" data-fb-profile="<?php echo esc_attr($fb_share_url); ?>">
            <i class="fab fa-facebook-f"></i>
        </a>
        <a href="#" class="lrp-share-btn lrp-share-email" data-type="email" data-points="<?php echo esc_attr($email_points); ?>" data-url="<?php echo esc_attr($product_url); ?>" data-title="<?php echo esc_attr($product_title); ?>">
            <i class="fas fa-envelope"></i>
        </a>
    </div>
    <?php
}

public function share_social_callback() {
    check_ajax_referer('lrp_share_nonce', 'nonce');
    $user_id = get_current_user_id();
    if (!$user_id) {
        wp_send_json_error(['message' => 'You must be logged in to share and earn points.']);
        return;
    }
    $type = sanitize_text_field($_POST['type']);
    $points = intval($_POST['points']);
    $url = sanitize_text_field($_POST['url']);
    $title = sanitize_text_field($_POST['title']);
    if (!in_array($type, ['facebook', 'email']) || $points <= 0) {
        wp_send_json_error(['message' => 'Invalid share type or points.']);
        return;
    }
    // Award points (assuming lrp_add_points updates available_points)
    lrp_add_points($user_id, $points, 'earn', 'Shared via ' . ucfirst($type));
    
    // Prepare redirect URL
    if ($type === 'facebook') {
        $redirect_url = 'https://www.facebook.com/sharer/sharer.php?u=' . urlencode($url);
    } else if ($type === 'email') {
        $redirect_url = 'mailto:?subject=' . urlencode('Check out this product: ' . $title) . '&body=' . urlencode('I thought you might like this: ' . $url);
    }
    
    wp_send_json_success(['redirect_url' => $redirect_url]);
}

} // end class LRP_Frontend