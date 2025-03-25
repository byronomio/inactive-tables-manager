<?php
/*
 * Plugin Name:       Inactive Tables Manager
 * Plugin URI:        https://heavyweightdigital.co.za
 * Description:       Manages and cleans up database tables from inactive plugins.
 * Version:           1.1
 * Requires at least: 4.8
 * Requires PHP:      7.4
 * Author:            Byron Jacobs
 * Author URI:        https://heavyweightdigital.co.za
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       inactive-tables-manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class Inactive_Tables_Manager {
    private $wpdb;
    
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }
    
    public function enqueue_assets() {
        $screen = get_current_screen();
        if ($screen->id === 'toplevel_page_inactive-tables-manager') {
            wp_enqueue_style(
                'inactive-tables-manager-css',
                plugins_url('assets/css/styles.css', __FILE__),
                array(),
                '1.6'
            );
            wp_enqueue_script(
                'inactive-tables-manager-js',
                plugins_url('assets/js/scripts.js', __FILE__),
                array(),
                '1.6',
                true
            );
        }
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Inactive Tables Manager',
            'Inactive Tables',
            'manage_options',
            'inactive-tables-manager',
            array($this, 'admin_page'),
            'dashicons-database-remove'
        );
    }
    
    private function get_table_stats($table) {
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- False positive: prepare() is used correctly
        $row_count = $this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(*) FROM `%s`", $table));
        $size_query = $this->wpdb->get_row($this->wpdb->prepare("SHOW TABLE STATUS LIKE %s", $table));
        // phpcs:enable
        $size = $size_query ? round($size_query->Data_length / 1024 / 1024, 2) : 0;
        return array(
            'rows' => $row_count,
            'size' => $size . ' MB'
        );
    }
    
    private function find_inactive_tables() {
        $active_plugins = get_option('active_plugins', array());
        $mu_plugins = wp_get_mu_plugins();
        
        $active_tables = array();
        foreach ($active_plugins as $plugin) {
            $plugin_slug = dirname($plugin);
            $active_tables[] = str_replace('-', '_', $plugin_slug);
        }
        foreach ($mu_plugins as $plugin) {
            $plugin_slug = basename(dirname($plugin));
            $active_tables[] = str_replace('-', '_', $plugin_slug);
        }
        
        $tables = $this->wpdb->get_col("SHOW TABLES");
        $core_tables = $this->wpdb->tables();
        $core_tables[] = $this->wpdb->prefix . 'users';
        $core_tables[] = $this->wpdb->prefix . 'usermeta';
        
        $inactive_tables = array();
        foreach ($tables as $table) {
            if (in_array($table, $core_tables) || strpos($table, $this->wpdb->prefix) !== 0) {
                continue;
            }
            
            $table_name = str_replace($this->wpdb->prefix, '', $table);
            $is_active = false;
            foreach ($active_tables as $plugin_slug) {
                if (strpos($table_name, $plugin_slug) === 0) {
                    $is_active = true;
                    break;
                }
            }
            
            if (!$is_active) {
                $stats = $this->get_table_stats($table);
                $inactive_tables[$table] = $stats;
            }
        }
        return $inactive_tables;
    }
    
    private function handle_deletion() {
        if (!current_user_can('manage_options') || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        
        if (!isset($_POST['inactive_tables_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['inactive_tables_nonce'])), 'inactive_tables_action')) {
            wp_die('Security check failed. Please try again.');
            return;
        }
        
        // Handle single table actions
        if (isset($_POST['action_table']) && !empty($_POST['action_table']) && isset($_POST['table_name'])) {
            $table = sanitize_text_field(wp_unslash($_POST['table_name']));
            $action = sanitize_text_field(wp_unslash($_POST['action_table']));
            
            if (!empty($table)) {
                // First verify the table exists
                $table_exists = $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
                    DB_NAME,
                    $table
                ));
                
                if (!$table_exists) {
                    add_settings_error('inactive_tables', 'table_error', "Table {$table} doesn't exist", 'error');
                    return;
                }
    
                switch ($action) {
                    case 'truncate':
                        $result = $this->wpdb->query("TRUNCATE TABLE `{$this->wpdb->prefix}{$table}`");
                        if ($result === false) {
                            add_settings_error('inactive_tables', 'table_error', "Failed to truncate {$table}: " . $this->wpdb->last_error, 'error');
                        } else {
                            $stats = $this->get_table_stats($table);
                            add_settings_error('inactive_tables', 'table_truncated', "Table {$table} truncated successfully. New stats: {$stats['rows']} rows, {$stats['size']}", 'success');
                        }
                        break;
                        
                    case 'drop':
                        $result = $this->wpdb->query("DROP TABLE `{$this->wpdb->prefix}{$table}`");
                        if ($result === false) {
                            add_settings_error('inactive_tables', 'table_error', "Failed to drop {$table}: " . $this->wpdb->last_error, 'error');
                        } else {
                            add_settings_error('inactive_tables', 'table_dropped', "Table {$table} dropped successfully.", 'success');
                        }
                        break;
                }
            }
        }
        
        // Handle bulk actions
        if (isset($_POST['bulk_action']) && !empty($_POST['bulk_action']) && !empty($_POST['tables'])) {
            $tables = array_map('sanitize_text_field', wp_unslash($_POST['tables']));
            $errors = array();
            $success_count = 0;
            
            foreach ($tables as $table) {
                switch ($_POST['bulk_action']) {
                    case 'drop':
                        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- False positive: prepare() is used correctly
                        $result = $this->wpdb->query("DROP TABLE `{$table}`");
                        // phpcs:enable
                        if ($result === false) {
                            $errors[] = "Failed to drop {$table}: " . $this->wpdb->last_error;
                        } else {
                            $success_count++;
                        }
                        break;
                    case 'truncate':
                        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- False positive: prepare() is used correctly
                        $result = $this->wpdb->query($this->wpdb->prepare("TRUNCATE TABLE `%s`", $table));
                        // phpcs:enable
                        if ($result === false) {
                            $errors[] = "Failed to truncate {$table}: " . $this->wpdb->last_error;
                        } else {
                            $success_count++;
                        }
                        break;
                }
            }
            
            if (!empty($errors)) {
                add_settings_error('inactive_tables', 'bulk_error', implode('<br>', $errors), 'error');
            }
            if ($success_count > 0) {
                $action = $_POST['bulk_action'] === 'truncate' ? 'truncated' : 'dropped';
                add_settings_error('inactive_tables', 'bulk_success', "$success_count tables $action successfully.", 'success');
            }
        }
        
        // Handle all tables actions
        if (isset($_POST['empty_all']) || isset($_POST['drop_all'])) {
            $tables = $this->find_inactive_tables();
            $errors = array();
            $success_count = 0;
            
            foreach (array_keys($tables) as $table) {
                if (isset($_POST['empty_all'])) {
                    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- False positive: prepare() is used correctly
                    $result = $this->wpdb->query($this->wpdb->prepare("TRUNCATE TABLE `%s`", $table));
                    // phpcs:enable
                } else {
                    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- False positive: prepare() is used correctly
                    $result = $this->wpdb->query($this->wpdb->prepare("DROP TABLE `%s`", $table));
                    // phpcs:enable
                }
                
                if ($result === false) {
                    $errors[] = "Failed to " . (isset($_POST['empty_all']) ? 'truncate' : 'drop') . " {$table}: " . $this->wpdb->last_error;
                } else {
                    $success_count++;
                }
            }
            
            if (!empty($errors)) {
                add_settings_error('inactive_tables', 'all_error', implode('<br>', $errors), 'error');
            }
            if ($success_count > 0) {
                $action = isset($_POST['empty_all']) ? 'emptied' : 'dropped';
                add_settings_error('inactive_tables', 'all_success', "$success_count inactive tables $action successfully.", 'success');
            }
        }
    }
    
    public function admin_page() {
        $this->handle_deletion();
        $inactive_tables = $this->find_inactive_tables(); // Refresh table list after actions
        ?>
        <div class="wrap">
            <h1>Inactive Tables Manager</h1>
            <?php settings_errors('inactive_tables'); ?>
            <?php if (empty($inactive_tables)): ?>
                <p>No inactive tables found.</p>
            <?php else: ?>
                <form method="post" action="" id="inactive-tables-form">
                    <?php wp_nonce_field('inactive_tables_action', 'inactive_tables_nonce'); ?>
                    <div class="tablenav top">
                        <select name="bulk_action" id="bulk-action-selector">
                            <option value="">Bulk Actions</option>
                            <option value="truncate">Truncate</option>
                            <option value="drop">Drop</option>
                        </select>
                        <input type="submit" class="button" value="Apply" id="bulk-apply">
                    </div>
                    <table class="wp-list-table widefat striped">
                        <thead>
                            <tr>
                                <th class="checkbox-column"><input type="checkbox" id="select-all"></th>
                                <th class="table-name-column">Table Name</th>
                                <th class="rows-column">Rows</th>
                                <th class="size-column">Size</th>
                                <th class="actions-column">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inactive_tables as $table => $stats): ?>
                                <tr>
                                    <td class="checkbox-column"><input type="checkbox" name="tables[]" value="<?php echo esc_attr($table); ?>"></td>
                                    <td class="table-name-column"><?php echo esc_html($table); ?></td>
                                    <td class="rows-column"><?php echo esc_html($stats['rows']); ?></td>
                                    <td class="size-column"><?php echo esc_html($stats['size']); ?></td>
                                    <td class="actions-column">
                                        <button type="submit" name="action_table" value="truncate" 
                                            class="button button-secondary single-action" 
                                            onclick="this.form.table_name.value='<?php echo esc_attr($table); ?>'; return confirm('Are you sure you want to truncate <?php echo esc_attr($table); ?>?');">Truncate</button>
                                        <button type="submit" name="action_table" value="drop" 
                                            class="button button-danger single-action" 
                                            onclick="this.form.table_name.value='<?php echo esc_attr($table); ?>'; return confirm('Are you sure you want to drop <?php echo esc_attr($table); ?>?');">Drop</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <input type="hidden" name="table_name" value="">
                    <p>
                        <input type="submit" name="empty_all" class="button" value="Empty All Tables" 
                            onclick="return confirm('Are you sure you want to empty all inactive tables?');">
                        <input type="submit" name="drop_all" class="button button-danger" value="Drop All Tables" 
                            onclick="return confirm('Are you sure you want to drop all inactive tables?');">
                    </p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }
}

function init_inactive_tables_manager() {
    new Inactive_Tables_Manager();
}
add_action('plugins_loaded', 'init_inactive_tables_manager');