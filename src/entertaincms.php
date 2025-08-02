<?php
/**
 * Plugin Name: Entertainer's CMS
 * Description: Simple, reliable booking and client management for entertainers
 * Version: 2.2
 * Author: Chris P Tee
 * Requires at least: 5.6
 * Tested up to: 6.4
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit('Direct access denied.');
}

// Define plugin constants
define('ECMS_VERSION', '2.2');
define('ECMS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ECMS_PLUGIN_URL', plugin_dir_url(__FILE__));

// REGISTER SHORTCODES EARLY (Fix for shortcode display issue)
add_action('init', function() {
    add_shortcode('ecms_contact_form', 'ecms_render_contact_form');
    add_shortcode('ecms_booking_form', 'ecms_render_booking_form');
    add_shortcode('test', function() { return '<p style="color:green;">TEST SHORTCODE WORKS!</p>'; });
}, 1);

// Shortcode callback functions
function ecms_render_contact_form($atts) {
    if (class_exists('EntertainCMS')) {
        return EntertainCMS::get_instance()->render_contact_form($atts);
    }
    return '<!-- EntertainCMS not loaded yet -->';
}

function ecms_render_booking_form($atts) {
    if (class_exists('EntertainCMS')) {
        return EntertainCMS::get_instance()->render_booking_form($atts);
    }
    return '<!-- EntertainCMS not loaded yet -->';
}

/**
 * Main Plugin Class
 */
class EntertainCMS {
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // AJAX Hooks
        add_action('wp_ajax_ecms_save_event', [$this, 'ajax_save_event']);
        add_action('wp_ajax_ecms_get_events', [$this, 'ajax_get_events']);
        add_action('wp_ajax_ecms_get_event_details', [$this, 'ajax_get_event_details']);
        add_action('wp_ajax_ecms_submit_inquiry', [$this, 'ajax_submit_inquiry']);
        add_action('wp_ajax_nopriv_ecms_submit_inquiry', [$this, 'ajax_submit_inquiry']);
        add_action('wp_ajax_ecms_test_ajax', [$this, 'ajax_test']);
        
