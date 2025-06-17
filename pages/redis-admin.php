<?php
/**
 * Redis Package Administration Page
 * 
 * Allows administrative users to create, edit and delete Redis configuration packages
 * that can be assigned to users via their Hestia user packages.
 */

// Security: Admin access check
if (!isset($_SESSION['userContext']) || $_SESSION['userContext'] !== 'admin') {
    header("Location: /");
    exit();
}

// ===============================================================
// FORM SUBMISSION HANDLER
// ===============================================================

if (!empty($_POST['action'])) {
    switch ($_POST['action']) {
        case 'save':
            $name = $_POST['package_name'];
            $content = $_POST['package_content'];
            // Execute the plugin command to save a package configuration
            $hcpp->run("v-invoke-plugin redis-admin save_package " . escapeshellarg($name) . " " . escapeshellarg($content));
            break;
            
        case 'delete':
            $name = $_POST['package_name'];
            // Execute the plugin command to delete a package configuration
            $hcpp->run("v-invoke-plugin redis-admin delete_package " . escapeshellarg($name));
            break;
    }
    
    // Redirect to prevent form resubmission
    header("Location: ?p=redis-admin");
    exit();
}

// ===============================================================
// DATA PREPARATION
// ===============================================================

// Fetch existing packages from the backend
$packages_json = $hcpp->run("v-invoke-plugin redis-admin get_packages");
$packages = json_decode($packages_json, true);

// Setup edit mode if requested via GET parameter
$edit_mode = false;
$edit_package = [
    'name' => '', 
    'content' => "maxmemory 128mb\nmaxmemory-policy allkeys-lru"
];

if (!empty($_GET['edit'])) {
    $edit_name = $_GET['edit'];
    if (isset($packages[$edit_name])) {
        $edit_mode = true;
        $edit_package['name'] = $edit_name;
        $edit_package['content'] = $packages[$edit_name];
    }
}

// Check for disabled PHP functions that might affect plugin operation
$disabled_functions = $hcpp->redismanager->check_required_functions();
?>

<!-- Toolbar -->
<div class="toolbar">
	<div class="toolbar-inner">
		<div class="toolbar-buttons">
			<a class="button button-secondary button-back" href="/list/user/">
				<i class="fas fa-arrow-left icon-blue"></i>Back to Users
			</a>
		</div>
	</div>
</div>

<!-- Main Content -->
<div class="container">

    <?php
    // Display warning if required functions are disabled
    if (!empty($disabled_functions)):
    ?>
    <div class="alert alert-danger u-mb20">
        <i class="fas fa-exclamation-triangle"></i>
        <div style="margin-left: 10px; display: inline-block;">
            <strong>Configuration Warning:</strong> The Redis plugin requires the following PHP functions to be enabled in `php.ini` for full functionality:
            <ul>
                <?php foreach ($disabled_functions as $function): ?>
                    <li><code><?= htmlspecialchars($function) ?></code></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <!-- =============================================================== -->
    <!-- LIST EXISTING PACKAGES                                          -->
    <!-- =============================================================== -->
    <h1 class="u-mb10">Existing Redis Packages</h1>
    <div class="units-table js-units-container">
        <div class="units-table-header">
            <div class="units-table-cell">Package Name</div>
            <div class="units-table-cell">Configuration</div>
            <div class="units-table-cell"></div>
        </div>
        <?php foreach ($packages as $name => $content): ?>
        <div class="units-table-row js-unit">
            <div class="units-table-cell u-text-bold"><?= htmlspecialchars($name) ?></div>
            <div class="units-table-cell"><pre style="margin:0;"><?= htmlspecialchars($content) ?></pre></div>
            <div class="units-table-cell u-text-right">
                <a href="?p=redis-admin&edit=<?= urlencode($name) ?>" class="button button-secondary">Edit</a>
                <?php if ($name !== 'default'): // Prevent deletion of the default package ?>
                <form method="post" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to delete this package?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="package_name" value="<?= htmlspecialchars($name) ?>">
                    <button type="submit" class="button button-danger">Delete</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <hr class="u-mt30 u-mb30">

    <!-- =============================================================== -->
    <!-- ADD / EDIT PACKAGE FORM                                         -->
    <!-- =============================================================== -->
    <h1 class="u-mb10"><?= $edit_mode ? 'Edit' : 'Add New' ?> Redis Package</h1>
    <form method="post">
        <input type="hidden" name="action" value="save">
        <div class="u-mb10">
            <label for="package_name" class="form-label">Package Name</label>
            <input type="text" class="form-control" name="package_name" id="package_name" 
                   value="<?= htmlspecialchars($edit_package['name']) ?>" <?= $edit_mode ? 'readonly' : '' ?> required>
            <?php if ($edit_mode): ?>
            <p class="form-text">Package name cannot be changed.</p>
            <?php endif; ?>
        </div>
        <div class="u-mb20">
            <label for="package_content" class="form-label">Configuration</label>
            <textarea class="form-control" name="package_content" id="package_content" 
                      rows="5" style="font-family: monospace;"><?= htmlspecialchars($edit_package['content']) ?></textarea>
            <p class="form-text">Define Redis settings here, e.g., `maxmemory 128mb`.</p>
        </div>
        <button type="submit" class="button">Save Package</button>
        <?php if ($edit_mode): ?>
        <a href="?p=redis-admin" class="button button-secondary">Cancel Edit</a>
        <?php endif; ?>
    </form>
</div>