<?php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/features.php';

// Require feature to be enabled
requireFeature('printers');

$user = getCurrentUser();
$pageTitle = 'My Printers';

// Get user's printers
$db = getDB();
$stmt = $db->prepare('SELECT * FROM printers WHERE user_id = :user_id ORDER BY is_default DESC, name ASC');
$stmt->execute([':user_id' => $user['id']]);
$printers = [];
while ($row = $stmt->fetch()) {
    $printers[] = $row;
}

require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>My Printers</h1>
        <button class="btn btn-primary" onclick="showAddPrinterModal()">Add Printer</button>
    </div>

    <?php if (empty($printers)): ?>
    <div class="empty-state">
        <p>You haven't added any printers yet.</p>
        <p>Add your printers to check if models fit on your print bed.</p>
        <button class="btn btn-primary" onclick="showAddPrinterModal()">Add Your First Printer</button>
    </div>
    <?php else: ?>
    <div class="printers-grid">
        <?php foreach ($printers as $printer): ?>
        <div class="printer-card <?= $printer['is_default'] ? 'is-default' : '' ?>">
            <?php if ($printer['is_default']): ?>
            <span class="default-badge">Default</span>
            <?php endif; ?>

            <h3><?= htmlspecialchars($printer['name']) ?></h3>

            <?php if ($printer['manufacturer'] || $printer['model']): ?>
            <p class="printer-model">
                <?= htmlspecialchars(trim($printer['manufacturer'] . ' ' . $printer['model'])) ?>
            </p>
            <?php endif; ?>

            <div class="printer-specs">
                <div class="spec">
                    <span class="spec-label">Print Type</span>
                    <span class="spec-value"><?= strtoupper($printer['print_type'] ?? 'FDM') ?></span>
                </div>
                <?php if ($printer['bed_x'] && $printer['bed_y'] && $printer['bed_z']): ?>
                <div class="spec">
                    <span class="spec-label">Build Volume</span>
                    <span class="spec-value">
                        <?= $printer['bed_x'] ?> x <?= $printer['bed_y'] ?> x <?= $printer['bed_z'] ?> mm
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($printer['notes']): ?>
            <p class="printer-notes"><?= htmlspecialchars($printer['notes']) ?></p>
            <?php endif; ?>

            <div class="printer-actions">
                <?php if (!$printer['is_default']): ?>
                <form method="post" action="/actions/printer" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="set_default">
                    <input type="hidden" name="printer_id" value="<?= $printer['id'] ?>">
                    <button type="submit" class="btn btn-secondary btn-sm">Set as Default</button>
                </form>
                <?php endif; ?>
                <button class="btn btn-secondary btn-sm" onclick="editPrinter(<?= htmlspecialchars(json_encode($printer)) ?>)">Edit</button>
                <button class="btn btn-danger btn-sm" onclick="deletePrinter(<?= $printer['id'] ?>)">Delete</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Add/Edit Printer Modal -->
<div id="printer-modal" class="modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modal-title">Add Printer</h2>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form id="printer-form" method="post" action="/actions/printer">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="form-action" value="create">
            <input type="hidden" name="printer_id" id="printer-id">

            <div class="form-group">
                <label for="name">Printer Name *</label>
                <input type="text" id="name" name="name" required placeholder="e.g., My Ender 3">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="manufacturer">Manufacturer</label>
                    <input type="text" id="manufacturer" name="manufacturer" placeholder="e.g., Creality">
                </div>
                <div class="form-group">
                    <label for="model">Model</label>
                    <input type="text" id="model" name="model" placeholder="e.g., Ender 3 V2">
                </div>
            </div>

            <div class="form-group">
                <label for="print_type">Print Type</label>
                <select id="print_type" name="print_type">
                    <option value="fdm">FDM</option>
                    <option value="sla">SLA</option>
                    <option value="sls">SLS</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <div class="form-group">
                <label>Build Volume (mm)</label>
                <div class="form-row three-col">
                    <div>
                        <input type="number" id="bed_x" name="bed_x" step="0.1" placeholder="X (width)">
                    </div>
                    <div>
                        <input type="number" id="bed_y" name="bed_y" step="0.1" placeholder="Y (depth)">
                    </div>
                    <div>
                        <input type="number" id="bed_z" name="bed_z" step="0.1" placeholder="Z (height)">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="3" placeholder="Any notes about this printer..."></textarea>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Printer</button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden form for printer deletion (carries CSRF token) -->
<form id="delete-printer-form" method="post" action="/actions/printer" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="printer_id" id="delete-printer-id">
</form>

<style>
.printers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.5rem;
}
.printer-card {
    background: var(--bg-secondary);
    border-radius: 12px;
    padding: 1.5rem;
    position: relative;
}
.printer-card.is-default {
    border: 2px solid var(--primary);
}
.default-badge {
    position: absolute;
    top: 1rem;
    right: 1rem;
    background: var(--primary);
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
}
.printer-card h3 {
    margin: 0 0 0.5rem 0;
}
.printer-model {
    color: var(--text-muted);
    margin: 0 0 1rem 0;
}
.printer-specs {
    margin-bottom: 1rem;
}
.spec {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--border);
}
.spec:last-child {
    border-bottom: none;
}
.spec-label {
    color: var(--text-muted);
}
.spec-value {
    font-weight: 500;
}
.printer-notes {
    background: var(--bg-tertiary);
    padding: 0.75rem;
    border-radius: 6px;
    font-size: 0.9rem;
    margin-bottom: 1rem;
}
.printer-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}
.form-row.three-col {
    grid-template-columns: 1fr 1fr 1fr;
}
</style>

<script>
function showAddPrinterModal() {
    document.getElementById('modal-title').textContent = 'Add Printer';
    document.getElementById('form-action').value = 'create';
    document.getElementById('printer-form').reset();
    document.getElementById('printer-modal').style.display = 'flex';
}

function editPrinter(printer) {
    document.getElementById('modal-title').textContent = 'Edit Printer';
    document.getElementById('form-action').value = 'update';
    document.getElementById('printer-id').value = printer.id;
    document.getElementById('name').value = printer.name || '';
    document.getElementById('manufacturer').value = printer.manufacturer || '';
    document.getElementById('model').value = printer.model || '';
    document.getElementById('print_type').value = printer.print_type || 'fdm';
    document.getElementById('bed_x').value = printer.bed_x || '';
    document.getElementById('bed_y').value = printer.bed_y || '';
    document.getElementById('bed_z').value = printer.bed_z || '';
    document.getElementById('notes').value = printer.notes || '';
    document.getElementById('printer-modal').style.display = 'flex';
}

function deletePrinter(printerId) {
    if (!confirm('Delete this printer?')) return;

    document.getElementById('delete-printer-id').value = printerId;
    document.getElementById('delete-printer-form').submit();
}

function closeModal() {
    document.getElementById('printer-modal').style.display = 'none';
}

// Close modal on backdrop click
document.getElementById('printer-modal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// Handle form submission
document.getElementById('printer-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);

    fetch('/actions/printer', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.error || 'Error saving printer');
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