        // Ensure shortcodes work everywhere
        add_filter('widget_text', 'do_shortcode');
        add_filter('the_excerpt', 'do_shortcode');
        add_filter('the_content', 'do_shortcode', 11);
    }
    
    public function activate() {
        $this->create_database_tables();
        $this->set_default_options();
        flush_rewrite_rules();
    }
    
    private function create_database_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Events table
        $sql_events = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ecms_events (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            event_title varchar(255) NOT NULL,
            event_type varchar(100),
            event_date datetime NOT NULL,
            event_duration int DEFAULT 120,
            event_fee decimal(10,2) DEFAULT 0.00,
            client_name varchar(255) NOT NULL,
            client_email varchar(255) NOT NULL,
            client_phone varchar(50),
            venue_name varchar(255),
            venue_address text,
            notes text,
            contract_status varchar(50) DEFAULT 'pending',
            payment_status varchar(50) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_event_date (event_date),
            INDEX idx_contract_status (contract_status)
        ) $charset_collate;";
        
        // Inquiries table
        $sql_inquiries = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ecms_inquiries (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            client_name varchar(255) NOT NULL,
            client_email varchar(255) NOT NULL,
            client_phone varchar(50),
            event_date date,
            event_type varchar(100),
            venue_name varchar(255),
            message text,
            status varchar(50) DEFAULT 'new',
            referrer varchar(255),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        ) $charset_collate;";
        
        // Kudos table
        $sql_kudos = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ecms_kudos (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_email varchar(255) NOT NULL,
            user_name varchar(255) NOT NULL,
            kudos_type varchar(50) NOT NULL,
            points int DEFAULT 1,
            description text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_user_email (user_email)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_events);
        dbDelta($sql_inquiries);
        dbDelta($sql_kudos);
        
        // Award installation kudos
        $this->award_kudos(get_option('admin_email'), 'Installation Master', 'installation', 10, 'Successfully installed EntertainCMS!');
    }
    
    private function set_default_options() {
        $defaults = [
            'ecms_company_name' => get_bloginfo('name'),
            'ecms_company_email' => get_option('admin_email'),
            'ecms_currency' => 'GBP',
            'ecms_default_duration' => 120
        ];
        foreach ($defaults as $key => $value) {
            if (!get_option($key)) {
                add_option($key, $value);
            }
        }
    }
    
    public function add_admin_menu() {
        add_menu_page('Entertainer CMS', 'Entertainer CMS', 'manage_options', 'ecms-dashboard', [$this, 'render_dashboard'], 'dashicons-calendar-alt', 30);
        add_submenu_page('ecms-dashboard', 'Dashboard', 'Dashboard', 'manage_options', 'ecms-dashboard', [$this, 'render_dashboard']);
        add_submenu_page('ecms-dashboard', 'Calendar', 'Calendar', 'manage_options', 'ecms-calendar', [$this, 'render_calendar']);
        add_submenu_page('ecms-dashboard', 'All Events', 'All Events', 'manage_options', 'ecms-events', [$this, 'render_events']);
        add_submenu_page('ecms-dashboard', 'Inquiries', 'Inquiries', 'manage_options', 'ecms-inquiries', [$this, 'render_inquiries']);
        add_submenu_page('ecms-dashboard', 'Debug Info', 'Debug Info', 'manage_options', 'ecms-debug', [$this, 'render_debug_page']);
    }
    
    public function enqueue_admin_scripts($hook) {
        // Only load scripts on our plugin pages
        if (strpos($hook, 'ecms') === false) {
            return;
        }
        
        wp_enqueue_script('jquery');
        
        // Enqueue FullCalendar
        wp_enqueue_script('fullcalendar', 'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js', ['jquery'], '5.11.3', true);
        wp_enqueue_style('fullcalendar', 'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css', [], '5.11.3');
        
        // Localize script to pass PHP variables to JS
        wp_localize_script('fullcalendar', 'ecms_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ecms_nonce')
        ]);
        
        // Add inline styles (can be moved to a separate CSS file later)
        wp_add_inline_style('fullcalendar', $this->get_admin_styles());
    }
    
    private function get_admin_styles() {
        return "
        .ecms-dashboard { max-width: 1200px; }
        .ecms-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0; }
        .ecms-stat-card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); text-align: center; border-left: 4px solid #0073aa; transition: all 0.3s ease; }
        .ecms-stat-card h3 { margin: 0 0 10px 0; color: #666; font-size: 14px; text-transform: uppercase; }
        .stat-number { font-size: 2.5em; font-weight: bold; color: #0073aa; }
        .ecms-stat-card-link { text-decoration: none; display: block; }
        .ecms-stat-card-link:hover { transform: translateY(-2px); text-decoration: none; }
        .ecms-stat-card:hover { box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
        .ecms-modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .ecms-modal-content { background-color: #fff; margin: 5% auto; padding: 0; border-radius: 8px; width: 90%; max-width: 800px; max-height: 80vh; overflow-y: auto; }
        .ecms-modal-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px 8px 0 0; }
        .ecms-modal-body { padding: 20px; }
        .ecms-close { color: white; float: right; font-size: 28px; font-weight: bold; cursor: pointer; opacity: 0.8; }
        .ecms-close:hover { opacity: 1; }
        .form-row { margin-bottom: 15px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-row label { display: block; font-weight: 600; margin-bottom: 5px; }
        .form-row input, .form-row textarea, .form-row select { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; }
        .btn { padding: 12px 24px; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-weight: 500; transition: all 0.2s ease; }
        .btn-primary { background: #007cba; color: white; }
        .btn-success { background: #00a32a; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn:hover { opacity: 0.9; transform: translateY(-2px); }
        ";
    }
    
    public function render_dashboard() {
        $stats = $this->get_dashboard_stats();
        ?>
        <div class="wrap ecms-dashboard">
            <h1>🎭 Entertainer CMS Dashboard</h1>
            
            <div class="ecms-stats-grid">
                <!-- IMPROVEMENT: Link to the All Events page -->
                <a href="?page=ecms-events" class="ecms-stat-card-link">
                    <div class="ecms-stat-card">
                        <h3>Upcoming Events</h3>
                        <div class="stat-number"><?php echo $stats['upcoming_events']; ?></div>
                    </div>
                </a>
                <a href="?page=ecms-inquiries" class="ecms-stat-card-link">
                    <div class="ecms-stat-card">
                        <h3>New Inquiries</h3>
                        <div class="stat-number"><?php echo $stats['new_inquiries']; ?></div>
                    </div>
                </a>
                <div class="ecms-stat-card">
                    <h3>This Month Revenue</h3>
                    <div class="stat-number">£<?php echo number_format($stats['monthly_revenue'], 2); ?></div>
                </div>
                <div class="ecms-stat-card">
                    <h3>Total Events</h3>
                    <div class="stat-number"><?php echo $stats['total_events']; ?></div>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 30px;">
                <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <h2>🎪 Recent Events</h2>
                    <?php echo $this->get_recent_events(); ?>
                </div>
                <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <h2>📋 Quick Actions</h2>
                    <p><a href="?page=ecms-calendar" class="button button-primary">📅 View Calendar</a></p>
                    <p><a href="?page=ecms-inquiries" class="button">📨 Check Inquiries</a></p>
                    <p><a href="?page=ecms-debug" class="button">🔍 Debug Info</a></p>
                    
                    <h3>📝 Client Forms</h3>
                    <div style="background: #e7f3ff; padding: 15px; border-radius: 6px; margin: 10px 0; font-family: monospace;">
                        <strong>Contact Form:</strong><br>
                        [ecms_contact_form]
                    </div>
                    <div style="background: #e7f3ff; padding: 15px; border-radius: 6px; margin: 10px 0; font-family: monospace;">
                        <strong>Booking Form:</strong><br>
                        [ecms_booking_form]
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function render_calendar() {
        ?>
        <div class="wrap">
            <h1>📅 Event Calendar</h1>
            <div style="margin: 20px 0;">
                <button type="button" class="button button-primary" onclick="openEventModal()">➕ Add New Event</button>
            </div>
            <div id="ecms-calendar" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);"></div>
        </div>
        
        <?php $this->render_event_modal(); ?>
        
        <script>
        // IMPROVEMENT: Moved calendar logic into a dedicated function for clarity
        document.addEventListener('DOMContentLoaded', function() {
            initialize_ecms_calendar();
        });

        function initialize_ecms_calendar() {
            var calendarEl = document.getElementById('ecms-calendar');
            if (!calendarEl) return;

            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek'
                },
                events: function(fetchInfo, successCallback, failureCallback) {
                    jQuery.post(ecms_ajax.ajax_url, {
                        action: 'ecms_get_events',
                        nonce: ecms_ajax.nonce
                    }, function(response) {
                        if (response.success) {
                            successCallback(response.data);
                        } else {
                            console.error('Failed to load events:', response.data.message);
                            failureCallback(new Error(response.data.message));
                        }
                    }).fail(function() {
                        console.error('AJAX error while loading events');
                        failureCallback(new Error('AJAX error'));
                    });
                },
                selectable: true,
                select: function(selectionInfo) {
                    openEventModal(null, selectionInfo.start); // Open modal for new event
                },
                eventClick: function(clickInfo) {
                    clickInfo.jsEvent.preventDefault(); // Prevent browser from following link
                    openEventModal(clickInfo.event.id); // Open modal to edit existing event
                }
            });
            calendar.render();
            window.ecmsCalendar = calendar; // Make calendar instance globally available
        }
        </script>
        <?php
    }
    
    public function render_events() {
        global $wpdb;
        $events = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ecms_events ORDER BY event_date DESC");
        ?>
        <div class="wrap">
            <h1>📝 All Events</h1>
            <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                <?php if (empty($events)): ?>
                    <p>No events yet. <a href="?page=ecms-calendar">Create your first event!</a></p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>Date</th>
                                <th>Client</th>
                                <th>Fee</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($event->event_title); ?></strong></td>
                                    <td><?php echo date('M j, Y g:i A', strtotime($event->event_date)); ?></td>
                                    <td><?php echo esc_html($event->client_name); ?></td>
                                    <td>£<?php echo number_format($event->event_fee, 2); ?></td>
                                    <td><?php echo ucfirst(esc_html($event->contract_status)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    public function render_inquiries() {
        global $wpdb;
        $inquiries = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ecms_inquiries ORDER BY created_at DESC LIMIT 20");
        ?>
        <div class="wrap">
            <h1>📨 Client Inquiries</h1>
            <?php if (empty($inquiries)): ?>
                <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <h3>No inquiries yet!</h3>
                    <p>Add the contact form to your website:</p>
                    <div style="background: #e7f3ff; padding: 15px; border-radius: 6px; font-family: monospace;">[ecms_contact_form]</div>
                </div>
            <?php else: ?>
                <?php foreach ($inquiries as $inquiry): ?>
                    <div style="background: #fff; padding: 20px; margin: 10px 0; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                        <h3><?php echo esc_html($inquiry->client_name); ?></h3>
                        <p>
                            <strong>Email:</strong> <a href="mailto:<?php echo esc_attr($inquiry->client_email); ?>"><?php echo esc_html($inquiry->client_email); ?></a><br>
                            <strong>Phone:</strong> <?php echo esc_html($inquiry->client_phone ?: 'Not provided'); ?><br>
                            <strong>Event Date:</strong> <?php echo $inquiry->event_date ? date('M j, Y', strtotime($inquiry->event_date)) : 'TBD'; ?><br>
                            <strong>Received:</strong> <?php echo date('M j, Y g:i A', strtotime($inquiry->created_at)); ?>
                        </p>
                        <?php if ($inquiry->message): ?>
                            <div style="margin-top: 15px; padding: 15px; background: #f8f9fa; border-radius: 6px;">
                                <?php echo nl2br(esc_html($inquiry->message)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }
    
    public function render_debug_page() {
        global $wpdb;
        ?>
        <div class="wrap">
            <h1>🔍 EntertainCMS Debug Info</h1>
            
            <h2>Database Tables</h2>
            <pre style="background: #f0f0f0; padding: 20px; border-radius: 8px;"><?php
                $tables = ['ecms_events', 'ecms_inquiries', 'ecms_kudos'];
                foreach ($tables as $table_name) {
                    $table = $wpdb->prefix . $table_name;
                    $exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
                    echo ucfirst(str_replace('ecms_', '', $table_name)) . " Table: " . ($exists ? "✅ EXISTS" : "❌ MISSING") . "\n";
                }
            ?></pre>
            
            <h2>AJAX Test</h2>
            <button id="test-ajax" class="button">Test AJAX Connection</button>
            <div id="ajax-result" style="margin-top: 10px;"></div>
            
            <script>
            jQuery('#test-ajax').click(function() {
                jQuery('#ajax-result').html('Testing...');
                jQuery.post(ajaxurl, {
                    action: 'ecms_test_ajax',
                    nonce: '<?php echo wp_create_nonce('ecms_nonce'); ?>'
                }, function(response) {
                    let result_html = '<pre style="background: #f0f0f0; padding: 10px;">' + JSON.stringify(response, null, 2) + '</pre>';
                    jQuery('#ajax-result').html(result_html);
                });
            });
            </script>
        </div>
        <?php
    }
    
    private function render_event_modal() {
        ?>
        <div id="ecms-event-modal" class="ecms-modal">
            <div class="ecms-modal-content">
                <div class="ecms-modal-header">
                    <span class="ecms-close" onclick="closeEventModal()">&times;</span>
                    <h2 id="ecms-modal-title">Add New Event</h2>
                </div>
                <div class="ecms-modal-body">
                    <form id="ecms-event-form">
                        <!-- IMPROVEMENT: Added hidden field for event ID to handle updates -->
                        <input type="hidden" name="event_id" id="ecms-event-id" value="">
                        
                        <div class="form-grid">
                            <div class="form-row"><label>Event Title *</label><input type="text" name="event_title" required></div>
                            <div class="form-row"><label>Event Type</label><select name="event_type"><option value="">Select Type</option><option value="wedding">Wedding</option><option value="corporate">Corporate Event</option><option value="birthday">Birthday Party</option><option value="private">Private Event</option></select></div>
                        </div>
                        <div class="form-grid">
                            <div class="form-row"><label>Event Date & Time *</label><input type="datetime-local" name="event_date" required></div>
                            <div class="form-row"><label>Event Fee (£)</label><input type="number" name="event_fee" step="0.01" min="0"></div>
                        </div>
                        <div class="form-grid">
                            <div class="form-row"><label>Client Name *</label><input type="text" name="client_name" required></div>
                            <div class="form-row"><label>Client Email *</label><input type="email" name="client_email" required></div>
                        </div>
                        <div class="form-row"><label>Client Phone</label><input type="tel" name="client_phone"></div>
                        <div class="form-row"><label>Venue Name</label><input type="text" name="venue_name"></div>
                        <div class="form-row"><label>Notes</label><textarea name="notes" rows="3"></textarea></div>
                        
                        <div style="margin-top: 20px;">
                            <button type="submit" class="btn btn-primary">💾 Save Event</button>
                            <button type="button" class="btn btn-secondary" onclick="closeEventModal()">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <script>
        // IMPROVEMENT: Consolidated modal logic into clear functions
        function openEventModal(eventId = null, startDate = null) {
            const modal = document.getElementById('ecms-event-modal');
            const form = document.getElementById('ecms-event-form');
            const title = document.getElementById('ecms-modal-title');
            
            form.reset();
            document.getElementById('ecms-event-id').value = '';

            if (eventId) {
                // Editing an existing event
                title.innerText = 'Edit Event';
                // Fetch event data via AJAX
                jQuery.post(ecms_ajax.ajax_url, {
                    action: 'ecms_get_event_details',
                    nonce: ecms_ajax.nonce,
                    event_id: eventId
                }, function(response) {
                    if (response.success) {
                        const event = response.data;
                        // Populate form
                        document.querySelector('#ecms-event-id').value = event.id;
                        document.querySelector('[name="event_title"]').value = event.event_title;
                        document.querySelector('[name="event_type"]').value = event.event_type;
                        // Format date for datetime-local input
                        const localDate = new Date(event.event_date.replace(' ', 'T'));
                        const formattedDate = new Date(localDate.getTime() - (localDate.getTimezoneOffset() * 60000)).toISOString().slice(0, 16);
                        document.querySelector('[name="event_date"]').value = formattedDate;
                        document.querySelector('[name="event_fee"]').value = event.event_fee;
                        document.querySelector('[name="client_name"]').value = event.client_name;
                        document.querySelector('[name="client_email"]').value = event.client_email;
                        document.querySelector('[name="client_phone"]').value = event.client_phone;
                        document.querySelector('[name="venue_name"]').value = event.venue_name;
                        document.querySelector('[name="notes"]').value = event.notes;
                    } else {
                        alert('Error: ' + response.data.message);
                    }
                });
            } else {
                // Adding a new event
                title.innerText = 'Add New Event';
                if (startDate) {
                    const dateInput = document.querySelector('[name="event_date"]');
                    const localDate = new Date(startDate.getTime() - (startDate.getTimezoneOffset() * 60000));
                    dateInput.value = localDate.toISOString().slice(0, 16);
                }
            }
            
            modal.style.display = 'block';
        }

        function closeEventModal() {
            document.getElementById('ecms-event-modal').style.display = 'none';
        }

        jQuery(document).ready(function($) {
            $('#ecms-event-form').on('submit', function(e) {
                e.preventDefault();
                
                var formData = {
                    action: 'ecms_save_event',
                    nonce: ecms_ajax.nonce,
                    event_id: $('#ecms-event-id').val(),
                    event_title: $('[name="event_title"]').val(),
                    event_type: $('[name="event_type"]').val(),
                    event_date: $('[name="event_date"]').val(),
                    event_fee: $('[name="event_fee"]').val(),
                    client_name: $('[name="client_name"]').val(),
                    client_email: $('[name="client_email"]').val(),
                    client_phone: $('[name="client_phone"]').val(),
                    venue_name: $('[name="venue_name"]').val(),
                    notes: $('[name="notes"]').val()
                };
                
                $.post(ecms_ajax.ajax_url, formData, function(response) {
                    if (response.success) {
                        alert('✅ Event saved successfully!');
                        closeEventModal();
                        if (window.ecmsCalendar) {
                            window.ecmsCalendar.refetchEvents();
                        }
                    } else {
                        alert('❌ Error: ' + (response.data.message || 'Unknown error'));
                    }
                }).fail(function() {
                    alert('❌ Connection error. Please try again.');
                });
            });
        });
        </script>
        <?php
    }
    
    public function render_contact_form($atts) {
        // This function remains largely the same, but the AJAX handler it calls is now fixed.
        ob_start();
        ?>
        <div class="ecms-contact-form" style="max-width: 700px; margin: auto; padding: 25px; background: #f9f9f9; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
            <h3 style="text-align: center;">🎭 Get In Touch!</h3>
            <form class="ecms-public-form">
                <!-- Form fields here, no changes needed to the HTML structure -->
                <div class="form-grid">
                    <div class="form-row"><label>Your Name *</label><input type="text" name="client_name" required></div>
                    <div class="form-row"><label>Email Address *</label><input type="email" name="client_email" required></div>
                </div>
                <div class="form-row"><label>Phone Number</label><input type="tel" name="client_phone"></div>
                <div class="form-row"><label>Tell me about your event</label><textarea name="message" rows="4"></textarea></div>
                <div class="form-row"><button type="submit" class="ecms-submit-btn">🚀 Send Inquiry</button></div>
                <div class="ecms-form-message" style="display:none; margin-top: 15px; padding: 15px; border-radius: 6px;"></div>
            </form>
        </div>
        <script>
        (function($) {
            $('.ecms-public-form').on('submit', function(e) {
                e.preventDefault();
                var $form = $(this);
                var $button = $form.find('.ecms-submit-btn');
                var $message = $form.find('.ecms-form-message');
                $button.prop('disabled', true).text('Sending...');
                
                var formData = {
                    action: 'ecms_submit_inquiry',
                    nonce: '<?php echo wp_create_nonce('ecms_nonce'); ?>',
                    client_name: $form.find('[name="client_name"]').val(),
                    client_email: $form.find('[name="client_email"]').val(),
                    client_phone: $form.find('[name="client_phone"]').val(),
                    message: $form.find('[name="message"]').val()
                };
                
                $.post('<?php echo admin_url('admin-ajax.php'); ?>', formData)
                    .done(function(response) {
                        if (response.success) {
                            $message.css({background: '#d4edda', color: '#155724'}).html('🎉 Thank you! Your inquiry has been sent.').slideDown();
                            $form[0].reset();
                        } else {
                            $message.css({background: '#f8d7da', color: '#721c24'}).html('❌ Error: ' + response.data.message).slideDown();
                        }
                    })
                    .fail(function() {
                        $message.css({background: '#f8d7da', color: '#721c24'}).html('❌ Connection error. Please try again.').slideDown();
                    })
                    .always(function() {
                        $button.prop('disabled', false).text('🚀 Send Inquiry');
                    });
            });
        })(jQuery);
        </script>
        <?php
        return ob_get_clean();
    }
    
    // AJAX Functions
    public function ajax_save_event() {
        check_ajax_referer('ecms_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized access.']);
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'ecms_events';
        
        // Sanitize all POST data
        $event_id = !empty($_POST['event_id']) ? intval($_POST['event_id']) : 0;
        $event_data = [
            'event_title'   => sanitize_text_field($_POST['event_title']),
            'event_type'    => sanitize_text_field($_POST['event_type'] ?? ''),
            'event_date'    => sanitize_text_field($_POST['event_date']),
            'event_fee'     => floatval($_POST['event_fee'] ?? 0),
            'client_name'   => sanitize_text_field($_POST['client_name']),
            'client_email'  => sanitize_email($_POST['client_email']),
            'client_phone'  => sanitize_text_field($_POST['client_phone'] ?? ''),
            'venue_name'    => sanitize_text_field($_POST['venue_name'] ?? ''),
            'notes'         => sanitize_textarea_field($_POST['notes'] ?? '')
        ];
        
        // IMPROVEMENT: Handle both insert and update in one function
        if ($event_id > 0) {
            // Update existing event
            $result = $wpdb->update($table_name, $event_data, ['id' => $event_id]);
        } else {
            // Insert new event
            $result = $wpdb->insert($table_name, $event_data);
            $event_id = $wpdb->insert_id;
        }
        
        if ($result === false) {
            wp_send_json_error(['message' => 'Database error: ' . $wpdb->last_error]);
        } else {
            wp_send_json_success(['message' => 'Event saved successfully!', 'event_id' => $event_id]);
        }
    }
    
    public function ajax_get_events() {
        check_ajax_referer('ecms_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized access.']);
            return;
        }
        
        global $wpdb;
        $events = $wpdb->get_results("SELECT id, event_title, event_date FROM {$wpdb->prefix}ecms_events ORDER BY event_date");
        
        $calendar_events = [];
        foreach ($events as $event) {
            $calendar_events[] = [
                'id'    => $event->id,
                'title' => $event->event_title,
                'start' => $event->event_date,
            ];
        }
        wp_send_json_success($calendar_events);
    }
    
    public function ajax_get_event_details() {
        check_ajax_referer('ecms_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized access.']);
            return;
        }
        
        // FIX: This function is now clean and serves its one purpose.
        $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
        if ($event_id === 0) {
            wp_send_json_error(['message' => 'No Event ID provided.']);
            return;
        }

        global $wpdb;
        $event = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ecms_events WHERE id = %d", $event_id));
        
        if ($event) {
            wp_send_json_success($event);
        } else {
            wp_send_json_error(['message' => 'Event not found.']);
        }
    }
    
    public function ajax_submit_inquiry() {
        check_ajax_referer('ecms_nonce', 'nonce');
        global $wpdb;
        
        $inquiry_data = [
            'client_name'   => sanitize_text_field($_POST['client_name']),
            'client_email'  => sanitize_email($_POST['client_email']),
            'client_phone'  => sanitize_text_field($_POST['client_phone'] ?? ''),
            'message'       => sanitize_textarea_field($_POST['message'] ?? ''),
            'status'        => 'new'
        ];
        
        $result = $wpdb->insert($wpdb->prefix . 'ecms_inquiries', $inquiry_data);
        
        if ($result) {
            // FIX: Email notification logic moved here and improved.
            $this->send_inquiry_notification($inquiry_data);
            wp_send_json_success(['message' => 'Inquiry submitted successfully.']);
        } else {
            wp_send_json_error(['message' => 'Failed to save inquiry to database.']);
        }
    }

    private function send_inquiry_notification($inquiry_data) {
        $to = get_option('admin_email');
        $subject = 'New EntertainCMS Inquiry: ' . $inquiry_data['client_name'];
        
        // Sanitize for email headers to prevent injection
        $from_name = str_replace(["\n", "\r"], '', $inquiry_data['client_name']);
        $from_email = str_replace(["\n", "\r"], '', $inquiry_data['client_email']);
        
        $headers = [
            "From: {$from_name} <{$from_email}>",
            "Reply-To: {$from_name} <{$from_email}>"
        ];
        
        $message_body = "New inquiry received from your website:\n\n";
        $message_body .= "Name: " . $inquiry_data['client_name'] . "\n";
        $message_body .= "Email: " . $inquiry_data['client_email'] . "\n";
        $message_body .= "Phone: " . ($inquiry_data['client_phone'] ?: 'Not provided') . "\n\n";
        $message_body .= "Message:\n" . $inquiry_data['message'];
        
        wp_mail($to, $subject, $message_body, $headers);
    }
    
    public function ajax_test() {
        check_ajax_referer('ecms_nonce', 'nonce');
        wp_send_json_success([
            'message' => '✅ AJAX is working!',
            'time' => current_time('mysql'),
            'user' => wp_get_current_user()->user_login
        ]);
    }
    
    // Helper Functions
    private function award_kudos($user_email, $user_name, $kudos_type, $points, $description) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'ecms_kudos', ['user_email' => $user_email, 'user_name' => $user_name, 'kudos_type' => $kudos_type, 'points' => $points, 'description' => $description]);
    }
    
    private function get_dashboard_stats() {
        global $wpdb;
        return [
            'upcoming_events' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ecms_events WHERE event_date >= NOW()") ?: 0,
            'new_inquiries' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ecms_inquiries WHERE status = 'new'") ?: 0,
            'monthly_revenue' => $wpdb->get_var("SELECT SUM(event_fee) FROM {$wpdb->prefix}ecms_events WHERE MONTH(event_date) = MONTH(NOW()) AND YEAR(event_date) = YEAR(NOW())") ?: 0,
            'total_events' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ecms_events") ?: 0
        ];
    }
    
    private function get_recent_events($limit = 5) {
        global $wpdb;
        $events = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ecms_events ORDER BY event_date DESC LIMIT %d", $limit));
        
        if (empty($events)) {
            return '<p>No events yet. <button type="button" class="button" onclick="openEventModal()">Add your first event!</button></p>';
        }
        
        $output = '<div>';
        foreach ($events as $event) {
            $output .= sprintf(
                '<div style="padding: 10px; border-bottom: 1px solid #eee;"><strong>%s</strong><br><small>%s - %s</small></div>',
                esc_html($event->event_title),
                date('M j, Y g:i A', strtotime($event->event_date)),
                esc_html($event->client_name)
            );
        }
        $output .= '</div>';
        return $output;
    }
}

// Initialize the plugin
EntertainCMS::get_instance();
