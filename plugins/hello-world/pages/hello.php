<?php
/**
 * Hello World Plugin - Public Page
 */
$pageTitle = 'Hello World';
$activePage = '';

require_once __DIR__ . '/../../../../includes/header.php';
?>

<div class="container" style="max-width: 800px; margin: 2rem auto; padding: 0 1rem;">
    <div class="hello-world-banner">
        <h2>Hello World!</h2>
        <p>The MeshSilo plugin system is working correctly.</p>
    </div>

    <div class="hello-world-info">
        <div class="hello-world-card">
            <h3>Plugin System</h3>
            <p>This page is served by the Hello World plugin, demonstrating route registration.</p>
        </div>
        <div class="hello-world-card">
            <h3>Version</h3>
            <p>Plugin v1.0.0</p>
        </div>
        <div class="hello-world-card">
            <h3>Assets</h3>
            <p>The CSS for this page is loaded from the plugin's assets directory via the asset proxy.</p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../../../includes/footer.php'; ?>
