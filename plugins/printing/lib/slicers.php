<?php
/**
 * Slicer Software Definitions
 *
 * Defines supported slicer software and their URL protocols.
 * Slicers that support URL protocols can open files directly from the browser.
 */

// Default slicer definitions
function getDefaultSlicers() {
    return [
        'bambustudio' => [
            'name' => 'Bambu Studio',
            'icon' => 'bambustudio',
            'protocol' => 'bambustudio://open?file={url}',
            'formats' => ['stl', '3mf', 'obj'],
            'description' => 'Bambu Lab official slicer',
            'enabled' => true
        ],
        'orcaslicer' => [
            'name' => 'OrcaSlicer',
            'icon' => 'orcaslicer',
            'protocol' => 'orcaslicer://open?file={url}',
            'formats' => ['stl', '3mf', 'obj', 'step'],
            'description' => 'Open-source slicer based on Bambu Studio',
            'enabled' => true
        ],
        'prusaslicer' => [
            'name' => 'PrusaSlicer',
            'icon' => 'prusaslicer',
            'protocol' => 'prusaslicer://open?file={url}',
            'formats' => ['stl', '3mf', 'obj'],
            'description' => 'Prusa Research slicer',
            'enabled' => true
        ],
        'cura' => [
            'name' => 'UltiMaker Cura',
            'icon' => 'cura',
            'protocol' => 'cura://open?file={url}',
            'formats' => ['stl', '3mf', 'obj'],
            'description' => 'UltiMaker Cura slicer',
            'enabled' => true
        ],
        'superslicer' => [
            'name' => 'SuperSlicer',
            'icon' => 'superslicer',
            'protocol' => 'superslicer://open?file={url}',
            'formats' => ['stl', '3mf', 'obj'],
            'description' => 'Advanced fork of PrusaSlicer',
            'enabled' => false
        ],
        'lychee' => [
            'name' => 'Lychee Slicer',
            'icon' => 'lychee',
            'protocol' => null, // No URL protocol support - download only
            'formats' => ['stl', '3mf', 'obj'],
            'description' => 'Resin printing slicer (SLA/MSLA)',
            'enabled' => true,
            'download_only' => true
        ],
        'chitubox' => [
            'name' => 'CHITUBOX',
            'icon' => 'chitubox',
            'protocol' => null, // No URL protocol support - download only
            'formats' => ['stl', '3mf', 'obj'],
            'description' => 'Resin printing slicer',
            'enabled' => false,
            'download_only' => true
        ],
        'crealityprint' => [
            'name' => 'Creality Print',
            'icon' => 'crealityprint',
            'protocol' => null, // No URL protocol support - download only
            'formats' => ['stl', '3mf', 'obj'],
            'description' => 'Creality official slicer',
            'enabled' => true,
            'download_only' => true
        ],
        'ideamaker' => [
            'name' => 'ideaMaker',
            'icon' => 'ideamaker',
            'protocol' => null,
            'formats' => ['stl', '3mf', 'obj'],
            'description' => 'Raise3D slicer',
            'enabled' => false,
            'download_only' => true
        ],
        'simplify3d' => [
            'name' => 'Simplify3D',
            'icon' => 'simplify3d',
            'protocol' => null,
            'formats' => ['stl', '3mf', 'obj'],
            'description' => 'Professional 3D printing software',
            'enabled' => false,
            'download_only' => true
        ]
    ];
}

/**
 * Get enabled slicers from settings
 */
function getEnabledSlicers() {
    $allSlicers = getDefaultSlicers();
    $enabledSetting = getSetting('enabled_slicers', null);

    if ($enabledSetting) {
        $enabledList = array_map('trim', explode(',', $enabledSetting));
        foreach ($allSlicers as $key => &$slicer) {
            $slicer['enabled'] = in_array($key, $enabledList);
        }
    }

    return array_filter($allSlicers, function($slicer) {
        return $slicer['enabled'];
    });
}

/**
 * Get slicers that support a specific file format
 */
function getSlicersForFormat($format) {
    $slicers = getEnabledSlicers();
    $format = strtolower($format);

    return array_filter($slicers, function($slicer) use ($format) {
        return in_array($format, $slicer['formats']);
    });
}

/**
 * Generate slicer URL for opening a file
 *
 * @param string $slicerKey The slicer identifier
 * @param string $fileUrl The full URL to the file
 * @return string|null The slicer URL or null if not supported
 */
function getSlicerUrl($slicerKey, $fileUrl) {
    $slicers = getDefaultSlicers();

    if (!isset($slicers[$slicerKey]) || empty($slicers[$slicerKey]['protocol'])) {
        return null;
    }

    $protocol = $slicers[$slicerKey]['protocol'];
    return str_replace('{url}', urlencode($fileUrl), $protocol);
}

/**
 * Check if a slicer supports direct opening via URL protocol
 */
function slicerSupportsProtocol($slicerKey) {
    $slicers = getDefaultSlicers();
    return isset($slicers[$slicerKey]) && !empty($slicers[$slicerKey]['protocol']);
}
