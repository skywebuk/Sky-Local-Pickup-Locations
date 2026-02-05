<?php
if (!defined('ABSPATH')) {
    exit;
}

$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
?>

<div class="wrap sky-pickup-admin">
    <h1>📦 Local Pickup Locations</h1>
    
    <form method="post" action="" id="sky-pickup-form">
        <?php wp_nonce_field('sky_pickup_save', 'sky_pickup_nonce'); ?>
        
        <div class="sky-pickup-settings-section">
            <h2>General Settings</h2>
            <table class="form-table">
                <tr>
                    <th><label for="pickup_label">Dropdown Label</label></th>
                    <td>
                        <input type="text" name="pickup_label" id="pickup_label"
                               value="<?php echo esc_attr($label); ?>" class="regular-text">
                    </td>
                </tr>
            </table>
        </div>

        <div class="sky-pickup-settings-section">
            <h2>Pickup Time Slots</h2>
            <p class="description">Control which time slots are available for customers to select during checkout. These apply to all locations.</p>
            <table class="form-table">
                <tr>
                    <th>Available Time Slots</th>
                    <td>
                        <fieldset>
                            <label style="display: block; margin-bottom: 10px;">
                                <input type="checkbox" name="pickup_slot_morning" value="yes"
                                       <?php checked($slot_morning, 'yes'); ?>>
                                <strong>Morning</strong> <span class="description">(e.g., 9:00 AM - 12:00 PM)</span>
                            </label>
                            <label style="display: block;">
                                <input type="checkbox" name="pickup_slot_evening" value="yes"
                                       <?php checked($slot_evening, 'yes'); ?>>
                                <strong>Evening</strong> <span class="description">(e.g., 12:00 PM - 5:00 PM)</span>
                            </label>
                            <p class="description" style="margin-top: 10px;">At least one time slot must be enabled. Customers will only see enabled options at checkout.</p>
                        </fieldset>
                    </td>
                </tr>
            </table>
        </div>

        <div class="sky-pickup-settings-section">
            <h2>Pickup Locations</h2>
            <p class="description">Add your pickup locations below. You can set multiple time slots with different days for each location.</p>
            
            <div id="sky-pickup-locations" class="sky-pickup-locations-list">
                <?php if (!empty($locations)): ?>
                    <?php foreach ($locations as $key => $location): ?>
                        <div class="sky-pickup-location-item" data-index="<?php echo $key; ?>">
                            <div class="sky-pickup-location-header">
                                <span class="sky-pickup-drag-handle">⋮⋮</span>
                                <span class="sky-pickup-location-title"><?php echo esc_html($location['name'] ?: 'New Location'); ?></span>
                                <span class="sky-pickup-location-toggle">▼</span>
                                <button type="button" class="sky-pickup-remove-btn" title="Remove">×</button>
                            </div>
                            <div class="sky-pickup-location-content">
                                <div class="sky-pickup-row">
                                    <div class="sky-pickup-field">
                                        <label>Location Name *</label>
                                        <input type="text" name="location_name[]" 
                                               value="<?php echo esc_attr($location['name']); ?>" 
                                               placeholder="e.g. Gates Store" required>
                                    </div>
                                    <div class="sky-pickup-field sky-pickup-field-small">
                                        <label>Enabled</label>
                                        <label class="sky-pickup-switch">
                                            <input type="checkbox" name="location_enabled[<?php echo $key; ?>]" 
                                                   value="yes" <?php checked($location['enabled'] ?? 'yes', 'yes'); ?>>
                                            <span class="sky-pickup-slider"></span>
                                        </label>
                                    </div>
                                </div>
                                <div class="sky-pickup-row">
                                    <div class="sky-pickup-field sky-pickup-field-wide">
                                        <label>Address *</label>
                                        <input type="text" name="location_address[]" 
                                               value="<?php echo esc_attr($location['address']); ?>" 
                                               placeholder="e.g. 94 Colne Road" required>
                                    </div>
                                    <div class="sky-pickup-field">
                                        <label>Postcode *</label>
                                        <input type="text" name="location_postcode[]" 
                                               value="<?php echo esc_attr($location['postcode']); ?>" 
                                               placeholder="e.g. BB10 1LP" required>
                                    </div>
                                </div>
                                <div class="sky-pickup-row">
                                    <div class="sky-pickup-field sky-pickup-field-full">
                                        <label>Google Maps Link</label>
                                        <input type="url" name="location_google_link[]" 
                                               value="<?php echo esc_attr($location['google_link'] ?? ''); ?>" 
                                               placeholder="e.g. https://maps.google.com/?q=...">
                                        <p class="field-description">Paste the Google Maps share link for this location</p>
                                    </div>
                                </div>
                                
                                <!-- Time Slots Section -->
                                <div class="sky-pickup-time-slots-section">
                                    <label class="sky-pickup-section-label">Opening Hours</label>
                                    <p class="field-description">Add multiple time slots for different days (e.g., weekday hours vs weekend hours)</p>
                                    
                                    <div class="sky-pickup-time-slots" data-location-index="<?php echo $key; ?>">
                                        <?php 
                                        $time_slots = $location['time_slots'] ?? [];
                                        if (empty($time_slots)) {
                                            $time_slots = [['days' => [], 'open' => '', 'close' => '']];
                                        }
                                        foreach ($time_slots as $slot_key => $slot): 
                                        ?>
                                        <div class="sky-pickup-time-slot">
                                            <div class="sky-pickup-slot-row">
                                                <div class="sky-pickup-days-select">
                                                    <label>Days</label>
                                                    <div class="sky-pickup-days">
                                                        <?php foreach ($days_of_week as $day): ?>
                                                            <label class="sky-pickup-day-label">
                                                                <input type="checkbox" 
                                                                       name="location_slots[<?php echo $key; ?>][<?php echo $slot_key; ?>][days][]" 
                                                                       value="<?php echo $day; ?>"
                                                                       <?php checked(in_array($day, $slot['days'] ?? [])); ?>>
                                                                <span><?php echo substr($day, 0, 3); ?></span>
                                                            </label>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="sky-pickup-slot-row sky-pickup-slot-times">
                                                <div class="sky-pickup-field">
                                                    <label>Opening Time</label>
                                                    <input type="time" 
                                                           name="location_slots[<?php echo $key; ?>][<?php echo $slot_key; ?>][open]" 
                                                           value="<?php echo esc_attr($slot['open'] ?? ''); ?>">
                                                </div>
                                                <div class="sky-pickup-field">
                                                    <label>Closing Time</label>
                                                    <input type="time" 
                                                           name="location_slots[<?php echo $key; ?>][<?php echo $slot_key; ?>][close]" 
                                                           value="<?php echo esc_attr($slot['close'] ?? ''); ?>">
                                                </div>
                                                <button type="button" class="button sky-pickup-remove-slot-btn" title="Remove this time slot">×</button>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <button type="button" class="button sky-pickup-add-slot-btn">
                                        + Add Time Slot
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <button type="button" id="sky-pickup-add-location" class="button button-secondary">
                + Add New Location
            </button>
        </div>

        <p class="submit">
            <button type="submit" name="sky_pickup_save" class="button button-primary button-hero">
                Save All Settings
            </button>
        </p>
    </form>
