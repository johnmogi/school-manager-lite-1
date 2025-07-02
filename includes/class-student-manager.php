<?php
/**
 * Student Manager
 *
 * Handles all operations related to students
 *
 * @package School_Manager_Lite
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class School_Manager_Lite_Student_Manager {
    /**
     * The single instance of the class.
     */
    private static $instance = null;

    /**
     * Main Instance.
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    public function __construct() {
        // Initialize hooks
        add_action('init', array($this, 'init'));
    }

    /**
     * Initialize.
     */
    public function init() {
        // Nothing to initialize yet
    }

    /**
     * Get students from custom table
     *
     * @param array $args Query arguments
     * @return array Array of student objects
     */
    public function get_students($args = array()) {
        global $wpdb;
        
        // Ensure student role exists
        $this->ensure_student_role_exists();
        
        $defaults = array(
            'class_id' => 0, // Filter by class
            'orderby' => 'name', 
            'order' => 'ASC',
            'limit' => -1,
            'offset' => 0,
            'search' => '',
            'count_total' => false,
        );

        $args = wp_parse_args($args, $defaults);
        $table_name = $wpdb->prefix . 'school_students';
        
        // Base query
        if ($args['count_total']) {
            $select = "SELECT COUNT(*)";
        } else {
            $select = "SELECT *";
        }
        
        $query = "{$select} FROM {$table_name} WHERE 1=1";
        $query_args = array();
        
        // Class filter
        if (!empty($args['class_id'])) {
            $query .= " AND class_id = %d";
            $query_args[] = $args['class_id'];
        }
        
        // Search
        if (!empty($args['search'])) {
            $query .= " AND (name LIKE %s OR email LIKE %s)";
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $query_args[] = $like;
            $query_args[] = $like;
        }
        
        // For count query, we're done here
        if ($args['count_total']) {
            if (!empty($query_args)) {
                $query = $wpdb->prepare($query, $query_args);
            }
            return $wpdb->get_var($query);
        }
        
        // Order
        $allowed_order_fields = array('name', 'id', 'email', 'created_at');
        $orderby = in_array($args['orderby'], $allowed_order_fields) ? $args['orderby'] : 'name';
        $order = $args['order'] === 'DESC' ? 'DESC' : 'ASC';
        
        $query .= " ORDER BY {$orderby} {$order}";
        
        // Limit
        if ($args['limit'] > 0) {
            $query .= " LIMIT %d OFFSET %d";
            $query_args[] = $args['limit'];
            $query_args[] = $args['offset'];
        }
        
        // Prepare query if needed
        if (!empty($query_args)) {
            $query = $wpdb->prepare($query, $query_args);
        }
        
        // Execute query
        $results = $wpdb->get_results($query);
        
        return is_array($results) ? $results : array();
    }
    
    /**
     * Get WordPress users with student role
     *
     * @param array $args Query arguments
     * @return array Array of WP_User objects
     */
    public function get_student_users($args = array()) {
        // Ensure student role exists
        $this->ensure_student_role_exists();
        
        $defaults = array(
            'role' => 'student_private',
            'orderby' => 'display_name',
            'order' => 'ASC',
            'number' => -1,
            'paged' => 1,
            'search' => '',
            'search_columns' => array('user_login', 'user_email', 'display_name'),
            'count_total' => true, // For pagination
            'fields' => 'all_with_meta', // Return complete user objects
        );

        $args = wp_parse_args($args, $defaults);
        
        // Convert search into WordPress format if provided
        if (!empty($args['search'])) {
            $args['search'] = '*' . $args['search'] . '*';
        }
        
        // Get users with student role
        return get_users($args);
    }
    
    /**
     * Ensure student role exists and has proper capabilities
     */
    public function ensure_student_role_exists() {
        // Check if the role exists
        if (!get_role('student_private')) {
            // The role doesn't exist, so create it
            add_role(
                'student_private',
                __('Student', 'school-manager-lite'),
                array(
                    'read' => true,
                    'access_school_content' => true,
                )
            );
        } else {
            // Ensure role has all required capabilities
            $student_role = get_role('student_private');
            $capabilities = array(
                'read' => true,
                'access_school_content' => true,
            );
            
            foreach ($capabilities as $cap => $grant) {
                if (!$student_role->has_cap($cap)) {
                    $student_role->add_cap($cap);
                }
            }
        }
    }

    /**
     * Get student by ID
     *
     * @param int $student_id Student ID
     * @return object|false Student object or false if not found
     */
    public function get_student($student_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'school_students';
        
        $student = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $student_id
        ));
        
        return $student ? $student : false;
    }

    /**
     * Get student by WordPress user ID
     *
     * @param int $wp_user_id WordPress user ID
     * @param int $class_id Optional class ID to get specific student record
     * @param bool $create_if_not_exists Whether to create a student record if not found
     * @return object|false Student object or false if not found and not created
     */
    public function get_student_by_user_id($wp_user_id, $class_id = 0, $create_if_not_exists = true) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'school_students';
        
        if ($class_id) {
            $student = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE wp_user_id = %d AND class_id = %d",
                $wp_user_id,
                $class_id
            ));
        } else {
            $student = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE wp_user_id = %d LIMIT 1",
                $wp_user_id
            ));
        }
        
        // If student not found but we should create one
        if (!$student && $create_if_not_exists) {
            $user = get_user_by('id', $wp_user_id);
            if ($user && in_array('student_private', (array)$user->roles)) {
                // Create student record
                $student_data = array(
                    'wp_user_id' => $wp_user_id,
                    'class_id' => $class_id ?: 0,
                    'name' => $user->display_name,
                    'email' => $user->user_email,
                    'created_at' => current_time('mysql')
                );
                
                $result = $wpdb->insert(
                    $table_name,
                    $student_data,
                    array('%d', '%d', '%s', '%s', '%s')
                );
                
                if ($result) {
                    $student = (object)array_merge(array('id' => $wpdb->insert_id), $student_data);
                }
            }
        }
        
        return $student ? $student : false;
    }

    /**
     * Create student
     *
     * @param array $data Student data
     * @return int|WP_Error Student ID or WP_Error on failure
     */
    public function create_student($data) {
        global $wpdb;
        
        $defaults = array(
            'wp_user_id' => 0, // Existing WordPress user ID
            'class_id' => 0,
            'name' => '',
            'email' => '',
            'create_user' => false, // Whether to create a WordPress user
            'password' => '', // Password for new user
            'send_credentials' => false, // Send email with login credentials
        );

        $data = wp_parse_args($data, $defaults);

        // Required fields
        if (empty($data['name'])) {
            return new WP_Error('missing_name', __('Student name is required', 'school-manager-lite'));
        }

        if (empty($data['class_id'])) {
            return new WP_Error('missing_class', __('Class is required', 'school-manager-lite'));
        }

        // Check if class exists
        $class_manager = School_Manager_Lite_Class_Manager::instance();
        $class = $class_manager->get_class($data['class_id']);
        
        if (!$class) {
            return new WP_Error('invalid_class', __('Invalid class ID', 'school-manager-lite'));
        }

        // Create WordPress user if needed
        if ($data['create_user'] && empty($data['wp_user_id'])) {
            if (empty($data['email'])) {
                return new WP_Error('missing_email', __('Email is required to create a user account', 'school-manager-lite'));
            }
            
            // Generate username from email
            $username = sanitize_user(current(explode('@', $data['email'])), true);
            
            // Make sure username is unique
            $i = 1;
            $original_username = $username;
            while (username_exists($username)) {
                $username = $original_username . $i;
                $i++;
            }
            
            // Generate password if not provided
            $password = !empty($data['password']) ? $data['password'] : wp_generate_password(12, true, true);
            
            // Create user
            $user_data = array(
                'user_login' => $username,
                'user_pass' => $password,
                'user_email' => $data['email'],
                'display_name' => $data['name'],
                'role' => 'student_private',
            );
            
            $user_id = wp_insert_user($user_data);
            
            if (is_wp_error($user_id)) {
                return $user_id;
            }
            
            $data['wp_user_id'] = $user_id;
            $data['password'] = $password; // Store for email sending
        }
        
        // If wp_user_id is provided, verify it's valid
        if (!empty($data['wp_user_id'])) {
            $user = get_user_by('id', $data['wp_user_id']);
            
            if (!$user) {
                return new WP_Error('invalid_user', __('Invalid WordPress user ID', 'school-manager-lite'));
            }
            
            // Assign student role if user doesn't have it
            if (!in_array('student_private', (array) $user->roles)) {
                $user->add_role('student_private');
            }
            
            // Check if student already exists for this user and class
            $table_name = $wpdb->prefix . 'school_students';
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE wp_user_id = %d AND class_id = %d",
                $data['wp_user_id'],
                $data['class_id']
            ));
            
            if ($existing) {
                return new WP_Error('student_exists', __('This user is already a student in this class', 'school-manager-lite'));
            }
        }

        // Insert student record
        $table_name = $wpdb->prefix . 'school_students';
        
        $insert_data = array(
            'wp_user_id' => $data['wp_user_id'],
            'class_id' => $data['class_id'],
            'name' => $data['name'],
            'email' => $data['email'],
            'created_at' => current_time('mysql'),
        );
        
        $insert_format = array('%d', '%d', '%s', '%s', '%s');
        
        $result = $wpdb->insert($table_name, $insert_data, $insert_format);
        
        if (!$result) {
            return new WP_Error('db_error', __('Could not create student record', 'school-manager-lite'));
        }
        
        $student_id = $wpdb->insert_id;
        
        // Send credentials email if requested and user was created
        if ($data['send_credentials'] && !empty($data['wp_user_id']) && !empty($data['password'])) {
            $this->send_student_credentials($data['wp_user_id'], $data['password'], $data['email']);
        }
        
        do_action('school_manager_lite_after_create_student', $student_id, $data);
        
        return $student_id;
    }

    /**
     * Update student
     *
     * @param int $student_id Student ID
     * @param array $data Student data
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function update_student($student_id, $data) {
        global $wpdb;
        
        $student = $this->get_student($student_id);
        
        if (!$student) {
            return new WP_Error('invalid_student', __('Invalid student ID', 'school-manager-lite'));
        }
        
        $update_data = array();
        $update_format = array();
        
        if (isset($data['name']) && !empty($data['name'])) {
            $update_data['name'] = $data['name'];
            $update_format[] = '%s';
        }
        
        if (isset($data['email'])) {
            $update_data['email'] = $data['email'];
            $update_format[] = '%s';
        }
        
        if (isset($data['class_id']) && !empty($data['class_id'])) {
            // Check if class exists
            $class_manager = School_Manager_Lite_Class_Manager::instance();
            $class = $class_manager->get_class($data['class_id']);
            
            if (!$class) {
                return new WP_Error('invalid_class', __('Invalid class ID', 'school-manager-lite'));
            }
            
            // Check if student already exists in this class
            if ($student->class_id != $data['class_id'] && !empty($student->wp_user_id)) {
                $table_name = $wpdb->prefix . 'school_students';
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table_name} WHERE wp_user_id = %d AND class_id = %d AND id != %d",
                    $student->wp_user_id,
                    $data['class_id'],
                    $student_id
                ));
                
                if ($existing) {
                    return new WP_Error('student_exists', __('This user is already a student in the target class', 'school-manager-lite'));
                }
            }
            
            $update_data['class_id'] = $data['class_id'];
            $update_format[] = '%d';
        }
        
        // If no data to update
        if (empty($update_data)) {
            return true;
        }
        
        $table_name = $wpdb->prefix . 'school_students';
        
        $result = $wpdb->update(
            $table_name,
            $update_data,
            array('id' => $student_id),
            $update_format,
            array('%d')
        );
        
        if ($result === false) {
            return new WP_Error('db_error', __('Could not update student', 'school-manager-lite'));
        }
        
        // Update WordPress user if needed
        if (!empty($student->wp_user_id)) {
            $user_data = array('ID' => $student->wp_user_id);
            
            if (!empty($update_data['name'])) {
                $user_data['display_name'] = $update_data['name'];
            }
            
            if (!empty($update_data['email'])) {
                $user_data['user_email'] = $update_data['email'];
            }
            
            if (count($user_data) > 1) { // More than just ID
                wp_update_user($user_data);
            }
        }
        
        do_action('school_manager_lite_after_update_student', $student_id, $data);
        
        return true;
    }

    /**
     * Delete student
     *
     * @param int $student_id Student ID
     * @param bool $delete_user Whether to delete the WordPress user as well
     * @return bool True on success, false on failure
     */
    public function delete_student($student_id, $delete_user = false) {
        global $wpdb;
        
        $student = $this->get_student($student_id);
        
        if (!$student) {
            return false;
        }
        
        do_action('school_manager_lite_before_delete_student', $student_id, $student);
        
        // Delete student record
        $table_name = $wpdb->prefix . 'school_students';
        $result = $wpdb->delete(
            $table_name,
            array('id' => $student_id),
            array('%d')
        );
        
        if ($result === false) {
            return false;
        }
        
        // Delete WordPress user if requested
        if ($delete_user && !empty($student->wp_user_id)) {
            // Check if user has other student records
            $other_records = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE wp_user_id = %d AND id != %d",
                $student->wp_user_id,
                $student_id
            ));
            
            if (empty($other_records)) {
                wp_delete_user($student->wp_user_id);
            }
        }
        
        return true;
    }

    /**
     * Send welcome email with login credentials
     *
     * @param int $user_id User ID
     * @param string $password User password
     * @param string $email User email
     * @return bool True on success, false on failure
     */
    /**
     * Get all classes a student is enrolled in
     *
     * @param int $user_id WordPress user ID
     * @return array Array of class objects
     */
    public function get_student_classes($user_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'school_students';
        $class_table = $wpdb->prefix . 'school_classes';
        
        $query = $wpdb->prepare(
            "SELECT c.* 
             FROM {$class_table} c
             INNER JOIN {$table_name} s ON c.id = s.class_id
             WHERE s.wp_user_id = %d
             ORDER BY c.name ASC",
            $user_id
        );
        
        $results = $wpdb->get_results($query);
        
        return is_array($results) ? $results : array();
    }
    
    /**
     * Send welcome email with login credentials
     *
     * @param int $user_id User ID
     * @param string $password User password
     * @param string $email User email
     * @return bool True on success, false on failure
     */
    private function send_student_credentials($user_id, $password, $email) {
        $user = get_userdata($user_id);
        
        if (!$user) {
            return false;
        }
        
        $blog_name = get_bloginfo('name');
        $login_url = wp_login_url();
        
        $subject = sprintf(__('Your %s Student Account', 'school-manager-lite'), $blog_name);
        
        $message = sprintf(__('Hello %s,', 'school-manager-lite'), $user->display_name) . "\r\n\r\n";
        $message .= sprintf(__('A student account has been created for you on %s.', 'school-manager-lite'), $blog_name) . "\r\n\r\n";
        $message .= __('Your login details:', 'school-manager-lite') . "\r\n";
        $message .= __('Username: ', 'school-manager-lite') . $user->user_login . "\r\n";
        $message .= __('Password: ', 'school-manager-lite') . $password . "\r\n\r\n";
        $message .= __('You can log in here: ', 'school-manager-lite') . $login_url . "\r\n\r\n";
        
        // Add class information if available
        $classes = $this->get_student_classes($user_id);
        if (!empty($classes)) {
            $message .= __('Your classes:', 'school-manager-lite') . "\r\n";
            foreach ($classes as $class) {
                $message .= '• ' . esc_html($class->name) . "\r\n";
            }
            $message .= "\r\n";
        }
        
        $message .= __('For security reasons, please change your password after your first login.', 'school-manager-lite') . "\r\n\r\n";
        $message .= __('Best regards,', 'school-manager-lite') . "\r\n";
        $message .= $blog_name . "\r\n";
        
        $headers = array('Content-Type: text/plain; charset=UTF-8');
        
        return wp_mail($email, $subject, $message, $headers);
    }
}

// Initialize the Student Manager
function School_Manager_Lite_Student_Manager() {
    return School_Manager_Lite_Student_Manager::instance();
}
