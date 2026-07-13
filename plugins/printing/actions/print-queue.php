<?php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/features.php';

printingRequireFeature('print_queue', 'Print queue');
$user = printingRequireLogin();

$action = $_POST['action'] ?? 'toggle';
$modelId = isset($_POST['model_id']) ? (int)$_POST['model_id'] : 0;

printingRequireCsrf($action, ['toggle', 'add', 'remove', 'priority', 'clear']);

switch ($action) {
    case 'toggle':
        if (!$modelId) {
            printingFail('No model specified');
        }
        $inQueue = togglePrintQueue($user['id'], $modelId);
        logActivity($inQueue ? 'add_to_queue' : 'remove_from_queue', 'model', $modelId);
        printingOk(['in_queue' => $inQueue]);
        break;

    case 'add':
        if (!$modelId) {
            printingFail('No model specified');
        }
        $priority = isset($_POST['priority']) ? (int)$_POST['priority'] : 0;
        $notes = $_POST['notes'] ?? '';
        $result = addToPrintQueue($user['id'], $modelId, $priority, $notes);
        if ($result) {
            logActivity('add_to_queue', 'model', $modelId);
        }
        printingJson(['success' => $result]);
        break;

    case 'remove':
        if (!$modelId) {
            printingFail('No model specified');
        }
        $result = removeFromPrintQueue($user['id'], $modelId);
        if ($result) {
            logActivity('remove_from_queue', 'model', $modelId);
        }
        printingJson(['success' => $result]);
        break;

    case 'priority':
        if (!$modelId) {
            printingFail('No model specified');
        }
        $priority = isset($_POST['priority']) ? (int)$_POST['priority'] : 0;
        $result = updatePrintQueuePriority($user['id'], $modelId, $priority);
        printingJson(['success' => $result]);
        break;

    case 'clear':
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM print_queue WHERE user_id = :user_id');
        $stmt->bindValue(':user_id', $user['id'], PDO::PARAM_INT);
        $stmt->execute();
        logActivity('clear_queue', 'print_queue', 0);
        printingOk();
        break;

    default:
        printingFail('Unknown action');
}
