<?php
/**
 * Plugin Name: Entertainer's CMS Pro
 * Description: Complete booking and contract management system for entertainers
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

// Register shortcodes early
add_action('init', function() {
    add_shortcode('ecms_contact_form', 'ecms_render_contact_form');
    add_shortcode('ecms_booking_form', 'ecms_render_booking_form');
}, 1);

function ecms_render_contact_form($atts) {
    if (class_exists('EntertainCMS_Pro')) {
        return EntertainCMS_Pro::get_instance()->render_contact_form($atts);
    }
    return '<!-- EntertainCMS not loaded yet -->';
}

function ecms_render_booking_form($atts) {
    if (class_exists('EntertainCMS_Pro')) {
        return EntertainCMS_Pro::get_instance()->render_booking_form($atts);
    }
    return '<!-- EntertainCMS not loaded yet -->';
}

/**
 * Main Plugin Class
 */
class EntertainCMS_Pro {
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
        add_action('wp_ajax_ecms_save_event', [$this, 'ajax_save_event']);
        add_action('wp_ajax_ecms_get_events', [$this, 'ajax_get_events']);
        add_action('wp_ajax_ecms_get_event_details', [$this, 'ajax_get_event_details']);
        add_action('wp_ajax_ecms_submit_inquiry', [$this, 'ajax_submit_inquiry']);
        add_action('wp_ajax_nopriv_ecms_submit_inquiry', [$this, 'ajax_submit_inquiry']);
        add_action('wp_ajax_ecms_export_google_calendar', [$this, 'ajax_export_google_calendar']);
        add_action('wp_ajax_ecms_export_ical', [$this, 'ajax_export_ical']);
        
        // Ensure shortcodes work everywhere
        add_filter('widget_text', 'do_shortcode');
        add_filter('the_excerpt', 'do_shortcode');
        add_filter('the_content', 'do_shortcode', 11);
    }

private function render_kudos_section() {
    global $wpdb;
    $user_email = get_option('admin_email');
    $total_kudos = $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(points) FROM {$wpdb->prefix}ecms_kudos WHERE user_email = %s", $user_email
    )) ?: 0;
    
    echo '<div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 25px; margin: 20px 0; border-radius: 10px;">
        <h2 style="margin: 0;">🏆 Your Kudos Score: ' . $total_kudos . ' Points</h2>
        <p style="opacity: 0.8;">Keep using the system to earn more kudos!</p>
    </div>';
}
    
    public function activate() {
$this->update_database_schema();        
$this->create_database_tables();
        $this->set_default_options();
        flush_rewrite_rules();
    }
