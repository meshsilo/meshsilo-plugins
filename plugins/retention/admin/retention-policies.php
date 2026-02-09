<?php
/**
 * Data Retention Policies Admin Page
 *
 * Manage retention policies and legal holds
 */

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/features.php';

// Require feature to be enabled
requireFeature('retention_policies');

// Require retention management permission
if (!isLoggedIn() || !canManageRetention()) {
    $_SESSION['error'] = 'You do not have permission to manage data retention policies.';
    header('Location: ' . route('home'));
    exit;
}

// Include RetentionManager
if (!class_exists('RetentionManager')) {
    require_once __DIR__ . '/../lib/RetentionManager.php';
}

$message = '';
$error = '';
$activeTab = $_GET['tab'] ?? 'policies';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create_policy':
            $conditions = [];
            if (!empty($_POST['age_days'])) {
                $conditions['age_days'] = (int)$_POST['age_days'];
            }
            if (!empty($_POST['age_field'])) {
                $conditions['age_field'] = $_POST['age_field'];
            }
            if (isset($_POST['is_archived']) && $_POST['is_archived'] !== '') {
                $conditions['is_archived'] = (bool)$_POST['is_archived'];
            }
            if (!empty($_POST['no_downloads_days'])) {
                $conditions['no_downloads_days'] = (int)$_POST['no_downloads_days'];
            }
            if (!empty($_POST['keep_minimum'])) {
                $conditions['keep_minimum'] = (int)$_POST['keep_minimum'];
            }

            $result = RetentionManager::createPolicy([
                'name' => $_POST['name'] ?? '',
                'description' => $_POST['description'] ?? '',
                'entity_type' => $_POST['entity_type'] ?? '',
                'conditions' => $conditions,
                'action' => $_POST['policy_action'] ?? 'archive',
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            ]);

            if ($result) {
                $message = 'Policy created successfully.';
            } else {
                $error = 'Failed to create policy.';
            }
            break;

        case 'update_policy':
            $conditions = [];
            if (!empty($_POST['age_days'])) {
                $conditions['age_days'] = (int)$_POST['age_days'];
            }
            if (!empty($_POST['age_field'])) {
                $conditions['age_field'] = $_POST['age_field'];
            }
            if (isset($_POST['is_archived']) && $_POST['is_archived'] !== '') {
                $conditions['is_archived'] = (bool)$_POST['is_archived'];
            }
            if (!empty($_POST['no_downloads_days'])) {
                $conditions['no_downloads_days'] = (int)$_POST['no_downloads_days'];
            }
            if (!empty($_POST['keep_minimum'])) {
                $conditions['keep_minimum'] = (int)$_POST['keep_minimum'];
            }

            $result = RetentionManager::updatePolicy($_POST['policy_id'], [
                'name' => $_POST['name'] ?? '',
                'description' => $_POST['description'] ?? '',
                'entity_type' => $_POST['entity_type'] ?? '',
                'conditions' => $conditions,
                'action' => $_POST['policy_action'] ?? 'archive',
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            ]);

            if ($result) {
                $message = 'Policy updated successfully.';
            } else {
                $error = 'Failed to update policy.';
            }
            break;

        case 'delete_policy':
            if (RetentionManager::deletePolicy($_POST['policy_id'])) {
                $message = 'Policy deleted successfully.';
            } else {
                $error = 'Failed to delete policy.';
            }
            break;

        case 'run_policy':
            $dryRun = isset($_POST['dry_run']);
            $policy = RetentionManager::getPolicy($_POST['policy_id']);
            if ($policy) {
                $result = RetentionManager::applyPolicy($policy, $dryRun);
                if ($dryRun) {
                    $message = "Dry run complete: {$result['affected']} entities would be affected, {$result['skipped_legal_hold']} under legal hold.";
                } else {
                    $message = "Policy executed: {$result['affected']} entities affected, {$result['skipped_legal_hold']} skipped (legal hold).";
                }
            }
            break;

        case 'run_all_policies':
            $dryRun = isset($_POST['dry_run']);
            $results = RetentionManager::applyAllPolicies($dryRun);
            $totalAffected = array_sum(array_column($results, 'affected'));
            $totalSkipped = array_sum(array_column($results, 'skipped_legal_hold'));
            if ($dryRun) {
                $message = "Dry run complete: {$totalAffected} entities would be affected across " . count($results) . " policies.";
            } else {
                $message = "Executed " . count($results) . " policies: {$totalAffected} entities affected, {$totalSkipped} skipped.";
            }
            break;

        case 'create_hold':
            $result = RetentionManager::createLegalHold(
                $_POST['entity_type'],
                (int)$_POST['entity_id'],
                $_POST['reason'],
                !empty($_POST['expires_at']) ? $_POST['expires_at'] : null
            );
            if ($result) {
                $message = 'Legal hold created successfully.';
                $activeTab = 'holds';
            } else {
                $error = 'Failed to create legal hold.';
            }
            break;

        case 'remove_hold':
            if (RetentionManager::removeLegalHold($_POST['hold_id'])) {
                $message = 'Legal hold removed successfully.';
                $activeTab = 'holds';
            } else {
                $error = 'Failed to remove legal hold.';
            }
            break;
    }
}

