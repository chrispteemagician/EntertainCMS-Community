// Entertainer CMS Pro Admin JavaScript
(function($) {
    'use strict';

    let calendar;
    let currentEventId = null;

    // Initialize when document is ready
    $(document).ready(function() {
        initializeCalendar();
        initializeModals();
        initializeEventHandlers();
        initializeFormValidation();
    });

    /**
     * Initialize FullCalendar
     */
    function initializeCalendar() {
        const calendarEl = document.getElementById('ecms-calendar');
        if (!calendarEl) return;

        calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay'
            },
            events: {
                url: ecms_ajax.ajax_url,
                method: 'POST',
                extraParams: {
                    action: 'ecms_get_events',
                    nonce: ecms_ajax.nonce
                },
                failure: function() {
                    showNotice('Failed to load events', 'error');
                }
            },
            editable: true,
            selectable: true,
            selectMirror: true,
            dayMaxEvents: true,
            
            // Event creation
            select: function(selectionInfo) {
                openEventModal(null, selectionInfo.start, selectionInfo.end);
                calendar.unselect();
            },
            
            // Event editing
            eventClick: function(clickInfo) {
                openEventModal(clickInfo.event);
            },
            
            // Event drag and drop
            eventDrop: function(dropInfo) {
                updateEventDate(dropInfo.event);
            },
            
            // Event resize
            eventResize: function(resizeInfo) {
                updateEventDate(resizeInfo.event);
            }
        });

        calendar.render();
    }

    /**
     * Initialize modal functionality
     */
    function initializeModals() {
        // Close modal when clicking outside or on close button
        $(document).on('click', '.ecms-modal', function(e) {
            if (e.target === this) {
                closeEventModal();
            }
        });

        $(document).on('click', '.ecms-close', function() {
            closeEventModal();
        });

        // Prevent modal from closing when clicking inside
        $(document).on('click', '.ecms-modal-content', function(e) {
            e.stopPropagation();
        });

        // Close modal on escape key
        $(document).keydown(function(e) {
            if (e.keyCode === 27) { // Escape key
                closeEventModal();
            }
        });
    }

    /**
     * Initialize event handlers
     */
    function initializeEventHandlers() {
        // Event form submission
        $('#ecms-event-form').on('submit', function(e) {
            e.preventDefault();
            saveEvent();
        });

        // Send contract buttons
        $(document).on('click', '.send-contract-btn', function() {
            const eventId = $(this).data('event-id');
            sendContract(eventId);
        });

        // Delete event buttons
        $(document).on('click', '.delete-event-btn', function() {
            const eventId = $(this).data('event-id');
            if (confirm('Are you sure you want to delete this event?')) {
                deleteEvent(eventId);
            }
        });

        // Auto-save form data
        $('#ecms-event-form input, #ecms-event-form textarea').on('blur', function() {
            const formData = $('#ecms-event-form').serialize();
            localStorage.setItem('ecms_form_draft', formData);
        });
    }

    /**
     * Initialize form validation
     */
    function initializeFormValidation() {
        $('#ecms-event-form').on('submit', function(e) {
            let isValid = true;
            const requiredFields = ['event_title', 'event_date', 'client_name', 'client_email'];

            // Clear previous validation errors
            $('.form-error').remove();
            $('.form-row').removeClass('has-error');

            // Validate required fields
            requiredFields.forEach(function(field) {
                const $field = $(`[name="${field}"]`);
                const value = $field.val().trim();

                if (!value) {
                    showFieldError($field, 'This field is required');
                    isValid = false;
                }
            });

            // Validate email format
            const email = $('[name="client_email"]').val();
            if (email && !isValidEmail(email)) {
                showFieldError($('[name="client_email"]'), 'Please enter a valid email address');
                isValid = false;
            }

            // Validate event date (must be in the future)
            const eventDate = new Date($('[name="event_date"]').val());
            const now = new Date();
            if (eventDate <= now) {
                showFieldError($('[name="event_date"]'), 'Event date must be in the future');
                isValid = false;
            }

            if (!isValid) {
                e.preventDefault();
                showNotice('Please correct the errors below', 'error');
            }
        });
    }

    /**
     * Open event modal for creating or editing
     */
    function openEventModal(event = null, startDate = null, endDate = null) {
        const modal = $('#ecms-event-modal');
        const form = $('#ecms-event-form');
        
        // Reset form
        form[0].reset();
        currentEventId = null;

        if (event) {
            // Editing existing event
            $('#modal-title').text('Edit Event');
            currentEventId = event.id;
            
            // Populate form with event data
            $('[name="event_title"]').val(event.title);
            $('[name="event_date"]').val(formatDateForInput(event.start));
            
            // Load additional event data via AJAX
            loadEventDetails(event.id);
        } else {
            // Creating new event
            $('#modal-title').text('Add New Event');
            
            if (startDate) {
                $('[name="event_date"]').val(formatDateForInput(startDate));
            }

            // Load draft data if available
            const draftData = localStorage.getItem('ecms_form_draft');
            if (draftData) {
                const params = new URLSearchParams(draftData);
                params.forEach((value, key) => {
                    $(`[name="${key}"]`).val(value);
                });
            }
        }

        modal.show();
        $('[name="event_title"]').focus();
    }

    /**
     * Close event modal
     */
    function closeEventModal() {
        $('#ecms-event-modal').hide();
        currentEventId = null;
        
        // Clear form validation errors
        $('.form-error').remove();
        $('.form-row').removeClass('has-error');
    }

    /**
     * Save event (create or update)
     */
    function saveEvent() {
        const formData = $('#ecms-event-form').serialize();
        const data = {
            action: 'ecms_save_event',
            nonce: ecms_ajax.nonce,
            event_id: currentEventId
        };

        // Add form data to AJAX data
        $('#ecms-event-form').serializeArray().forEach(function(item) {
            data[item.name] = item.value;
        });

        // Show loading state
        const submitBtn = $('#ecms-event-form button[type="submit"]');
        const originalText = submitBtn.text();
        submitBtn.prop('disabled', true).html('<span class="ecms-loading"></span> Saving...');

        $.post(ecms_ajax.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    showNotice('Event saved successfully', 'success');
                    closeEventModal();
                    
                    // Refresh calendar
                    if (calendar) {
                        calendar.refetchEvents();
                    }
                    
                    // Clear draft data
                    localStorage.removeItem('ecms_form_draft');
                } else {
                    showNotice(response.data.message || 'Failed to save event', 'error');
                }
            })
            .fail(function() {
                showNotice('Network error. Please try again.', 'error');
            })
            .always(function() {
                submitBtn.prop('disabled', false).text(originalText);
            });
    }

    /**
     * Load event details for editing
     */
    function loadEventDetails(eventId) {
        const data = {
            action: 'ecms_get_event_details',
            nonce: ecms_ajax.nonce,
            event_id: eventId
        };

        $.post(ecms_ajax.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    const event = response.data;
                    
                    // Populate form fields
                    Object.keys(event).forEach(function(key) {
                        const field = $(`[name="${key}"]`);
                        if (field.length) {
                            field.val(event[key]);
                        }
                    });
                }
            })
            .fail(function() {
                showNotice('Failed to load event details', 'error');
            });
    }

    /**
     * Update event date when dragged or resized
     */
    function updateEventDate(event) {
        const data = {
            action: 'ecms_update_event_date',
            nonce: ecms_ajax.nonce,
            event_id: event.id,
            start_date: event.start.toISOString(),
            end_date: event.end ? event.end.toISOString() : null
        };

        $.post(ecms_ajax.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    showNotice('Event date updated', 'success', 2000);
                } else {
                    showNotice('Failed to update event date', 'error');
                    calendar.refetchEvents(); // Revert changes
                }
            })
            .fail(function() {
                showNotice('Network error', 'error');
                calendar.refetchEvents(); // Revert changes
            });
    }

    /**
     * Send contract email
     */
    function sendContract(eventId) {
        const data = {
            action: 'ecms_send_contract',
            nonce: ecms_ajax.nonce,
            event_id: eventId
        };

        const btn = $(`.send-contract-btn[data-event-id="${eventId}"]`);
        const originalText = btn.text();
        btn.prop('disabled', true).html('<span class="ecms-loading"></span> Sending...');

        $.post(ecms_ajax.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    showNotice('Contract sent successfully', 'success');
                    btn.text('Contract Sent').addClass('sent');
                } else {
                    showNotice(response.data.message || 'Failed to send contract', 'error');
                    btn.prop('disabled', false).text(originalText);
                }
            })
            .fail(function() {
                showNotice('Network error. Please try again.', 'error');
                btn.prop('disabled', false).text(originalText);
            });
    }

    /**
     * Delete event
     */
    function deleteEvent(eventId) {
        const data = {
            action: 'ecms_delete_event',
            nonce: ecms_ajax.nonce,
            event_id: eventId
        };

        $.post(ecms_ajax.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    showNotice('Event deleted successfully', 'success');
                    
                    // Refresh calendar
                    if (calendar) {
                        calendar.refetchEvents();
                    }
                    
                    // Remove from table if present
                    $(`.event-row[data-event-id="${eventId}"]`).fadeOut();
                } else {
                    showNotice(response.data.message || 'Failed to delete event', 'error');
                }
            })
            .fail(function() {
                showNotice('Network error. Please try again.', 'error');
            });
    }

    /**
     * Show notification
     */
    function showNotice(message, type = 'info', duration = 5000) {
        const notice = $(`
            <div class="ecms-notice ${type}" style="display: none;">
                ${message}
                <button type="button" class="notice-dismiss" onclick="$(this).parent().fadeOut()">×</button>
            </div>
        `);

        $('.wrap').first().prepend(notice);
        notice.fadeIn();

        if (duration > 0) {
            setTimeout(() => {
                notice.fadeOut(() => notice.remove());
            }, duration);
        }
    }

    /**
     * Show field validation error
     */
    function showFieldError(field, message) {
        const formRow = field.closest('.form-row');
        formRow.addClass('has-error');
        
        if (!formRow.find('.form-error').length) {
            formRow.append(`<span class="form-error" style="color: #dc3545; font-size: 12px; margin-top: 5px; display: block;">${message}</span>`);
        }
    }

    /**
     * Validate email format
     */
    function isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    /**
     * Format date for HTML datetime-local input
     */
    function formatDateForInput(date) {
        if (!date) return '';
        
        const d = new Date(date);
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        const hours = String(d.getHours()).padStart(2, '0');
        const minutes = String(d.getMinutes()).padStart(2, '0');
        
        return `${year}-${month}-${day}T${hours}:${minutes}`;
    }

    /**
     * Auto-save functionality
     */
    function initAutoSave() {
        let autoSaveTimer;
        
        $('#ecms-event-form input, #ecms-event-form textarea').on('input', function() {
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(function() {
                const formData = $('#ecms-event-form').serialize();
                localStorage.setItem('ecms_form_draft', formData);
                
                // Show saved indicator
                showNotice('Draft saved', 'info', 1000);
            }, 2000);
        });
    }

    // Global functions that can be called from PHP/HTML
    window.openEventModal = openEventModal;
    window.closeEventModal = closeEventModal;
    window.sendContract = sendContract;
    window.deleteEvent = deleteEvent;

})(jQuery);
