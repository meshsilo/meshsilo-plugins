<?php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/dedup.php';
require_once __DIR__ . '/../../../includes/features.php';

// Require feature to be enabled
requireFeature('print_queue');

$pageTitle = 'Print Queue';
$activePage = 'print-queue';

// Require login
if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = '/print-queue';
    header('Location: ' . route('login'));
    exit;
}

$db = getDB();
$user = getCurrentUser();

// Get user's print queue
$queue = getUserPrintQueue($user['id'], 100);

// Enhance models with preview data
foreach ($queue as &$model) {
    $modelData = $db->query("SELECT id, part_count, file_type FROM models WHERE id = " . (int)$model['model_id'])->fetchArray(PDO::FETCH_ASSOC);
    if ($modelData && $modelData['part_count'] > 0) {
        $partStmt = $db->prepare('SELECT id, file_type FROM models WHERE parent_id = :parent_id ORDER BY original_path ASC LIMIT 1');
        $partStmt->bindValue(':parent_id', $model['model_id'], PDO::PARAM_INT);
        $partResult = $partStmt->execute();
        $firstPart = $partResult->fetchArray(PDO::FETCH_ASSOC);
        if ($firstPart) {
            $model['preview_path'] = '/actions/preview?id=' . $firstPart['id'];
            $model['preview_type'] = $firstPart['file_type'];
        }
    } elseif ($modelData) {
        $model['preview_path'] = '/actions/preview?id=' . $modelData['id'];
        $model['preview_type'] = $modelData['file_type'];
    }
}
unset($model);

require_once __DIR__ . '/../../../includes/header.php';
?>

        <div class="page-container-wide">
            <div class="page-header">
                <h1>Print Queue</h1>
                <p><?= count($queue) . ' model' . (count($queue) !== 1 ? 's' : '') . ' to print' ?></p>
            </div>

            <?php if (empty($queue)): ?>
                <p class="text-muted" style="text-align: center; padding: 3rem;">
                    Your print queue is empty.<br>
                    Click the printer icon on any model to add it to your queue.
                </p>
            <?php else: ?>
                <div class="print-queue-actions" style="margin-bottom: 1.5rem; display: flex; gap: 1rem; flex-wrap: wrap;">
                    <button type="button" class="btn btn-primary" onclick="downloadSelected()">
                        Download Selected as ZIP
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="clearQueue()">
                        Clear Queue
                    </button>
                    <label style="display: flex; align-items: center; gap: 0.5rem;">
                        <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                        Select All
                    </label>
                </div>

                <div class="queue-list">
                    <?php foreach ($queue as $model): ?>
                    <div class="queue-item" data-model-id="<?= $model['model_id'] ?>">
                        <label class="queue-checkbox">
                            <input type="checkbox" name="selected[]" value="<?= $model['model_id'] ?>">
                        </label>
                        <div class="queue-priority">
                            <select class="priority-select" onchange="updatePriority(<?= $model['model_id'] ?>, this.value)">
                                <option value="0" <?= $model['priority'] == 0 ? 'selected' : '' ?>>Normal</option>
                                <option value="1" <?= $model['priority'] == 1 ? 'selected' : '' ?>>High</option>
                                <option value="2" <?= $model['priority'] == 2 ? 'selected' : '' ?>>Urgent</option>
                            </select>
                        </div>
                        <div class="queue-thumbnail model-thumbnail"
                            <?php if (!empty($model['preview_path'])): ?>
                            data-model-url="<?= htmlspecialchars($model['preview_path']) ?>"
                            data-file-type="<?= htmlspecialchars($model['preview_type']) ?>"
                            <?php endif; ?>>
                        </div>
                        <div class="queue-info">
                            <a href="model.php?id=<?= $model['model_id'] ?>" class="queue-title">
                                <?= htmlspecialchars($model['model_name']) ?>
                            </a>
                            <?php if ($model['category_name']): ?>
                            <span class="queue-category"><?= htmlspecialchars($model['category_name']) ?></span>
                            <?php endif; ?>
                            <span class="queue-date">Added <?= date('M j, Y', strtotime($model['added_at'])) ?></span>
                        </div>
                        <div class="queue-actions">
                            <a href="actions/download.php?id=<?= $model['model_id'] ?>" class="btn btn-small" title="Download">
                                Download
                            </a>
                            <button type="button" class="btn btn-small btn-danger" onclick="removeFromQueue(<?= $model['model_id'] ?>, this)" title="Remove">
                                Remove
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <script>
        async function removeFromQueue(modelId, btn) {
            try {
                const response = await fetch('/actions/print-queue', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=remove&model_id=' + modelId
                });
                const data = await response.json();
                if (data.success) {
                    btn.closest('.queue-item').remove();
                    updateQueueCount();
                }
            } catch (err) {
                console.error('Failed to remove from queue:', err);
            }
        }

        async function updatePriority(modelId, priority) {
            try {
                await fetch('/actions/print-queue', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=priority&model_id=' + modelId + '&priority=' + priority
                });
            } catch (err) {
                console.error('Failed to update priority:', err);
            }
        }

        async function clearQueue() {
            if (!confirm('Remove all models from your print queue?')) return;
            try {
                const response = await fetch('/actions/print-queue', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=clear'
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                }
            } catch (err) {
                console.error('Failed to clear queue:', err);
            }
        }

        function toggleSelectAll(checkbox) {
            document.querySelectorAll('.queue-item input[type="checkbox"]').forEach(cb => {
                cb.checked = checkbox.checked;
            });
        }

        function downloadSelected() {
            const selected = Array.from(document.querySelectorAll('.queue-item input[type="checkbox"]:checked'))
                .map(cb => cb.value);
            if (selected.length === 0) {
                alert('Please select models to download');
                return;
            }
            window.location = 'actions/batch-download.php?ids=' + selected.join(',');
        }

        function updateQueueCount() {
            const remaining = document.querySelectorAll('.queue-item').length;
            const countEl = document.querySelector('.page-header p');
            countEl.textContent = remaining + ' model' + (remaining !== 1 ? 's' : '') + ' to print';
            if (remaining === 0) {
                location.reload();
            }
        }
        </script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