// Get data for display
$policies = RetentionManager::getPolicies();
$legalHolds = RetentionManager::getLegalHolds(false);
$stats = RetentionManager::getStats();
$retentionLog = RetentionManager::getRetentionLog([], 50, 0);

// Entity type labels
$entityTypes = [
    'model' => 'Models',
    'version' => 'Model Versions',
    'activity' => 'Activity Log',
    'audit' => 'Audit Log',
    'session' => 'Sessions'
];

$actionLabels = [
    'archive' => 'Archive',
    'delete' => 'Delete',
    'notify' => 'Notify Only'
];

$pageTitle = 'Data Retention';
include __DIR__ . '/../../../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/../../../includes/admin-sidebar.php'; ?>

    <div class="admin-content">
        <div class="page-header">
            <h1>Data Retention</h1>
            <p>Manage data retention policies and legal holds for compliance</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Stats Overview -->
        <div class="stats-grid" style="margin-bottom: 1.5rem;">
            <div class="stat-card">
                <div class="stat-value"><?= $stats['active_policies'] ?></div>
                <div class="stat-label">Active Policies</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $stats['active_legal_holds'] ?></div>
                <div class="stat-label">Active Legal Holds</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $stats['actions_30_days'] ?></div>
                <div class="stat-label">Actions (30 days)</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab-btn <?= $activeTab === 'policies' ? 'active' : '' ?>"
                    onclick="switchTab('policies')">Retention Policies</button>
            <button class="tab-btn <?= $activeTab === 'holds' ? 'active' : '' ?>"
                    onclick="switchTab('holds')">Legal Holds</button>
            <button class="tab-btn <?= $activeTab === 'log' ? 'active' : '' ?>"
                    onclick="switchTab('log')">Execution Log</button>
        </div>

        <!-- Policies Tab -->
        <div id="tab-policies" class="tab-content" style="<?= $activeTab !== 'policies' ? 'display:none' : '' ?>">
            <div class="section-header">
                <h2>Retention Policies</h2>
                <button class="btn btn-primary" onclick="openPolicyModal()">Create Policy</button>
            </div>

            <div class="card">
                <div style="margin-bottom: 1rem;">
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="run_all_policies">
                        <button type="submit" name="dry_run" value="1" class="btn btn-secondary">
                            Dry Run All Policies
                        </button>
                        <button type="submit" class="btn btn-warning"
                                onclick="return confirm('This will execute all active policies. Continue?')">
                            Run All Policies
                        </button>
                    </form>
                </div>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Entity Type</th>
                            <th>Conditions</th>
                            <th>Action</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($policies as $policy): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($policy['name']) ?></strong>
                                    <?php if ($policy['description']): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($policy['description']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= $entityTypes[$policy['entity_type']] ?? $policy['entity_type'] ?></td>
                                <td>
                                    <?php
                                    $condText = [];
                                    if (!empty($policy['conditions']['age_days'])) {
                                        $condText[] = "Older than {$policy['conditions']['age_days']} days";
                                    }
                                    if (isset($policy['conditions']['is_archived'])) {
                                        $condText[] = $policy['conditions']['is_archived'] ? 'Archived only' : 'Non-archived only';
                                    }
                                    if (!empty($policy['conditions']['no_downloads_days'])) {
                                        $condText[] = "No downloads in {$policy['conditions']['no_downloads_days']} days";
                                    }
                                    if (!empty($policy['conditions']['keep_minimum'])) {
                                        $condText[] = "Keep min {$policy['conditions']['keep_minimum']} versions";
                                    }
                                    echo implode('<br>', $condText) ?: 'None';
                                    ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $policy['action'] === 'delete' ? 'danger' : ($policy['action'] === 'archive' ? 'warning' : 'info') ?>">
                                        <?= $actionLabels[$policy['action']] ?? $policy['action'] ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $policy['is_active'] ? 'success' : 'secondary' ?>">
                                        <?= $policy['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="run_policy">
                                            <input type="hidden" name="policy_id" value="<?= $policy['id'] ?>">
                                            <button type="submit" name="dry_run" value="1" class="btn btn-sm btn-secondary" title="Dry Run">
                                                Test
                                            </button>
                                            <button type="submit" class="btn btn-sm btn-warning" title="Execute"
                                                    onclick="return confirm('Execute this policy?')">
                                                Run
                                            </button>
                                        </form>
                                        <button class="btn btn-sm btn-secondary"
                                                onclick="editPolicy(<?= htmlspecialchars(json_encode($policy)) ?>)">
                                            Edit
                                        </button>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_policy">
                                            <input type="hidden" name="policy_id" value="<?= $policy['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger"
                                                    onclick="return confirm('Delete this policy?')">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($policies)): ?>
                            <tr>
                                <td colspan="6" class="text-center">No retention policies defined.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Legal Holds Tab -->
        <div id="tab-holds" class="tab-content" style="<?= $activeTab !== 'holds' ? 'display:none' : '' ?>">
            <div class="section-header">
                <h2>Legal Holds</h2>
                <button class="btn btn-primary" onclick="openHoldModal()">Create Legal Hold</button>
            </div>

            <div class="card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Entity</th>
                            <th>Reason</th>
                            <th>Created By</th>
                            <th>Created</th>
                            <th>Expires</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($legalHolds as $hold): ?>
                            <?php
                            $isActive = empty($hold['expires_at']) || strtotime($hold['expires_at']) > time();
                            ?>
                            <tr class="<?= $isActive ? '' : 'text-muted' ?>">
                                <td>
                                    <?= $entityTypes[$hold['entity_type']] ?? $hold['entity_type'] ?>
                                    #<?= $hold['entity_id'] ?>
                                </td>
                                <td><?= htmlspecialchars($hold['reason']) ?></td>
                                <td><?= htmlspecialchars($hold['created_by_name'] ?? 'System') ?></td>
                                <td><?= date('M j, Y', strtotime($hold['created_at'])) ?></td>
                                <td>
                                    <?= $hold['expires_at'] ? date('M j, Y', strtotime($hold['expires_at'])) : 'Never' ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $isActive ? 'warning' : 'secondary' ?>">
                                        <?= $isActive ? 'Active' : 'Expired' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($isActive): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="remove_hold">
                                            <input type="hidden" name="hold_id" value="<?= $hold['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger"
                                                    onclick="return confirm('Remove this legal hold? This may allow affected data to be deleted.')">
                                                Remove Hold
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($legalHolds)): ?>
                            <tr>
                                <td colspan="7" class="text-center">No legal holds.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Log Tab -->
        <div id="tab-log" class="tab-content" style="<?= $activeTab !== 'log' ? 'display:none' : '' ?>">
            <div class="section-header">
                <h2>Execution Log</h2>
            </div>

            <div class="card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Policy</th>
                            <th>Entity</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($retentionLog['data'] as $log): ?>
                            <tr>
                                <td><?= date('M j, Y H:i', strtotime($log['executed_at'])) ?></td>
                                <td><?= htmlspecialchars($log['policy_name'] ?? 'Unknown') ?></td>
                                <td>
                                    <?= $entityTypes[$log['entity_type']] ?? $log['entity_type'] ?>
                                    #<?= $log['entity_id'] ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $log['action'] === 'delete' ? 'danger' : ($log['action'] === 'archive' ? 'warning' : 'info') ?>">
                                        <?= $actionLabels[$log['action']] ?? $log['action'] ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($retentionLog['data'])): ?>
                            <tr>
                                <td colspan="4" class="text-center">No retention actions logged.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Policy Modal -->
