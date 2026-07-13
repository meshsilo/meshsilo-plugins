<?php
/**
 * Mesh Analysis Actions
 * - analyze: inspect an STL model for mesh issues (manifold, holes, normals...)
 * - repair:  repair a mesh via admesh (when the tool is available)
 * - status:  return the stored mesh status for a model
 */

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/features.php';

printingRequireFeature('mesh_analysis', 'Mesh analysis');
$user = printingRequireLogin();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

printingRequireCsrf($action, ['analyze', 'repair']);

switch ($action) {
    case 'analyze':
        analyzeMesh();
        break;
    case 'repair':
        repairMesh();
        break;
    case 'status':
        meshStatus();
        break;
    default:
        printingFail('Invalid action');
}

/**
 * Resolve the absolute path to a model's file, or null when it can't be found.
 *
 * Prefers the host's getAbsoluteFilePath() when available, and falls back to the
 * documented storage layout (DB paths carry an 'assets/' prefix; files live under
 * UPLOAD_PATH = storage/assets/). Returns the first candidate that exists on disk.
 */
function meshResolveFile($model) {
    $candidates = [];

    if (function_exists('getAbsoluteFilePath')) {
        $path = getAbsoluteFilePath($model);
        if ($path) {
            $candidates[] = $path;
        }
    }

    if (defined('UPLOAD_PATH')) {
        $rel = !empty($model['dedup_path']) ? $model['dedup_path'] : ($model['file_path'] ?? '');
        if ($rel !== '') {
            if (strpos($rel, 'assets/') === 0) {
                $rel = substr($rel, strlen('assets/'));
            }
            $candidates[] = rtrim(UPLOAD_PATH, '/') . '/' . ltrim($rel, '/');
        }
    }

    foreach ($candidates as $candidate) {
        if ($candidate && file_exists($candidate)) {
            return $candidate;
        }
    }

    return $candidates[0] ?? null;
}

function analyzeMesh() {
    $user = getCurrentUser();
    $modelId = (int)($_POST['model_id'] ?? 0);
    if (!$modelId) {
        printingFail('Model ID required');
    }

    $db = getDB();
    $model = printingRequireModelOwner($db, $modelId, $user);

    $filePath = meshResolveFile($model);
    if (!$filePath || !file_exists($filePath)) {
        printingFail('Model file not found', 404);
    }

    $analysis = MeshAnalyzer::analyze($filePath);
    if (isset($analysis['error'])) {
        printingFail($analysis['error']);
    }

    MeshAnalyzer::updateModelMeshStatus($modelId, $analysis);
    logActivity('analyze_mesh', 'model', $modelId);

    printingOk(['analysis' => $analysis]);
}

function repairMesh() {
    $user = getCurrentUser();
    $modelId = (int)($_POST['model_id'] ?? 0);
    if (!$modelId) {
        printingFail('Model ID required');
    }

    if (!MeshAnalyzer::isAdmeshAvailable()) {
        printingFail('Mesh repair requires the admesh tool, which is not installed on the server.', 501);
    }

    $db = getDB();
    $model = printingRequireModelOwner($db, $modelId, $user);

    $filePath = meshResolveFile($model);
    if (!$filePath || !file_exists($filePath)) {
        printingFail('Model file not found', 404);
    }

    $repair = MeshAnalyzer::repair($filePath);
    if (empty($repair['success'])) {
        printingFail($repair['error'] ?? 'Repair failed');
    }

    // Re-analyze so the stored status reflects the repaired mesh.
    $analysis = MeshAnalyzer::analyze($filePath);
    if (!isset($analysis['error'])) {
        MeshAnalyzer::updateModelMeshStatus($modelId, $analysis);
    }
    logActivity('repair_mesh', 'model', $modelId);

    printingOk(['repaired' => true, 'analysis' => isset($analysis['error']) ? null : $analysis]);
}

function meshStatus() {
    $modelId = (int)($_GET['model_id'] ?? 0);
    if (!$modelId) {
        printingFail('Model ID required');
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
    $stmt->execute([':id' => $modelId]);
    $model = $stmt->fetch();
    if (!$model) {
        printingFail('Model not found', 404);
    }

    printingOk([
        'status' => MeshAnalyzer::getMeshStatus($model),
        'admesh_available' => MeshAnalyzer::isAdmeshAvailable()
    ]);
}
