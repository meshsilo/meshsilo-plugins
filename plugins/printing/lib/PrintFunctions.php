<?php
/**
 * Print Queue Functions
 *
 * Extracted from core db.php - provides print queue database operations.
 * These are plain functions that use getDB() from the core includes.
 */

function isInPrintQueue($userId, $modelId) {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT id FROM print_queue WHERE user_id = :user_id AND model_id = :model_id');
        $stmt->execute([':user_id' => $userId, ':model_id' => $modelId]);
        return $stmt->fetch() !== false;
    } catch (Exception $e) {
        return false;
    }
}

function addToPrintQueue($userId, $modelId, $priority = 0, $notes = '') {
    try {
        $db = getDB();
        if (isInPrintQueue($userId, $modelId)) {
            $stmt = $db->prepare('UPDATE print_queue SET priority = :priority, notes = :notes WHERE user_id = :user_id AND model_id = :model_id');
        } else {
            $stmt = $db->prepare('INSERT INTO print_queue (user_id, model_id, priority, notes) VALUES (:user_id, :model_id, :priority, :notes)');
        }
        $stmt->execute([
            ':user_id' => $userId,
            ':model_id' => $modelId,
            ':priority' => $priority,
            ':notes' => $notes
        ]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function removeFromPrintQueue($userId, $modelId) {
    try {
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM print_queue WHERE user_id = :user_id AND model_id = :model_id');
        $stmt->execute([':user_id' => $userId, ':model_id' => $modelId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function togglePrintQueue($userId, $modelId) {
    if (isInPrintQueue($userId, $modelId)) {
        removeFromPrintQueue($userId, $modelId);
        return false;
    } else {
        addToPrintQueue($userId, $modelId);
        return true;
    }
}

function getUserPrintQueue($userId, $limit = 100) {
    try {
        $db = getDB();
        $stmt = $db->prepare('
            SELECT pq.*, m.name as model_name, m.file_path, m.print_type,
                   c.name as category_name
            FROM print_queue pq
            JOIN models m ON pq.model_id = m.id
            LEFT JOIN categories c ON m.category_id = c.id
            WHERE pq.user_id = :user_id AND m.parent_id IS NULL
            ORDER BY pq.priority DESC, pq.added_at DESC
            LIMIT :limit
        ');
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

function updatePrintQueuePriority($userId, $modelId, $priority) {
    try {
        $db = getDB();
        $stmt = $db->prepare('UPDATE print_queue SET priority = :priority WHERE user_id = :user_id AND model_id = :model_id');
        $stmt->execute([':priority' => $priority, ':user_id' => $userId, ':model_id' => $modelId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function getPrintQueueCount($userId) {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT COUNT(*) as count FROM print_queue WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ? (int)$row['count'] : 0;
    } catch (Exception $e) {
        return 0;
    }
}
