<?php
/**
 * OpenSCAD Parameter Editor Page
 *
 * Displays parsed parameters from a .scad file as a form,
 * allows editing, previewing, and exporting to STL/3MF.
 */
require_once __DIR__ . '/../../../includes/config.php';

if (!isLoggedIn()) {
    header('Location: ' . route('login'));
    exit;
}

$partId = (int)($params['partId'] ?? 0);
if ($partId <= 0) {
    http_response_code(404);
    echo 'Part not found';
    exit;
}

$db = getDB();
$stmt = $db->prepare('SELECT m.*, p.name as parent_name, p.id as parent_model_id FROM models m LEFT JOIN models p ON m.parent_id = p.id WHERE m.id = :id');
$stmt->bindValue(':id', $partId, PDO::PARAM_INT);
$result = $stmt->execute();
$part = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$part || strtolower($part['file_type'] ?? '') !== 'scad') {
    http_response_code(404);
    echo 'Not a .scad file';
    exit;
}

// Get absolute file path
require_once __DIR__ . '/../../../includes/dedup.php';
$filePath = getAbsoluteFilePath($part);

if (!$filePath || !file_exists($filePath)) {
    http_response_code(404);
    echo 'File not found on disk';
    exit;
}

// Parse the .scad file
$parsed = OpenScadParser::parse($filePath);
$parameters = $parsed['parameters'];
$sections = $parsed['sections'];
$source = $parsed['source'];

// Get plugin settings
$pm = PluginManager::getInstance();
$settings = $pm->getSettings('openscad');
$defaultFormat = $settings['default_export_format'] ?? 'stl';

// Check if OpenSCAD is available
$renderer = new OpenScadRenderer($settings);
$isAvailable = $renderer->isAvailable();
$version = $isAvailable ? $renderer->getVersion() : null;

$pageTitle = 'OpenSCAD Editor — ' . htmlspecialchars($part['name']);
$activePage = '';
$needsViewer = false;

