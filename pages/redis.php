<?php
// Handle POST actions from buttons
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    $user = $_SESSION['user'];
    $hcpp->run("v-invoke-plugin redis {$action} {$user}");
    // Redirect to prevent form resubmission
    header("Location: ?p=redis");
    exit();
}
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
    <div id="redis-status-card" class="u-mb20">
        <h1 class="u-mb10">Redis Management</h1>
        <p>Manage your personal Redis instance here. Your application can connect using the UNIX socket path provided below.</p>
        
        <div class="u-mt20">
            <p><strong>Status:</strong> <span id="status-text">Loading...</span></p>
            <p><strong>Socket Path:</strong> <code>/home/<?= $_SESSION['user'] ?>/redis/redis.sock</code></p>
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

function fetchStatus() {
    const statusEl = document.getElementById('status-text');
    const memoryEl = document.getElementById('memory-text');

    // Use Hestia's API invoker to get status from our plugin
    fetch('/api/v1/run/v-invoke-plugin', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            cmd: 'v-invoke-plugin',
            arg1: 'redis',
            arg2: 'get_status',
            arg3: '<?= $_SESSION['user'] ?>'
        })
    })
    .then(response => response.json())
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