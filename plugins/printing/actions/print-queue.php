<?php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/features.php';

header('Content-Type: application/json');

// Check if print queue feature is enabled
if (!isFeatureEnabled('print_queue')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Print queue feature is disabled']);
    exit;
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user = getCurrentUser();
$action = $_POST['action'] ?? 'toggle';
$modelId = isset($_POST['model_id']) ? (int)$_POST['model_id'] : 0;

switch ($action) {
    case 'toggle':
        if (!$modelId) {
            echo json_encode(['success' => false, 'error' => 'No model specified']);
            exit;
        }
        $inQueue = togglePrintQueue($user['id'], $modelId);
        logActivity($user['id'], $inQueue ? 'add_to_queue' : 'remove_from_queue', 'model', $modelId);
        echo json_encode(['success' => true, 'in_queue' => $inQueue]);
        break;

    case 'add':
        if (!$modelId) {
            echo json_encode(['success' => false, 'error' => 'No model specified']);
            exit;
        }
        $priority = isset($_POST['priority']) ? (int)$_POST['priority'] : 0;
        $notes = $_POST['notes'] ?? '';
        $result = addToPrintQueue($user['id'], $modelId, $priority, $notes);
        if ($result) {
            logActivity($user['id'], 'add_to_queue', 'model', $modelId);
        }
        echo json_encode(['success' => $result]);
        break;

    case 'remove':
        if (!$modelId) {
            echo json_encode(['success' => false, 'error' => 'No model specified']);
            exit;
        }
        $result = removeFromPrintQueue($user['id'], $modelId);
        if ($result) {
            logActivity($user['id'], 'remove_from_queue', 'model', $modelId);
        }
        echo json_encode(['success' => $result]);
        break;

    case 'priority':
        if (!$modelId) {
            echo json_encode(['success' => false, 'error' => 'No model specified']);
            exit;
        }
        $priority = isset($_POST['priority']) ? (int)$_POST['priority'] : 0;
        $result = updatePrintQueuePriority($user['id'], $modelId, $priority);
        echo json_encode(['success' => $result]);
        break;

    case 'clear':
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM print_queue WHERE user_id = :user_id');
        $stmt->bindValue(':user_id', $user['id'], PDO::PARAM_INT);
        $result = $stmt->execute();
        logActivity($user['id'], 'clear_queue', 'print_queue', 0);
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
