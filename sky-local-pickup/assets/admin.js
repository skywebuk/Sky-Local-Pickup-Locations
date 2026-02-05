/**
 * Sky Local Pickup - Admin JavaScript
 */
(function($) {
    'use strict';

    var SkyPickupAdmin = {
        init: function() {
            this.bindEvents();
            this.initSortable();
        },

        bindEvents: function() {
            // Add new location
            $('#sky-pickup-add-location').on('click', this.addLocation);

            // Toggle location content
            $(document).on('click', '.sky-pickup-location-header', this.toggleLocation);

            // Remove location
            $(document).on('click', '.sky-pickup-remove-btn', this.removeLocation);

            // Update title on name change
            $(document).on('input', 'input[name="location_name[]"]', this.updateTitle);

            // Add time slot
            $(document).on('click', '.sky-pickup-add-slot-btn', this.addTimeSlot);

            // Remove time slot
            $(document).on('click', '.sky-pickup-remove-slot-btn', this.removeTimeSlot);
        },

        initSortable: function() {
            if ($.fn.sortable) {
                $('#sky-pickup-locations').sortable({
                    handle: '.sky-pickup-drag-handle',
                    placeholder: 'sky-pickup-location-item ui-sortable-placeholder',
                    axis: 'y',
                    cursor: 'grabbing',
                    opacity: 0.8,
                    update: function() {
                        SkyPickupAdmin.reindexLocations();
                    }
                });
            }
        },

        addLocation: function(e) {
            e.preventDefault();
            
            var template = $('#sky-pickup-location-template').html();
            var index = $('#sky-pickup-locations .sky-pickup-location-item').length;
            
            template = template.replace(/\{\{INDEX\}\}/g, index);
            
            var $newLocation = $(template);
            $('#sky-pickup-locations').append($newLocation);
            
            // Open the new location
            $newLocation.addClass('is-open');
            
            // Focus on name field
            $newLocation.find('input[name="location_name[]"]').focus();
            
            // Scroll to new location
            $('html, body').animate({
                scrollTop: $newLocation.offset().top - 100
            }, 300);
        },

        toggleLocation: function(e) {
            // Don't toggle if clicking remove button
            if ($(e.target).hasClass('sky-pickup-remove-btn')) {
                return;
            }
            
            var $item = $(this).closest('.sky-pickup-location-item');
            $item.toggleClass('is-open');
        },

        removeLocation: function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            if (!confirm('Are you sure you want to remove this location?')) {
                return;
            }
            
            var $item = $(this).closest('.sky-pickup-location-item');
            
            $item.slideUp(300, function() {
                $(this).remove();
                SkyPickupAdmin.reindexLocations();
            });
        },

        updateTitle: function() {
            var value = $(this).val() || 'New Location';
            $(this).closest('.sky-pickup-location-item')
                   .find('.sky-pickup-location-title')
                   .text(value);
        },

        addTimeSlot: function(e) {
            e.preventDefault();
            
            var $section = $(this).closest('.sky-pickup-time-slots-section');
            var $slotsContainer = $section.find('.sky-pickup-time-slots');
            var locIndex = $slotsContainer.data('location-index');
            var slotIndex = $slotsContainer.find('.sky-pickup-time-slot').length;
            
            var template = $('#sky-pickup-slot-template').html();
            template = template.replace(/\{\{LOC_INDEX\}\}/g, locIndex);
            template = template.replace(/\{\{SLOT_INDEX\}\}/g, slotIndex);
            
            var $newSlot = $(template);
            $slotsContainer.append($newSlot);
            
            // Animate in
            $newSlot.hide().slideDown(200);
        },

        removeTimeSlot: function(e) {
            e.preventDefault();
            
            var $slot = $(this).closest('.sky-pickup-time-slot');
            var $container = $slot.closest('.sky-pickup-time-slots');
            
            // Don't remove if it's the only slot
            if ($container.find('.sky-pickup-time-slot').length <= 1) {
                alert('You need at least one time slot. Clear the fields instead if not needed.');
                return;
            }
            
            $slot.slideUp(200, function() {
                $(this).remove();
            });
        },

        reindexLocations: function() {
            $('#sky-pickup-locations .sky-pickup-location-item').each(function(index) {
                var $item = $(this);
                $item.attr('data-index', index);
                
                // Update location index in time slots container
                $item.find('.sky-pickup-time-slots').attr('data-location-index', index);
                
                // Update enabled checkbox name
                $item.find('input[name^="location_enabled"]').attr('name', 'location_enabled[' + index + ']');
                
                // Update time slot names
                $item.find('.sky-pickup-time-slot').each(function(slotIndex) {
                    $(this).find('input[name^="location_slots"]').each(function() {
                        var name = $(this).attr('name');
                        // Replace the location and slot indices
                        var newName = name.replace(/location_slots\[\d+\]\[\d+\]/, 'location_slots[' + index + '][' + slotIndex + ']');
                        $(this).attr('name', newName);
                    });
                });
            });
        }
    };

    $(document).ready(function() {
        SkyPickupAdmin.init();
    });

})(jQuery);
