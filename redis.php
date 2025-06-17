<?php
/**
 * Redis Manager Plugin for Hestia Control Panel
 * 
 * This plugin allows Hestia users to have their own personal Redis instance
 * with customizable configuration based on package settings.
 * 
 * @version 1.0
 */

if (!class_exists('RedisManager')) {
    class RedisManager extends HCPP_Hooks
    {
        /**
         * List of PHP functions needed for the Redis plugin to operate.
         * If any of these are disabled, plugin functionality may be limited.
         */
        private $required_functions = [
            'shell_exec',      // Used for executing system commands
            'posix_getpwuid',  // Used for user information
            'posix_getuid',    // Used for user ID
            'glob',            // Used for file listing
            'unlink'           // Used to delete files
        ];

        /**
         * Initialize the plugin by registering hooks, actions and custom pages
         */
        public function __construct()
        {
            global $hcpp;
            
            // User lifecycle hooks
            $hcpp->add_action('v_add_user', [$this, 'on_user_change']);
            $hcpp->add_action('v_change_user_package', [$this, 'on_user_change']);
            $hcpp->add_action('v_delete_user', [$this, 'on_user_delete']);
            
            // Plugin command handling
            $hcpp->add_action('hcpp_invoke_plugin', [$this, 'handle_invocations']);
            
            // Register custom pages
            $hcpp->add_custom_page('redis', __DIR__ . '/pages/redis.php');
            $hcpp->add_custom_page('redis-admin', __DIR__ . '/pages/redis-admin.php');
            
            // UI integration hooks
            $hcpp->add_action('hcpp_list_web_xpath', [$this, 'hcpp_list_web_xpath']);
            $hcpp->add_action('hcpp_list_user_xpath', [$this, 'hcpp_list_user_xpath']);
            
            // AJAX handler for status updates
            $hcpp->add_action('hcpp_ob_started', [$this, 'handle_ajax_requests'], 5);
        }

        /**
         * Check which required functions are disabled in PHP configuration
         * 
         * @return array List of disabled functions that the plugin needs
         */
        public function check_required_functions()
        {
            $disabled_string = ini_get('disable_functions');
            if (empty($disabled_string)) return [];
            
            $disabled_functions = array_map('trim', explode(',', $disabled_string));
            return array_intersect($this->required_functions, $disabled_functions);
        }

        /**
         * Handle AJAX requests for Redis status information
         * Called by the hcpp_ob_started action hook
         */
        public function handle_ajax_requests()
        {
            if (isset($_GET['p']) && $_GET['p'] === 'redis' && isset($_GET['api']) && $_GET['api'] === 'get_status') {
                require_once($_SERVER["DOCUMENT_ROOT"] . "/inc/main.php");
                header('Content-Type: application/json');
                
                // Authentication check
                if (empty($_SESSION['user'])) {
                    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
                    exit();
                }

                global $hcpp;

                // Get the current user or the user being impersonated
                $user = $this->_get_effective_user();
                
                // Get detailed Redis info for this user
                $info_json = $hcpp->run("v-invoke-plugin redis get_user_redis_info " . escapeshellarg($user));

                echo $info_json;
                exit();
            }
        }

        /**
         * Add Redis button to web interface
         * 
         * @param DOMXPath $xpath The XPath object for DOM manipulation
         * @return DOMXPath Modified XPath object
         */
        public function hcpp_list_web_xpath($xpath)
        {
            $addWebButton = $xpath->query("//a[@href='/add/web/']")->item(0);
            if ($addWebButton) {
                // Create new Redis button
                $newButton = $xpath->document->createElement('a');
                $newButton->setAttribute('href', '?p=redis');
                $newButton->setAttribute('class', 'button button-secondary');
                $newButton->setAttribute('title', 'Redis Management');
                
                // Add Redis icon and text
                $icon = $xpath->document->createElement('span', '&#11042;');
                $icon->setAttribute('style', 'font-size:x-large;color:red;margin:-2px 4px 0 0;');
                $text = $xpath->document->createTextNode('Redis');
                $newButton->appendChild($icon);
                $newButton->appendChild($text);
                
                // Insert the button
                $addWebButton->parentNode->insertBefore($newButton, $addWebButton->nextSibling);
            }
            return $xpath;
        }

        /**
         * Add Redis admin button to user management interface (admin only)
         * 
         * @param DOMXPath $xpath The XPath object for DOM manipulation
         * @return DOMXPath Modified XPath object
         */
        public function hcpp_list_user_xpath($xpath)
        {
            if (isset($_SESSION['userContext']) && $_SESSION['userContext'] === 'admin') {
                $addUserButton = $xpath->query("//a[@href='/add/user/']")->item(0);
                if ($addUserButton) {
                    // Create new Redis admin button
                    $newButton = $xpath->document->createElement('a');
                    $newButton->setAttribute('href', '?p=redis-admin');
                    $newButton->setAttribute('class', 'button button-secondary');
                    $newButton->setAttribute('title', 'Manage Redis Packages');
                    
                    // Add icon and text
                    $icon = $xpath->document->createElement('i');
                    $icon->setAttribute('class', 'fas fa-cog icon-red');
                    $text = $xpath->document->createTextNode(' Redis Packages');
                    $newButton->appendChild($icon);
                    $newButton->appendChild($text);
                    
                    // Insert the button
                    $addUserButton->parentNode->insertBefore($newButton, $addUserButton->nextSibling);
                }
            }
            return $xpath;
        }

        /**
         * Handle user creation or package change
         * Generate appropriate Redis configuration
         * 
         * @param array $args Arguments from the hook [username, package]
         * @return array Unmodified arguments
         */
        public function on_user_change($args)
        {
            $user = $args[0];
            $package = $args[1];
            $this->generate_redis_config($user, $package);
            return $args;
        }

        /**
         * Handle user deletion - clean up Redis service and files
         * 
         * @param array $args Arguments from the hook [username]
         * @return array Unmodified arguments
         */
        public function on_user_delete($args)
        {
            global $hcpp;
            $user = $args[0];
            $hcpp->log("Disabling and removing Redis for user: $user");
            
            // Stop and disable the service
            shell_exec("systemctl disable --now redis-user@{$user}.service");
            
            // Remove user's Redis directory
            shell_exec("rm -rf /home/{$user}/redis");
            
            return $args;
        }

        /**
         * Handle plugin command invocations from other parts of Hestia
         * 
         * @param array $args Arguments from the hook [plugin, action, ...]
         * @return array Unmodified arguments
         */
        public function handle_invocations($args)
        {
            // ===============================================================
            // REDIS USER SERVICE COMMANDS
            // ===============================================================
            if ($args[0] === 'redis') {
                $action = $args[1];
                $user = $args[2];
                
                switch ($action) {
                    case 'enable': 
                        // Start and enable Redis service for user
                        shell_exec("systemctl enable --now redis-user@{$user}.service"); 
                        break;
                        
                    case 'disable': 
                        // Stop and disable Redis service for user
                        shell_exec("systemctl disable --now redis-user@{$user}.service"); 
                        break;
                        
                    case 'restart': 
                        // Restart Redis service for user
                        shell_exec("systemctl restart redis-user@{$user}.service"); 
                        break;
                    
                    case 'provision_user':
                        // Create Redis configuration for existing user
                        $user_details_json = shell_exec("/usr/local/hestia/bin/v-list-user " . escapeshellarg($user) . " json");
                        $user_details = json_decode($user_details_json, true);
                        
                        if (isset($user_details[$user])) {
                            $package = $user_details[$user]['PACKAGE'];
                            $this->generate_redis_config($user, $package);
                        }
                        break;

                    case 'get_user_redis_info':
                        // Get Redis service status
                        $status = trim(shell_exec("systemctl is-active redis-user@{$user}.service"));
                        $socket = "/home/{$user}/redis/redis.sock";
                        
                        // Use redis-cli to get memory information
                        $command = "runuser -u " . escapeshellarg($user) . " -- /usr/bin/redis-cli -s " . 
                                   escapeshellarg($socket) . " INFO memory 2>/dev/null";
                        $info = shell_exec($command);

                        // Extract memory usage from INFO command output
                        preg_match('/used_memory_human:(.*)/', $info, $matches);
                        $memory = trim($matches[1] ?? 'N/A');
                        
                        echo json_encode(['status' => $status, 'memory' => $memory]);
                        break;
                }
            }
            
            // ===============================================================
            // REDIS ADMIN PACKAGE COMMANDS
            // ===============================================================
            if ($args[0] === 'redis-admin') {
                // Security: Ensure we're running as root for admin operations
                if (posix_getpwuid(posix_getuid())['name'] !== 'root') {
                    exit("Error: Permission denied.");
                }
                
                $action = $args[1];
                $packages_dir = __DIR__ . '/packages/';
                
                switch ($action) {
                    case 'get_packages':
                        // Retrieve all package configurations
                        $files = glob($packages_dir . '*.conf');
                        $packages = [];
                        
                        foreach ($files as $file) {
                            $name = basename($file, '.conf');
                            $packages[$name] = file_get_contents($file);
                        }
                        
                        echo json_encode($packages);
                        break;
                        
                    case 'save_package':
                        // Save a new or updated package configuration
                        $name = $args[2];
                        $content = $args[3];
                        
                        // Sanitize package name for security
                        $sane_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
                        if (empty($sane_name)) {
                            exit("Error: Invalid package name.");
                        }
                        
                        file_put_contents($packages_dir . $sane_name . '.conf', $content);

                        // After saving, find all users with this package and update their configs.
                        global $hcpp;
                        $all_users_json = shell_exec("/usr/local/hestia/bin/v-list-users json");
                        $all_users = json_decode($all_users_json, true);

                        foreach ($all_users as $username => $details) {
                            if (isset($details['PACKAGE']) && $details['PACKAGE'] === $sane_name) {
                                // This user matches the package we just saved.
                                // Regenerate their config to apply the new settings.
                                $hcpp->log("Updating Redis config for user {$username} due to package '{$sane_name}' update.");
                                $this->generate_redis_config($username, $sane_name);
                            }
                        }

                        break;
                        
                    case 'delete_package':
                        // Delete a package configuration (except default)
                        $name = $args[2];
                        if ($name === 'default') {
                            exit("Error: Cannot delete default package.");
                        }
                        
                        // Sanitize package name for security
                        $sane_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
                        $file_path = $packages_dir . $sane_name . '.conf';
                        
                        if (file_exists($file_path)) {
                            unlink($file_path);
                        }
                        break;
                }
            }
            
            return $args;
        }

        /**
         * Generate Redis configuration file for a user
         * 
         * Creates the user's Redis directory if needed and applies package-specific settings
         * 
         * @param string $user    Username
         * @param string $package Package name (maps to a .conf file in packages directory)
         */
        public function generate_redis_config($user, $package)
        {
            // Create user's Redis directory if it doesn't exist
            $user_redis_dir = "/home/{$user}/redis";
            if (!is_dir($user_redis_dir)) {
                mkdir($user_redis_dir, 0750, true);
                chown($user_redis_dir, $user);
                chgrp($user_redis_dir, $user);
            }
            
            // Load package-specific settings, fallback to default if not found
            $package_conf_path = __DIR__ . "/packages/{$package}.conf";
            if (!file_exists($package_conf_path)) {
                $package_conf_path = __DIR__ . "/packages/default.conf";
            }
            $package_settings = file_get_contents($package_conf_path);
            
            // Prepare paths for configuration
            $socket_path = "{$user_redis_dir}/redis.sock";
            $conf_path = "{$user_redis_dir}/redis.conf";
            
            // Create Redis configuration with socket connection (no TCP)
            // Note: We disable TCP ports for security and only use UNIX sockets
            $config_content = "
port 0
unixsocket {$socket_path}
unixsocketperm 770
pidfile {$user_redis_dir}/redis.pid
logfile {$user_redis_dir}/redis.log
dir {$user_redis_dir}
{$package_settings}
";
            // Write configuration file and set appropriate permissions
            file_put_contents($conf_path, trim($config_content));
            chown($conf_path, 'root');
            chgrp($conf_path, 'root');
            chmod($conf_path, 0644);
        }

        /**
         * Get the effective user (considering admin impersonation via 'look')
         * 
         * @return string Username of the effective user
         */
        private function _get_effective_user() {
            if (isset($_SESSION['look']) && !empty($_SESSION['look'])) {
                return $_SESSION['look'];
            }
            return $_SESSION['user'];
        }
    }
    
    // Register the plugin with Hestia
    global $hcpp;
    $hcpp->register_plugin(RedisManager::class);
}