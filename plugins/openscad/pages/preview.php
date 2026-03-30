<?php
/**
 * OpenSCAD Preview Action
 *
 * Renders a PNG preview of a .scad file with parameter overrides.
 * Returns JSON with image URL and render time.
 */
require_once __DIR__ . '/../../../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$partId = (int)($params['partId'] ?? $_POST['part_id'] ?? 0);
if ($partId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid part ID']);
    exit;
}

$db = getDB();
$stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
$stmt->bindValue(':id', $partId, PDO::PARAM_INT);
$result = $stmt->execute();
$part = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$part || strtolower($part['file_type'] ?? '') !== 'scad') {
    echo json_encode(['success' => false, 'error' => 'Not a .scad file']);
    exit;
}

require_once __DIR__ . '/../../../includes/dedup.php';
$filePath = getAbsoluteFilePath($part);

if (!$filePath || !file_exists($filePath)) {
    echo json_encode(['success' => false, 'error' => 'File not found']);
    exit;
}

// Parse parameters and build overrides
$parsed = OpenScadParser::parse($filePath);
$overrides = $_POST['params'] ?? [];
$overrideArgs = OpenScadParser::buildOverrides($parsed['parameters'], $overrides);

// Set up preview output path in public cache
$previewDir = __DIR__ . '/../../../storage/cache/openscad-previews';
if (!is_dir($previewDir)) {
    mkdir($previewDir, 0755, true);
}
$previewFile = $previewDir . '/preview_' . $partId . '.png';

// Get plugin settings and render preview
$pm = PluginManager::getInstance();
$settings = $pm->getSettings('openscad');
$renderer = new OpenScadRenderer($settings);

if (!$renderer->isAvailable()) {
    echo json_encode(['success' => false, 'error' => 'OpenSCAD is not available']);
    exit;
}

$result = $renderer->renderPreview($filePath, $previewFile, 512, $overrideArgs);

if ($result['success']) {
    // Serve as base64 data URI to avoid routing issues
    $imageData = base64_encode(file_get_contents($previewFile));
    echo json_encode([
        'success' => true,
        'image_url' => 'data:image/png;base64,' . $imageData,
        'duration_ms' => $result['duration_ms'],
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => $result['output'] ?: 'Preview render failed',
        'duration_ms' => $result['duration_ms'],
    ]);
}

// Clean up preview file
@unlink($previewFile);
exit;