<div id="policy-modal" class="modal" style="display: none;">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3 id="policy-modal-title">Create Retention Policy</h3>
            <button class="modal-close" onclick="closePolicyModal()">&times;</button>
        </div>
        <form method="POST" id="policy-form">
            <input type="hidden" name="action" id="policy-form-action" value="create_policy">
            <input type="hidden" name="policy_id" id="policy-form-id" value="">

            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="policy-name">Policy Name *</label>
                        <input type="text" id="policy-name" name="name" required class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="policy-entity-type">Entity Type *</label>
                        <select id="policy-entity-type" name="entity_type" required class="form-control"
                                onchange="updateConditionFields()">
                            <option value="">Select...</option>
                            <?php foreach ($entityTypes as $value => $label): ?>
                                <option value="<?= $value ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="policy-description">Description</label>
                    <textarea id="policy-description" name="description" rows="2" class="form-control"></textarea>
                </div>

                <div class="form-group">
                    <label for="policy-action">Action *</label>
                    <select id="policy-action" name="policy_action" required class="form-control">
                        <option value="archive">Archive (non-destructive)</option>
                        <option value="notify">Notify Only (no changes)</option>
                        <option value="delete">Delete (permanent)</option>
                    </select>
                </div>

                <fieldset class="form-fieldset">
                    <legend>Conditions</legend>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="policy-age-days">Age (days)</label>
                            <input type="number" id="policy-age-days" name="age_days" min="1" class="form-control"
                                   placeholder="e.g., 365">
                            <small>Items older than this many days</small>
                        </div>

                        <div class="form-group" id="age-field-group">
                            <label for="policy-age-field">Date Field</label>
                            <select id="policy-age-field" name="age_field" class="form-control">
                                <option value="created_at">Created Date</option>
                                <option value="updated_at">Last Modified</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid" id="model-conditions" style="display: none;">
                        <div class="form-group">
                            <label for="policy-archived">Archived Status</label>
                            <select id="policy-archived" name="is_archived" class="form-control">
                                <option value="">Any</option>
                                <option value="1">Archived Only</option>
                                <option value="0">Non-Archived Only</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="policy-no-downloads">No Downloads (days)</label>
                            <input type="number" id="policy-no-downloads" name="no_downloads_days" min="1"
                                   class="form-control" placeholder="e.g., 180">
                        </div>
                    </div>

                    <div class="form-group" id="version-conditions" style="display: none;">
                        <label for="policy-keep-min">Keep Minimum Versions</label>
                        <input type="number" id="policy-keep-min" name="keep_minimum" min="1"
                               class="form-control" placeholder="e.g., 5">
                        <small>Always keep at least this many versions per model</small>
                    </div>
                </fieldset>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_active" id="policy-active" checked>
                        Policy is active
                    </label>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePolicyModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Policy</button>
            </div>
        </form>
    </div>
