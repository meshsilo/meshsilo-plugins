<?php
/**
 * 3D Printing Tools Plugin - Boot File
 *
 * Loads all printing libraries and registers routes, pages, actions, and filters.
 * Available variables: $plugin (PluginManager), $pluginDir (string), $pluginMeta (array)
 */

// Load libraries
require_once $pluginDir . '/lib/http.php';
require_once $pluginDir . '/lib/gcode.php';
require_once $pluginDir . '/lib/slicers.php';
require_once $pluginDir . '/lib/VolumeCalculator.php';
require_once $pluginDir . '/lib/MeshAnalyzer.php';

// Register action routes
$plugin->addRoute('POST', '/actions/print-photo', ['file' => $pluginDir . '/actions/print-photo.php'], 'actions.print-photo');
$plugin->addRoute('GET', '/actions/print-photo', ['file' => $pluginDir . '/actions/print-photo.php'], 'actions.print-photo.get');
$plugin->addRoute('POST', '/actions/cost-calculator', ['file' => $pluginDir . '/actions/cost-calculator.php'], 'actions.cost-calculator');
$plugin->addRoute('GET', '/actions/cost-calculator', ['file' => $pluginDir . '/actions/cost-calculator.php'], 'actions.cost-calculator.get');
$plugin->addRoute('POST', '/actions/mesh', ['file' => $pluginDir . '/actions/mesh.php'], 'actions.mesh');
$plugin->addRoute('GET', '/actions/mesh', ['file' => $pluginDir . '/actions/mesh.php'], 'actions.mesh.get');
$plugin->addRoute('POST', '/actions/slicer', ['file' => $pluginDir . '/actions/slicer.php'], 'actions.slicer');
$plugin->addRoute('GET', '/actions/slicer', ['file' => $pluginDir . '/actions/slicer.php'], 'actions.slicer.get');

// The slicer download action is token-authenticated and must be reachable by a
// desktop slicer that has no browser session, so mark the route public. The
// 'urls' action on the same route still enforces login itself.
$plugin->addFilter('public_routes', function($routes) {
    $routes[] = '/actions/slicer';
    return $routes;
});

// Register assets
$plugin->addStylesheet('printing', 'printing.css');
$plugin->addScript('printing', 'printing.js');

// Register feature toggles matching the core features.php format
$plugin->addFilter('available_features', function($features) {
    $printFeatures = [
        'print_history' => [
            'name' => 'Print History',
            'description' => 'Track print jobs with filament usage and ratings',
            'icon' => 'history',
            'category' => 'Printing',
            'default' => true,
        ],
        'slicer_integration' => [
            'name' => 'Slicer Integration',
            'description' => 'Open models directly in configured slicer applications',
            'icon' => 'sliders',
            'category' => 'Integration',
            'default' => true,
        ],
        'mesh_analysis' => [
            'name' => 'Mesh Analysis',
            'description' => 'Analyze STL meshes for holes, non-manifold edges, and inverted normals (optional admesh repair)',
            'icon' => 'activity',
            'category' => 'Printing',
            'default' => true,
        ],
    ];

    foreach ($printFeatures as $key => $feature) {
        if (!isset($features[$key])) {
            $features[$key] = $feature;
        }
    }
    return $features;
});

// Model header actions - "Send to slicer" dropdown for a sliceable model
$plugin->addFilter('model_header_actions', function($html, $model) {
    if (function_exists('isFeatureEnabled') && !isFeatureEnabled('slicer_integration')) return $html;
    $type = strtolower($model['file_type'] ?? '');
    if (!in_array($type, ['stl', '3mf', 'obj', 'step', 'stp'], true)) return $html;
    if (!function_exists('getEnabledSlicers')) return $html;
    $slicers = getEnabledSlicers();
    if (empty($slicers)) return $html;

    $items = '';
    foreach ($slicers as $key => $s) {
        $items .= '<a href="#" class="dropdown-item slicer-link" data-slicer="' . htmlspecialchars($key)
            . '" data-model-id="' . (int)$model['id'] . '">' . htmlspecialchars($s['name']) . '</a>';
    }
    $html .= '<div class="dropdown slicer-dropdown">'
        . '<button type="button" class="btn btn-secondary slicer-toggle">Send to slicer <span class="dropdown-arrow">&#9662;</span></button>'
        . '<div class="dropdown-menu dropdown-menu-right">' . $items . '</div></div>';
    return $html;
});

