<?php
// SECURITY: This page is for the admin user only.
if ($_SESSION['user'] !== 'admin') {
    header("Location: /");
    exit();
}

// Handle form submissions for saving or deleting packages
if (!empty($_POST['action'])) {
    if ($_POST['action'] === 'save') {
        $name = $_POST['package_name'];
        $content = $_POST['package_content'];
        // Use escapeshellarg for security, even though we call run()
        $hcpp->run("v-invoke-plugin redis-admin save_package " . escapeshellarg($name) . " " . escapeshellarg($content));
    }
    if ($_POST['action'] === 'delete') {
        $name = $_POST['package_name'];
        $hcpp->run("v-invoke-plugin redis-admin delete_package " . escapeshellarg($name));
    }
    // Redirect to prevent form resubmission on refresh
    header("Location: ?p=redis-admin");
    exit();
}

// Fetch all existing packages to display
$packages_json = $hcpp->run("v-invoke-plugin redis-admin get_packages");
$packages = json_decode($packages_json, true);

// Check if we are editing a package
$edit_mode = false;
$edit_package = ['name' => '', 'content' => 'maxmemory 128mb' . "\n" . 'maxmemory-policy allkeys-lru'];
if (!empty($_GET['edit'])) {
    $edit_name = $_GET['edit'];
    if (isset($packages[$edit_name])) {
        $edit_mode = true;
        $edit_package['name'] = $edit_name;
        $edit_package['content'] = $packages[$edit_name];
    }
}
?>

<!-- Toolbar -->
<div class="toolbar">
    <div class="toolbar-inner">
        <div class="toolbar-buttons">
            <a class="button button-secondary button-back" href="/?p=redis">
                <i class="fas fa-arrow-left icon-blue"></i>Back to User View
            </a>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="container">

    <!-- List Existing Packages -->
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
                <?php if ($name !== 'default'): ?>
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

    <!-- Add / Edit Form -->
    <h1 class="u-mb10"><?= $edit_mode ? 'Edit' : 'Add New' ?> Redis Package</h1>
    <form method="post">
        <input type="hidden" name="action" value="save">
        <div class="u-mb10">
            <label for="package_name" class="form-label">Package Name</label>
            <input type="text" class="form-control" name="package_name" id="package_name" value="<?= htmlspecialchars($edit_package['name']) ?>" <?= $edit_mode ? 'readonly' : '' ?> required>
            <?php if ($edit_mode): ?><p class="form-text">Package name cannot be changed.</p><?php endif; ?>
        </div>
        <div class="u-mb20">
            <label for="package_content" class="form-label">Configuration</label>
            <textarea class="form-control" name="package_content" id="package_content" rows="5" style="font-family: monospace;"><?= htmlspecialchars($edit_package['content']) ?></textarea>
            <p class="form-text">Define Redis settings here, e.g., `maxmemory 128mb`.</p>
        </div>
        <button type="submit" class="button">Save Package</button>
        <?php if ($edit_mode): ?><a href="?p=redis-admin" class="button button-secondary">Cancel Edit</a><?php endif; ?>
    </form>
</div>