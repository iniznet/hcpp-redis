<?php
if (!class_exists('RedisManager')) {
    class RedisManager extends HCPP_Hooks
    {
        /**
         * List of PHP functions required for this plugin to operate correctly.
         * @var array
         */
        private $required_functions = [
            'shell_exec',
            'posix_getpwuid',
            'posix_getuid',
            'glob',
            'unlink'
        ];

        public function __construct()
        {
            global $hcpp;
            $hcpp->add_action('v_add_user', [$this, 'on_user_change']);
            $hcpp->add_action('v_change_user_package', [$this, 'on_user_change']);
            $hcpp->add_action('v_delete_user', [$this, 'on_user_delete']);
            $hcpp->add_action('hcpp_invoke_plugin', [$this, 'handle_invocations']);
            $hcpp->add_custom_page('redis', __DIR__ . '/pages/redis.php');
            $hcpp->add_custom_page('redis-admin', __DIR__ . '/pages/redis-admin.php');
            $hcpp->add_action('hcpp_list_web_xpath', [$this, 'hcpp_list_web_xpath']);
            $hcpp->add_action('hcpp_list_user_xpath', [$this, 'hcpp_list_user_xpath']);
            $hcpp->add_action('hcpp_ob_started', [$this, 'handle_ajax_requests'], 5);
        }

        /**
         * Checks the php.ini 'disable_functions' directive for required functions.
         * @return array A list of required functions that are disabled. Empty if all are enabled.
         */
        public function check_required_functions()
        {
            $disabled_string = ini_get('disable_functions');
            if (empty($disabled_string)) {
                return [];
            }
            $disabled_functions = array_map('trim', explode(',', $disabled_string));
            return array_intersect($this->required_functions, $disabled_functions);
        }

        public function handle_ajax_requests()
        {
            if (isset($_GET['p']) && $_GET['p'] === 'redis' && isset($_GET['api']) && $_GET['api'] === 'get_status') {
                require_once( $_SERVER["DOCUMENT_ROOT"] . "/inc/main.php" );
                header('Content-Type: application/json');
                if (empty($_SESSION['user'])) {
                    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
                    exit();
                }
                $user = $this->_get_effective_user();
                $status = trim(shell_exec("systemctl is-active redis-user@{$user}.service"));
                $socket = "/home/{$user}/redis/redis.sock";
                $info = shell_exec("redis-cli -s {$socket} INFO memory 2>/dev/null");
                preg_match('/used_memory_human:(.*)/', $info, $matches);
                $memory = trim($matches[1] ?? 'N/A');
                echo json_encode(['status' => $status, 'memory' => $memory]);
                exit();
            }
        }

        public function hcpp_list_web_xpath($xpath)
        {
            $addWebButton = $xpath->query("//a[@href='/add/web/']")->item(0);
            if ($addWebButton) {
                $newButton = $xpath->document->createElement('a');
                $newButton->setAttribute('href', '?p=redis');
                $newButton->setAttribute('class', 'button button-secondary');
                $newButton->setAttribute('title', 'Redis Management');
                $icon = $xpath->document->createElement('span', '&#11042;');
                $icon->setAttribute('style', 'font-size:x-large;color:red;margin:-2px 4px 0 0;');
                $text = $xpath->document->createTextNode('Redis');
                $newButton->appendChild($icon);
                $newButton->appendChild($text);
                $addWebButton->parentNode->insertBefore($newButton, $addWebButton->nextSibling);
            }
            return $xpath;
        }

        public function hcpp_list_user_xpath($xpath)
        {
            if (isset($_SESSION['userContext']) && $_SESSION['userContext'] === 'admin') {
                $addUserButton = $xpath->query("//a[@href='/add/user/']")->item(0);
                if ($addUserButton) {
                    $newButton = $xpath->document->createElement('a');
                    $newButton->setAttribute('href', '?p=redis-admin');
                    $newButton->setAttribute('class', 'button button-secondary');
                    $newButton->setAttribute('title', 'Manage Redis Packages');
                    $icon = $xpath->document->createElement('i');
                    $icon->setAttribute('class', 'fas fa-cog icon-red');
                    $text = $xpath->document->createTextNode(' Redis Packages');
                    $newButton->appendChild($icon);
                    $newButton->appendChild($text);
                    $addUserButton->parentNode->insertBefore($newButton, $addUserButton->nextSibling);
                }
            }
            return $xpath;
        }

        public function on_user_change($args)
        {
            $user = $args[0];
            $package = $args[1];
            $this->generate_redis_config($user, $package);
            return $args;
        }

        public function on_user_delete($args)
        {
            global $hcpp;
            $user = $args[0];
            $hcpp->log("Disabling and removing Redis for user: $user");
            shell_exec("systemctl disable --now redis-user@{$user}.service");
            shell_exec("rm -rf /home/{$user}/redis");
            return $args;
        }

        public function handle_invocations($args)
        {
            if ($args[0] === 'redis') {
                $action = $args[1];
                $user = $args[2];
                switch ($action) {
                    case 'enable': shell_exec("systemctl enable --now redis-user@{$user}.service"); break;
                    case 'disable': shell_exec("systemctl disable --now redis-user@{$user}.service"); break;
                    case 'restart': shell_exec("systemctl restart redis-user@{$user}.service"); break;
                }
            }
            if ($args[0] === 'redis-admin') {
                if (posix_getpwuid(posix_getuid())['name'] !== 'root') exit("Error: Permission denied.");
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
                        $sane_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
                        if (empty($sane_name)) exit("Error: Invalid package name.");
                        file_put_contents($packages_dir . $sane_name . '.conf', $content);
                        break;
                    case 'delete_package':
                        $name = $args[2];
                        if ($name === 'default') exit("Error: Cannot delete default package.");
                        $sane_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
                        $file_path = $packages_dir . $sane_name . '.conf';
                        if (file_exists($file_path)) unlink($file_path);
                        break;
                }
            }
            return $args;
        }

        private function generate_redis_config($user, $package)
        {
            $user_redis_dir = "/home/{$user}/redis";
            if (!is_dir($user_redis_dir)) {
                mkdir($user_redis_dir, 0750, true);
                chown($user_redis_dir, $user);
                chgrp($user_redis_dir, $user);
            }
            $package_conf_path = __DIR__ . "/packages/{$package}.conf";
            if (!file_exists($package_conf_path)) $package_conf_path = __DIR__ . "/packages/default.conf";
            $package_settings = file_get_contents($package_conf_path);
            $socket_path = "{$user_redis_dir}/redis.sock";
            $conf_path = "{$user_redis_dir}/redis.conf";
            $config_content = "
port 0
unixsocket {$socket_path}
unixsocketperm 770
pidfile {$user_redis_dir}/redis.pid
logfile {$user_redis_dir}/redis.log
dir {$user_redis_dir}
{$package_settings}
";
            file_put_contents($conf_path, trim($config_content));
            chown($conf_path, 'root');
            chgrp($conf_path, 'root');
            chmod($conf_path, 0644);
        }

        private function _get_effective_user() {
            if (isset($_SESSION['look']) && !empty($_SESSION['look'])) return $_SESSION['look'];
            return $_SESSION['user'];
        }
    }
    global $hcpp;
    $hcpp->register_plugin(RedisManager::class);
}