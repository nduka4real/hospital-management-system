<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HMS_Frontend_Dashboard {

    public static function init() {
        add_shortcode( 'hms_doctor_nurse_dashboard', array( __CLASS__, 'render_dashboard' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_hms_get_appointments_calendar', array( __CLASS__, 'ajax_get_appointments_calendar' ) );
        add_action( 'wp_ajax_nopriv_hms_get_appointments_calendar', array( __CLASS__, 'ajax_get_appointments_calendar' ) );
    }

    /**
     * Enqueue FullCalendar and custom styles/scripts on pages containing the shortcode.
     */
    public static function enqueue_assets() {
        global $post;
        if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'hms_doctor_nurse_dashboard' ) ) {
            wp_enqueue_style( 'fullcalendar-css', 'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css', array(), '5.11.3' );
            wp_enqueue_script( 'fullcalendar-js', 'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js', array( 'jquery' ), '5.11.3', true );
            wp_enqueue_style( 'hms-frontend-dashboard', plugin_dir_url( __FILE__ ) . 'assets/hms-frontend-dashboard.css', array(), '1.0' );
            wp_enqueue_script( 'hms-frontend-dashboard', plugin_dir_url( __FILE__ ) . 'assets/hms-frontend-dashboard.js', array( 'jquery', 'fullcalendar-js' ), '1.0', true );
            wp_localize_script( 'hms-frontend-dashboard', 'hms_frontend', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'hms_calendar_nonce' ),
            ) );
        }
    }

    /**
     * Shortcode callback: display the dashboard for doctors/nurses.
     */
    public static function render_dashboard() {
        if ( ! is_user_logged_in() ) {
            return '<p>' . __( 'Please log in to view your dashboard.', 'hms' ) . '</p>';
        }

        $user = wp_get_current_user();
        if ( ! in_array( 'hms_doctor', (array) $user->roles ) && ! in_array( 'hms_nurse', (array) $user->roles ) ) {
            return '<p>' . __( 'You do not have permission to view this dashboard.', 'hms' ) . '</p>';
        }

        // Get current tab from URL parameter (list or calendar)
        $current_tab = isset( $_GET['hms_tab'] ) ? sanitize_key( $_GET['hms_tab'] ) : 'list';
        ob_start();
        ?>
        <div class="hms-dashboard-wrapper">
            <h2><?php _e( 'Appointments Dashboard', 'hms' ); ?></h2>
            <div class="hms-tabs">
                <a href="?hms_tab=list" class="hms-tab <?php echo $current_tab === 'list' ? 'active' : ''; ?>"><?php _e( 'List View', 'hms' ); ?></a>
                <a href="?hms_tab=calendar" class="hms-tab <?php echo $current_tab === 'calendar' ? 'active' : ''; ?>"><?php _e( 'Calendar View', 'hms' ); ?></a>
            </div>

            <div class="hms-tab-content">
                <?php if ( $current_tab === 'list' ) : ?>
                    <?php self::render_list_view(); ?>
                <?php else : ?>
                    <?php self::render_calendar_view(); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the list view with filters and a table of appointments.
     */
    private static function render_list_view() {
        $user = wp_get_current_user();
        $args = array();

        // Apply filters from GET
        if ( ! empty( $_GET['hms_department'] ) ) {
            $args['department_id'] = (int) $_GET['hms_department'];
        }
        if ( ! empty( $_GET['hms_status'] ) ) {
            $args['status'] = sanitize_key( $_GET['hms_status'] );
        }
        if ( ! empty( $_GET['hms_date_from'] ) ) {
            $args['date_from'] = sanitize_text_field( $_GET['hms_date_from'] );
        }
        if ( ! empty( $_GET['hms_date_to'] ) ) {
            $args['date_to'] = sanitize_text_field( $_GET['hms_date_to'] );
        }

        // Fetch appointments based on role
        if ( in_array( 'hms_doctor', (array) $user->roles ) ) {
            $doctor = HMS_Doctors::get_by_user_id( $user->ID );
            if ( ! $doctor ) {
                echo '<p>' . __( 'No doctor record found for your account.', 'hms' ) . '</p>';
                return;
            }
            $appointments = self::get_filtered_appointments_for_doctor( $args, $doctor->id );
        } elseif ( in_array( 'hms_nurse', (array) $user->roles ) ) {
            $nurse = HMS_Nurses::get_by_user_id( $user->ID );
            if ( ! $nurse ) {
                echo '<p>' . __( 'No nurse record found for your account.', 'hms' ) . '</p>';
                return;
            }
            // Nurses see all appointments in their department (or all if no department)
            if ( $nurse->department_id ) {
                $args['department_id'] = $nurse->department_id;
            }
            $appointments = HMS_Appointments::get_all( $args );
        } else {
            $appointments = array();
        }

        // Display filter form
        ?>
        <form method="get" action="" class="hms-filter-form">
            <input type="hidden" name="hms_tab" value="list">
            <div class="hms-filters">
                <select name="hms_department">
                    <option value=""><?php _e( 'All Departments', 'hms' ); ?></option>
                    <?php
                    // If you have a departments table, fetch them; otherwise placeholder
                    // Example: $departments = HMS_Departments::get_all();
                    // For now, we'll just show a static list or rely on existing data.
                    ?>
                </select>
                <select name="hms_status">
                    <option value=""><?php _e( 'All Statuses', 'hms' ); ?></option>
                    <option value="pending" <?php selected( $_GET['hms_status'] ?? '', 'pending' ); ?>>Pending</option>
                    <option value="confirmed" <?php selected( $_GET['hms_status'] ?? '', 'confirmed' ); ?>>Confirmed</option>
                    <option value="completed" <?php selected( $_GET['hms_status'] ?? '', 'completed' ); ?>>Completed</option>
                    <option value="cancelled" <?php selected( $_GET['hms_status'] ?? '', 'cancelled' ); ?>>Cancelled</option>
                </select>
                <input type="date" name="hms_date_from" value="<?php echo esc_attr( $_GET['hms_date_from'] ?? '' ); ?>" placeholder="<?php _e( 'From', 'hms' ); ?>">
                <input type="date" name="hms_date_to" value="<?php echo esc_attr( $_GET['hms_date_to'] ?? '' ); ?>" placeholder="<?php _e( 'To', 'hms' ); ?>">
                <input type="submit" class="button" value="<?php _e( 'Filter', 'hms' ); ?>">
                <a href="<?php echo remove_query_arg( array( 'hms_department', 'hms_status', 'hms_date_from', 'hms_date_to' ) ); ?>" class="button"><?php _e( 'Reset', 'hms' ); ?></a>
            </div>
        </form>

        <table class="hms-appointments-table">
            <thead>
                <tr>
                    <th><?php _e( 'ID', 'hms' ); ?></th>
                    <th><?php _e( 'Patient', 'hms' ); ?></th>
                    <th><?php _e( 'Doctor', 'hms' ); ?></th>
                    <th><?php _e( 'Department', 'hms' ); ?></th>
                    <th><?php _e( 'Date & Time', 'hms' ); ?></th>
                    <th><?php _e( 'Reason', 'hms' ); ?></th>
                    <th><?php _e( 'Status', 'hms' ); ?></th>
                    <th><?php _e( 'Actions', 'hms' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $appointments ) ) : ?>
                    <tr><td colspan="8"><?php _e( 'No appointments found.', 'hms' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $appointments as $appt ) : 
                        $patient = HMS_Patients::get( $appt->patient_id );
                        $doctor = $appt->doctor_id ? HMS_Doctors::get( $appt->doctor_id ) : null;
                        $doctor_user = $doctor ? get_userdata( $doctor->user_id ) : null;
                        // Department name - assume a function exists
                        $department = $appt->department_id ? self::get_department_name( $appt->department_id ) : __( 'N/A', 'hms' );
                    ?>
                    <tr>
                        <td><?php echo $appt->id; ?></td>
                        <td><?php echo $patient ? esc_html( $patient->name ) : __( 'Unknown', 'hms' ); ?></td>
                        <td><?php echo $doctor_user ? esc_html( $doctor_user->display_name ) : __( 'Unassigned', 'hms' ); ?></td>
                        <td><?php echo esc_html( $department ); ?></td>
                        <td><?php echo $appt->appointment_date . ' ' . $appt->appointment_time; ?></td>
                        <td><?php echo esc_html( $appt->reason ); ?></td>
                        <td><span class="hms-status <?php echo $appt->status; ?>"><?php echo ucfirst( $appt->status ); ?></span></td>
                        <td>
                            <form method="post" action="" style="display:inline;">
                                <?php wp_nonce_field( 'hms_status', 'hms_status_nonce' ); ?>
                                <input type="hidden" name="hms_action" value="update_appointment_status">
                                <input type="hidden" name="appointment_id" value="<?php echo $appt->id; ?>">
                                <select name="status">
                                    <option value="pending" <?php selected( $appt->status, 'pending' ); ?>>Pending</option>
                                    <option value="confirmed" <?php selected( $appt->status, 'confirmed' ); ?>>Confirmed</option>
                                    <option value="completed" <?php selected( $appt->status, 'completed' ); ?>>Completed</option>
                                    <option value="cancelled" <?php selected( $appt->status, 'cancelled' ); ?>>Cancelled</option>
                                </select>
                                <input type="submit" class="button button-small" value="<?php _e( 'Update', 'hms' ); ?>">
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Helper to get department name (dummy – replace with your actual logic)
     */
    private static function get_department_name( $department_id ) {
        // If you have a departments table, use it.
        // For now, return a placeholder.
        return 'Department #' . $department_id;
    }

    /**
     * Custom query for doctors: show appointments assigned to them OR unassigned (NULL).
     */
    private static function get_filtered_appointments_for_doctor( $args, $doctor_id ) {
        global $wpdb;
        $table = HMS_DB::appointments_table();
        $where = array( '1=1' );

        if ( ! empty( $args['department_id'] ) ) {
            $where[] = $wpdb->prepare( 'department_id = %d', $args['department_id'] );
        }
        if ( ! empty( $args['status'] ) ) {
            $where[] = $wpdb->prepare( 'status = %s', $args['status'] );
        }
        if ( ! empty( $args['date_from'] ) ) {
            $where[] = $wpdb->prepare( 'appointment_date >= %s', $args['date_from'] );
        }
        if ( ! empty( $args['date_to'] ) ) {
            $where[] = $wpdb->prepare( 'appointment_date <= %s', $args['date_to'] );
        }

        // Show assigned to this doctor OR unassigned (doctor_id IS NULL)
        $where[] = $wpdb->prepare( '(doctor_id = %d OR doctor_id IS NULL)', $doctor_id );

        $sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY appointment_date DESC, appointment_time DESC';
        return $wpdb->get_results( $sql );
    }

    /**
     * Render the calendar view.
     */
    private static function render_calendar_view() {
        ?>
        <div id="hms-calendar"></div>
        <?php
        // The calendar is initialized via the external JS file
    }

    /**
     * AJAX endpoint to fetch appointments for the calendar (front-end).
     */
    public static function ajax_get_appointments_calendar() {
        check_ajax_referer( 'hms_calendar_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( __( 'Please log in.', 'hms' ) );
        }

        $user = wp_get_current_user();
        if ( ! in_array( 'hms_doctor', (array) $user->roles ) && ! in_array( 'hms_nurse', (array) $user->roles ) ) {
            wp_send_json_error( __( 'Permission denied.', 'hms' ) );
        }

        $start = isset( $_POST['start'] ) ? sanitize_text_field( $_POST['start'] ) : '';
        $end   = isset( $_POST['end'] ) ? sanitize_text_field( $_POST['end'] ) : '';

        $args = array();
        if ( $start ) $args['date_from'] = $start;
        if ( $end ) $args['date_to'] = $end;

        if ( in_array( 'hms_doctor', (array) $user->roles ) ) {
            $doctor = HMS_Doctors::get_by_user_id( $user->ID );
            if ( ! $doctor ) {
                wp_send_json_error( __( 'Doctor record not found.', 'hms' ) );
            }
            $appointments = self::get_filtered_appointments_for_doctor( $args, $doctor->id );
        } else { // nurse
            $nurse = HMS_Nurses::get_by_user_id( $user->ID );
            if ( ! $nurse ) {
                wp_send_json_error( __( 'Nurse record not found.', 'hms' ) );
            }
            if ( $nurse->department_id ) {
                $args['department_id'] = $nurse->department_id;
            }
            $appointments = HMS_Appointments::get_all( $args );
        }

        $events = array();
        foreach ( $appointments as $appt ) {
            $doctor = $appt->doctor_id ? HMS_Doctors::get( $appt->doctor_id ) : null;
            $doctor_name = $doctor ? get_userdata( $doctor->user_id )->display_name : __( 'Unassigned', 'hms' );
            $patient = HMS_Patients::get( $appt->patient_id );
            $title = sprintf( '%s - %s', $patient ? $patient->name : __( 'Patient', 'hms' ), $doctor_name );
            $events[] = array(
                'id'    => $appt->id,
                'title' => $title,
                'start' => $appt->appointment_date . 'T' . $appt->appointment_time,
                'end'   => $appt->appointment_date . 'T' . $appt->appointment_time, // Add duration if needed
                'color' => self::get_status_color( $appt->status ),
                'extendedProps' => array(
                    'status' => $appt->status,
                    'reason' => $appt->reason,
                ),
            );
        }
        wp_send_json_success( $events );
    }

    private static function get_status_color( $status ) {
        switch ( $status ) {
            case 'pending':   return '#f0ad4e';
            case 'confirmed': return '#5bc0de';
            case 'completed': return '#5cb85c';
            case 'cancelled': return '#d9534f';
            default:          return '#777';
        }
    }
}