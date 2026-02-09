<?php
$pageTitle = 'Backup & Recovery';
$adminPage = 'backups';

require_once __DIR__ . '/../../../includes/permissions.php';

// Check permission
if (!canManageBackups()) {
    $_SESSION['error'] = 'You do not have permission to manage backups.';
    header('Location: ' . route('admin.health'));
    exit;
}

if (!class_exists('BackupManager')) {
    require_once __DIR__ . '/../lib/BackupManager.php';
}

$message = '';
$error = '';

// Handle actions
// CSRF protection for all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    $error = 'Invalid request. Please refresh the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'create':
                $label = $_POST['label'] ?? 'manual';
                $backup = BackupManager::createBackup($label);
                $message = "Backup created: {$backup['filename']} ({$backup['size']} bytes)";

                // Replicate if configured
                $replicationConfig = BackupManager::getReplicationConfig();
                if ($replicationConfig && $replicationConfig['enabled']) {
                    $backupPath = __DIR__ . '/../../../storage/backups/' . $backup['filename'];
                    $replicationResult = BackupManager::replicateBackup($backupPath);
                    if ($replicationResult['success']) {
                        $message .= '. Replicated to ' . count($replicationResult['results']) . ' target(s).';
                    }
                }
                break;

            case 'verify':
                $backupPath = $_POST['backup_path'] ?? '';
                if ($backupPath && file_exists($backupPath)) {
                    $result = BackupManager::verifyBackup($backupPath);
                    if ($result['valid']) {
                        $message = "Backup verified successfully. Checksum: " . substr($result['checksum'], 0, 16) . '...';
                    } else {
                        $error = "Backup verification failed: " . $result['error'];
                    }
                }
                break;

            case 'verify_all':
                $results = BackupManager::verifyAllBackups();
                $valid = count(array_filter($results, fn($r) => $r['valid']));
                $invalid = count($results) - $valid;
                $message = "Verified {$valid} backups. " . ($invalid > 0 ? "$invalid invalid backups found." : "All backups valid.");
                break;

            case 'restore':
                $backupPath = $_POST['backup_path'] ?? '';
                $confirm = $_POST['confirm'] ?? '';
                if ($confirm !== 'RESTORE') {
                    $error = 'Please type RESTORE to confirm';
                } elseif ($backupPath && file_exists($backupPath)) {
                    $result = BackupManager::restore($backupPath);
                    $message = $result['message'];
                }
                break;

            case 'delete':
                $backupPath = $_POST['backup_path'] ?? '';
                if ($backupPath && file_exists($backupPath)) {
                    unlink($backupPath);
                    // Delete metadata too
                    $filename = basename($backupPath);
                    if (preg_match('/backup_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})/', $filename, $m)) {
                        $metadataPath = dirname($backupPath) . '/metadata_' . $m[1] . '.json';
                        if (file_exists($metadataPath)) {
                            unlink($metadataPath);
                        }
                    }
                    $message = "Backup deleted";
                }
                break;

            case 'cleanup':
                $keepDays = (int)($_POST['keep_days'] ?? 30);
                $keepCount = (int)($_POST['keep_count'] ?? 10);
                $result = BackupManager::cleanupBackups($keepDays, $keepCount);
                $message = "Cleanup complete. Deleted {$result['count']} old backups.";
                break;

            case 'save_replication':
                $targets = [];
                $targetCount = (int)($_POST['target_count'] ?? 0);

                for ($i = 0; $i < $targetCount; $i++) {
                    if (!empty($_POST["target_{$i}_name"])) {
                        $targets[] = [
                            'name' => $_POST["target_{$i}_name"],
                            'type' => $_POST["target_{$i}_type"] ?? 's3',
                            'endpoint' => $_POST["target_{$i}_endpoint"] ?? '',
                            'bucket' => $_POST["target_{$i}_bucket"] ?? '',
                            'access_key' => $_POST["target_{$i}_access_key"] ?? '',
                            'secret_key' => $_POST["target_{$i}_secret_key"] ?? '',
                            'region' => $_POST["target_{$i}_region"] ?? 'us-east-1',
                            'prefix' => $_POST["target_{$i}_prefix"] ?? 'silo-backups/',
                            'host' => $_POST["target_{$i}_host"] ?? '',
                            'port' => $_POST["target_{$i}_port"] ?? '22',
                            'username' => $_POST["target_{$i}_username"] ?? '',
                            'password' => $_POST["target_{$i}_password"] ?? '',
                            'path' => $_POST["target_{$i}_path"] ?? '',
                        ];
                    }
                }

                if (BackupManager::configureReplication($targets)) {
                    $message = 'Replication configuration saved';
                } else {
                    $error = 'Failed to save replication configuration';
                }
                break;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get data