</div>

<!-- Location Template -->
<script type="text/template" id="sky-pickup-location-template">
    <div class="sky-pickup-location-item" data-index="{{INDEX}}">
        <div class="sky-pickup-location-header">
            <span class="sky-pickup-drag-handle">⋮⋮</span>
            <span class="sky-pickup-location-title">New Location</span>
            <span class="sky-pickup-location-toggle">▼</span>
            <button type="button" class="sky-pickup-remove-btn" title="Remove">×</button>
        </div>
        <div class="sky-pickup-location-content" style="display:block;">
            <div class="sky-pickup-row">
                <div class="sky-pickup-field">
                    <label>Location Name *</label>
                    <input type="text" name="location_name[]" placeholder="e.g. Gates Store" required>
                </div>
                <div class="sky-pickup-field sky-pickup-field-small">
                    <label>Enabled</label>
                    <label class="sky-pickup-switch">
                        <input type="checkbox" name="location_enabled[{{INDEX}}]" value="yes" checked>
                        <span class="sky-pickup-slider"></span>
                    </label>
                </div>
            </div>
            <div class="sky-pickup-row">
                <div class="sky-pickup-field sky-pickup-field-wide">
                    <label>Address *</label>
                    <input type="text" name="location_address[]" placeholder="e.g. 94 Colne Road" required>
                </div>
                <div class="sky-pickup-field">
                    <label>Postcode *</label>
                    <input type="text" name="location_postcode[]" placeholder="e.g. BB10 1LP" required>
                </div>
            </div>
            <div class="sky-pickup-row">
                <div class="sky-pickup-field sky-pickup-field-full">
                    <label>Google Maps Link</label>
                    <input type="url" name="location_google_link[]" placeholder="e.g. https://maps.google.com/?q=...">
                    <p class="field-description">Paste the Google Maps share link for this location</p>
                </div>
            </div>
            
            <!-- Time Slots Section -->
            <div class="sky-pickup-time-slots-section">
                <label class="sky-pickup-section-label">Opening Hours</label>
                <p class="field-description">Add multiple time slots for different days (e.g., weekday hours vs weekend hours)</p>
                
                <div class="sky-pickup-time-slots" data-location-index="{{INDEX}}">
                    <div class="sky-pickup-time-slot">
                        <div class="sky-pickup-slot-row">
                            <div class="sky-pickup-days-select">
                                <label>Days</label>
                                <div class="sky-pickup-days">
                                    <?php foreach ($days_of_week as $day): ?>
                                        <label class="sky-pickup-day-label">
                                            <input type="checkbox" name="location_slots[{{INDEX}}][0][days][]" value="<?php echo $day; ?>">
                                            <span><?php echo substr($day, 0, 3); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div class="sky-pickup-slot-row sky-pickup-slot-times">
                            <div class="sky-pickup-field">
                                <label>Opening Time</label>
                                <input type="time" name="location_slots[{{INDEX}}][0][open]">
                            </div>
                            <div class="sky-pickup-field">
                                <label>Closing Time</label>
                                <input type="time" name="location_slots[{{INDEX}}][0][close]">
                            </div>
                            <button type="button" class="button sky-pickup-remove-slot-btn" title="Remove this time slot">×</button>
                        </div>
                    </div>
                </div>
                
                <button type="button" class="button sky-pickup-add-slot-btn">
                    + Add Time Slot
                </button>
            </div>
        </div>
    </div>
</script>

<!-- Time Slot Template -->
<script type="text/template" id="sky-pickup-slot-template">
    <div class="sky-pickup-time-slot">
        <div class="sky-pickup-slot-row">
            <div class="sky-pickup-days-select">
                <label>Days</label>
                <div class="sky-pickup-days">
                    <?php foreach ($days_of_week as $day): ?>
                        <label class="sky-pickup-day-label">
                            <input type="checkbox" name="location_slots[{{LOC_INDEX}}][{{SLOT_INDEX}}][days][]" value="<?php echo $day; ?>">
                            <span><?php echo substr($day, 0, 3); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="sky-pickup-slot-row sky-pickup-slot-times">
            <div class="sky-pickup-field">
                <label>Opening Time</label>
                <input type="time" name="location_slots[{{LOC_INDEX}}][{{SLOT_INDEX}}][open]">
            </div>
            <div class="sky-pickup-field">
                <label>Closing Time</label>
                <input type="time" name="location_slots[{{LOC_INDEX}}][{{SLOT_INDEX}}][close]">
            </div>
            <button type="button" class="button sky-pickup-remove-slot-btn" title="Remove this time slot">×</button>
        </div>
    </div>
</script>
