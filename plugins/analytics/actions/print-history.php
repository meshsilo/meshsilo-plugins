<?php
/**
 * Print History Actions
 * - Record prints
 * - View print history
 */

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/features.php';

header('Content-Type: application/json');

// Check if print history feature is enabled
if (!isFeatureEnabled('print_history')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Print history feature is disabled']);
    exit;
}

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user = getCurrentUser();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'record':
        recordPrint();
        break;
    case 'update':
        updatePrintRecord();
        break;
    case 'delete':
        deletePrintRecord();
        break;
    case 'list':
        listPrintHistory();
        break;
    case 'stats':
        getPrintStats();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function recordPrint() {
    global $user;

    $modelId = (int)($_POST['model_id'] ?? 0);
    $printerId = (int)($_POST['printer_id'] ?? 0) ?: null;
    $printDate = $_POST['print_date'] ?? date('Y-m-d H:i:s');
    $durationMinutes = (int)($_POST['duration_minutes'] ?? 0) ?: null;
    $filamentUsedG = floatval($_POST['filament_used_g'] ?? 0) ?: null;
    $filamentType = trim($_POST['filament_type'] ?? '');
    $filamentColor = trim($_POST['filament_color'] ?? '');
    $success = isset($_POST['success']) ? (int)$_POST['success'] : 1;
    $qualityRating = (int)($_POST['quality_rating'] ?? 0) ?: null;
    $notes = trim($_POST['notes'] ?? '');
    $settings = $_POST['settings'] ?? null;

    if (!$modelId) {
        echo json_encode(['success' => false, 'error' => 'Model ID required']);
        return;
    }

    $db = getDB();

    // Validate model exists
    $stmt = $db->prepare('SELECT id FROM models WHERE id = :id');
    $stmt->execute([':id' => $modelId]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Model not found']);
        return;
    }

    $stmt = $db->prepare('
        INSERT INTO print_history (model_id, user_id, printer_id, print_date, duration_minutes,
                                   filament_used_g, filament_type, filament_color, success,
                                   quality_rating, notes, settings)
        VALUES (:model_id, :user_id, :printer_id, :print_date, :duration_minutes,
                :filament_used_g, :filament_type, :filament_color, :success,
                :quality_rating, :notes, :settings)
    ');
    $stmt->execute([
        ':model_id' => $modelId,
        ':user_id' => $user['id'],
        ':printer_id' => $printerId,
        ':print_date' => $printDate,
        ':duration_minutes' => $durationMinutes,
        ':filament_used_g' => $filamentUsedG,
        ':filament_type' => $filamentType ?: null,
        ':filament_color' => $filamentColor ?: null,
        ':success' => $success,
        ':quality_rating' => $qualityRating,
        ':notes' => $notes ?: null,
        ':settings' => $settings
    ]);

    $printId = $db->lastInsertId();

    // Update model's printed status
    $stmt = $db->prepare('UPDATE models SET is_printed = 1, printed_at = :printed_at WHERE id = :id');
    $stmt->execute([':printed_at' => $printDate, ':id' => $modelId]);

    logActivity('record_print', 'model', $modelId, null, ['print_id' => $printId]);

    echo json_encode([
        'success' => true,
        'print_id' => $printId
    ]);
}

function updatePrintRecord() {
    global $user;

    $printId = (int)($_POST['print_id'] ?? 0);
    if (!$printId) {
        echo json_encode(['success' => false, 'error' => 'Print ID required']);
        return;
    }

    $db = getDB();

    // Verify ownership
    $stmt = $db->prepare('SELECT user_id FROM print_history WHERE id = :id');
    $stmt->execute([':id' => $printId]);
    $record = $stmt->fetch();

    if (!$record || ($record['user_id'] !== $user['id'] && !$user['is_admin'])) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        return;
    }

    $updates = [];
    $params = [':id' => $printId];

    $fields = [
        'printer_id', 'print_date', 'duration_minutes', 'filament_used_g',
        'filament_type', 'filament_color', 'success', 'quality_rating', 'notes', 'settings'
    ];

    foreach ($fields as $field) {
        if (isset($_POST[$field])) {
            $updates[] = "$field = :$field";
            $params[":$field"] = $_POST[$field] ?: null;
        }
    }

    if (empty($updates)) {
        echo json_encode(['success' => false, 'error' => 'No fields to update']);
        return;
    }

    $sql = 'UPDATE print_history SET ' . implode(', ', $updates) . ' WHERE id = :id';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['success' => true]);
}