$backups = BackupManager::listBackups();
$status = BackupManager::getStatus();
$replicationConfig = BackupManager::getReplicationConfig();

include __DIR__ . '/../../../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/../../../includes/admin-sidebar.php'; ?>

    <div class="admin-content">
        <div class="page-header">
            <h1>Backup & Recovery</h1>
            <p>Create backups, restore from point-in-time, and configure multi-region replication</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Status Card -->
        <div class="card mb-4">
            <div class="card-header">
                <h2>Backup Status</h2>
            </div>
            <div class="card-body">
                <div class="status-grid">
                    <div class="status-item">
                        <span class="status-label">Total Backups</span>
                        <span class="status-value"><?= $status['total_backups'] ?></span>
                    </div>
                    <div class="status-item">
                        <span class="status-label">Latest Backup</span>
                        <span class="status-value"><?= $status['latest_backup'] ?? 'Never' ?></span>
                    </div>
                    <div class="status-item">
                        <span class="status-label">Last Backup Status</span>
                        <span class="status-value">
                            <?php if ($status['latest_backup_valid'] === true): ?>
                                <span class="badge badge-success">Valid</span>
                            <?php elseif ($status['latest_backup_valid'] === false): ?>
                                <span class="badge badge-danger">Invalid</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Unknown</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="status-item">
                        <span class="status-label">Replication</span>
                        <span class="status-value">
                            <?php if ($status['replication_enabled']): ?>
                                <span class="badge badge-success"><?= $status['replication_targets'] ?> target(s)</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Disabled</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <div class="mt-4">
                    <form method="post" style="display: inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="label" value="manual">
                        <button type="submit" class="btn btn-primary">Create Backup Now</button>
                    </form>
                    <form method="post" style="display: inline; margin-left: 0.5rem;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="verify_all">
                        <button type="submit" class="btn btn-secondary">Verify All Backups</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Backup List -->
        <div class="card mb-4">
            <div class="card-header">
                <h2>Available Backups</h2>
            </div>
            <div class="card-body">
                <?php if (empty($backups)): ?>
                    <p class="text-muted">No backups found. Create your first backup above.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Label</th>
                                <th>Size</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($backups as $backup): ?>
                                <tr>
                                    <td><?= htmlspecialchars($backup['timestamp']) ?></td>
                                    <td><?= htmlspecialchars($backup['label']) ?></td>
                                    <td><?= number_format($backup['size'] / 1024 / 1024, 2) ?> MB</td>
                                    <td>
                                        <?php if ($backup['exists']): ?>
                                            <span class="badge badge-success">Available</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">Missing</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($backup['exists']): ?>
                                            <form method="post" style="display: inline;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="verify">
                                                <input type="hidden" name="backup_path" value="<?= htmlspecialchars($backup['path']) ?>">
                                                <button type="submit" class="btn btn-sm">Verify</button>
                                            </form>
                                            <button type="button" class="btn btn-sm btn-warning"
                                                    onclick="showRestoreModal('<?= htmlspecialchars($backup['path']) ?>', '<?= htmlspecialchars($backup['timestamp']) ?>')">
                                                Restore
                                            </button>
                                            <form method="post" style="display: inline;"
                                                  onsubmit="return confirm('Delete this backup?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="backup_path" value="<?= htmlspecialchars($backup['path']) ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Cleanup -->
        <div class="card mb-4">
            <div class="card-header">
                <h2>Retention Policy</h2>
            </div>
            <div class="card-body">
                <form method="post" class="form-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cleanup">
                    <div class="form-group">
                        <label>Keep at least</label>
                        <input type="number" name="keep_count" value="10" min="1" max="100" class="form-control" style="width: 80px;">
                        <span>most recent backups</span>
                    </div>
                    <div class="form-group" style="margin-left: 1rem;">
                        <label>Delete backups older than</label>
                        <input type="number" name="keep_days" value="30" min="1" max="365" class="form-control" style="width: 80px;">
                        <span>days</span>
                    </div>
                    <button type="submit" class="btn btn-secondary" style="margin-left: 1rem;">Run Cleanup</button>
                </form>
            </div>
        </div>

        <!-- Multi-Region Replication -->
        <div class="card">
            <div class="card-header">
                <h2>Multi-Region Replication</h2>
            </div>
            <div class="card-body">
                <p class="text-muted">Configure secondary storage locations for automatic backup replication.</p>

                <form method="post" id="replication-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_replication">
                    <input type="hidden" name="target_count" id="target-count" value="<?= count($replicationConfig['targets'] ?? []) ?>">

                    <div id="replication-targets">
                        <?php
                        $targets = $replicationConfig['targets'] ?? [];
                        foreach ($targets as $i => $target):
                        ?>
                            <div class="replication-target" data-index="<?= $i ?>">
                                <h4>Target <?= $i + 1 ?>
                                    <button type="button" class="btn btn-sm btn-danger" onclick="removeTarget(<?= $i ?>)">Remove</button>
                                </h4>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Name</label>
                                        <input type="text" name="target_<?= $i ?>_name" value="<?= htmlspecialchars($target['name']) ?>" class="form-control" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Type</label>
                                        <select name="target_<?= $i ?>_type" class="form-control" onchange="toggleTargetFields(<?= $i ?>, this.value)">
                                            <option value="s3" <?= ($target['type'] ?? '') === 's3' ? 'selected' : '' ?>>S3 / S3-Compatible</option>
                                            <option value="sftp" <?= ($target['type'] ?? '') === 'sftp' ? 'selected' : '' ?>>SFTP</option>
                                            <option value="local" <?= ($target['type'] ?? '') === 'local' ? 'selected' : '' ?>>Local / Network Mount</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="target-fields s3-fields" style="<?= ($target['type'] ?? 's3') !== 's3' ? 'display:none' : '' ?>">
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Endpoint</label>
                                            <input type="text" name="target_<?= $i ?>_endpoint" value="<?= htmlspecialchars($target['config']['endpoint'] ?? '') ?>" class="form-control" placeholder="https://s3.amazonaws.com">
                                        </div>
                                        <div class="form-group">
                                            <label>Bucket</label>
                                            <input type="text" name="target_<?= $i ?>_bucket" value="<?= htmlspecialchars($target['config']['bucket'] ?? '') ?>" class="form-control">
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Access Key</label>
                                            <input type="text" name="target_<?= $i ?>_access_key" value="<?= htmlspecialchars($target['config']['access_key'] ?? '') ?>" class="form-control">
                                        </div>
                                        <div class="form-group">
                                            <label>Secret Key</label>
                                            <input type="password" name="target_<?= $i ?>_secret_key" value="<?= htmlspecialchars($target['config']['secret_key'] ?? '') ?>" class="form-control">
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Region</label>
                                            <input type="text" name="target_<?= $i ?>_region" value="<?= htmlspecialchars($target['config']['region'] ?? 'us-east-1') ?>" class="form-control">
                                        </div>
                                        <div class="form-group">
                                            <label>Prefix</label>
                                            <input type="text" name="target_<?= $i ?>_prefix" value="<?= htmlspecialchars($target['config']['prefix'] ?? 'silo-backups/') ?>" class="form-control">
                                        </div>
                                    </div>
                                </div>
                                <div class="target-fields sftp-fields" style="<?= ($target['type'] ?? '') !== 'sftp' ? 'display:none' : '' ?>">
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Host</label>
                                            <input type="text" name="target_<?= $i ?>_host" value="<?= htmlspecialchars($target['config']['host'] ?? '') ?>" class="form-control">
                                        </div>
                                        <div class="form-group">
                                            <label>Port</label>
                                            <input type="number" name="target_<?= $i ?>_port" value="<?= htmlspecialchars($target['config']['port'] ?? '22') ?>" class="form-control">
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Username</label>
                                            <input type="text" name="target_<?= $i ?>_username" value="<?= htmlspecialchars($target['config']['username'] ?? '') ?>" class="form-control">
                                        </div>
                                        <div class="form-group">
                                            <label>Password</label>
                                            <input type="password" name="target_<?= $i ?>_password" value="<?= htmlspecialchars($target['config']['password'] ?? '') ?>" class="form-control">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Remote Path</label>
                                        <input type="text" name="target_<?= $i ?>_path" value="<?= htmlspecialchars($target['config']['path'] ?? '/backups/') ?>" class="form-control">
                                    </div>
                                </div>
                                <div class="target-fields local-fields" style="<?= ($target['type'] ?? '') !== 'local' ? 'display:none' : '' ?>">
                                    <div class="form-group">
                                        <label>Local/Network Path</label>
                                        <input type="text" name="target_<?= $i ?>_path" value="<?= htmlspecialchars($target['config']['path'] ?? '/mnt/backup/') ?>" class="form-control" placeholder="/mnt/backup/">
                                    </div>
                                </div>
                                <hr>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" class="btn btn-secondary" onclick="addTarget()">Add Replication Target</button>
                    <button type="submit" class="btn btn-primary">Save Configuration</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Restore Modal -->
