<?php
/**
 * Hello World Plugin - Admin Settings Page
 *
 * This file is loaded by PluginManager when visiting /admin/plugin/hello-world
 * Variables available: $adminPage (string), $pluginManager (PluginManager)
 */

$pageTitle = 'Hello World Settings';

require_once __DIR__ . '/../../../../includes/header.php';
?>

<div class="admin-layout">
<?php require_once __DIR__ . '/../../../../includes/admin-sidebar.php'; ?>

    <div class="admin-content">
        <div class="page-header">
            <h1>Hello World Plugin</h1>
            <p>This is the admin settings page for the Hello World test plugin.</p>
        </div>

        <div class="hello-world-banner">
            <h2>Plugin System Working</h2>
            <p>If you can see this page, plugin admin pages are functioning correctly.</p>
        </div>

        <div class="hello-world-info">
            <div class="hello-world-card">
                <h3>Plugin Routes</h3>
                <p>Visit <a href="/hello-world">/hello-world</a> to see the public page.</p>
            </div>
            <div class="hello-world-card">
                <h3>Admin Pages</h3>
                <p>This page is registered via <code>addAdminPage()</code> and served at <code>/admin/plugin/hello-world</code>.</p>
            </div>
            <div class="hello-world-card">
                <h3>CSS Assets</h3>
                <p>The styles on this page come from <code>/plugin-assets/hello-world/hello.css</code>.</p>
            </div>
            <div class="hello-world-card">
                <h3>Sidebar Menu</h3>
                <p>The "Hello World" link in the sidebar was added via <code>addAdminMenuItem()</code>.</p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../../../includes/footer.php'; ?>