require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="page-container">
    <div class="page-header">
        <div class="model-breadcrumb">
            <a href="<?= route('home') ?>">Home</a>
            <span class="breadcrumb-sep">/</span>
            <?php if ($part['parent_model_id']): ?>
            <a href="<?= route('model.show', ['id' => $part['parent_model_id']]) ?>"><?= htmlspecialchars($part['parent_name']) ?></a>
            <span class="breadcrumb-sep">/</span>
            <?php endif; ?>
            <span class="breadcrumb-current"><?= htmlspecialchars($part['name']) ?>.scad</span>
        </div>
        <h1><?= htmlspecialchars($part['name']) ?></h1>
        <p>Edit OpenSCAD parameters and export to STL or 3MF</p>
    </div>

    <?php if (!$isAvailable): ?>
    <div class="alert alert-warning">
        OpenSCAD is not available on this server. Install OpenSCAD or enable the Docker container in your docker-compose.yml to use rendering features.
    </div>
    <?php endif; ?>

    <div class="openscad-layout">
        <!-- Parameter Form -->
        <div class="openscad-params">
            <form id="openscad-form" method="post" action="/openscad/render/<?= $partId ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="part_id" value="<?= $partId ?>">

                <?php if (empty($parameters)): ?>
                <div class="section-card">
                    <p class="text-muted">No customizable parameters found in this file.</p>
                    <p class="form-help">OpenSCAD parameters are detected from variable assignments with comments, e.g.:<br>
                    <code>wall_thickness = 2; // [1:0.5:5]</code></p>
                </div>
                <?php else: ?>
                    <?php foreach ($sections as $section): ?>
                    <details class="settings-section" open>
                        <summary><h2><?= htmlspecialchars($section) ?></h2></summary>
                        <?php foreach ($parameters as $name => $param):
                            if ($param['section'] !== $section) continue;
                            $inputId = 'param-' . htmlspecialchars($name);
                            $inputName = 'params[' . htmlspecialchars($name) . ']';
                        ?>
                        <div class="form-group">
                            <label for="<?= $inputId ?>"><?= htmlspecialchars($name) ?></label>

                            <?php if ($param['type'] === 'checkbox'): ?>
                            <label class="toggle-label">
                                <input type="hidden" name="<?= $inputName ?>" value="0">
                                <input type="checkbox" id="<?= $inputId ?>" name="<?= $inputName ?>" value="1" <?= $param['value'] ? 'checked' : '' ?>>
                                <span class="toggle-switch"></span>
                                <span><?= htmlspecialchars($param['description'] ?: $name) ?></span>
                            </label>

                            <?php elseif ($param['type'] === 'select' && !empty($param['options'])): ?>
                            <select id="<?= $inputId ?>" name="<?= $inputName ?>" class="form-input">
                                <?php foreach ($param['options'] as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>" <?= (string)$param['value'] === (string)$opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <?php elseif ($param['type'] === 'slider'): ?>
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <input type="range" id="<?= $inputId ?>" name="<?= $inputName ?>"
                                    value="<?= htmlspecialchars((string)$param['value']) ?>"
                                    min="<?= $param['min'] ?? 0 ?>"
                                    max="<?= $param['max'] ?? 100 ?>"
                                    step="<?= $param['step'] ?? 1 ?>"
                                    style="flex: 1;"
                                    oninput="document.getElementById('<?= $inputId ?>-val').textContent = this.value">
                                <span id="<?= $inputId ?>-val" style="min-width: 3rem; text-align: right; font-family: var(--font-mono);"><?= htmlspecialchars((string)$param['value']) ?></span>
                            </div>

                            <?php elseif ($param['type'] === 'number'): ?>
                            <input type="number" id="<?= $inputId ?>" name="<?= $inputName ?>" class="form-input"
                                value="<?= htmlspecialchars((string)$param['value']) ?>"
                                <?= $param['min'] !== null ? 'min="' . $param['min'] . '"' : '' ?>
                                <?= $param['max'] !== null ? 'max="' . $param['max'] . '"' : '' ?>
                                <?= $param['step'] !== null ? 'step="' . $param['step'] . '"' : '' ?>>

                            <?php elseif ($param['type'] === 'vector'): ?>
                            <?php $vecVals = is_array($param['value']) ? $param['value'] : [0, 0, 0]; ?>
                            <div style="display: flex; gap: 0.5rem;">
                                <?php foreach ($vecVals as $vi => $vv): ?>
                                <input type="number" name="<?= $inputName ?>[<?= $vi ?>]" class="form-input" value="<?= htmlspecialchars((string)$vv) ?>" step="any" style="flex: 1;">
                                <?php endforeach; ?>
                            </div>

                            <?php else: // text ?>
                            <input type="text" id="<?= $inputId ?>" name="<?= $inputName ?>" class="form-input"
                                value="<?= htmlspecialchars((string)$param['value']) ?>">
                            <?php endif; ?>

                            <?php if ($param['description']): ?>
                            <p class="form-help"><?= htmlspecialchars($param['description']) ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </details>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Export Options -->
                <details class="settings-section" open>
                    <summary><h2>Export</h2></summary>
                    <div class="form-group">
                        <label for="export-format">Output Format</label>
                        <select id="export-format" name="format" class="form-input">
                            <option value="stl" <?= $defaultFormat === 'stl' ? 'selected' : '' ?>>STL</option>
                            <option value="3mf" <?= $defaultFormat === '3mf' ? 'selected' : '' ?>>3MF</option>
                        </select>
                    </div>
                    <div class="form-actions" style="border-top: none; padding-top: 0;">
                        <?php if ($isAvailable): ?>
                        <button type="submit" class="btn btn-primary" id="render-btn">Render & Download</button>
                        <button type="button" class="btn btn-secondary" id="preview-btn" onclick="previewRender()">Preview</button>
                        <?php else: ?>
                        <button type="button" class="btn btn-secondary" disabled>OpenSCAD not available</button>
                        <?php endif; ?>
                        <a href="<?= $part['parent_model_id'] ? route('model.show', ['id' => $part['parent_model_id']]) : route('home') ?>" class="btn btn-secondary">Back</a>
                    </div>
                </details>
            </form>
        </div>

        <!-- Source Code Viewer -->
        <div class="openscad-source">
            <details class="settings-section">
                <summary><h2>Source Code</h2></summary>
                <pre class="code-block" style="max-height: 500px; overflow: auto; white-space: pre; font-size: 0.8rem;"><?= htmlspecialchars($source) ?></pre>
            </details>

            <?php if ($version): ?>
            <p class="text-muted" style="margin-top: 0.5rem; font-size: 0.8rem;">OpenSCAD <?= htmlspecialchars($version) ?></p>
            <?php endif; ?>

            <!-- Preview image container -->
            <div id="preview-container" style="display: none; margin-top: 1rem;">
                <div class="section-card">
                    <h3 style="margin-bottom: 0.5rem;">Preview</h3>
                    <div id="preview-loading" style="display: none; text-align: center; padding: 2rem; color: var(--color-text-muted);">Rendering preview...</div>
                    <img id="preview-image" style="display: none; width: 100%; border-radius: var(--radius);" alt="OpenSCAD preview">
                    <p id="preview-error" style="display: none; color: var(--color-danger);"></p>
                    <p id="preview-time" style="display: none; font-size: 0.8rem; color: var(--color-text-muted); margin-top: 0.5rem;"></p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.openscad-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
    align-items: start;
}

.openscad-params {
    position: sticky;
    top: 5rem;
}

@media (max-width: 1024px) {
    .openscad-layout {
        grid-template-columns: 1fr;
    }
    .openscad-params {
        position: static;
    }
}

input[type="range"] {
    -webkit-appearance: none;
    height: 6px;
    background: var(--color-border);
    border-radius: 3px;
    outline: none;
}

input[type="range"]::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 18px;
    height: 18px;
    background: var(--color-primary);
    border-radius: 50%;
    cursor: pointer;
}
</style>

<script>
async function previewRender() {
    const form = document.getElementById('openscad-form');
    const container = document.getElementById('preview-container');
    const loading = document.getElementById('preview-loading');
    const img = document.getElementById('preview-image');
    const error = document.getElementById('preview-error');
    const timeEl = document.getElementById('preview-time');

    container.style.display = 'block';
    loading.style.display = 'block';
    img.style.display = 'none';
    error.style.display = 'none';
    timeEl.style.display = 'none';

    try {
        const formData = new FormData(form);
        const response = await fetch('/openscad/preview/<?= $partId ?>', {
            method: 'POST',
            body: formData,
        });
        const data = await response.json();

        loading.style.display = 'none';

        if (data.success) {
            img.src = data.image_url + '?t=' + Date.now();
            img.style.display = 'block';
            timeEl.textContent = 'Rendered in ' + data.duration_ms + 'ms';
            timeEl.style.display = 'block';
        } else {
            error.textContent = data.error || 'Preview failed';
            error.style.display = 'block';
        }
    } catch (err) {
        loading.style.display = 'none';
        error.textContent = 'Failed to connect to server';
        error.style.display = 'block';
    }
}
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