function deletePrintRecord() {
    global $user;

    $printId = (int)($_POST['print_id'] ?? 0);
    if (!$printId) {
        echo json_encode(['success' => false, 'error' => 'Print ID required']);
        return;
    }

    $db = getDB();

    // Verify ownership
    $stmt = $db->prepare('SELECT user_id FROM print_history WHERE id = :id');
    $stmt->execute([':id' => $printId]);
    $record = $stmt->fetch();

    if (!$record || ($record['user_id'] !== $user['id'] && !$user['is_admin'])) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        return;
    }

    $stmt = $db->prepare('DELETE FROM print_history WHERE id = :id');
    $stmt->execute([':id' => $printId]);

    echo json_encode(['success' => true]);
}

function listPrintHistory() {
    global $user;

    $modelId = (int)($_GET['model_id'] ?? 0);
    $limit = min(100, (int)($_GET['limit'] ?? 50));

    $db = getDB();

    if ($modelId) {
        $stmt = $db->prepare('
            SELECT ph.*, m.name as model_name, p.name as printer_name, u.username
            FROM print_history ph
            JOIN models m ON ph.model_id = m.id
            LEFT JOIN printers p ON ph.printer_id = p.id
            LEFT JOIN users u ON ph.user_id = u.id
            WHERE ph.model_id = :model_id
            ORDER BY ph.print_date DESC
            LIMIT :limit
        ');
        $stmt->execute([':model_id' => $modelId, ':limit' => $limit]);
    } else {
        $stmt = $db->prepare('
            SELECT ph.*, m.name as model_name, p.name as printer_name, u.username
            FROM print_history ph
            JOIN models m ON ph.model_id = m.id
            LEFT JOIN printers p ON ph.printer_id = p.id
            LEFT JOIN users u ON ph.user_id = u.id
            WHERE ph.user_id = :user_id OR :is_admin = 1
            ORDER BY ph.print_date DESC
            LIMIT :limit
        ');
        $stmt->execute([
            ':user_id' => $user['id'],
            ':is_admin' => $user['is_admin'] ? 1 : 0,
            ':limit' => $limit
        ]);
    }

    $history = [];
    while ($row = $stmt->fetch()) {
        $history[] = $row;
    }

    echo json_encode(['success' => true, 'history' => $history]);
}

function getPrintStats() {
    global $user;

    $db = getDB();
    $type = $db->getType();

    // Total prints
    $stmt = $db->prepare('SELECT COUNT(*) FROM print_history WHERE user_id = :user_id');
    $stmt->execute([':user_id' => $user['id']]);
    $totalPrints = (int)$stmt->fetchColumn();

    // Success rate
    $stmt = $db->prepare('SELECT COUNT(*) FROM print_history WHERE user_id = :user_id AND success = 1');
    $stmt->execute([':user_id' => $user['id']]);
    $successfulPrints = (int)$stmt->fetchColumn();
    $successRate = $totalPrints > 0 ? round(($successfulPrints / $totalPrints) * 100, 1) : 0;

    // Total print time
    $stmt = $db->prepare('SELECT SUM(duration_minutes) FROM print_history WHERE user_id = :user_id');
    $stmt->execute([':user_id' => $user['id']]);
    $totalMinutes = (int)$stmt->fetchColumn();

    // Total filament used
    $stmt = $db->prepare('SELECT SUM(filament_used_g) FROM print_history WHERE user_id = :user_id');
    $stmt->execute([':user_id' => $user['id']]);
    $totalFilament = (float)$stmt->fetchColumn();

    // Prints by filament type
    $stmt = $db->prepare('
        SELECT filament_type, COUNT(*) as count
        FROM print_history
        WHERE user_id = :user_id AND filament_type IS NOT NULL
        GROUP BY filament_type
        ORDER BY count DESC
    ');
    $stmt->execute([':user_id' => $user['id']]);
    $byFilamentType = [];
    while ($row = $stmt->fetch()) {
        $byFilamentType[$row['filament_type']] = (int)$row['count'];
    }

    // Average quality rating
    $stmt = $db->prepare('SELECT AVG(quality_rating) FROM print_history WHERE user_id = :user_id AND quality_rating IS NOT NULL');
    $stmt->execute([':user_id' => $user['id']]);
    $avgQuality = round((float)$stmt->fetchColumn(), 1);

    echo json_encode([
        'success' => true,
        'stats' => [
            'total_prints' => $totalPrints,
            'successful_prints' => $successfulPrints,
            'failed_prints' => $totalPrints - $successfulPrints,
            'success_rate' => $successRate,
            'total_print_time_minutes' => $totalMinutes,
            'total_print_time_hours' => round($totalMinutes / 60, 1),
            'total_filament_g' => $totalFilament,
            'total_filament_kg' => round($totalFilament / 1000, 2),
            'by_filament_type' => $byFilamentType,
            'average_quality_rating' => $avgQuality
        ]
    ]);
}