</div>

<!-- Legal Hold Modal -->
<div id="hold-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Create Legal Hold</h3>
            <button class="modal-close" onclick="closeHoldModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_hold">

            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="hold-entity-type">Entity Type *</label>
                        <select id="hold-entity-type" name="entity_type" required class="form-control">
                            <option value="">Select...</option>
                            <?php foreach ($entityTypes as $value => $label): ?>
                                <option value="<?= $value ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="hold-entity-id">Entity ID *</label>
                        <input type="number" id="hold-entity-id" name="entity_id" required min="1" class="form-control">
                    </div>
                </div>

                <div class="form-group">
                    <label for="hold-reason">Reason *</label>
                    <textarea id="hold-reason" name="reason" required rows="3" class="form-control"
                              placeholder="Legal case reference, investigation ID, etc."></textarea>
                </div>

                <div class="form-group">
                    <label for="hold-expires">Expires (optional)</label>
                    <input type="datetime-local" id="hold-expires" name="expires_at" class="form-control">
                    <small>Leave blank for indefinite hold</small>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeHoldModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Hold</button>
            </div>
        </form>
    </div>
</div>

<style>
.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.section-header h2 {
    margin: 0;
}

.tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    border-bottom: 2px solid var(--border-color);
    padding-bottom: 0;
}

