/**
 * Sky Local Pickup - Frontend JavaScript
 */
(function($) {
    'use strict';

    var SkyPickup = {
        init: function() {
            this.bindEvents();
            this.checkShippingMethod();

            // Listen for WooCommerce shipping method changes
            $(document.body).on('updated_checkout', this.checkShippingMethod.bind(this));
            $(document.body).on('updated_shipping_method', this.checkShippingMethod.bind(this));
        },

        bindEvents: function() {
            $(document).on('change', '#sky_pickup_location', this.onLocationChange.bind(this));
            $(document).on('change', '#sky_pickup_date', this.onDateChange.bind(this));
            $(document).on('change', '#sky_pickup_time_slot', this.onTimeSlotChange.bind(this));
            $(document).on('change', 'input[name^="shipping_method"]', this.checkShippingMethod.bind(this));
        },

        checkShippingMethod: function() {
            var $wrapper = $('#sky-pickup-wrapper');
            var isLocalPickup = false;

            // Check both classic and block checkout
            $('input[name^="shipping_method"]:checked, input[name="shipping_method"]:checked').each(function() {
                if ($(this).val().indexOf('local_pickup') !== -1) {
                    isLocalPickup = true;
                    return false;
                }
            });

            // Also check for pre-selected local pickup (radio might be hidden)
            if (!isLocalPickup) {
                var selectedMethod = $('input[name^="shipping_method"][type="radio"]:checked').val();
                if (selectedMethod && selectedMethod.indexOf('local_pickup') !== -1) {
                    isLocalPickup = true;
                }
            }

            // Check hidden inputs for block checkout
            if (!isLocalPickup) {
                $('input[name^="shipping_method"][type="hidden"]').each(function() {
                    if ($(this).val().indexOf('local_pickup') !== -1) {
                        isLocalPickup = true;
                        return false;
                    }
                });
            }

            if (isLocalPickup) {
                $wrapper.slideDown(300);
            } else {
                $wrapper.slideUp(300);
                $('#sky-pickup-details').hide();
                $('#sky-pickup-date-wrapper').hide();
                $('#sky-pickup-slot-wrapper').hide();
            }
        },

        onLocationChange: function() {
            var $select = $('#sky_pickup_location');
            var $details = $('#sky-pickup-details');
            var $dateWrapper = $('#sky-pickup-date-wrapper');
            var $slotWrapper = $('#sky-pickup-slot-wrapper');
            var $dateSelect = $('#sky_pickup_date');
            var $selected = $select.find('option:selected');

            if (!$selected.val()) {
                $details.slideUp(200);
                $dateWrapper.slideUp(200);
                $slotWrapper.slideUp(200);
                $select.removeClass('selected');
                return;
            }

            $select.addClass('selected');

            // Check if same day pickup is allowed for this location
            var sameDayAllowed = $selected.data('same-day') === 'yes';
            var $sameDayOption = $dateSelect.find('.sky-pickup-same-day-option');

            if (sameDayAllowed) {
                $sameDayOption.show();
            } else {
                $sameDayOption.hide();
                // If "Today" was selected but same day is not allowed, reset
                if ($sameDayOption.is(':selected')) {
                    $dateSelect.val('');
                    $dateSelect.removeClass('selected');
                }
            }

            // Reset date selection when location changes
            $dateSelect.val('');
            $dateSelect.removeClass('selected');

            // Show date and time slot dropdowns
            $dateWrapper.slideDown(300);
            $slotWrapper.slideDown(300);

            // Get data from selected option
            var address = $selected.data('address');
            var postcode = $selected.data('postcode');
            var googleLink = $selected.data('google-link');
            var timeSlots = $selected.data('time-slots');

            // Update address
            $details.find('.sky-pickup-address').text(address + ', ' + postcode);

            // Format and display time slots
            var hoursHtml = this.formatTimeSlots(timeSlots);
            $details.find('.sky-pickup-hours').html(hoursHtml);

            // Show/hide hours container based on whether there are time slots
            if (hoursHtml) {
                $details.find('.sky-pickup-hours-container').show();
            } else {
                $details.find('.sky-pickup-hours-container').hide();
            }

            // Update Google Maps link
            if (googleLink) {
                $('#sky-pickup-directions').attr('href', googleLink).show();
            } else {
                $('#sky-pickup-directions').hide();
            }

            // Show details
            $details.slideDown(300);

            // Store selection in session via AJAX
            this.saveSelection($selected.val());
        },

        onDateChange: function() {
            var $dateSelect = $('#sky_pickup_date');
            var selectedDate = $dateSelect.val();

            if (selectedDate) {
                $dateSelect.addClass('selected');
            } else {
                $dateSelect.removeClass('selected');
            }

            // Save to session
            this.saveSelection();
        },

        onTimeSlotChange: function() {
            var $slotSelect = $('#sky_pickup_time_slot');
            var selectedSlot = $slotSelect.val();

            if (selectedSlot) {
                $slotSelect.addClass('selected');
            } else {
                $slotSelect.removeClass('selected');
            }

            // Save to session
            this.saveSelection();
        },

        formatTimeSlots: function(timeSlots) {
            if (!timeSlots || !timeSlots.length) {
                return '';
            }

            var self = this;
            var lines = [];

            timeSlots.forEach(function(slot) {
                if (!slot.open && !slot.close) {
                    return;
                }

                var days = slot.days && slot.days.length ? slot.days.join(', ') : '';
                var time = self.formatTime(slot.open) + ' - ' + self.formatTime(slot.close);

                if (days) {
                    lines.push('<div class="sky-pickup-hours-line"><strong>' + days + ':</strong> ' + time + '</div>');
                } else {
                    lines.push('<div class="sky-pickup-hours-line">' + time + '</div>');
                }
            });

            return lines.join('');
        },

        formatTime: function(time) {
            if (!time) return '';

            // Convert 24h to 12h format
            var parts = time.split(':');
            if (parts.length < 2) return time;

            var hours = parseInt(parts[0]);
            var minutes = parts[1];
            var suffix = hours >= 12 ? 'pm' : 'am';

            hours = hours % 12 || 12;

            return hours + ':' + minutes + suffix;
        },

        saveSelection: function(locationKey) {
            var data = {
                action: 'sky_save_pickup_selection',
                nonce: skyPickup.nonce
            };

            // Get location key if not provided
            if (typeof locationKey === 'undefined') {
                locationKey = $('#sky_pickup_location').val();
            }

            if (locationKey) {
                data.location_key = locationKey;
            }

            // Get date
            var selectedDate = $('#sky_pickup_date').val();
            if (selectedDate) {
                data.pickup_date = selectedDate;
            }

            // Get time slot
            var selectedSlot = $('#sky_pickup_time_slot').val();
            if (selectedSlot) {
                data.pickup_slot = selectedSlot;
            }

            $.ajax({
                url: skyPickup.ajaxUrl,
                type: 'POST',
                data: data
            });
        }
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        SkyPickup.init();
    });

    // Re-initialize after AJAX updates
    $(document.body).on('updated_checkout', function() {
        setTimeout(function() {
            SkyPickup.checkShippingMethod();
        }, 100);
    });

})(jQuery);