private function update_database_schema() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'ecms_events';
    
    // Check and add missing columns
    $columns_to_add = [
        'client_address' => 'text',
        'emergency_contact' => 'varchar(100)', 
        'venue_address' => 'text',
        'venue_phone' => 'varchar(50)',
        'special_requirements' => 'text'
    ];
    
    foreach ($columns_to_add as $column => $type) {
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table_name}` LIKE %s",
            $column
        ));
        
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE `{$table_name}` ADD `{$column}` {$type}");
        }
    }
}
    
    private function create_database_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Enhanced events table with all contact fields
        $sql_events = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ecms_events (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            event_title varchar(255) NOT NULL,
            event_type varchar(100),
            event_date datetime NOT NULL,
            event_duration int DEFAULT 120,
            event_fee decimal(10,2) DEFAULT 0.00,
            deposit_amount decimal(10,2) DEFAULT 0.00,
            client_name varchar(255) NOT NULL,
            client_email varchar(255) NOT NULL,
            client_phone varchar(50),
            client_address text,
            emergency_contact varchar(100),
            venue_name varchar(255),
            venue_address text,
            venue_phone varchar(50),
            special_requirements text,
            notes text,
            contract_status varchar(50) DEFAULT 'pending',
            payment_status varchar(50) DEFAULT 'pending',
            google_calendar_id varchar(255),
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
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_events);
        dbDelta($sql_inquiries);
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
        add_menu_page(
            'Entertainer CMS Pro',
            'Entertainer CMS',
            'manage_options',
            'ecms-dashboard',
            [$this, 'render_dashboard'],
            'dashicons-calendar-alt',
            30
        );
        
        add_submenu_page('ecms-dashboard', 'Dashboard', 'Dashboard', 'manage_options', 'ecms-dashboard', [$this, 'render_dashboard']);
        add_submenu_page('ecms-dashboard', 'Calendar', 'Calendar', 'manage_options', 'ecms-calendar', [$this, 'render_calendar']);
        add_submenu_page('ecms-dashboard', 'All Events', 'All Events', 'manage_options', 'ecms-events', [$this, 'render_events']);
        add_submenu_page('ecms-dashboard', 'Inquiries', 'Inquiries', 'manage_options', 'ecms-inquiries', [$this, 'render_inquiries']);
        add_submenu_page('ecms-dashboard', 'Debug Info', 'Debug Info', 'manage_options', 'ecms-debug', [$this, 'render_debug_page']);
    }
    
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'ecms') === false) {
            return;
        }
        
        wp_enqueue_script('jquery');
        
        // Enqueue FullCalendar
        wp_enqueue_script(
            'fullcalendar',
            'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js',
            ['jquery'],
            '5.11.3',
            true
        );
        
        wp_enqueue_style(
            'fullcalendar',
            'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css',
            [],
            '5.11.3'
        );
        
        wp_localize_script('fullcalendar', 'ecms_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ecms_nonce')
        ]);
        
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
        .ecms-modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.6); }
        .ecms-modal-content { background-color: #fff; margin: 2% auto; padding: 0; border-radius: 10px; width: 95%; max-width: 900px; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .ecms-modal-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 25px; border-radius: 10px 10px 0 0; position: relative; }
        .ecms-modal-header h2 { margin: 0; font-size: 24px; }
        .ecms-modal-body { padding: 25px; }
        .ecms-close { color: white; position: absolute; top: 15px; right: 20px; font-size: 30px; font-weight: bold; cursor: pointer; opacity: 0.8; line-height: 1; }
        .ecms-close:hover { opacity: 1; }
        .form-section { margin-bottom: 30px; }
        .form-section h3 { color: #333; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 20px; }
        .form-row { margin-bottom: 15px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }
        .form-row label { display: block; font-weight: 600; margin-bottom: 5px; color: #555; }
        .form-row input, .form-row textarea, .form-row select { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
        .form-row textarea { resize: vertical; min-height: 80px; }
        .btn { padding: 12px 24px; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-weight: 500; transition: all 0.2s ease; margin-right: 10px; }
        .btn-primary { background: #007cba; color: white; }
        .btn-success { background: #00a32a; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn:hover { opacity: 0.9; transform: translateY(-2px); }
        .clickable-row { cursor: pointer; transition: background-color 0.2s ease; }
        .clickable-row:hover { background-color: #f0f8ff; }
        .fc-daygrid-day:hover { cursor: pointer; background-color: rgba(0,115,170,0.1); }
        .fc-event:hover { cursor: pointer; }
        .fc-daygrid-day-frame:hover { background-color: rgba(0,115,170,0.05); }
        .event-details-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0; }
        .detail-card { background: #f9f9f9; padding: 15px; border-radius: 8px; border-left: 4px solid #007cba; }
        .detail-card h4 { margin: 0 0 10px 0; color: #333; }
        .detail-card p { margin: 5px 0; }
        .contact-info { background: #e8f4fd; padding: 15px; border-radius: 8px; margin: 10px 0; }
        .calendar-export-buttons { margin: 15px 0; text-align: center; }
        ";
    }
    
    public function render_dashboard() {
        $stats = $this->get_dashboard_stats();
        ?>
        <div class="wrap ecms-dashboard">
            <h1>🎭 Entertainer CMS Dashboard</h1>
            
            <div class="ecms-stats-grid">
                <a href="?page=ecms-calendar" class="ecms-stat-card-link">
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
            // Add after stats grid, before the Recent Events section:
<?php $this->render_kudos_section(); ?>
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
                </div>
            </div>
        </div>
        <?php 
        $this->render_event_modal(); 
        $this->render_event_details_modal();
        ?>
        <?php
    }
    
    public function render_calendar() {
        ?>
        <div class="wrap">
            <h1>📅 Event Calendar</h1>
            <div style="margin: 20px 0;">
                <button type="button" class="button button-primary" onclick="openEventModal()">➕ Add New Event</button>
                <button type="button" class="button" onclick="exportAllToGoogleCalendar()">📤 Export to Google Calendar</button>
                <button type="button" class="button" onclick="exportAllToICal()">📅 Export to iCal</button>
            </div>
            <div id="ecms-calendar" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);"></div>
        </div>
        
        <?php 
        $this->render_event_modal(); 
        $this->render_event_details_modal();
        ?>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('ecms-calendar');
            if (calendarEl) {
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
                                successCallback([]);
                            }
                        }).fail(function() {
                            successCallback([]);
                        });
                    },
                    selectable: true,
                    select: function(selectionInfo) {
                        openEventModal(selectionInfo.start);
                    },
                    eventClick: function(clickInfo) {
                        showEventDetails(clickInfo.event.id);
                    }
                });
                calendar.render();
                window.ecmsCalendar = calendar;
            }
        });
        
        function exportAllToGoogleCalendar() {
            window.open(ecms_ajax.ajax_url + '?action=ecms_export_google_calendar&nonce=' + ecms_ajax.nonce, '_blank');
        }
        
        function exportAllToICal() {
            window.open(ecms_ajax.ajax_url + '?action=ecms_export_ical&nonce=' + ecms_ajax.nonce, '_blank');
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
                                <th>Venue</th>
                                <th>Fee</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event): ?>
                                <tr class="clickable-row" onclick="showEventDetails(<?php echo $event->id; ?>)">
                                    <td><strong><?php echo esc_html($event->event_title); ?></strong></td>
                                    <td><?php echo date('M j, Y g:i A', strtotime($event->event_date)); ?></td>
                                    <td><?php echo esc_html($event->client_name); ?></td>
                                    <td><?php echo esc_html($event->venue_name ?: 'TBD'); ?></td>
                                    <td>£<?php echo number_format($event->event_fee, 2); ?></td>
                                    <td><?php echo ucfirst($event->contract_status); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php $this->render_event_details_modal(); ?>
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
                            <strong>Email:</strong> <a href="mailto:<?php echo $inquiry->client_email; ?>"><?php echo $inquiry->client_email; ?></a><br>
                            <strong>Phone:</strong> <?php echo $inquiry->client_phone ?: 'Not provided'; ?><br>
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
            <pre style="background: #f0f0f0; padding: 20px; border-radius: 8px;">
<?php
$table = $wpdb->prefix . 'ecms_events';
$exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
echo "Events Table: " . ($exists ? "✅ EXISTS" : "❌ MISSING") . "\n\n";

if ($exists) {
    $columns = $wpdb->get_results("DESCRIBE $table");
    echo "Table Structure:\n";
    foreach($columns as $col) {
        echo "  - {$col->Field} ({$col->Type})\n";
    }
    
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
    echo "\nTotal Events: $count\n";
}

$inquiries_table = $wpdb->prefix . 'ecms_inquiries';
$inquiries_exists = $wpdb->get_var("SHOW TABLES LIKE '$inquiries_table'");
echo "\nInquiries Table: " . ($inquiries_exists ? "✅ EXISTS" : "❌ MISSING") . "\n";
?>
            </pre>
            
            <h2>Plugin Info</h2>
            <pre style="background: #f0f0f0; padding: 20px; border-radius: 8px;">
WordPress Version: <?php echo get_bloginfo('version'); ?>
PHP Version: <?php echo PHP_VERSION; ?>
Plugin Version: <?php echo ECMS_VERSION; ?>
Site URL: <?php echo site_url(); ?>
Admin Email: <?php echo get_option('admin_email'); ?>
            </pre>
        </div>
        <?php
    }
    
    private function render_event_modal() {
        ?>
        <div id="ecms-event-modal" class="ecms-modal">
            <div class="ecms-modal-content">
                <div class="ecms-modal-header">
                    <span class="ecms-close" onclick="closeEventModal()">&times;</span>
                    <h2 id="event-modal-title">Add New Event</h2>
                </div>
                <div class="ecms-modal-body">
                    <form id="ecms-event-form">
                        <input type="hidden" name="event_id" id="event_id">
                        
                        <div class="form-section">
                            <h3>🎭 Event Details</h3>
                            <div class="form-grid">
                                <div class="form-row">
                                    <label>Event Title *</label>
                                    <input type="text" name="event_title" required>
                                </div>
                                <div class="form-row">
                                    <label>Event Type</label>
                                    <select name="event_type">
                                        <option value="">Select Type</option>
                                        <option value="wedding">Wedding</option>
                                        <option value="corporate">Corporate Event</option>
                                        <option value="birthday">Birthday Party</option>
                                        <option value="private">Private Event</option>
                                        <option value="club">Club Performance</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-grid-3">
                                <div class="form-row">
                                    <label>Event Date & Time *</label>
                                    <input type="datetime-local" name="event_date" required>
                                </div>
                                <div class="form-row">
                                    <label>Duration (minutes)</label>
                                    <input type="number" name="event_duration" value="120" min="30">
                                </div>
                                <div class="form-row">
                                    <label>Event Fee (£)</label>
                                    <input type="number" name="event_fee" step="0.01" min="0">
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h3>👤 Client Details</h3>
                            <div class="form-grid">
                                <div class="form-row">
                                    <label>Client Name *</label>
                                    <input type="text" name="client_name" required>
                                </div>
                                <div class="form-row">
                                    <label>Client Email *</label>
                                    <input type="email" name="client_email" required>
                                </div>
                            </div>
                            
                            <div class="form-grid">
                                <div class="form-row">
                                    <label>Client Phone</label>
                                    <input type="tel" name="client_phone">
                                </div>
                                <div class="form-row">
                                    <label>Emergency Contact</label>
                                    <input type="tel" name="emergency_contact">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <label>Client Home Address</label>
                                <textarea name="client_address" rows="2" placeholder="Full home address including postcode"></textarea>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h3>📍 Venue Details</h3>
                            <div class="form-grid">
                                <div class="form-row">
                                    <label>Venue Name</label>
                                    <input type="text" name="venue_name">
                                </div>
                                <div class="form-row">
                                    <label>Venue Contact Phone</label>
                                    <input type="tel" name="venue_phone">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <label>Venue Address</label>
                                <textarea name="venue_address" rows="2" placeholder="Full venue address including postcode"></textarea>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h3>📝 Additional Information</h3>
                            <div class="form-row">
                                <label>Special Requirements</label>
                                <textarea name="special_requirements" rows="2" placeholder="Equipment, setup, access requirements etc."></textarea>
                            </div>
                            
                            <div class="form-row">
                                <label>Notes</label>
                                <textarea name="notes" rows="3" placeholder="General notes about the event"></textarea>
                            </div>
                        </div>
                        
                        <div style="margin-top: 30px; text-align: right; border-top: 1px solid #eee; padding-top: 20px;">
                            <button type="button" class="btn btn-secondary" onclick="closeEventModal()">Cancel</button>
                            <button type="submit" class="btn btn-primary">💾 Save Event</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <script>
        function openEventModal(date, eventId) {
            const modal = document.getElementById('ecms-event-modal');
            const form = document.getElementById('ecms-event-form');
            const title = document.getElementById('event-modal-title');
            
            form.reset();
            document.getElementById('event_id').value = eventId || '';
            title.textContent = eventId ? 'Edit Event' : 'Add New Event';
            
            if (eventId) {
                loadEventForEdit(eventId);
            } else if (date) {
                const dateInput = document.querySelector('[name="event_date"]');
                const localDate = new Date(date.getTime() - (date.getTimezoneOffset() * 60000));
                dateInput.value = localDate.toISOString().slice(0, 16);
            }
            
            modal.style.display = 'block';
        }

        function closeEventModal() {
            document.getElementById('ecms-event-modal').style.display = 'none';
        }

        function loadEventForEdit(eventId) {
            jQuery.post(ecms_ajax.ajax_url, {
                action: 'ecms_get_event_details',
                nonce: ecms_ajax.nonce,
                event_id: eventId
            }, function(response) {
                if (response.success) {
                    const event = response.data;
                    Object.keys(event).forEach(function(key) {
                        const field = document.querySelector(`[name="${key}"]`);
                        if (field) {
                            if (key === 'event_date') {
                                const date = new Date(event[key]);
                                const localDate = new Date(date.getTime() - (date.getTimezoneOffset() * 60000));
                                field.value = localDate.toISOString().slice(0, 16);
                            } else {
                                field.value = event[key] || '';
                            }
                        }
                    });
                }
            });
        }

        jQuery(document).ready(function($) {
            $('#ecms-event-form').on('submit', function(e) {
                e.preventDefault();
                
                var formData = {
                    action: 'ecms_save_event',
                    nonce: ecms_ajax.nonce
                };
                
                $(this).serializeArray().forEach(function(field) {
                    formData[field.name] = field.value;
                });
                
                var $submitBtn = $(this).find('button[type="submit"]');
                $submitBtn.prop('disabled', true).text('Saving...');
                
                $.post(ecms_ajax.ajax_url, formData, function(response) {
                    if (response.success) {
                        alert('✅ Event saved successfully!');
                        closeEventModal();
                        if (window.ecmsCalendar) {
                            window.ecmsCalendar.refetchEvents();
                        }
                        if (window.location.href.includes('page=ecms-events')) {
                            location.reload();
                        }
                    } else {
                        alert('❌ Error: ' + (response.data.message || 'Unknown error'));
                    }
                }).fail(function() {
                    alert('❌ Connection error. Please try again.');
                }).always(function() {
                    $submitBtn.prop('disabled', false).text('💾 Save Event');
                });
            });
        });
        </script>
        <?php
    }
    
    private function render_event_details_modal() {
        ?>
        <div id="ecms-event-details-modal" class="ecms-modal">
            <div class="ecms-modal-content">
                <div class="ecms-modal-header">
                    <span class="ecms-close" onclick="closeEventDetailsModal()">&times;</span>
                    <h2 id="event-details-title">Event Details</h2>
                </div>
                <div class="ecms-modal-body" id="event-details-content">
                    <!-- Content loaded via AJAX -->
                </div>
            </div>
        </div>
        
        <script>
        function showEventDetails(eventId) {
            const modal = document.getElementById('ecms-event-details-modal');
            const content = document.getElementById('event-details-content');
            
            content.innerHTML = '<p>Loading...</p>';
            modal.style.display = 'block';
            
            jQuery.post(ecms_ajax.ajax_url, {
                action: 'ecms_get_event_details',
                nonce: ecms_ajax.nonce,
                event_id: eventId
            }, function(response) {
                if (response.success) {
                    const event = response.data;
                    content.innerHTML = buildEventDetailsHTML(event);
                } else {
                    content.innerHTML = '<p>Error loading event details.</p>';
                }
            });
        }

        function closeEventDetailsModal() {
            document.getElementById('ecms-event-details-modal').style.display = 'none';
        }

        function buildEventDetailsHTML(event) {
            const eventDate = new Date(event.event_date);
            const formattedDate = eventDate.toLocaleDateString('en-GB', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
            
            return `
                <div class="event-details-grid">
                    <div class="detail-card">
                        <h4>🎭 Event Information</h4>
                        <p><strong>Title:</strong> ${event.event_title}</p>
                        <p><strong>Type:</strong> ${event.event_type || 'Not specified'}</p>
                        <p><strong>Date & Time:</strong> ${formattedDate}</p>
                        <p><strong>Duration:</strong> ${event.event_duration || 120} minutes</p>
                        <p><strong>Fee:</strong> £${parseFloat(event.event_fee || 0).toFixed(2)}</p>
                        <p><strong>Status:</strong> ${event.contract_status}</p>
                    </div>
                    
                    <div class="detail-card">
                        <h4>👤 Client Information</h4>
                        <p><strong>Name:</strong> ${event.client_name}</p>
                        <p><strong>Email:</strong> <a href="mailto:${event.client_email}">${event.client_email}</a></p>
                        <p><strong>Phone:</strong> ${event.client_phone ? `<a href="tel:${event.client_phone}">${event.client_phone}</a>` : 'Not provided'}</p>
                        <p><strong>Emergency Contact:</strong> ${event.emergency_contact || 'Not provided'}</p>
                        ${event.client_address ? `<p><strong>Address:</strong><br>${event.client_address.replace(/\n/g, '<br>')}</p>` : ''}
                    </div>
                </div>
                
                ${event.venue_name ? `
                <div class="detail-card">
                    <h4>📍 Venue Information</h4>
                    <p><strong>Name:</strong> ${event.venue_name}</p>
                    ${event.venue_phone ? `<p><strong>Phone:</strong> <a href="tel:${event.venue_phone}">${event.venue_phone}</a></p>` : ''}
                    ${event.venue_address ? `<p><strong>Address:</strong><br>${event.venue_address.replace(/\n/g, '<br>')}</p>` : ''}
                </div>
                ` : ''}
                
                ${event.special_requirements ? `
                <div class="detail-card">
                    <h4>⚙️ Special Requirements</h4>
                    <p>${event.special_requirements.replace(/\n/g, '<br>')}</p>
                </div>
                ` : ''}
                
                ${event.notes ? `
                <div class="detail-card">
                    <h4>📝 Notes</h4>
                    <p>${event.notes.replace(/\n/g, '<br>')}</p>
                </div>
                ` : ''}
                
                <div class="calendar-export-buttons">
                    <button type="button" class="btn btn-primary" onclick="openEventModal(null, ${event.id})">✏️ Edit Event</button>
                    <button type="button" class="btn btn-success" onclick="exportEventToGoogleCalendar(${event.id})">📤 Add to Google Calendar</button>
                    <button type="button" class="btn btn-success" onclick="exportEventToICal(${event.id})">📅 Download iCal</button>
                </div>
            `;
        }

        function exportEventToGoogleCalendar(eventId) {
            window.open(ecms_ajax.ajax_url + '?action=ecms_export_google_calendar&event_id=' + eventId + '&nonce=' + ecms_ajax.nonce, '_blank');
        }

        function exportEventToICal(eventId) {
            window.open(ecms_ajax.ajax_url + '?action=ecms_export_ical&event_id=' + eventId + '&nonce=' + ecms_ajax.nonce, '_blank');
        }
        </script>
        <?php
    }
    
    public function render_contact_form($atts) {
        $atts = shortcode_atts(['show_referral' => 'no'], $atts);
        $form_id = 'ecms-form-' . uniqid();
        
        ob_start();
        ?>
        <div class="ecms-contact-form">
            <h3>🎭 Get In Touch - Let's Make Your Event Amazing!</h3>
            <p>Interested in booking an entertainment service? Let's chat!</p>
            
            <form id="<?php echo $form_id; ?>" class="ecms-public-form">
                <div class="form-grid">
                    <div class="form-row">
                        <label>Your Name *</label>
                        <input type="text" name="client_name" required>
                    </div>
                    <div class="form-row">
                        <label>Email Address *</label>
                        <input type="email" name="client_email" required>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-row">
                        <label>Phone Number</label>
                        <input type="tel" name="client_phone">
                    </div>
                    <div class="form-row">
                        <label>Event Date (if known)</label>
                        <input type="date" name="event_date">
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-row">
                        <label>Type of Event</label>
                        <select name="event_type">
                            <option value="">Please select...</option>
                            <option value="wedding">Wedding</option>
                            <option value="corporate">Corporate Event</option>
                            <option value="birthday">Birthday Party</option>
                            <option value="private">Private Event</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <label>Venue Name</label>
                        <input type="text" name="venue_name">
                    </div>
                </div>
                
                <div class="form-row">
                    <label>Tell me about your event</label>
                    <textarea name="message" rows="4" placeholder="Tell us about your event, venue, audience size, and any special requirements..."></textarea>
                </div>
                
                <div class="form-row">
                    <button type="submit" class="ecms-submit-btn">🚀 Send Inquiry</button>
                </div>
                
                <div class="ecms-form-message" style="display:none;"></div>
            </form>
        </div>
        
        <script>
        (function($) {
            $(document).ready(function() {
                $('#<?php echo $form_id; ?>').on('submit', function(e) {
                    e.preventDefault();
                    
                    var $form = $(this);
                    var $button = $form.find('.ecms-submit-btn');
                    var $message = $form.find('.ecms-form-message');
                    
                    $button.prop('disabled', true).text('Sending...');
                    
                    var formData = {
                        action: 'ecms_submit_inquiry',
                        nonce: '<?php echo wp_create_nonce('ecms_nonce'); ?>'
                    };
                    
                    $form.serializeArray().forEach(function(field) {
                        formData[field.name] = field.value;
                    });
                    
                    $.post('<?php echo admin_url('admin-ajax.php'); ?>', formData)
                        .done(function(response) {
                            if (response.success) {
                                $message.removeClass('error').addClass('success')
                                    .html('🎉 Thank you! Your inquiry has been sent. We\'ll get back to you soon!')
                                    .slideDown();
                                $form[0].reset();
                            } else {
                                $message.removeClass('success').addClass('error')
                                    .html('❌ Sorry, there was an error. Please try again or call us directly.')
                                    .slideDown();
                            }
                        })
                        .fail(function() {
                            $message.removeClass('success').addClass('error')
                                .html('❌ Connection error. Please check your internet and try again.')
                                .slideDown();
                        })
                        .always(function() {
                            $button.prop('disabled', false).text('🚀 Send Inquiry');
                        });
                });
            });
        })(jQuery);
        </script>
        
        <style>
        .ecms-contact-form { max-width: 600px; margin: 0 auto; padding: 20px; background: #f9f9f9; border-radius: 10px; }
        .ecms-public-form .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        @media (max-width: 600px) { .ecms-public-form .form-grid { grid-template-columns: 1fr; } }
        .ecms-public-form .form-row { margin-bottom: 15px; }
        .ecms-public-form label { display: block; font-weight: 600; margin-bottom: 5px; color: #333; }
        .ecms-public-form input, .ecms-public-form textarea, .ecms-public-form select { 
            width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px; 
        }
        .ecms-public-form .ecms-submit-btn { 
            background: #007cba; color: white; padding: 12px 30px; border: none; border-radius: 6px; 
            cursor: pointer; font-weight: 600; font-size: 16px; width: 100%; transition: all 0.3s ease; 
        }
        .ecms-public-form .ecms-submit-btn:hover { background: #005a87; transform: translateY(-2px); }
        .ecms-form-message { margin-top: 15px; padding: 15px; border-radius: 6px; text-align: center; font-weight: 600; }
        .ecms-form-message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .ecms-form-message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        </style>
        <?php
        return ob_get_clean();
    }
    
    // AJAX Functions
    public function ajax_save_event() {
        check_ajax_referer('ecms_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized access']);
        }
        
        global $wpdb;
        
        $event_data = [
            'event_title' => sanitize_text_field($_POST['event_title']),
            'event_type' => sanitize_text_field($_POST['event_type'] ?? ''),
            'event_date' => sanitize_text_field($_POST['event_date']),
            'event_duration' => intval($_POST['event_duration'] ?? 120),
            'event_fee' => floatval($_POST['event_fee'] ?? 0),
            'client_name' => sanitize_text_field($_POST['client_name']),
            'client_email' => sanitize_email($_POST['client_email']),
            'client_phone' => sanitize_text_field($_POST['client_phone'] ?? ''),
            'client_address' => sanitize_textarea_field($_POST['client_address'] ?? ''),
            'emergency_contact' => sanitize_text_field($_POST['emergency_contact'] ?? ''),
            'venue_name' => sanitize_text_field($_POST['venue_name'] ?? ''),
            'venue_address' => sanitize_textarea_field($_POST['venue_address'] ?? ''),
            'venue_phone' => sanitize_text_field($_POST['venue_phone'] ?? ''),
            'special_requirements' => sanitize_textarea_field($_POST['special_requirements'] ?? ''),
            'notes' => sanitize_textarea_field($_POST['notes'] ?? '')
        ];
        
        $event_id = intval($_POST['event_id'] ?? 0);
        
        if ($event_id) {
            // Update existing event
            $result = $wpdb->update(
                $wpdb->prefix . 'ecms_events',
                $event_data,
                ['id' => $event_id]
            );
        } else {
            // Create new event
            $result = $wpdb->insert($wpdb->prefix . 'ecms_events', $event_data);
            $event_id = $wpdb->insert_id;
        }
        
        if ($result !== false) {
            wp_send_json_success(['message' => 'Event saved successfully', 'event_id' => $event_id]);
        } else {
            wp_send_json_error(['message' => 'Failed to save: ' . $wpdb->last_error]);
        }
    }
    
    public function ajax_get_events() {
        check_ajax_referer('ecms_nonce', 'nonce');
        
        global $wpdb;
        $events = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ecms_events ORDER BY event_date");
        
        $calendar_events = [];
        foreach ($events as $event) {
            $calendar_events[] = [
                'id' => $event->id,
                'title' => $event->event_title,
                'start' => $event->event_date,
                'backgroundColor' => $event->contract_status === 'confirmed' ? '#00a32a' : '#f39c12',
                'borderColor' => $event->contract_status === 'confirmed' ? '#00a32a' : '#f39c12',
                'extendedProps' => [
                    'client_name' => $event->client_name,
                    'venue_name' => $event->venue_name,
                    'event_fee' => $event->event_fee
                ]
            ];
        }
        
        wp_send_json_success($calendar_events);
    }
    
    public function ajax_get_event_details() {
        check_ajax_referer('ecms_nonce', 'nonce');
        
        $event_id = intval($_POST['event_id']);
        global $wpdb;
        
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ecms_events WHERE id = %d",
            $event_id
        ));
        
        if ($event) {
            wp_send_json_success($event);
        } else {
            wp_send_json_error(['message' => 'Event not found']);
        }
    }
    
    public function ajax_submit_inquiry() {
        check_ajax_referer('ecms_nonce', 'nonce');
        
        global $wpdb;
        
        $inquiry_data = [
            'client_name' => sanitize_text_field($_POST['client_name']),
            'client_email' => sanitize_email($_POST['client_email']),
            'client_phone' => sanitize_text_field($_POST['client_phone'] ?? ''),
            'event_date' => !empty($_POST['event_date']) ? sanitize_text_field($_POST['event_date']) : null,
            'event_type' => sanitize_text_field($_POST['event_type'] ?? ''),
            'venue_name' => sanitize_text_field($_POST['venue_name'] ?? ''),
            'message' => sanitize_textarea_field($_POST['message'] ?? ''),
            'status' => 'new'
        ];
        
        $result = $wpdb->insert($wpdb->prefix . 'ecms_inquiries', $inquiry_data);
        
        if ($result) {
            // Send email notification
            $to = get_option('admin_email');
            $subject = 'New Inquiry: ' . $inquiry_data['client_name'];
            $message = sprintf(
                "New inquiry received!\n\n" .
                "Name: %s\n" .
                "Email: %s\n" .
                "Phone: %s\n" .
                "Event Date: %s\n" .
                "Event Type: %s\n" .
                "Venue: %s\n\n" .
                "Message:\n%s",
                $inquiry_data['client_name'],
                $inquiry_data['client_email'],
                $inquiry_data['client_phone'] ?: 'Not provided',
                $inquiry_data['event_date'] ?: 'TBD',
                $inquiry_data['event_type'] ?: 'Not specified',
                $inquiry_data['venue_name'] ?: 'Not specified',
                $inquiry_data['message']
            );
            $headers = ['From: ' . $inquiry_data['client_name'] . ' <' . $inquiry_data['client_email'] . '>'];

            wp_mail($to, $subject, $message, $headers);
            
            wp_send_json_success(['message' => 'Inquiry submitted successfully']);
        } else {
            wp_send_json_error(['message' => 'Failed to submit inquiry']);
        }
    }
    
    public function ajax_export_google_calendar() {
        check_ajax_referer('ecms_nonce', 'nonce');
        
        global $wpdb;
        
        $event_id = intval($_GET['event_id'] ?? 0);
        
        if ($event_id) {
            // Export single event
            $event = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ecms_events WHERE id = %d",
                $event_id
            ));
            $events = $event ? [$event] : [];
        } else {
            // Export all events
            $events = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ecms_events ORDER BY event_date");
        }
        
        if (empty($events)) {
            wp_die('No events to export');
        }
        
        // Create Google Calendar URL for first event (Google Calendar doesn't support bulk import via URL)
        $event = $events[0];
        $start_time = date('Ymd\THis', strtotime($event->event_date));
        $end_time = date('Ymd\THis', strtotime($event->event_date . ' +' . ($event->event_duration ?? 120) . ' minutes'));
        
        $details = "Client: " . $event->client_name . "\n";
        $details .= "Email: " . $event->client_email . "\n";
        if ($event->client_phone) $details .= "Phone: " . $event->client_phone . "\n";
        if ($event->venue_name) $details .= "Venue: " . $event->venue_name . "\n";
        if ($event->notes) $details .= "Notes: " . $event->notes;
        
        $google_url = "https://calendar.google.com/calendar/render?action=TEMPLATE" .
                     "&text=" . urlencode($event->event_title) .
                     "&dates=" . $start_time . "/" . $end_time .
                     "&details=" . urlencode($details) .
                     "&location=" . urlencode($event->venue_address ?: $event->venue_name ?: '');
        
        wp_redirect($google_url);
        exit;
    }
    
    public function ajax_export_ical() {
        check_ajax_referer('ecms_nonce', 'nonce');
        
        global $wpdb;
        
        $event_id = intval($_GET['event_id'] ?? 0);
        
        if ($event_id) {
            // Export single event
            $event = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ecms_events WHERE id = %d",
                $event_id
            ));
            $events = $event ? [$event] : [];
            $filename = 'event-' . $event_id . '.ics';
        } else {
            // Export all events
            $events = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ecms_events ORDER BY event_date");
            $filename = 'entertaincms-events.ics';
        }
        
        if (empty($events)) {
            wp_die('No events to export');
        }
        
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        echo "BEGIN:VCALENDAR\r\n";
        echo "VERSION:2.0\r\n";
        echo "PRODID:-//EntertainCMS//EntertainCMS Pro//EN\r\n";
        echo "CALSCALE:GREGORIAN\r\n";
        
        foreach ($events as $event) {
            $start_time = date('Ymd\THis', strtotime($event->event_date));
            $end_time = date('Ymd\THis', strtotime($event->event_date . ' +' . ($event->event_duration ?? 120) . ' minutes'));
            $created_time = date('Ymd\THis', strtotime($event->created_at));
            
            $description = "Client: " . $event->client_name . "\\n";
            $description .= "Email: " . $event->client_email . "\\n";
            if ($event->client_phone) $description .= "Phone: " . $event->client_phone . "\\n";
            if ($event->venue_name) $description .= "Venue: " . $event->venue_name . "\\n";
            if ($event->notes) $description .= "Notes: " . str_replace(["\r\n", "\n", "\r"], "\\n", $event->notes);
            
            echo "BEGIN:VEVENT\r\n";
            echo "UID:" . $event->id . "@entertaincms\r\n";
            echo "DTSTAMP:" . $created_time . "\r\n";
            echo "DTSTART:" . $start_time . "\r\n";
            echo "DTEND:" . $end_time . "\r\n";
            echo "SUMMARY:" . str_replace(["\r\n", "\n", "\r"], " ", $event->event_title) . "\r\n";
            echo "DESCRIPTION:" . $description . "\r\n";
            if ($event->venue_address || $event->venue_name) {
                echo "LOCATION:" . str_replace(["\r\n", "\n", "\r"], " ", $event->venue_address ?: $event->venue_name) . "\r\n";
            }
            echo "STATUS:CONFIRMED\r\n";
            echo "END:VEVENT\r\n";
        }
        
        echo "END:VCALENDAR\r\n";
        exit;
    }
    
    // Helper Functions
    private function get_dashboard_stats() {
        global $wpdb;
        
        return [
            'upcoming_events' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ecms_events WHERE event_date >= NOW()"
            ) ?: 0,
            'new_inquiries' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ecms_inquiries WHERE status = 'new'"
            ) ?: 0,
            'monthly_revenue' => $wpdb->get_var(
                "SELECT SUM(event_fee) FROM {$wpdb->prefix}ecms_events 
                 WHERE MONTH(event_date) = MONTH(NOW()) AND YEAR(event_date) = YEAR(NOW())"
            ) ?: 0,
            'total_events' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ecms_events"
            ) ?: 0
        ];
    }
    
    private function get_recent_events($limit = 5) {
        global $wpdb;
        
        $events = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ecms_events WHERE event_date >= NOW() ORDER BY event_date ASC LIMIT %d",
            $limit
        ));
        
        if (empty($events)) {
            return '<p>No upcoming events. <button type="button" class="button" onclick="openEventModal()">Add your first event!</button></p>';
        }
        
        $output = '<div>';
        foreach ($events as $event) {
            $output .= sprintf(
                '<div style="padding: 10px; border-bottom: 1px solid #eee; cursor: pointer;" onclick="showEventDetails(%d)">
                    <strong>%s</strong><br>
                    <small>%s - %s</small>
                </div>',
                $event->id,
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
EntertainCMS_Pro::get_instance();