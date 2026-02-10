<?php
/**
 * Plugin Name: Sky Local Pickup Locations
 * Plugin URI: https://skywebdesign.co.uk
 * Description: Modern local pickup location selector for WooCommerce with time slots and custom locations.
 * Version: 1.0.5
 * Author: Sky Web Design
 * Author URI: https://skywebdesign.co.uk
 * Text Domain: sky-local-pickup
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SKY_LOCAL_PICKUP_VERSION', '1.0.5');
define('SKY_LOCAL_PICKUP_PATH', plugin_dir_path(__FILE__));
define('SKY_LOCAL_PICKUP_URL', plugin_dir_url(__FILE__));

class Sky_Local_Pickup {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('plugins_loaded', [$this, 'init']);
    }

    public function init() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
            return;
        }

        // Admin
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);

        // Frontend
        add_action('wp_enqueue_scripts', [$this, 'frontend_scripts']);
        add_action('woocommerce_after_shipping_rate', [$this, 'display_pickup_selector'], 10, 2);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_pickup_location']);

        // Validation
        add_action('woocommerce_checkout_process', [$this, 'validate_pickup_location']);

        // Display in admin order
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'display_admin_order_meta']);

        // Display in emails
        add_action('woocommerce_email_after_order_table', [$this, 'display_email_pickup_location'], 10, 4);

        // Display on thank you page
        add_action('woocommerce_thankyou', [$this, 'display_thankyou_pickup_location'], 5);

        // Display on order details (customer account)
        add_action('woocommerce_order_details_after_order_table', [$this, 'display_order_details_pickup_location']);

        // AJAX handlers
        add_action('wp_ajax_sky_get_location_details', [$this, 'ajax_get_location_details']);
        add_action('wp_ajax_nopriv_sky_get_location_details', [$this, 'ajax_get_location_details']);
        add_action('wp_ajax_sky_save_pickup_selection', [$this, 'ajax_save_pickup_selection']);
        add_action('wp_ajax_nopriv_sky_save_pickup_selection', [$this, 'ajax_save_pickup_selection']);

        // HPOS compatibility
        add_action('before_woocommerce_init', [$this, 'declare_hpos_compatibility']);
    }

    public function declare_hpos_compatibility() {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }

    public function woocommerce_missing_notice() {
        echo '<div class="notice notice-error"><p><strong>Sky Local Pickup</strong> requires WooCommerce to be installed and activated.</p></div>';
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Local Pickup Locations',
            'Pickup Locations',
            'manage_woocommerce',
            'sky-local-pickup',
            [$this, 'admin_page']
        );
    }

    public function register_settings() {
        register_setting('sky_local_pickup_settings', 'sky_pickup_locations');
        register_setting('sky_local_pickup_settings', 'sky_pickup_label');
    }

    public function admin_scripts($hook) {
        if ($hook !== 'woocommerce_page_sky-local-pickup') {
            return;
        }

        wp_enqueue_style('sky-pickup-admin', SKY_LOCAL_PICKUP_URL . 'assets/admin.css', [], SKY_LOCAL_PICKUP_VERSION);
        wp_enqueue_script('sky-pickup-admin', SKY_LOCAL_PICKUP_URL . 'assets/admin.js', ['jquery'], SKY_LOCAL_PICKUP_VERSION, true);
    }

    public function frontend_scripts() {
        if (!is_checkout()) {
            return;
        }

        wp_enqueue_style('sky-pickup-frontend', SKY_LOCAL_PICKUP_URL . 'assets/frontend.css', [], SKY_LOCAL_PICKUP_VERSION);
        wp_enqueue_script('sky-pickup-frontend', SKY_LOCAL_PICKUP_URL . 'assets/frontend.js', ['jquery'], SKY_LOCAL_PICKUP_VERSION, true);

        $locations = get_option('sky_pickup_locations', []);

        wp_localize_script('sky-pickup-frontend', 'skyPickup', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sky_pickup_nonce'),
            'locations' => $locations,
        ]);
    }

    public function admin_page() {
        $locations = get_option('sky_pickup_locations', []);
        $label = get_option('sky_pickup_label', 'Select Pickup Location');

        if (isset($_POST['sky_pickup_save']) && wp_verify_nonce($_POST['sky_pickup_nonce'], 'sky_pickup_save')) {
            $new_locations = [];

            if (!empty($_POST['location_name'])) {
                foreach ($_POST['location_name'] as $key => $name) {
                    if (!empty($name)) {
                        // Process time slots
                        $time_slots = [];
                        if (!empty($_POST['location_slots'][$key])) {
                            foreach ($_POST['location_slots'][$key] as $slot_key => $slot) {
                                if (!empty($slot['open']) || !empty($slot['close'])) {
                                    $time_slots[] = [
                                        'days' => array_map('sanitize_text_field', $slot['days'] ?? []),
                                        'open' => sanitize_text_field($slot['open'] ?? ''),
                                        'close' => sanitize_text_field($slot['close'] ?? ''),
                                    ];
                                }
                            }
                        }

                        $new_locations[] = [
                            'name' => sanitize_text_field($name),
                            'address' => sanitize_text_field($_POST['location_address'][$key] ?? ''),
                            'postcode' => sanitize_text_field($_POST['location_postcode'][$key] ?? ''),
                            'google_link' => esc_url_raw($_POST['location_google_link'][$key] ?? ''),
                            'time_slots' => $time_slots,
                            'enabled' => isset($_POST['location_enabled'][$key]) ? 'yes' : 'no',
                            'same_day' => isset($_POST['location_same_day'][$key]) ? 'yes' : 'no',
                            'slot_morning' => isset($_POST['location_slot_morning'][$key]) ? 'yes' : 'no',
                            'slot_afternoon' => isset($_POST['location_slot_afternoon'][$key]) ? 'yes' : 'no',
                        ];
                    }
                }
            }

            update_option('sky_pickup_locations', $new_locations);
            update_option('sky_pickup_label', sanitize_text_field($_POST['pickup_label'] ?? 'Select Pickup Location'));

            $locations = $new_locations;
            $label = get_option('sky_pickup_label', 'Select Pickup Location');

            echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully!</p></div>';
        }

        include SKY_LOCAL_PICKUP_PATH . 'templates/admin-page.php';
    }

    public function display_pickup_selector($method, $index) {
        // Only show for local_pickup
        if ($method->method_id !== 'local_pickup') {
            return;
        }

        $locations = get_option('sky_pickup_locations', []);

        // Filter enabled locations only
        $enabled_locations = array_filter($locations, function($loc) {
            return ($loc['enabled'] ?? 'yes') === 'yes';
        });

        if (empty($enabled_locations)) {
            return;
        }

        $chosen = WC()->session->get('sky_chosen_pickup_location');
        $chosen_date = WC()->session->get('sky_chosen_pickup_date');
        $chosen_slot = WC()->session->get('sky_chosen_pickup_slot');

        // Generate available dates (3 days starting from tomorrow)
        $today = new DateTime();
        $today->setTime(0, 0, 0);

        $available_dates = [];
        $start_date = new DateTime();
        $start_date->modify('+1 day');
        for ($i = 0; $i < 3; $i++) {
            $date = clone $start_date;
            $date->modify("+{$i} days");
            $available_dates[] = [
                'value' => $date->format('Y-m-d'),
                'label' => $date->format('l, j F Y'),
            ];
        }
        ?>
        <div class="sky-pickup-wrapper" id="sky-pickup-wrapper" style="display:none;">
            <div class="sky-pickup-container">
                <select name="sky_pickup_location" id="sky_pickup_location" class="sky-pickup-select">
                    <option value=""><?php _e('-- Choose pickup location --', 'sky-local-pickup'); ?></option>
                    <?php foreach ($enabled_locations as $key => $location):
                        $time_slots_json = json_encode($location['time_slots'] ?? []);
                    ?>
                        <option value="<?php echo esc_attr($key); ?>"
                                data-address="<?php echo esc_attr($location['address']); ?>"
                                data-postcode="<?php echo esc_attr($location['postcode']); ?>"
                                data-google-link="<?php echo esc_attr($location['google_link'] ?? ''); ?>"
                                data-time-slots="<?php echo esc_attr($time_slots_json); ?>"
                                data-same-day="<?php echo esc_attr($location['same_day'] ?? 'no'); ?>"
                                data-slot-morning="<?php echo esc_attr($location['slot_morning'] ?? 'no'); ?>"
                                data-slot-afternoon="<?php echo esc_attr($location['slot_afternoon'] ?? 'no'); ?>"
                                <?php selected($chosen, $key); ?>>
                            <?php echo esc_html($location['name']); ?> - <?php echo esc_html($location['postcode']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <!-- Pickup Date Dropdown -->
                <div class="sky-pickup-date-wrapper" id="sky-pickup-date-wrapper" style="display:none;">
                    <select name="sky_pickup_date" id="sky_pickup_date" class="sky-pickup-select">
                        <option value=""><?php _e('-- Choose pickup date --', 'sky-local-pickup'); ?></option>
                        <option value="<?php echo esc_attr($today->format('Y-m-d')); ?>" class="sky-pickup-same-day-option" style="display:none;" <?php selected($chosen_date, $today->format('Y-m-d')); ?>>
                            <?php _e('Today', 'sky-local-pickup'); ?> - <?php echo esc_html($today->format('l, j F Y')); ?>
                        </option>
                        <?php foreach ($available_dates as $date): ?>
                            <option value="<?php echo esc_attr($date['value']); ?>" <?php selected($chosen_date, $date['value']); ?>>
                                <?php echo esc_html($date['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Time Slot Dropdown -->
                <div class="sky-pickup-slot-wrapper" id="sky-pickup-slot-wrapper" style="display:none;">
                    <select name="sky_pickup_time_slot" id="sky_pickup_time_slot" class="sky-pickup-select">
                        <option value=""><?php _e('-- Choose time slot --', 'sky-local-pickup'); ?></option>
                        <option value="morning" class="sky-pickup-morning-option" style="display:none;" <?php selected($chosen_slot, 'morning'); ?>><?php _e('Morning (9:00 AM - 12:00 PM)', 'sky-local-pickup'); ?></option>
                        <option value="afternoon" class="sky-pickup-afternoon-option" style="display:none;" <?php selected($chosen_slot, 'afternoon'); ?>><?php _e('Afternoon (12:00 PM - 5:00 PM)', 'sky-local-pickup'); ?></option>
                    </select>
                </div>

                <div class="sky-pickup-details" id="sky-pickup-details" style="display:none;">
                    <div class="sky-pickup-info">
                        <div class="sky-pickup-info-row">
                            <span class="sky-pickup-address"></span>
                        </div>
                        <div class="sky-pickup-info-row sky-pickup-hours-container">
                            <span class="sky-pickup-hours"></span>
                        </div>
                    </div>
                    <a href="#" class="sky-pickup-directions" id="sky-pickup-directions" target="_blank" style="display:none;">
                        View on Google Maps
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    public function validate_pickup_location() {
        $chosen_methods = WC()->session->get('chosen_shipping_methods');

        if (!empty($chosen_methods)) {
            foreach ($chosen_methods as $method) {
                if (strpos($method, 'local_pickup') !== false) {
                    // Check if sky_pickup_location exists and is not empty string
                    // Using isset and strict comparison because value could be "0" which is valid
                    if (!isset($_POST['sky_pickup_location']) || $_POST['sky_pickup_location'] === '') {
                        wc_add_notice(__('Please choose a collection point to continue.', 'sky-local-pickup'), 'error');
                        break;
                    }

                    // Get location to check same_day setting
                    $location_key = intval($_POST['sky_pickup_location']);
                    $locations = get_option('sky_pickup_locations', []);
                    $location = $locations[$location_key] ?? null;
                    $same_day_allowed = ($location && ($location['same_day'] ?? 'no') === 'yes');

                    // Validate pickup date
                    if (!isset($_POST['sky_pickup_date']) || $_POST['sky_pickup_date'] === '') {
                        wc_add_notice(__('Please choose a pickup date to continue.', 'sky-local-pickup'), 'error');
                    } else {
                        $selected_date = new DateTime($_POST['sky_pickup_date']);
                        $selected_date->setTime(0, 0, 0);

                        $today = new DateTime();
                        $today->setTime(0, 0, 0);

                        $tomorrow = new DateTime();
                        $tomorrow->modify('+1 day');
                        $tomorrow->setTime(0, 0, 0);

                        $max_date = new DateTime();
                        $max_date->modify('+3 days');
                        $max_date->setTime(0, 0, 0);

                        // Check if date is today and same_day is not allowed
                        if ($selected_date == $today && !$same_day_allowed) {
                            wc_add_notice(__('Same day pickup is not available for this location.', 'sky-local-pickup'), 'error');
                        }
                        // Check if date is in the past
                        elseif ($selected_date < $today) {
                            wc_add_notice(__('Please select a valid pickup date.', 'sky-local-pickup'), 'error');
                        }
                        // Check if date is beyond the allowed range (3 days from tomorrow)
                        elseif ($selected_date > $max_date) {
                            wc_add_notice(__('Please select a pickup date within the next 3 days.', 'sky-local-pickup'), 'error');
                        }
                    }

                    // Validate time slot
                    if (!isset($_POST['sky_pickup_time_slot']) || $_POST['sky_pickup_time_slot'] === '') {
                        wc_add_notice(__('Please choose a pickup time slot to continue.', 'sky-local-pickup'), 'error');
                    } else {
                        $selected_slot = $_POST['sky_pickup_time_slot'];
                        $slot_morning_allowed = ($location && ($location['slot_morning'] ?? 'no') === 'yes');
                        $slot_afternoon_allowed = ($location && ($location['slot_afternoon'] ?? 'no') === 'yes');

                        // Verify time slot is valid and enabled for this location
                        if ($selected_slot === 'morning' && !$slot_morning_allowed) {
                            wc_add_notice(__('Morning slot is not available for this location.', 'sky-local-pickup'), 'error');
                        } elseif ($selected_slot === 'afternoon' && !$slot_afternoon_allowed) {
                            wc_add_notice(__('Afternoon slot is not available for this location.', 'sky-local-pickup'), 'error');
                        } elseif (!in_array($selected_slot, ['morning', 'afternoon'])) {
                            wc_add_notice(__('Invalid pickup time slot selected.', 'sky-local-pickup'), 'error');
                        }
                    }

                    break;
                }
            }
        }
    }

    public function save_pickup_location($order_id) {
        // Check if location was selected (handle "0" as valid)
        if (!isset($_POST['sky_pickup_location']) || $_POST['sky_pickup_location'] === '') {
            return;
        }

        $location_key = intval($_POST['sky_pickup_location']);
        $locations = get_option('sky_pickup_locations', []);

        if (!isset($locations[$location_key])) {
            return;
        }

        $location = $locations[$location_key];
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        // Format time slots for display
        $hours_display = $this->format_time_slots_display($location['time_slots'] ?? []);

        // Save all location data
        $order->update_meta_data('_sky_pickup_location_name', $location['name']);
        $order->update_meta_data('_sky_pickup_location_address', $location['address']);
        $order->update_meta_data('_sky_pickup_location_postcode', $location['postcode']);
        $order->update_meta_data('_sky_pickup_location_hours', $hours_display);
        $order->update_meta_data('_sky_pickup_location_google_link', $location['google_link'] ?? '');

        // Save pickup date
        if (!empty($_POST['sky_pickup_date'])) {
            $pickup_date = sanitize_text_field($_POST['sky_pickup_date']);
            $order->update_meta_data('_sky_pickup_date', $pickup_date);

            // Format date for display
            $date_obj = new DateTime($pickup_date);
            $order->update_meta_data('_sky_pickup_date_display', $date_obj->format('l, j F Y'));
        }

        // Save time slot
        if (!empty($_POST['sky_pickup_time_slot'])) {
            $time_slot = sanitize_text_field($_POST['sky_pickup_time_slot']);
            $order->update_meta_data('_sky_pickup_time_slot', $time_slot);

            // Format time slot for display
            $slot_labels = [
                'morning' => __('Morning (9:00 AM - 12:00 PM)', 'sky-local-pickup'),
                'afternoon' => __('Afternoon (12:00 PM - 5:00 PM)', 'sky-local-pickup'),
            ];
            $order->update_meta_data('_sky_pickup_time_slot_display', $slot_labels[$time_slot] ?? $time_slot);
        }

        $order->save();

        // Store in session for thank you page
        WC()->session->set('sky_chosen_pickup_location', $location_key);
        WC()->session->set('sky_chosen_pickup_date', $_POST['sky_pickup_date'] ?? '');
        WC()->session->set('sky_chosen_pickup_slot', $_POST['sky_pickup_time_slot'] ?? '');
    }

    private function format_time_slots_display($time_slots) {
        if (empty($time_slots)) {
            return '';
        }

        $output = [];
        foreach ($time_slots as $slot) {
            $days = implode(', ', $slot['days'] ?? []);
            $time = $this->format_time($slot['open']) . ' - ' . $this->format_time($slot['close']);
            if (!empty($days)) {
                $output[] = $days . ': ' . $time;
            } else {
                $output[] = $time;
            }
        }
        return implode(' | ', $output);
    }

    private function format_time($time) {
        if (empty($time)) return '';
        $timestamp = strtotime($time);
        return $timestamp ? date('g:ia', $timestamp) : $time;
    }

    public function display_admin_order_meta($order) {
        $location_name = $order->get_meta('_sky_pickup_location_name');

        if (empty($location_name)) {
            return;
        }

        $address = $order->get_meta('_sky_pickup_location_address');
        $postcode = $order->get_meta('_sky_pickup_location_postcode');
        $hours = $order->get_meta('_sky_pickup_location_hours');
        $google_link = $order->get_meta('_sky_pickup_location_google_link');
        $pickup_date = $order->get_meta('_sky_pickup_date_display');
        $time_slot = $order->get_meta('_sky_pickup_time_slot_display');

        $pin_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 5px;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>';
        $clock_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 5px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>';
        $calendar_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 5px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>';
        ?>
        <div class="sky-pickup-admin-meta" style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-left: 4px solid #007cba; border-radius: 4px;">
            <h3 style="margin: 0 0 10px 0; color: #1d2327;">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 5px;"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                Pickup Location
            </h3>
            <p style="margin: 5px 0;"><strong><?php echo esc_html($location_name); ?></strong></p>
            <p style="margin: 5px 0;"><?php echo $pin_icon; ?><?php echo esc_html($address); ?> <?php echo esc_html($postcode); ?></p>
            <?php if (!empty($pickup_date)): ?>
                <p style="margin: 5px 0;"><?php echo $calendar_icon; ?><strong>Pickup Date:</strong> <?php echo esc_html($pickup_date); ?></p>
            <?php endif; ?>
            <?php if (!empty($time_slot)): ?>
                <p style="margin: 5px 0;"><?php echo $clock_icon; ?><strong>Time Slot:</strong> <?php echo esc_html($time_slot); ?></p>
            <?php endif; ?>
            <?php if (!empty($hours)): ?>
                <p style="margin: 5px 0; color: #666; font-size: 12px;"><?php echo $clock_icon; ?>Store Hours: <?php echo esc_html($hours); ?></p>
            <?php endif; ?>
            <?php if (!empty($google_link)): ?>
                <p style="margin: 10px 0 0 0;">
                    <a href="<?php echo esc_url($google_link); ?>"
                       target="_blank"
                       style="color: #007cba; text-decoration: none;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 3px;"><polygon points="3 11 22 2 13 21 11 13 3 11"></polygon></svg>
                        View on Google Maps
                    </a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function display_email_pickup_location($order, $sent_to_admin, $plain_text, $email) {
        $location_name = $order->get_meta('_sky_pickup_location_name');

        if (empty($location_name)) {
            return;
        }

        $address = $order->get_meta('_sky_pickup_location_address');
        $postcode = $order->get_meta('_sky_pickup_location_postcode');
        $hours = $order->get_meta('_sky_pickup_location_hours');
        $google_link = $order->get_meta('_sky_pickup_location_google_link');
        $pickup_date = $order->get_meta('_sky_pickup_date_display');
        $time_slot = $order->get_meta('_sky_pickup_time_slot_display');

        if ($plain_text) {
            echo "\n\n=== PICKUP LOCATION ===\n";
            echo $location_name . "\n";
            echo $address . " " . $postcode . "\n";
            if (!empty($pickup_date)) {
                echo "Pickup Date: " . $pickup_date . "\n";
            }
            if (!empty($time_slot)) {
                echo "Time Slot: " . $time_slot . "\n";
            }
            if (!empty($hours)) {
                echo "Store Hours: " . $hours . "\n";
            }
            if (!empty($google_link)) {
                echo "Map: " . $google_link . "\n";
            }
        } else {
            ?>
            <div style="margin: 20px 0; padding: 20px; background: #f8f9fa; border-left: 4px solid #007cba; border-radius: 4px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
                <h2 style="margin: 0 0 15px 0; color: #1d2327; font-size: 18px;">📦 Pickup Location</h2>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 8px 0; border-bottom: 1px solid #e0e0e0;">
                            <strong style="color: #1d2327;"><?php echo esc_html($location_name); ?></strong>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; border-bottom: 1px solid #e0e0e0; color: #50575e;">
                            📍 <?php echo esc_html($address); ?> <?php echo esc_html($postcode); ?>
                        </td>
                    </tr>
                    <?php if (!empty($pickup_date)): ?>
                    <tr>
                        <td style="padding: 8px 0; border-bottom: 1px solid #e0e0e0; color: #50575e;">
                            📅 <strong>Pickup Date:</strong> <?php echo esc_html($pickup_date); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($time_slot)): ?>
                    <tr>
                        <td style="padding: 8px 0; border-bottom: 1px solid #e0e0e0; color: #50575e;">
                            🕐 <strong>Time Slot:</strong> <?php echo esc_html($time_slot); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($hours)): ?>
                    <tr>
                        <td style="padding: 8px 0; border-bottom: 1px solid #e0e0e0; color: #888; font-size: 13px;">
                            🕐 Store Hours: <?php echo esc_html($hours); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($google_link)): ?>
                    <tr>
                        <td style="padding: 12px 0 0 0;">
                            <a href="<?php echo esc_url($google_link); ?>"
                               target="_blank"
                               style="display: inline-block; padding: 10px 20px; background: #007cba; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 500;">
                                Get Directions →
                            </a>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
            <?php
        }
    }

    public function display_thankyou_pickup_location($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        $location_name = $order->get_meta('_sky_pickup_location_name');

        if (empty($location_name)) {
            return;
        }

        $address = $order->get_meta('_sky_pickup_location_address');
        $postcode = $order->get_meta('_sky_pickup_location_postcode');
        $hours = $order->get_meta('_sky_pickup_location_hours');
        $google_link = $order->get_meta('_sky_pickup_location_google_link');
        $pickup_date = $order->get_meta('_sky_pickup_date_display');
        $time_slot = $order->get_meta('_sky_pickup_time_slot_display');
        ?>
        <div class="sky-pickup-thankyou">
            <h2>
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 8px;"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                Pickup Location
            </h2>
            <div class="sky-pickup-thankyou-details">
                <div class="sky-pickup-thankyou-info">
                    <p class="sky-pickup-name"><strong><?php echo esc_html($location_name); ?></strong></p>
                    <p class="sky-pickup-address">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 5px;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                        <?php echo esc_html($address); ?> <?php echo esc_html($postcode); ?>
                    </p>
                    <?php if (!empty($pickup_date)): ?>
                        <p class="sky-pickup-date">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 5px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            <strong>Pickup Date:</strong> <?php echo esc_html($pickup_date); ?>
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($time_slot)): ?>
                        <p class="sky-pickup-slot">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 5px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                            <strong>Time Slot:</strong> <?php echo esc_html($time_slot); ?>
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($hours)): ?>
                        <p class="sky-pickup-hours" style="color: #888; font-size: 13px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 5px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                            Store Hours: <?php echo esc_html($hours); ?>
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($google_link)): ?>
                        <a href="<?php echo esc_url($google_link); ?>"
                           target="_blank"
                           class="sky-pickup-directions-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 5px;"><polygon points="3 11 22 2 13 21 11 13 3 11"></polygon></svg>
                            View on Google Maps
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <style>
            .sky-pickup-thankyou {
                margin: 30px 0;
                padding: 25px;
                background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
                border-radius: 12px;
                border-left: 5px solid #007cba;
            }
            .sky-pickup-thankyou h2 {
                margin: 0 0 20px 0;
                font-size: 20px;
                color: #1d2327;
                display: flex;
                align-items: center;
            }
            .sky-pickup-thankyou-info p {
                margin: 8px 0;
                color: #50575e;
            }
            .sky-pickup-thankyou-info p svg {
                color: #007cba;
            }
            .sky-pickup-thankyou-info .sky-pickup-name {
                color: #1d2327;
                font-size: 16px;
            }
            .sky-pickup-directions-btn {
                display: inline-flex;
                align-items: center;
                margin-top: 12px;
                padding: 12px 24px;
                background: #007cba;
                color: #fff !important;
                text-decoration: none;
                border-radius: 6px;
                font-weight: 500;
                transition: background 0.2s;
            }
            .sky-pickup-directions-btn:hover {
                background: #005a87;
            }
            .sky-pickup-directions-btn svg {
                color: #fff;
            }
        </style>
        <?php
    }

    public function display_order_details_pickup_location($order) {
        $location_name = $order->get_meta('_sky_pickup_location_name');

        if (empty($location_name)) {
            return;
        }

        $address = $order->get_meta('_sky_pickup_location_address');
        $postcode = $order->get_meta('_sky_pickup_location_postcode');
        $hours = $order->get_meta('_sky_pickup_location_hours');
        $google_link = $order->get_meta('_sky_pickup_location_google_link');
        $pickup_date = $order->get_meta('_sky_pickup_date_display');
        $time_slot = $order->get_meta('_sky_pickup_time_slot_display');

        $pin_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#007cba" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 5px;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>';
        $clock_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#007cba" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 5px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>';
        $calendar_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#007cba" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 5px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>';
        ?>
        <section class="sky-pickup-order-details">
            <h2>Pickup Location</h2>
            <p><strong><?php echo esc_html($location_name); ?></strong></p>
            <p><?php echo $pin_icon; ?><?php echo esc_html($address); ?> <?php echo esc_html($postcode); ?></p>
            <?php if (!empty($pickup_date)): ?>
                <p><?php echo $calendar_icon; ?><strong>Pickup Date:</strong> <?php echo esc_html($pickup_date); ?></p>
            <?php endif; ?>
            <?php if (!empty($time_slot)): ?>
                <p><?php echo $clock_icon; ?><strong>Time Slot:</strong> <?php echo esc_html($time_slot); ?></p>
            <?php endif; ?>
            <?php if (!empty($hours)): ?>
                <p style="color: #888; font-size: 13px;"><?php echo $clock_icon; ?>Store Hours: <?php echo esc_html($hours); ?></p>
            <?php endif; ?>
            <?php if (!empty($google_link)): ?>
                <p><a href="<?php echo esc_url($google_link); ?>" target="_blank">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 3px;"><polygon points="3 11 22 2 13 21 11 13 3 11"></polygon></svg>
                    View on Google Maps
                </a></p>
            <?php endif; ?>
        </section>
        <?php
    }

    public function ajax_get_location_details() {
        check_ajax_referer('sky_pickup_nonce', 'nonce');

        $location_key = intval($_POST['location_key'] ?? -1);
        $locations = get_option('sky_pickup_locations', []);

        if (!isset($locations[$location_key])) {
            wp_send_json_error('Location not found');
        }

        wp_send_json_success($locations[$location_key]);
    }

    public function ajax_save_pickup_selection() {
        check_ajax_referer('sky_pickup_nonce', 'nonce');

        if (isset($_POST['location_key'])) {
            WC()->session->set('sky_chosen_pickup_location', intval($_POST['location_key']));
        }

        if (isset($_POST['pickup_date'])) {
            WC()->session->set('sky_chosen_pickup_date', sanitize_text_field($_POST['pickup_date']));
        }

        if (isset($_POST['pickup_slot'])) {
            WC()->session->set('sky_chosen_pickup_slot', sanitize_text_field($_POST['pickup_slot']));
        }

        wp_send_json_success();
    }
}

// Initialize plugin
Sky_Local_Pickup::instance();
