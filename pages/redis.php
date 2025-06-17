<?php
// Determine the effective user (accounting for admin "look" sessions)
$effective_user = (isset($_SESSION['look']) && !empty($_SESSION['look']))
    ? $_SESSION['look']
    : $_SESSION['user'];

// Just-in-time provisioning - create Redis directory if it doesn't exist
$user_redis_dir = "/home/{$effective_user}/redis";
if (!is_dir($user_redis_dir)) {
    $hcpp->run("v-invoke-plugin redis provision_user " . escapeshellarg($effective_user));
}

// Handle Redis service actions (enable/disable/restart)
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    $hcpp->run("v-invoke-plugin redis {$action} " . escapeshellarg($effective_user));
    header("Location: ?p=redis");
    exit();
}

// Check for required PHP functions
$disabled_functions = $hcpp->redismanager->check_required_functions();
?>

<!-- Toolbar -->
<div class="toolbar">
	<div class="toolbar-inner">
		<div class="toolbar-buttons">
			<a class="button button-secondary" href="javascript:location.reload();">
				<i class="fas fa-rotate-right icon-green"></i>Refresh
			</a>
		</div>
	</div>
</div>

<!-- Main Content -->
<div class="container">

    <?php if (!empty($disabled_functions)): // Show warning if required functions are disabled ?>
    <div class="alert alert-danger u-mb20">
        <i class="fas fa-exclamation-triangle"></i>
        <div style="margin-left: 10px; display: inline-block;">
            <strong>Warning:</strong> The Redis plugin may not function correctly. The following required PHP functions are disabled in your server's configuration. Please contact your server administrator.
            <ul>
                <?php foreach ($disabled_functions as $function): ?>
                    <li><code><?= htmlspecialchars($function) ?></code></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['userContext']) && $_SESSION['userContext'] === 'admin'): // Admin-only info ?>
    <div class="alert alert-info u-mb20">
        <i class="fas fa-user-shield"></i>
        <div style="margin-left: 10px; display: inline-block;">
            You are logged in as admin. <a href="?p=redis-admin">Click here to manage Redis packages.</a>
        </div>
    </div>
    <?php endif; ?>

    <div id="redis-status-card" class="u-mb20">
        <h1 class="u-mb10">Redis Management for <?= htmlspecialchars($effective_user) ?></h1>
        <p>Manage your personal Redis instance here. Your application can connect using the UNIX socket path provided below.</p>
        <div class="u-mt20">
            <p><strong>Status:</strong> <span id="status-text">Loading...</span></p>
            <p><strong>Socket Path:</strong> <code>/home/<?= htmlspecialchars($effective_user) ?>/redis/redis.sock</code></p>
            <p><strong>Memory Usage:</strong> <span id="memory-text">Loading...</span></p>
        </div>
        <div class="u-mt20">
            <form method="post" style="display: inline-block;">
                <button type="submit" name="action" value="enable" class="button">Enable / Start</button>
            </form>
            <form method="post" style="display: inline-block;">
                <button type="submit" name="action" value="restart" class="button button-secondary">Restart</button>
            </form>
            <form method="post" style="display: inline-block;">
                <button type="submit" name="action" value="disable" class="button button-danger">Disable / Stop</button>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    fetchStatus();
});

// Fetch Redis service status via AJAX
function fetchStatus() {
    const statusEl = document.getElementById('status-text');
    const memoryEl = document.getElementById('memory-text');
    
    fetch('?p=redis&api=get_status')
    .then(response => {
        if (!response.ok) throw new Error('Network response was not ok');
        return response.json();
    })
    .then(data => {
        if (data.status === 'active') {
            statusEl.innerHTML = '<span style="color: green;">● Active</span>';
        } else {
            statusEl.innerHTML = '<span style="color: red;">● Inactive</span>';
        }
        memoryEl.textContent = data.memory;
    })
    .catch(error => {
        statusEl.textContent = 'Error fetching status.';
        console.error('Error:', error);
    });
}
</script>