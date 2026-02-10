<?php
/**
 * Printer Profile Actions
 * - CRUD for printer profiles
 * - Check if model fits printer
 */

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/features.php';

header('Content-Type: application/json');

// Check if printers feature is enabled
if (!isFeatureEnabled('printers')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Printers feature is disabled']);
    exit;
}

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user = getCurrentUser();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'create':
        createPrinter();
        break;
    case 'update':
        updatePrinter();
        break;
    case 'delete':
        deletePrinter();
        break;
    case 'list':
        listPrinters();
        break;
    case 'get':
        getPrinter();
        break;
    case 'set_default':
        setDefaultPrinter();
        break;
    case 'check_fit':
        checkModelFit();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function createPrinter() {
    global $user;

    $name = trim($_POST['name'] ?? '');
    $manufacturer = trim($_POST['manufacturer'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $bedX = floatval($_POST['bed_x'] ?? 0);
    $bedY = floatval($_POST['bed_y'] ?? 0);
    $bedZ = floatval($_POST['bed_z'] ?? 0);
    $printType = $_POST['print_type'] ?? 'fdm';
    $notes = trim($_POST['notes'] ?? '');

    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Printer name required']);
        return;
    }

    $db = getDB();
    $stmt = $db->prepare('
        INSERT INTO printers (user_id, name, manufacturer, model, bed_x, bed_y, bed_z, print_type, notes)
        VALUES (:user_id, :name, :manufacturer, :model, :bed_x, :bed_y, :bed_z, :print_type, :notes)
    ');
    $stmt->execute([
        ':user_id' => $user['id'],
        ':name' => $name,
        ':manufacturer' => $manufacturer,
        ':model' => $model,
        ':bed_x' => $bedX ?: null,
        ':bed_y' => $bedY ?: null,
        ':bed_z' => $bedZ ?: null,
        ':print_type' => $printType,
        ':notes' => $notes
    ]);

    $printerId = $db->lastInsertId();

    echo json_encode([
        'success' => true,
        'printer_id' => $printerId
    ]);
}

function updatePrinter() {
    global $user;

    $printerId = (int)($_POST['printer_id'] ?? 0);
    if (!$printerId) {
        echo json_encode(['success' => false, 'error' => 'Printer ID required']);
        return;
    }

    $db = getDB();

    // Verify ownership
    $stmt = $db->prepare('SELECT user_id FROM printers WHERE id = :id');
    $stmt->execute([':id' => $printerId]);
    $printer = $stmt->fetch();

    if (!$printer || ($printer['user_id'] !== $user['id'] && !$user['is_admin'])) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        return;
    }

    $updates = [];
    $params = [':id' => $printerId];

    $fields = ['name', 'manufacturer', 'model', 'bed_x', 'bed_y', 'bed_z', 'print_type', 'notes'];
    foreach ($fields as $field) {
        if (isset($_POST[$field])) {
            $updates[] = "$field = :$field";
            $params[":$field"] = $_POST[$field];
        }
    }

    if (empty($updates)) {
        echo json_encode(['success' => false, 'error' => 'No fields to update']);
        return;
    }

    $sql = 'UPDATE printers SET ' . implode(', ', $updates) . ' WHERE id = :id';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['success' => true]);
}

function deletePrinter() {
    global $user;

    $printerId = (int)($_POST['printer_id'] ?? 0);
    if (!$printerId) {
        echo json_encode(['success' => false, 'error' => 'Printer ID required']);
        return;
    }

    $db = getDB();

    // Verify ownership
    $stmt = $db->prepare('SELECT user_id FROM printers WHERE id = :id');
    $stmt->execute([':id' => $printerId]);
    $printer = $stmt->fetch();

    if (!$printer || ($printer['user_id'] !== $user['id'] && !$user['is_admin'])) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        return;
    }

    $stmt = $db->prepare('DELETE FROM printers WHERE id = :id');
    $stmt->execute([':id' => $printerId]);

    echo json_encode(['success' => true]);
}

