# HestiaCP Per-User Redis Plugin (hcpp-redis)

This is a plugin for the [Hestia Control Panel](https://hestiacp.com) that provides isolated, per-user Redis instances. It is built upon the powerful `hestiacp-pluginable` framework.

Each user's Redis instance runs as their own system user, communicates securely over a UNIX socket (no network ports are opened), and has its configuration and memory limits determined by their assigned Hestia package.

> [!WARNING]
> ## EXPERIMENTAL SOFTWARE
> This plugin is a proof-of-concept and is **NOT RECOMMENDED FOR PRODUCTION ENVIRONMENTS.** It has not been widely tested and could contain bugs that might affect your server's stability or security.
>
> **Always back up your system before installing.** Use this plugin at your own risk, preferably on a non-critical development server.

---

## Features

-   **Per-User Isolation**: Each Hestia user gets their own dedicated Redis process, running under their own user account.
-   **Secure by Default**: Uses UNIX sockets for communication, meaning no TCP ports are exposed to the network.
-   **Package-Based Limits**: Easily define different Redis memory limits (`maxmemory`) for different Hestia user packages (e.g., `default`, `premium`).
-   **Systemd Management**: Uses `systemd` template units (`redis-user@.service`) for robust and reliable process management.
-   **Simple User Interface**: A custom page is added to the Hestia UI for users to enable, disable, restart, and check the status of their Redis instance.
-   **Secure Configuration**: The Redis configuration file is owned by `root` to prevent users from overriding their assigned memory limits via SSH.

## How It Works

The plugin creates a `systemd` service template that can start a Redis instance for any user (`redis-user@<username>.service`). When a user is created or their package is changed, the plugin:

1.  Creates a `~/redis/` directory in the user's home folder.
2.  Generates a `redis.conf` file inside that directory based on a template corresponding to the user's Hestia package.
3.  **Crucially, it sets the ownership of `redis.conf` to `root:root` with `644` permissions.** This allows the user's Redis process to read the configuration but prevents the user from editing it.
4.  The user's Redis process runs and stores its data and socket file within the `~/redis/` directory, which it owns.

## Requirements

-   Hestia Control Panel v1.9.X or greater.
-   **[hestiacp-pluginable](https://github.com/virtuosoft-dev/hestiacp-pluginable)** must be installed first.
-   Ubuntu or Debian Linux OS.
-   `root` or `sudo` access to the server.

## Installation

**Please back up your server before proceeding!**

1.  SSH into your HestiaCP server.
2.  Navigate to the Hestia plugins directory:
    ```bash
    cd /usr/local/hestia/plugins
    ```
3.  Clone this repository.
    ```bash
    sudo git clone https://github.com/iniznet/hcpp-redis.git redis
    chmod +x /usr/local/hestia/plugins/redis/install
    chmod +x /usr/local/hestia/plugins/redis/uninstall
    ```
4.  The plugin's installation script will be triggered automatically the next time an admin logs into the Hestia UI or saves the server configuration. To run it manually:
    ```bash
    sudo /usr/local/hestia/plugins/redis/install
    ```

## Configuration: Setting Memory Limits

This plugin's key feature is configuring Redis based on Hestia packages.

1.  Plugin configuration templates are located in `/usr/local/hestia/plugins/redis/packages/`.
2.  The plugin includes a `default.conf` which is used if a package-specific file doesn't exist.
3.  To create a configuration for a Hestia package named `premium`, create a new file:
    ```bash
    sudo nano /usr/local/hestia/plugins/redis/packages/premium.conf
    ```
4.  Add your Redis settings to this file. The most important is `maxmemory`.

    **Example for `premium.conf`:**
    ```ini
    # Settings for the 'premium' package
    maxmemory 256mb
    maxmemory-policy allkeys-lru
    ```

Now, any user assigned the `premium` package will get a Redis instance with a 256MB memory limit.

## Usage

### For the User

-   Log in to the Hestia Control Panel.
-   A "Redis" item will be available in the main user menu (or you can navigate to `/?p=redis`).
-   On this page, the user can see the status, memory usage, and socket path, and can Enable, Disable, or Restart their instance.

### For the Application Developer

-   Your application (e.g., PHP, Node.js, Python) should connect to Redis using the UNIX socket path.
-   **Socket Path**: `/home/<username>/redis/redis.sock`

## Uninstallation

To remove the plugin:

1.  Run the uninstallation script (this will stop all user Redis instances and clean up systemd files):
    ```bash
    sudo /usr/local/hestia/plugins/redis/uninstall
    ```
2.  Remove the plugin directory:
    ```bash
    sudo rm -rf /usr/local/hestia/plugins/redis
    ```