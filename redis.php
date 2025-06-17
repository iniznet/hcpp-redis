<?php
if (!class_exists('RedisManager')) {
    class RedisManager extends HCPP_Hooks
    {
public function __construct()
        {
            global $hcpp;
            // Hook into user management events
            $hcpp->add_action('v_add_user', [$this, 'on_user_change']);
            $hcpp->add_action('v_change_user_package', [$this, 'on_user_change']);
            $hcpp->add_action('v_delete_user', [$this, 'on_user_delete']);

            // Hook for UI actions
            $hcpp->add_action('hcpp_list_web_xpath', [$this, 'hcpp_list_web_xpath']);
            $hcpp->add_action('hcpp_invoke_plugin', [$this, 'handle_invocations']);

            // Add custom pages to the UI
            $hcpp->add_custom_page('redis', __DIR__ . '/pages/redis.php');
            $hcpp->add_custom_page('redis-admin', __DIR__ . '/pages/redis-admin.php');
        }

        /**
         * Handles user creation and package changes.
         */
        public function on_user_change($args)
        {
            $user = $args[0];
            $package = $args[1];
            $this->generate_redis_config($user, $package);
            return $args;
        }

        /**
         * Handles user deletion.
         */
        public function on_user_delete($args)
        {
            global $hcpp;
            $user = $args[0];
            $hcpp->log("Disabling and removing Redis for user: $user");

            // Stop and disable the service
            shell_exec("systemctl disable --now redis-user@{$user}.service");

            // Clean up files
            shell_exec("rm -rf /home/{$user}/redis");
            return $args;
        }

        /**
         * Generates the redis.conf file for a user based on their package.
         * This version includes security hardening for file permissions.
         */
        private function generate_redis_config($user, $package)
        {
            global $hcpp;
            $hcpp->log("Generating Redis config for user: $user, package: $package");

            $user_redis_dir = "/home/{$user}/redis";
            if (!is_dir($user_redis_dir)) {
                mkdir($user_redis_dir, 0750, true);
                // The user MUST own their data directory
                chown($user_redis_dir, $user);
                chgrp($user_redis_dir, $user);
            }

            $package_conf_path = __DIR__ . "/packages/{$package}.conf";
            if (!file_exists($package_conf_path)) {
                $package_conf_path = __DIR__ . "/packages/default.conf";
            }
            $package_settings = file_get_contents($package_conf_path);

            $socket_path = "{$user_redis_dir}/redis.sock";
            $conf_path = "{$user_redis_dir}/redis.conf";

            $config_content = "
# Managed by hcpp-redis. DO NOT EDIT.
port 0
unixsocket {$socket_path}
unixsocketperm 770
pidfile {$user_redis_dir}/redis.pid
logfile {$user_redis_dir}/redis.log
dir {$user_redis_dir}
{$package_settings}
";
            // Write the file first
            file_put_contents($conf_path, trim($config_content));

            // Change ownership to root, so the user cannot edit it.
            chown($conf_path, 'root');
            chgrp($conf_path, 'root');

            // Set permissions to 644 (rw-r--r--)
            // - root can read/write.
            // - The user's Redis process (running as 'other') only can read.
            chmod($conf_path, 0644);

            $hcpp->log("Secured redis.conf for {$user} with root ownership.");
        }

        /**
         * Adds a "Redis" button to the web domain management page.
         * This will appear next to the "Add Web Domain" button.
         */
        public function hcpp_list_web_xpath($xpath)
        {
            // Find the "Add Web Domain" button to use as an anchor for our new button.
            $addWebButton = $xpath->query("//a[@href='/add/web/']")->item(0);

            if ($addWebButton) {
                // Create the new "Redis" button element
                $newButton = $xpath->document->createElement('a');
                $newButton->setAttribute('href', '?p=redis');
                $newButton->setAttribute('class', 'button button-secondary');
                $newButton->setAttribute('title', 'Redis Management');

                // Create the icon element (a red circle for Redis)
                $icon = $xpath->document->createElement('span', '&#11042;');
                $icon->setAttribute('style', 'font-size:x-large;color:red;margin:-2px 4px 0 0;');

                // Create the text for the button
                $text = $xpath->document->createTextNode('Redis');

                // Append the icon and text to the new button
                $newButton->appendChild($icon);
                $newButton->appendChild($text);

                // Insert the new button into the DOM, right after the "Add Web Domain" button.
                // We use nextSibling to place it neatly in the button group.
                $addWebButton->parentNode->insertBefore($newButton, $addWebButton->nextSibling);
            }

            return $xpath;
        }

        /**
         * Handles backend calls from the UI.
         */
        public function handle_invocations($args)
        {
            // Route user-facing actions
            if ($args[0] === 'redis') {
                $action = $args[1];
                $user = $args[2]; // The user performing the action

                switch ($action) {
                    case 'enable':
                        shell_exec("systemctl enable --now redis-user@{$user}.service");
                        break;
                    case 'disable':
                        shell_exec("systemctl disable --now redis-user@{$user}.service");
                        break;
                    case 'restart':
                        shell_exec("systemctl restart redis-user@{$user}.service");
                        break;
                    case 'get_status':
                        $status = trim(shell_exec("systemctl is-active redis-user@{$user}.service"));
                        $socket = "/home/{$user}/redis/redis.sock";
                        $info = shell_exec("redis-cli -s {$socket} INFO memory 2>/dev/null");
                        
                        preg_match('/used_memory_human:(.*)/', $info, $matches);
                        $memory = trim($matches[1] ?? 'N/A');

                        echo json_encode(['status' => $status, 'memory' => $memory]);
                        break;
                }
            }

            // *** NEW: Route admin-facing actions ***
            if ($args[0] === 'redis-admin') {
                // SECURITY: Ensure only the admin user can perform these actions
                if (posix_getpwuid(posix_getuid())['name'] !== 'root') {
                    echo "Error: Permission denied.";
                    exit(1);
                }

                $action = $args[1];
                $packages_dir = __DIR__ . '/packages/';

                switch ($action) {
                    case 'get_packages':
                        $files = glob($packages_dir . '*.conf');
                        $packages = [];
                        foreach ($files as $file) {
                            $name = basename($file, '.conf');
                            $packages[$name] = file_get_contents($file);
                        }
                        echo json_encode($packages);
                        break;

                    case 'save_package':
                        $name = $args[2];
                        $content = $args[3];
                        // Sanitize name to prevent directory traversal
                        $sane_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
                        if (empty($sane_name)) {
                            echo "Error: Invalid package name.";
                            exit(1);
                        }
                        file_put_contents($packages_dir . $sane_name . '.conf', $content);
                        echo "Package '{$sane_name}' saved.";
                        break;

                    case 'delete_package':
                        $name = $args[2];
                        // SECURITY: Never allow deleting the default package
                        if ($name === 'default') {
                            echo "Error: Cannot delete the default package.";
                            exit(1);
                        }
                        $sane_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
                        $file_path = $packages_dir . $sane_name . '.conf';
                        if (file_exists($file_path)) {
                            unlink($file_path);
                            echo "Package '{$sane_name}' deleted.";
                        }
                        break;
                }
            }
            return $args;
        }
    }

    global $hcpp;
    $hcpp->register_plugin(RedisManager::class);
}