// Model detail tabs - GCode metadata
$plugin->addFilter('model_detail_tabs', function($tabs, $model) {
    if (($model['file_type'] ?? '') !== 'gcode') return $tabs;

    $gcodeMetadata = function_exists('getGCodeMetadata') ? getGCodeMetadata($model['id']) : null;
    if (!$gcodeMetadata || empty(array_filter($gcodeMetadata))) return $tabs;

    $content = '<div class="gcode-metadata"><div class="gcode-stats">';

    if (!empty($gcodeMetadata['print_time_formatted'])) {
        $content .= '<div class="gcode-stat"><span class="gcode-label">Print Time</span><span class="gcode-value">' . htmlspecialchars($gcodeMetadata['print_time_formatted']) . '</span></div>';
    }
    if (!empty($gcodeMetadata['filament_used_m'])) {
        $content .= '<div class="gcode-stat"><span class="gcode-label">Filament</span><span class="gcode-value">' . htmlspecialchars(number_format($gcodeMetadata['filament_used_m'], 2)) . ' m';
        if (!empty($gcodeMetadata['filament_used_g'])) {
            $content .= ' (' . htmlspecialchars(number_format($gcodeMetadata['filament_used_g'], 1)) . ' g)';
        }
        $content .= '</span></div>';
    }
    if (!empty($gcodeMetadata['layer_height'])) {
        $content .= '<div class="gcode-stat"><span class="gcode-label">Layer Height</span><span class="gcode-value">' . htmlspecialchars($gcodeMetadata['layer_height']) . ' mm</span></div>';
    }
    if (!empty($gcodeMetadata['layer_count'])) {
        $content .= '<div class="gcode-stat"><span class="gcode-label">Layers</span><span class="gcode-value">' . htmlspecialchars(number_format($gcodeMetadata['layer_count'])) . '</span></div>';
    }
    if (!empty($gcodeMetadata['hotend_temp'])) {
        $content .= '<div class="gcode-stat"><span class="gcode-label">Hotend</span><span class="gcode-value">' . htmlspecialchars($gcodeMetadata['hotend_temp']) . '&deg;C</span></div>';
    }
    if (!empty($gcodeMetadata['bed_temp'])) {
        $content .= '<div class="gcode-stat"><span class="gcode-label">Bed</span><span class="gcode-value">' . htmlspecialchars($gcodeMetadata['bed_temp']) . '&deg;C</span></div>';
    }
    if (!empty($gcodeMetadata['infill'])) {
        $content .= '<div class="gcode-stat"><span class="gcode-label">Infill</span><span class="gcode-value">' . htmlspecialchars($gcodeMetadata['infill']) . '</span></div>';
    }
    if (!empty($gcodeMetadata['slicer'])) {
        $slicerText = htmlspecialchars($gcodeMetadata['slicer']);
        if (!empty($gcodeMetadata['slicer_version'])) $slicerText .= ' ' . htmlspecialchars($gcodeMetadata['slicer_version']);
        $content .= '<div class="gcode-stat"><span class="gcode-label">Slicer</span><span class="gcode-value">' . $slicerText . '</span></div>';
    }

    $content .= '</div></div>';

    $tabs[] = ['label' => 'Print Information', 'content' => $content];
    return $tabs;
});

// Model detail sidebar - volume/cost info
$plugin->addFilter('model_detail_sidebar', function($html, $model) {
    if (in_array($model['file_type'] ?? '', ['gcode'])) return $html;

    $volume = class_exists('VolumeCalculator') ? VolumeCalculator::getModelVolume($model) : null;
    if (!$volume) return $html;

    $cost = VolumeCalculator::estimateCost($volume);
    $html .= '<div class="print-cost-info">';
    $html .= '<strong>Volume:</strong> ' . number_format($volume, 2) . ' cm&sup3;<br>';
    if ($cost) {
        $html .= '<strong>Est. Cost:</strong> $' . number_format($cost['estimated_cost'], 2);
    }
    $html .= '</div>';
    return $html;
});