<div id="restore-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <h3>Restore from Backup</h3>
        <p class="text-danger">
            <strong>Warning:</strong> This will replace your current database with the backup.
            All changes made after <span id="restore-timestamp"></span> will be lost.
        </p>
        <p>A pre-restore backup will be created automatically.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="restore">
            <input type="hidden" name="backup_path" id="restore-path">
            <div class="form-group">
                <label>Type RESTORE to confirm:</label>
                <input type="text" name="confirm" class="form-control" required autocomplete="off">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeRestoreModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">Restore Database</button>
            </div>
        </form>
    </div>
</div>

<style>
/* Page-specific styles for replication targets */
.replication-target {
    background: var(--color-surface-hover);
    padding: 1rem;
    border-radius: var(--radius);
    margin-bottom: 1rem;
}

.replication-target h4 {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    font-size: 1rem;
}

.replication-target hr {
    border: none;
    border-top: 1px solid var(--color-border);
    margin: 1rem 0 0 0;
}

.form-group {
    margin-bottom: 1rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
}
</style>

<script>
let targetIndex = <?= count($replicationConfig['targets'] ?? []) ?>;

function showRestoreModal(path, timestamp) {
    document.getElementById('restore-path').value = path;
    document.getElementById('restore-timestamp').textContent = timestamp;
    document.getElementById('restore-modal').style.display = 'flex';
}

