<?php
/**
 * Data Retention Policy Manager
 *
 * Provides automated data retention, archiving, and purging
 * with legal hold support for compliance
 */

class RetentionManager {
    // Entity types that can have retention policies
    const ENTITY_MODEL = 'model';
    const ENTITY_VERSION = 'version';
    const ENTITY_ACTIVITY = 'activity';
    const ENTITY_AUDIT = 'audit';
    const ENTITY_SESSION = 'session';

    // Retention actions
    const ACTION_ARCHIVE = 'archive';
    const ACTION_DELETE = 'delete';
    const ACTION_NOTIFY = 'notify';

    /**
     * Get all retention policies
     */
    public static function getPolicies($activeOnly = false) {
        $db = getDB();

        $sql = 'SELECT * FROM retention_policies';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name ASC';

        $result = $db->query($sql);
        $policies = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $row['conditions'] = json_decode($row['conditions'], true) ?: [];
            $policies[] = $row;
        }

        return $policies;
    }

    /**
     * Get a single policy by ID
     */
    public static function getPolicy($id) {
        $db = getDB();

        $stmt = $db->prepare('SELECT * FROM retention_policies WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $result = $stmt->execute();
        $policy = $result->fetchArray(PDO::FETCH_ASSOC);

        if ($policy) {
            $policy['conditions'] = json_decode($policy['conditions'], true) ?: [];
        }

        return $policy;
    }

    /**
     * Create a new retention policy
     */
    public static function createPolicy($data) {
        $db = getDB();

        $stmt = $db->prepare('
            INSERT INTO retention_policies (name, description, entity_type, conditions, action, is_active, created_at, updated_at)
            VALUES (:name, :description, :entity_type, :conditions, :action, :is_active, :created_at, :updated_at)
        ');

        $stmt->bindValue(':name', $data['name'], PDO::PARAM_STR);
        $stmt->bindValue(':description', $data['description'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':entity_type', $data['entity_type'], PDO::PARAM_STR);
        $stmt->bindValue(':conditions', json_encode($data['conditions'] ?? []), PDO::PARAM_STR);
        $stmt->bindValue(':action', $data['action'], PDO::PARAM_STR);
        $stmt->bindValue(':is_active', $data['is_active'] ?? 1, PDO::PARAM_INT);
        $stmt->bindValue(':created_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);

        if ($stmt->execute()) {
            $id = $db->lastInsertRowID();
            AuditLogger::logAdmin('retention_policy_created', [
                'resource_type' => 'retention_policy',
                'resource_id' => $id,
                'resource_name' => $data['name']
            ]);
            return $id;
        }

        return false;
    }

    /**
     * Update an existing policy
     */
    public static function updatePolicy($id, $data) {
        $db = getDB();

        $old = self::getPolicy($id);
        if (!$old) {
            return false;
        }

        $stmt = $db->prepare('
            UPDATE retention_policies
            SET name = :name, description = :description, entity_type = :entity_type,
                conditions = :conditions, action = :action, is_active = :is_active, updated_at = :updated_at
            WHERE id = :id
        ');

        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':name', $data['name'], PDO::PARAM_STR);
        $stmt->bindValue(':description', $data['description'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':entity_type', $data['entity_type'], PDO::PARAM_STR);
        $stmt->bindValue(':conditions', json_encode($data['conditions'] ?? []), PDO::PARAM_STR);
        $stmt->bindValue(':action', $data['action'], PDO::PARAM_STR);
        $stmt->bindValue(':is_active', $data['is_active'] ?? 1, PDO::PARAM_INT);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);

        if ($stmt->execute()) {
            AuditLogger::logAdmin('retention_policy_updated', [
                'resource_type' => 'retention_policy',
                'resource_id' => $id,
                'resource_name' => $data['name'],
                'old_value' => $old,
                'new_value' => $data
            ]);
            return true;
        }

        return false;
    }

    /**
     * Delete a policy
     */
    public static function deletePolicy($id) {
        $db = getDB();

        $old = self::getPolicy($id);
        if (!$old) {
            return false;
        }

        $stmt = $db->prepare('DELETE FROM retention_policies WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        if ($stmt->execute()) {
            AuditLogger::logAdmin('retention_policy_deleted', [
                'resource_type' => 'retention_policy',
                'resource_id' => $id,
                'resource_name' => $old['name']
            ]);
            return true;
        }

        return false;
    }

    /**
     * Check if entity is under legal hold
     */
    public static function isUnderLegalHold($entityType, $entityId) {
        $db = getDB();

        $stmt = $db->prepare('
            SELECT COUNT(*) as count FROM legal_holds
            WHERE entity_type = :type AND entity_id = :id
            AND (expires_at IS NULL OR expires_at > :now)
        ');
        $stmt->bindValue(':type', $entityType, PDO::PARAM_STR);
        $stmt->bindValue(':id', $entityId, PDO::PARAM_INT);
        $stmt->bindValue(':now', date('Y-m-d H:i:s'), PDO::PARAM_STR);

        $result = $stmt->execute()->fetchArray(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    }

    /**
     * Get all legal holds
     */
    public static function getLegalHolds($activeOnly = true) {
        $db = getDB();

        $sql = '
            SELECT lh.*, u.username as created_by_name
            FROM legal_holds lh
            LEFT JOIN users u ON lh.created_by = u.id
        ';

        if ($activeOnly) {
            $sql .= ' WHERE lh.expires_at IS NULL OR lh.expires_at > :now';
        }

        $sql .= ' ORDER BY lh.created_at DESC';

        $stmt = $db->prepare($sql);
        if ($activeOnly) {
            $stmt->bindValue(':now', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        }

        $result = $stmt->execute();
        $holds = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $holds[] = $row;
        }

        return $holds;
    }

    /**
     * Create a legal hold
     */
    public static function createLegalHold($entityType, $entityId, $reason, $expiresAt = null) {
        $db = getDB();
        $user = getCurrentUser();

        $stmt = $db->prepare('
            INSERT INTO legal_holds (entity_type, entity_id, reason, created_by, expires_at, created_at)
            VALUES (:entity_type, :entity_id, :reason, :created_by, :expires_at, :created_at)
        ');

        $stmt->bindValue(':entity_type', $entityType, PDO::PARAM_STR);
        $stmt->bindValue(':entity_id', $entityId, PDO::PARAM_INT);
        $stmt->bindValue(':reason', $reason, PDO::PARAM_STR);
        $stmt->bindValue(':created_by', $user['id'] ?? null, PDO::PARAM_INT);
        $stmt->bindValue(':expires_at', $expiresAt, PDO::PARAM_STR);
        $stmt->bindValue(':created_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);

        if ($stmt->execute()) {
            $id = $db->lastInsertRowID();
            AuditLogger::logSecurity('legal_hold_created', [
                'resource_type' => $entityType,
                'resource_id' => $entityId,
                'metadata' => ['reason' => $reason, 'expires_at' => $expiresAt]
            ]);
            return $id;
        }

        return false;
    }

    /**
     * Remove a legal hold
     */
    public static function removeLegalHold($holdId) {
        $db = getDB();

        // Get hold info first
        $stmt = $db->prepare('SELECT * FROM legal_holds WHERE id = :id');
        $stmt->bindValue(':id', $holdId, PDO::PARAM_INT);
        $hold = $stmt->execute()->fetchArray(PDO::FETCH_ASSOC);

        if (!$hold) {
            return false;
        }

        $stmt = $db->prepare('DELETE FROM legal_holds WHERE id = :id');
        $stmt->bindValue(':id', $holdId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            AuditLogger::logSecurity('legal_hold_removed', [
                'resource_type' => $hold['entity_type'],
                'resource_id' => $hold['entity_id'],
                'metadata' => ['reason' => $hold['reason']]
            ]);
            return true;
        }

        return false;
    }

    /**
     * Apply a single retention policy
     */
    public static function applyPolicy($policy, $dryRun = false) {
        $results = [
            'policy_id' => $policy['id'],
            'policy_name' => $policy['name'],
            'entity_type' => $policy['entity_type'],
            'action' => $policy['action'],
            'affected' => 0,
            'skipped_legal_hold' => 0,
            'errors' => [],
            'dry_run' => $dryRun
        ];

        // Get matching entities
        $entities = self::findMatchingEntities($policy);

        foreach ($entities as $entity) {
            // Check legal hold
            if (self::isUnderLegalHold($policy['entity_type'], $entity['id'])) {
                $results['skipped_legal_hold']++;
                continue;
            }

            if ($dryRun) {
                $results['affected']++;
                continue;
            }

            try {
                $success = self::executeAction($policy['entity_type'], $entity['id'], $policy['action']);
                if ($success) {
                    $results['affected']++;
                    self::logRetentionAction($policy['id'], $policy['entity_type'], $entity['id'], $policy['action']);
                }
            } catch (Exception $e) {
                $results['errors'][] = [
                    'entity_id' => $entity['id'],
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Apply all active retention policies
     */
    public static function applyAllPolicies($dryRun = false) {
        $policies = self::getPolicies(true);
        $results = [];

        foreach ($policies as $policy) {
            $results[] = self::applyPolicy($policy, $dryRun);
        }

        // Log overall execution
        if (!$dryRun) {
            AuditLogger::logAdmin('retention_policies_executed', [
                'metadata' => [
                    'policies_count' => count($policies),
                    'results' => $results
                ]
            ]);
        }

        return $results;
    }

    /**
     * Find entities matching policy conditions
     */
    private static function findMatchingEntities($policy) {
        $db = getDB();
        $conditions = $policy['conditions'];
        $entities = [];

        switch ($policy['entity_type']) {
            case self::ENTITY_MODEL:
                $entities = self::findMatchingModels($conditions);
                break;

            case self::ENTITY_VERSION:
                $entities = self::findMatchingVersions($conditions);
                break;

            case self::ENTITY_ACTIVITY:
                $entities = self::findMatchingActivity($conditions);
                break;

            case self::ENTITY_AUDIT:
                $entities = self::findMatchingAudit($conditions);
                break;

            case self::ENTITY_SESSION:
                $entities = self::findMatchingSessions($conditions);
                break;
        }

        return $entities;
    }

    /**
     * Find models matching conditions
     */
    private static function findMatchingModels($conditions) {
        $db = getDB();
        $where = ['1=1'];
        $params = [];

        // Age condition (days since created or last modified)
        if (!empty($conditions['age_days'])) {
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$conditions['age_days']} days"));
            $dateField = $conditions['age_field'] ?? 'created_at';
            $where[] = "$dateField < :age_cutoff";
            $params[':age_cutoff'] = $cutoff;
        }

        // Archived status
        if (isset($conditions['is_archived'])) {
            $where[] = 'is_archived = :is_archived';
            $params[':is_archived'] = $conditions['is_archived'] ? 1 : 0;
        }

        // No downloads in X days
        if (!empty($conditions['no_downloads_days'])) {
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$conditions['no_downloads_days']} days"));
            $where[] = '(last_downloaded_at IS NULL OR last_downloaded_at < :download_cutoff)';
            $params[':download_cutoff'] = $cutoff;
        }

        // Category filter
        if (!empty($conditions['category_id'])) {
            $where[] = 'category_id = :category_id';
            $params[':category_id'] = $conditions['category_id'];
        }

        $sql = 'SELECT id, name FROM models WHERE ' . implode(' AND ', $where);
        $stmt = $db->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $result = $stmt->execute();
        $entities = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $entities[] = $row;
        }

        return $entities;
    }

    /**
     * Find model versions matching conditions
     */
    private static function findMatchingVersions($conditions) {
        $db = getDB();
        $where = ['1=1'];
        $params = [];

        // Age condition
        if (!empty($conditions['age_days'])) {
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$conditions['age_days']} days"));
            $where[] = 'created_at < :age_cutoff';
            $params[':age_cutoff'] = $cutoff;
        }

        // Keep minimum versions
        if (!empty($conditions['keep_minimum'])) {
            // Exclude versions that would leave less than minimum
            $where[] = "id NOT IN (
                SELECT id FROM (
                    SELECT id, ROW_NUMBER() OVER (PARTITION BY model_id ORDER BY version_number DESC) as rn
                    FROM model_versions
                ) ranked WHERE rn <= :keep_min
            )";
            $params[':keep_min'] = $conditions['keep_minimum'];
        }

        $sql = 'SELECT id, model_id, version_number FROM model_versions WHERE ' . implode(' AND ', $where);
        $stmt = $db->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $result = $stmt->execute();
        $entities = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $entities[] = $row;
        }

        return $entities;
    }

    /**
     * Find activity log entries matching conditions
     */
    private static function findMatchingActivity($conditions) {
        $db = getDB();
        $where = ['1=1'];
        $params = [];

        if (!empty($conditions['age_days'])) {
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$conditions['age_days']} days"));
            $where[] = 'created_at < :age_cutoff';
            $params[':age_cutoff'] = $cutoff;
        }

        if (!empty($conditions['action_type'])) {
            $where[] = 'action = :action_type';
            $params[':action_type'] = $conditions['action_type'];
        }

        $sql = 'SELECT id FROM activity_log WHERE ' . implode(' AND ', $where);
        $stmt = $db->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $result = $stmt->execute();
        $entities = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $entities[] = $row;
        }

        return $entities;
    }

    /**
     * Find audit log entries matching conditions
     */
    private static function findMatchingAudit($conditions) {
        $db = getDB();
        $where = ['1=1'];
        $params = [];

        if (!empty($conditions['age_days'])) {
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$conditions['age_days']} days"));
            $where[] = 'created_at < :age_cutoff';
            $params[':age_cutoff'] = $cutoff;
        }

        // Only delete info-level logs, preserve warnings/errors
        if (!empty($conditions['severity_max'])) {
            $severities = ['info'];
            if ($conditions['severity_max'] === 'warning') {
                $severities[] = 'warning';
            }
            $placeholders = implode(',', array_fill(0, count($severities), '?'));
            $where[] = "severity IN ($placeholders)";
        }

        $sql = 'SELECT id FROM audit_log WHERE ' . implode(' AND ', $where);
        $stmt = $db->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $result = $stmt->execute();
        $entities = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $entities[] = $row;
        }

        return $entities;
    }

    /**
     * Find session entries matching conditions
     */
    private static function findMatchingSessions($conditions) {
        $db = getDB();
        $where = ['1=1'];
        $params = [];

        if (!empty($conditions['age_days'])) {
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$conditions['age_days']} days"));
            $where[] = 'last_activity < :age_cutoff';
            $params[':age_cutoff'] = $cutoff;
        }

        $sql = 'SELECT id FROM user_sessions WHERE ' . implode(' AND ', $where);
        $stmt = $db->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $result = $stmt->execute();
        $entities = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $entities[] = $row;
        }

        return $entities;
    }

    /**
     * Execute retention action on entity
     */
    private static function executeAction($entityType, $entityId, $action) {
        $db = getDB();

        switch ($action) {
            case self::ACTION_ARCHIVE:
                return self::archiveEntity($entityType, $entityId);

            case self::ACTION_DELETE:
                return self::deleteEntity($entityType, $entityId);

            case self::ACTION_NOTIFY:
                return self::notifyAboutEntity($entityType, $entityId);

            default:
                throw new Exception("Unknown action: $action");
        }
    }

    /**
     * Archive an entity
     */
    private static function archiveEntity($entityType, $entityId) {
        $db = getDB();

        switch ($entityType) {
            case self::ENTITY_MODEL:
                $stmt = $db->prepare('UPDATE models SET is_archived = 1 WHERE id = :id');
                $stmt->bindValue(':id', $entityId, PDO::PARAM_INT);
                return $stmt->execute() !== false;

            default:
                // Only models can be archived
                return false;
        }
    }

    /**
     * Delete an entity
     */
    private static function deleteEntity($entityType, $entityId) {
        $db = getDB();

        switch ($entityType) {
            case self::ENTITY_MODEL:
                // This should use the existing model deletion logic
                // For now, just mark as archived to be safe
                $stmt = $db->prepare('UPDATE models SET is_archived = 1 WHERE id = :id');
                $stmt->bindValue(':id', $entityId, PDO::PARAM_INT);
                return $stmt->execute() !== false;

            case self::ENTITY_VERSION:
                // Delete version file and record
                $stmt = $db->prepare('SELECT * FROM model_versions WHERE id = :id');
                $stmt->bindValue(':id', $entityId, PDO::PARAM_INT);
                $version = $stmt->execute()->fetchArray(PDO::FETCH_ASSOC);

                if ($version && !empty($version['file_path']) && file_exists($version['file_path'])) {
                    unlink($version['file_path']);
                }

                $stmt = $db->prepare('DELETE FROM model_versions WHERE id = :id');
                $stmt->bindValue(':id', $entityId, PDO::PARAM_INT);
                return $stmt->execute() !== false;

            case self::ENTITY_ACTIVITY:
                $stmt = $db->prepare('DELETE FROM activity_log WHERE id = :id');
                $stmt->bindValue(':id', $entityId, PDO::PARAM_INT);
                return $stmt->execute() !== false;

            case self::ENTITY_AUDIT:
                $stmt = $db->prepare('DELETE FROM audit_log WHERE id = :id');
                $stmt->bindValue(':id', $entityId, PDO::PARAM_INT);
                return $stmt->execute() !== false;

            case self::ENTITY_SESSION:
                $stmt = $db->prepare('DELETE FROM user_sessions WHERE id = :id');
                $stmt->bindValue(':id', $entityId, PDO::PARAM_INT);
                return $stmt->execute() !== false;

            default:
                return false;
        }
    }

    /**
     * Send notification about entity (for review before action)
     */
    private static function notifyAboutEntity($entityType, $entityId) {
        // Trigger webhook or email notification
        if (class_exists('Events')) {
            Events::dispatch('retention.notify', [
                'entity_type' => $entityType,
                'entity_id' => $entityId
            ]);
        }
        return true;
    }

    /**
     * Log retention action to retention_log table
     */
    private static function logRetentionAction($policyId, $entityType, $entityId, $action) {
        try {
            $db = getDB();

            // Use the actual schema columns from migrations
            $stmt = $db->prepare('
                INSERT INTO retention_log (policy_id, action_taken, items_processed, items_affected, details, started_at, completed_at)
                VALUES (:policy_id, :action_taken, 1, 1, :details, :started_at, :completed_at)
            ');

            $details = json_encode(['entity_type' => $entityType, 'entity_id' => $entityId]);
            $now = date('Y-m-d H:i:s');

            $stmt->bindValue(':policy_id', $policyId, PDO::PARAM_INT);
            $stmt->bindValue(':action_taken', $action, PDO::PARAM_STR);
            $stmt->bindValue(':details', $details, PDO::PARAM_STR);
            $stmt->bindValue(':started_at', $now, PDO::PARAM_STR);
            $stmt->bindValue(':completed_at', $now, PDO::PARAM_STR);

            return $stmt->execute() !== false;
        } catch (Exception $e) {
            // Table may not exist or have different schema
            return false;
        }
    }

    /**
     * Get retention log with pagination
     */
    public static function getRetentionLog($filters = [], $limit = 100, $offset = 0) {
        try {
            $db = getDB();

            $where = ['1=1'];
            $params = [];

            if (!empty($filters['policy_id'])) {
                $where[] = 'rl.policy_id = :policy_id';
                $params[':policy_id'] = $filters['policy_id'];
            }

            // Use action_taken instead of action (matches migration schema)
            if (!empty($filters['action'])) {
                $where[] = 'rl.action_taken = :action';
                $params[':action'] = $filters['action'];
            }

            // Use created_at instead of executed_at (matches migration schema)
            if (!empty($filters['date_from'])) {
                $where[] = 'rl.created_at >= :date_from';
                $params[':date_from'] = $filters['date_from'];
            }

            if (!empty($filters['date_to'])) {
                $where[] = 'rl.created_at <= :date_to';
                $params[':date_to'] = $filters['date_to'];
            }

            $whereClause = implode(' AND ', $where);

            // Get count
            $countSql = "SELECT COUNT(*) as total FROM retention_log rl WHERE $whereClause";
            $stmt = $db->prepare($countSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $result = $stmt->execute();
            $row = $result->fetchArray(PDO::FETCH_ASSOC);
            $total = (int)($row['total'] ?? 0);

            // Get results - use created_at for ordering
            $sql = "
                SELECT rl.*, rp.name as policy_name
                FROM retention_log rl
                LEFT JOIN retention_policies rp ON rl.policy_id = rp.id
                WHERE $whereClause
                ORDER BY rl.created_at DESC
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

            $result = $stmt->execute();
            $logs = [];
            while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
                $logs[] = $row;
            }

            return [
                'data' => $logs,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset
            ];
        } catch (Exception $e) {
            // Table may not exist or have different schema
            return [
                'data' => [],
                'total' => 0,
                'limit' => $limit,
                'offset' => $offset
            ];
        }
    }

    /**
     * Get statistics about retention
     */
    public static function getStats() {
        $db = getDB();

        $stats = [
            'active_policies' => 0,
            'active_legal_holds' => 0,
            'actions_30_days' => 0,
            'actions_by_type' => []
        ];

        try {
            // Active policies count
            $stats['active_policies'] = (int)$db->querySingle('SELECT COUNT(*) FROM retention_policies WHERE is_active = 1');
        } catch (Exception $e) {
            // Table may not exist yet
        }

        try {
            // Active legal holds (not released and not expired)
            $stmt = $db->prepare('SELECT COUNT(*) FROM legal_holds WHERE released_at IS NULL AND (expires_at IS NULL OR expires_at > :now)');
            $stmt->bindValue(':now', date('Y-m-d H:i:s'), PDO::PARAM_STR);
            $result = $stmt->execute();
            $row = $result->fetchArray();
            $stats['active_legal_holds'] = (int)($row[0] ?? 0);
        } catch (Exception $e) {
            // Table may not exist yet
        }

        try {
            // Actions in last 30 days
            $stmt = $db->prepare('SELECT COUNT(*) FROM retention_log WHERE created_at > :cutoff');
            $stmt->bindValue(':cutoff', date('Y-m-d H:i:s', strtotime('-30 days')), PDO::PARAM_STR);
            $result = $stmt->execute();
            $row = $result->fetchArray();
            $stats['actions_30_days'] = (int)($row[0] ?? 0);
        } catch (Exception $e) {
            // Table may not exist yet
        }

        try {
            // Actions by type (last 30 days)
            $stmt = $db->prepare('
                SELECT action_taken, COUNT(*) as count
                FROM retention_log
                WHERE created_at > :cutoff
                GROUP BY action_taken
            ');
            $stmt->bindValue(':cutoff', date('Y-m-d H:i:s', strtotime('-30 days')), PDO::PARAM_STR);
            $result = $stmt->execute();
            while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
                $stats['actions_by_type'][$row['action_taken']] = $row['count'];
            }
        } catch (Exception $e) {
            // Table may not exist yet
        }

        return $stats;
    }
}