// Part row actions - slicer dropdown, print type, printed status
$plugin->addFilter('part_row_actions', function($html, $part) {
    $output = '';

    // Print type badge
    if (!empty($part['print_type'])) {
        $output .= '<span class="print-type-badge print-type-' . htmlspecialchars($part['print_type']) . '">' . strtoupper(htmlspecialchars($part['print_type'])) . '</span>';
    }

    // Printed badge
    if (!empty($part['is_printed'])) {
        $output .= '<span class="printed-badge">Printed</span>';
    }

    // Slicer dropdown
    if (function_exists('isFeatureEnabled') && isFeatureEnabled('slicer_integration') && function_exists('getSlicersForFormat')) {
        $slicers = getSlicersForFormat($part['file_type'] ?? '');
        if (!empty($slicers)) {
            $output .= '<div class="dropdown slicer-dropdown">';
            $output .= '<button type="button" class="btn btn-small btn-secondary slicer-toggle">Open in <span class="dropdown-arrow">&#9662;</span></button>';
            $output .= '<div class="dropdown-menu dropdown-menu-right">';
            foreach ($slicers as $key => $slicer) {
                $output .= '<a href="#" class="dropdown-item slicer-link" data-slicer="' . htmlspecialchars($key) . '" data-part-id="' . (int)$part['id'] . '" data-has-protocol="' . (!empty($slicer['protocol']) ? '1' : '0') . '">' . htmlspecialchars($slicer['name']) . '</a>';
            }
            $output .= '</div></div>';
        }
    }

    return $html . $output;
});

// Admin settings sections - slicer configuration
$plugin->addFilter('admin_settings_sections', function($html) {
    if (!function_exists('getDefaultSlicers')) return $html;

    $allSlicers = getDefaultSlicers();
    $enabled = explode(',', getSetting('enabled_slicers', ''));

    $html .= '<details open><summary>Slicer Integration</summary><div class="settings-group">';
    $html .= '<p class="form-help">Select which slicer applications to show in the "Open in" menus.</p>';

    foreach ($allSlicers as $key => $slicer) {
        $checked = in_array($key, $enabled) ? ' checked' : '';
        $protocol = !empty($slicer['protocol']) ? ' <span class="badge">URL Protocol</span>' : '';
        $html .= '<label class="checkbox-label"><input type="checkbox" name="enabled_slicers[]" value="' . htmlspecialchars($key) . '"' . $checked . '><span>' . htmlspecialchars($slicer['name']) . $protocol . '</span></label>';
    }

    $html .= '</div></details>';
    return $html;
});

// Admin settings saved - save slicer config
$plugin->addFilter('admin_settings_saved', function($result, $post) {
    $slicers = $post['enabled_slicers'] ?? [];
    setSetting('enabled_slicers', implode(',', $slicers));
    return $result;
});

// Process GCode on upload
$plugin->addFilter('after_upload', function($result, $modelId, $data) {
    if (($data['file_type'] ?? '') === 'gcode' && function_exists('processGCodeFile')) {
        $model = getModelById($modelId);
        if ($model) {
            $filePath = getAbsoluteFilePath($model);
            if ($filePath && file_exists($filePath)) {
                processGCodeFile($modelId, $filePath);
            }
        }
    }
    return $result;
});

