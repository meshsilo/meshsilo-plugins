<?php
/**
 * Slicer integration.
 *
 * A desktop slicer opened via its URL protocol (slicer://open?file=...) runs
 * OUTSIDE the browser session, so it can't send a login cookie. This endpoint
 * therefore has a PUBLIC, token-authenticated download action: the plugin marks
 * /actions/slicer public via the 'public_routes' filter (boot.php), and the
 * download action authenticates with its own short-lived HMAC token instead of
 * the session. The 'urls' action (which issues those tokens) still requires login.
 */

require_once __DIR__ . '/../../../includes/config.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// PUBLIC: token-authenticated file serving (no session).
if ($action === 'download') {
    slicerServeToken();
    exit;
}

// Everything else requires a logged-in user.
printingRequireLogin();

switch ($action) {
    case 'urls':
        slicerIssueUrls();
        break;
    default:
        printingFail('Invalid action');
}

/** Formats a slicer can open. */
function slicerFormats() {
    return ['stl', '3mf', 'obj', 'step', 'stp'];
}

/** Secret for signing download tokens (generated once, stored in settings). */
function slicerSecret() {
    $s = function_exists('getSetting') ? getSetting('slicer_download_secret', '') : '';
    if (!$s) {
        $s = bin2hex(random_bytes(32));
        if (function_exists('setSetting')) {
            setSetting('slicer_download_secret', $s);
        }
    }
    return $s;
}

function slicerB64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function slicerB64UrlDecode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

/** Create a short-lived HMAC token that grants download of one model. */
function slicerMakeToken($modelId, $ttl = 3600) {
    $payload = slicerB64UrlEncode(json_encode(['id' => (int)$modelId, 'exp' => time() + $ttl]));
    $sig = slicerB64UrlEncode(hash_hmac('sha256', $payload, slicerSecret(), true));
    return $payload . '.' . $sig;
}

/** Verify a token; return the model id or null. */
function slicerVerifyToken($token) {
    if (!is_string($token) || strpos($token, '.') === false) {
        return null;
    }
    [$payload, $sig] = explode('.', $token, 2);
    $expected = slicerB64UrlEncode(hash_hmac('sha256', $payload, slicerSecret(), true));
    if (!hash_equals($expected, $sig)) {
        return null;
    }
    $data = json_decode(slicerB64UrlDecode($payload), true);
    if (!is_array($data) || empty($data['id']) || empty($data['exp'])) {
        return null;
    }
    if ((int)$data['exp'] < time()) {
        return null;
    }
    return (int)$data['id'];
}

/** Resolve a model's absolute file path (host helper, with a storage-layout fallback). */
function slicerResolveFile($model) {
    if (function_exists('getAbsoluteFilePath')) {
        $p = getAbsoluteFilePath($model);
        if ($p && is_file($p)) {
            return $p;
        }
    }
    if (defined('UPLOAD_PATH')) {
        $rel = !empty($model['dedup_path']) ? $model['dedup_path'] : ($model['file_path'] ?? '');
        if ($rel !== '') {
            if (strpos($rel, 'assets/') === 0) {
                $rel = substr($rel, strlen('assets/'));
            }
            $p = rtrim(UPLOAD_PATH, '/') . '/' . ltrim($rel, '/');
            if (is_file($p)) {
                return $p;
            }
        }
    }
    return null;
}

/** Stream a model file to a token-bearing (session-less) client. */
function slicerServeToken() {
    $modelId = slicerVerifyToken($_GET['token'] ?? '');
    if (!$modelId) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid or expired token']);
        exit;
    }
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
    $stmt->execute([':id' => $modelId]);
    $model = $stmt->fetch();
    if (!$model) {
        http_response_code(404);
        exit;
    }
    if (!in_array(strtolower($model['file_type'] ?? ''), slicerFormats(), true)) {
        http_response_code(415);
        exit;
    }
    $path = slicerResolveFile($model);
    if (!$path) {
        http_response_code(404);
        exit;
    }
    $filename = $model['filename'] ?: ('model-' . $modelId . '.' . strtolower($model['file_type'] ?? 'stl'));
    $filename = str_replace(['"', '\\', "\r", "\n"], '', $filename);

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

/**
 * For a comma-separated list of model/part ids, issue a token per sliceable file.
 * The JS builds each download URL as <origin>/actions/slicer?action=download&token=...
 */
function slicerIssueUrls() {
    $raw = $_GET['ids'] ?? $_POST['ids'] ?? '';
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)))));
    if (empty($ids)) {
        printingFail('No parts specified');
    }
    $ids = array_slice($ids, 0, 200);

    $db = getDB();
    $files = [];
    foreach ($ids as $id) {
        $stmt = $db->prepare('SELECT id, name, filename, file_type FROM models WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $m = $stmt->fetch();
        if (!$m) {
            continue;
        }
        if (!in_array(strtolower($m['file_type'] ?? ''), slicerFormats(), true)) {
            continue;
        }
        $files[] = [
            'id'    => (int)$m['id'],
            'name'  => $m['name'] ?: $m['filename'],
            'token' => slicerMakeToken((int)$m['id']),
        ];
    }
    if (empty($files)) {
        printingFail('No sliceable files available');
    }
    printingOk(['files' => $files]);
}
