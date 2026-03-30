<?php
/**
 * OpenSCAD Integration Plugin
 *
 * Provides parameter parsing, visual editing, and STL/3MF export for .scad files.
 */

// Load the OpenSCAD parser
require_once $pluginDir . '/OpenScadParser.php';
require_once $pluginDir . '/OpenScadRenderer.php';

// Register routes
$plugin->addRoute('GET', '/openscad/edit/{partId}', function ($params) use ($pluginDir, $plugin) {
    $adminPage = 'openscad';
    require $pluginDir . '/pages/editor.php';
}, 'openscad.edit');

$plugin->addRoute('POST', '/openscad/render/{partId}', function ($params) use ($pluginDir, $plugin) {
    require $pluginDir . '/pages/render.php';
}, 'openscad.render');

$plugin->addRoute('POST', '/openscad/preview/{partId}', function ($params) use ($pluginDir, $plugin) {
    require $pluginDir . '/pages/preview.php';
}, 'openscad.preview');

// Add "Edit in OpenSCAD" action for .scad parts on model detail page
$plugin->addFilter('part_actions', function ($html, $part) {
    $fileType = strtolower($part['file_type'] ?? '');
    if ($fileType === 'scad') {
        $editUrl = '/openscad/edit/' . (int)$part['id'];
        $html .= ' <a href="' . htmlspecialchars($editUrl) . '" class="btn btn-sm btn-primary" title="Edit parameters and export">OpenSCAD</a>';
    }
    return $html;
}, 15);

// Add stylesheet
$plugin->addStylesheet('openscad', 'assets/openscad.css');