// Model detail tab - mesh analysis for STL models
$plugin->addFilter('model_detail_tabs', function($tabs, $model) {
    if (strtolower($model['file_type'] ?? '') !== 'stl') return $tabs;
    if (function_exists('isFeatureEnabled') && !isFeatureEnabled('mesh_analysis')) return $tabs;
    if (!class_exists('MeshAnalyzer')) return $tabs;

    $modelId = (int)$model['id'];
    $status = MeshAnalyzer::getMeshStatus($model);

    // The model row handed to this filter may predate the mesh columns; fetch
    // the current status directly so the summary is accurate after an analysis.
    if ($status === null && !array_key_exists('is_manifold', $model) && function_exists('getDB')) {
        try {
            $db = getDB();
            $stmt = $db->prepare('SELECT is_manifold, mesh_errors FROM models WHERE id = :id');
            $stmt->execute([':id' => $modelId]);
            $row = $stmt->fetch();
            if ($row) {
                $status = MeshAnalyzer::getMeshStatus($row);
            }
        } catch (\Throwable $e) {
            // Mesh columns not created yet - treat as never analyzed.
        }
    }

    $admesh = MeshAnalyzer::isAdmeshAvailable();

    $summary = '<p class="mesh-empty">This model has not been analyzed yet.</p>';
    if ($status !== null) {
        $count = count($status['issues'] ?? []);
        if ($status['is_manifold'] === true) {
            $summary = '<p class="mesh-ok">&#10003; Mesh is manifold (watertight) with no detected issues.</p>';
        } elseif ($status['is_manifold'] === false) {
            $summary = '<p class="mesh-bad">&#9888; ' . $count . ' mesh ' . ($count === 1 ? 'issue' : 'issues') . ' detected.</p>';
        } else {
            $summary = '<p class="mesh-empty">Analyzed. Install <code>admesh</code> on the server for a full manifold check.</p>';
        }
    }

    $content = '<div class="mesh-analysis" data-model-id="' . $modelId . '">';
    $content .= '<div class="mesh-result">' . $summary . '</div>';
    $content .= '<div class="mesh-actions">';
    $content .= '<button type="button" class="btn btn-secondary mesh-analyze-btn" data-model-id="' . $modelId . '">Analyze Mesh</button>';
    if ($status !== null && $status['is_manifold'] === false && $admesh) {
        $content .= '<button type="button" class="btn btn-warning mesh-repair-btn" data-model-id="' . $modelId . '">Repair Mesh</button>';
    }
    if (!$admesh) {
        $content .= '<span class="mesh-hint">Install <code>admesh</code> on the server to enable automatic repair.</span>';
    }
    $content .= '</div></div>';

    $tabs[] = ['label' => 'Mesh Analysis', 'content' => $content];
    return $tabs;
});

// Model card - "Slice" dropdown for sliceable models (browse / category pages)
$plugin->addFilter('model_card_extra', function($html, $model) {
    if (function_exists('isFeatureEnabled') && !isFeatureEnabled('slicer_integration')) return $html;
    $type = strtolower($model['file_type'] ?? $model['preview_type'] ?? '');
    if (!in_array($type, ['stl', '3mf', 'obj', 'step', 'stp'], true)) return $html;
    if (!function_exists('getEnabledSlicers')) return $html;
    $slicers = getEnabledSlicers();
    if (empty($slicers)) return $html;

    $items = '';
    foreach ($slicers as $key => $s) {
        $items .= '<a href="#" class="dropdown-item slicer-link" data-slicer="' . htmlspecialchars($key)
            . '" data-model-id="' . (int)$model['id'] . '">' . htmlspecialchars($s['name']) . '</a>';
    }
    $html .= '<div class="dropdown slicer-dropdown slicer-card-dropdown" onclick="event.stopPropagation();">'
        . '<button type="button" class="btn btn-small btn-secondary slicer-toggle">Slice <span class="dropdown-arrow">&#9662;</span></button>'
        . '<div class="dropdown-menu dropdown-menu-right">' . $items . '</div></div>';
    return $html;
});

// Footer content - expose a CSRF token for the plugin's AJAX actions and the
// enabled-slicer list. The plugin JavaScript reads the CSRF token from this
// holder for state-changing requests, and uses window.printingSlicers to build
// "Send to slicer" dropdowns where there is no server-side hook (part folders).
$plugin->addFilter('footer_content', function($html) {
    if (function_exists('isLoggedIn') && isLoggedIn() && function_exists('csrf_field')) {
        $html .= '<div id="printing-csrf" hidden>' . csrf_field() . '</div>';
    }
    // Expose enabled slicers to the JS so it can build "Send to slicer" dropdowns
    // (e.g. injected per part-folder, where there is no server-side hook).
    if (function_exists('isFeatureEnabled') && isFeatureEnabled('slicer_integration') && function_exists('getEnabledSlicers')) {
        $list = [];
        foreach (getEnabledSlicers() as $key => $s) {
            $list[] = ['key' => $key, 'name' => $s['name'], 'protocol' => !empty($s['protocol'])];
        }
        if ($list) {
            $html .= '<script>window.printingSlicers = ' . json_encode($list) . ';</script>';
        }
    }
    return $html;
});
