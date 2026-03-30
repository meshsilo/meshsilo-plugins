<?php
/**
 * OpenSCAD Render Action
 *
 * Renders a .scad file with parameter overrides and sends the output file as a download.
 */
require_once __DIR__ . '/../../../includes/config.php';

if (!isLoggedIn() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    exit;
}

if (!Csrf::check()) {
    http_response_code(403);
    echo 'Invalid CSRF token';
    exit;
}

$partId = (int)($params['partId'] ?? $_POST['part_id'] ?? 0);
if ($partId <= 0) {
    http_response_code(400);
    echo 'Invalid part ID';
    exit;
}

$db = getDB();
$stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
$stmt->bindValue(':id', $partId, PDO::PARAM_INT);
$result = $stmt->execute();
$part = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$part || strtolower($part['file_type'] ?? '') !== 'scad') {
    http_response_code(404);
    echo 'Not a .scad file';
    exit;
}

require_once __DIR__ . '/../../../includes/dedup.php';
$filePath = getAbsoluteFilePath($part);

if (!$filePath || !file_exists($filePath)) {
    http_response_code(404);
    echo 'File not found';
    exit;
}

// Parse parameters and build overrides
$parsed = OpenScadParser::parse($filePath);
$overrides = $_POST['params'] ?? [];
$overrideArgs = OpenScadParser::buildOverrides($parsed['parameters'], $overrides);

// Get export format
$format = in_array($_POST['format'] ?? '', ['stl', '3mf']) ? $_POST['format'] : 'stl';

// Set up output path
$outputDir = sys_get_temp_dir() . '/meshsilo_openscad';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}
$outputFile = $outputDir . '/' . $partId . '_' . time() . '.' . $format;

// Get plugin settings and render
$pm = PluginManager::getInstance();
$settings = $pm->getSettings('openscad');
$renderer = new OpenScadRenderer($settings);

if (!$renderer->isAvailable()) {
    http_response_code(503);
    echo 'OpenSCAD is not available on this server';
    exit;
}

$result = $renderer->render($filePath, $outputFile, $format, $overrideArgs);

if (!$result['success']) {
    http_response_code(500);
    echo 'Render failed: ' . htmlspecialchars($result['output']);
    exit;
}

// Log the render
logInfo('OpenSCAD render', [
    'part_id' => $partId,
    'format' => $format,
    'duration_ms' => $result['duration_ms'],
    'file_size' => $result['file_size'],
    'user' => getCurrentUser()['username'] ?? 'unknown',
]);

// Send file as download
$filename = pathinfo($part['name'] ?? 'model', PATHINFO_FILENAME) . '.' . $format;
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $result['file_size']);
header('Cache-Control: no-cache');
readfile($outputFile);

// Clean up
@unlink($outputFile);
exit;