.tab-btn {
    padding: 0.75rem 1.5rem;
    border: none;
    background: none;
    cursor: pointer;
    font-size: 1rem;
    color: var(--text-muted);
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all 0.2s ease;
}

.tab-btn:hover {
    color: var(--text-color);
}

.tab-btn.active {
    color: var(--primary-color);
    border-bottom-color: var(--primary-color);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
}

.stat-card {
    background: var(--card-bg);
    padding: 1rem;
    border-radius: var(--radius);
    text-align: center;
}

.stat-value {
    font-size: 2rem;
    font-weight: bold;
    color: var(--primary-color);
}

.stat-label {
    color: var(--text-muted);
    font-size: 0.875rem;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

.form-fieldset {
    border: 1px solid var(--border-color);
    padding: 1rem;
    border-radius: var(--radius);
    margin: 1rem 0;
}

.form-fieldset legend {
    padding: 0 0.5rem;
    font-weight: 500;
}

.badge-danger {
    background-color: #dc3545;
    color: white;
}

.badge-warning {
    background-color: #ffc107;
    color: #000;
}

.badge-success {
    background-color: #28a745;
    color: white;
}

.badge-info {
    background-color: #17a2b8;
    color: white;
}

.badge-secondary {
    background-color: #6c757d;
    color: white;
}

.btn-group {
    display: flex;
    gap: 0.25rem;
}

.text-muted {
    opacity: 0.6;
}

@media (max-width: 768px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
function switchTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));

    // Show selected tab
    document.getElementById('tab-' + tabName).style.display = 'block';
    event.target.classList.add('active');

    // Update URL
    const url = new URL(window.location);
    url.searchParams.set('tab', tabName);
    history.replaceState(null, '', url);
}

function openPolicyModal() {
    document.getElementById('policy-modal-title').textContent = 'Create Retention Policy';
    document.getElementById('policy-form-action').value = 'create_policy';
    document.getElementById('policy-form-id').value = '';
    document.getElementById('policy-form').reset();
    document.getElementById('policy-modal').style.display = 'flex';
    updateConditionFields();
}

function editPolicy(policy) {
    document.getElementById('policy-modal-title').textContent = 'Edit Retention Policy';
    document.getElementById('policy-form-action').value = 'update_policy';
    document.getElementById('policy-form-id').value = policy.id;
    document.getElementById('policy-name').value = policy.name;
    document.getElementById('policy-description').value = policy.description || '';
    document.getElementById('policy-entity-type').value = policy.entity_type;
    document.getElementById('policy-action').value = policy.action;
    document.getElementById('policy-active').checked = policy.is_active == 1;

    // Set conditions
    const conditions = policy.conditions || {};
    document.getElementById('policy-age-days').value = conditions.age_days || '';
    document.getElementById('policy-age-field').value = conditions.age_field || 'created_at';
    document.getElementById('policy-archived').value = conditions.is_archived !== undefined ? (conditions.is_archived ? '1' : '0') : '';
    document.getElementById('policy-no-downloads').value = conditions.no_downloads_days || '';
    document.getElementById('policy-keep-min').value = conditions.keep_minimum || '';

    updateConditionFields();
    document.getElementById('policy-modal').style.display = 'flex';
}

function closePolicyModal() {
    document.getElementById('policy-modal').style.display = 'none';
}

function updateConditionFields() {
    const entityType = document.getElementById('policy-entity-type').value;

    // Show/hide entity-specific conditions
    document.getElementById('model-conditions').style.display = entityType === 'model' ? 'grid' : 'none';
    document.getElementById('version-conditions').style.display = entityType === 'version' ? 'block' : 'none';
    document.getElementById('age-field-group').style.display = ['model', 'version'].includes(entityType) ? 'block' : 'none';
}

function openHoldModal() {
    document.getElementById('hold-modal').style.display = 'flex';
}

function closeHoldModal() {
    document.getElementById('hold-modal').style.display = 'none';
}

// Close modal on backdrop click
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.style.display = 'none';
        }
    });
});

// Close modal on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal').forEach(m => m.style.display = 'none');
    }
});
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