function listPrinters() {
    global $user;

    $db = getDB();
    $stmt = $db->prepare('
        SELECT * FROM printers
        WHERE user_id = :user_id OR user_id IS NULL
        ORDER BY is_default DESC, name ASC
    ');
    $stmt->execute([':user_id' => $user['id']]);

    $printers = [];
    while ($row = $stmt->fetch()) {
        $printers[] = $row;
    }

    echo json_encode(['success' => true, 'printers' => $printers]);
}

function getPrinter() {
    global $user;

    $printerId = (int)($_GET['printer_id'] ?? 0);
    if (!$printerId) {
        echo json_encode(['success' => false, 'error' => 'Printer ID required']);
        return;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM printers WHERE id = :id AND (user_id = :user_id OR user_id IS NULL)');
    $stmt->execute([':id' => $printerId, ':user_id' => $user['id']]);
    $printer = $stmt->fetch();

    if (!$printer) {
        echo json_encode(['success' => false, 'error' => 'Printer not found']);
        return;
    }

    echo json_encode(['success' => true, 'printer' => $printer]);
}

function setDefaultPrinter() {
    global $user;

    $printerId = (int)($_POST['printer_id'] ?? 0);
    if (!$printerId) {
        echo json_encode(['success' => false, 'error' => 'Printer ID required']);
        return;
    }

    $db = getDB();

    // Clear existing default
    $stmt = $db->prepare('UPDATE printers SET is_default = 0 WHERE user_id = :user_id');
    $stmt->execute([':user_id' => $user['id']]);

    // Set new default
    $stmt = $db->prepare('UPDATE printers SET is_default = 1 WHERE id = :id AND user_id = :user_id');
    $stmt->execute([':id' => $printerId, ':user_id' => $user['id']]);

    echo json_encode(['success' => true]);
}

function checkModelFit() {
    $modelId = (int)($_GET['model_id'] ?? 0);
    $printerId = (int)($_GET['printer_id'] ?? 0);

    if (!$modelId) {
        echo json_encode(['success' => false, 'error' => 'Model ID required']);
        return;
    }

    $db = getDB();

    // Get model dimensions
    $stmt = $db->prepare('SELECT dim_x, dim_y, dim_z, dim_unit FROM models WHERE id = :id');
    $stmt->execute([':id' => $modelId]);
    $model = $stmt->fetch();

    if (!$model || !$model['dim_x']) {
        echo json_encode(['success' => false, 'error' => 'Model dimensions not available']);
        return;
    }

    // Get printer
    if ($printerId) {
        $stmt = $db->prepare('SELECT * FROM printers WHERE id = :id');
        $stmt->execute([':id' => $printerId]);
    } else {
        // Get default printer
        global $user;
        $stmt = $db->prepare('SELECT * FROM printers WHERE user_id = :user_id AND is_default = 1');
        $stmt->execute([':user_id' => $user['id']]);
    }
    $printer = $stmt->fetch();

    if (!$printer || !$printer['bed_x']) {
        echo json_encode(['success' => false, 'error' => 'Printer bed dimensions not available']);
        return;
    }

    // Convert to same unit (mm)
    $modelDims = [
        'x' => (float)$model['dim_x'],
        'y' => (float)$model['dim_y'],
        'z' => (float)$model['dim_z']
    ];

    // If model is in inches, convert to mm
    if ($model['dim_unit'] === 'in') {
        $modelDims['x'] *= 25.4;
        $modelDims['y'] *= 25.4;
        $modelDims['z'] *= 25.4;
    }

    $fits = true;
    $margins = [];

    // Check each dimension
    if ($modelDims['x'] > $printer['bed_x']) {
        $fits = false;
        $margins['x'] = $modelDims['x'] - $printer['bed_x'];
    } else {
        $margins['x'] = $printer['bed_x'] - $modelDims['x'];
    }

    if ($modelDims['y'] > $printer['bed_y']) {
        $fits = false;
        $margins['y'] = $modelDims['y'] - $printer['bed_y'];
    } else {
        $margins['y'] = $printer['bed_y'] - $modelDims['y'];
    }

    if ($modelDims['z'] > $printer['bed_z']) {
        $fits = false;
        $margins['z'] = $modelDims['z'] - $printer['bed_z'];
    } else {
        $margins['z'] = $printer['bed_z'] - $modelDims['z'];
    }

    echo json_encode([
        'success' => true,
        'fits' => $fits,
        'model_dimensions' => $modelDims,
        'printer_dimensions' => [
            'x' => (float)$printer['bed_x'],
            'y' => (float)$printer['bed_y'],
            'z' => (float)$printer['bed_z']
        ],
        'margins' => $margins,
        'printer_name' => $printer['name']
    ]);
}