function closeRestoreModal() {
    document.getElementById('restore-modal').style.display = 'none';
}

function addTarget() {
    const container = document.getElementById('replication-targets');
    const i = targetIndex++;
    document.getElementById('target-count').value = targetIndex;

    const html = `
        <div class="replication-target" data-index="${i}">
            <h4>Target ${i + 1}
                <button type="button" class="btn btn-sm btn-danger" onclick="removeTarget(${i})">Remove</button>
            </h4>
            <div class="form-row">
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" name="target_${i}_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select name="target_${i}_type" class="form-control" onchange="toggleTargetFields(${i}, this.value)">
                        <option value="s3">S3 / S3-Compatible</option>
                        <option value="sftp">SFTP</option>
                        <option value="local">Local / Network Mount</option>
                    </select>
                </div>
            </div>
            <div class="target-fields s3-fields">
                <div class="form-row">
                    <div class="form-group">
                        <label>Endpoint</label>
                        <input type="text" name="target_${i}_endpoint" class="form-control" placeholder="https://s3.amazonaws.com">
                    </div>
                    <div class="form-group">
                        <label>Bucket</label>
                        <input type="text" name="target_${i}_bucket" class="form-control">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Access Key</label>
                        <input type="text" name="target_${i}_access_key" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Secret Key</label>
                        <input type="password" name="target_${i}_secret_key" class="form-control">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Region</label>
                        <input type="text" name="target_${i}_region" value="us-east-1" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Prefix</label>
                        <input type="text" name="target_${i}_prefix" value="silo-backups/" class="form-control">
                    </div>
                </div>
            </div>
            <div class="target-fields sftp-fields" style="display: none;">
                <div class="form-row">
                    <div class="form-group">
                        <label>Host</label>
                        <input type="text" name="target_${i}_host" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Port</label>
                        <input type="number" name="target_${i}_port" value="22" class="form-control">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="target_${i}_username" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="target_${i}_password" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label>Remote Path</label>
                    <input type="text" name="target_${i}_path" value="/backups/" class="form-control">
                </div>
            </div>
            <div class="target-fields local-fields" style="display: none;">
                <div class="form-group">
                    <label>Local/Network Path</label>
                    <input type="text" name="target_${i}_path" class="form-control" placeholder="/mnt/backup/">
                </div>
            </div>
            <hr>
        </div>
    `;

    container.insertAdjacentHTML('beforeend', html);
}

function removeTarget(index) {
    const target = document.querySelector(`.replication-target[data-index="${index}"]`);
    if (target) {
        target.remove();
    }
}

function toggleTargetFields(index, type) {
    const target = document.querySelector(`.replication-target[data-index="${index}"]`);
    if (!target) return;

    target.querySelectorAll('.target-fields').forEach(el => el.style.display = 'none');
    target.querySelector(`.${type}-fields`).style.display = 'block';
}
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
