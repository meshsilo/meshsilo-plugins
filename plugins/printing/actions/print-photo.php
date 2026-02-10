<?php
/**
 * Print Photo Actions
 * - Upload print photos
 * - Delete print photos
 * - Set primary photo
 */

require_once __DIR__ . '/../../../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user = getCurrentUser();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF validation for state-changing actions
if (in_array($action, ['upload', 'delete', 'set_primary'])) {
    if (!Csrf::check()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid request token']);
        exit;
    }
}

switch ($action) {
    case 'upload':
        uploadPrintPhoto();
        break;
    case 'delete':
        deletePrintPhoto();
        break;
    case 'set_primary':
        setPrimaryPhoto();
        break;
    case 'list':
        listPrintPhotos();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function uploadPrintPhoto() {
    global $user;

    $modelId = (int)($_POST['model_id'] ?? 0);
    $caption = trim($_POST['caption'] ?? '');

    if (!$modelId) {
        echo json_encode(['success' => false, 'error' => 'Model ID required']);
        return;
    }

    // Verify model ownership
    $db = getDB();
    $stmt = $db->prepare('SELECT user_id, uploaded_by FROM models WHERE id = :id');
    $stmt->execute([':id' => $modelId]);
    $model = $stmt->fetch();

    if (!$model) {
        echo json_encode(['success' => false, 'error' => 'Model not found']);
        return;
    }

    $ownerId = $model['user_id'] ?? $model['uploaded_by'] ?? null;
    if ($ownerId && $ownerId != $user['id'] && !$user['is_admin'] && !canEdit()) {
        echo json_encode(['success' => false, 'error' => 'Permission denied - not model owner']);
        return;
    }

    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'No photo uploaded']);
        return;
    }

    $file = $_FILES['photo'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        echo json_encode(['success' => false, 'error' => 'Invalid image type. Allowed: JPG, PNG, GIF, WebP']);
        return;
    }

    // Create photos directory if needed
    $photosDir = UPLOAD_PATH . 'photos/' . $modelId;
    if (!is_dir($photosDir)) {
        mkdir($photosDir, 0755, true);
    }

    // Generate unique filename
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('print_') . '.' . $ext;
    $filePath = $photosDir . '/' . $filename;
    $relativePath = 'photos/' . $modelId . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        echo json_encode(['success' => false, 'error' => 'Failed to save photo']);
        return;
    }

    // Resize if too large (max 2000px width)
    resizeImage($filePath, 2000);

    // Insert into database
    $db = getDB();
    $stmt = $db->prepare('
        INSERT INTO print_photos (model_id, user_id, filename, file_path, caption)
        VALUES (:model_id, :user_id, :filename, :file_path, :caption)
    ');
    $stmt->execute([
        ':model_id' => $modelId,
        ':user_id' => $user['id'],
        ':filename' => $filename,
        ':file_path' => $relativePath,
        ':caption' => $caption
    ]);

    $photoId = $db->lastInsertId();

    logActivity('upload_photo', 'model', $modelId, null, ['photo_id' => $photoId]);

    echo json_encode([
        'success' => true,
        'photo' => [
            'id' => $photoId,
            'filename' => $filename,
            'file_path' => $relativePath,
            'caption' => $caption
        ]
    ]);
}

function deletePrintPhoto() {
    global $user;

    $photoId = (int)($_POST['photo_id'] ?? 0);

    if (!$photoId) {
        echo json_encode(['success' => false, 'error' => 'Photo ID required']);
        return;
    }

    $db = getDB();

    // Get photo info
    $stmt = $db->prepare('SELECT * FROM print_photos WHERE id = :id');
    $stmt->execute([':id' => $photoId]);
    $photo = $stmt->fetch();

    if (!$photo) {
        echo json_encode(['success' => false, 'error' => 'Photo not found']);
        return;
    }

    // Check permission (owner or admin)
    if ($photo['user_id'] !== $user['id'] && !$user['is_admin']) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        return;
    }

    // Delete file
    $filePath = UPLOAD_PATH . $photo['file_path'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }

    // Delete from database
    $stmt = $db->prepare('DELETE FROM print_photos WHERE id = :id');
    $stmt->execute([':id' => $photoId]);

    logActivity('delete_photo', 'model', $photo['model_id'], null, ['photo_id' => $photoId]);

    echo json_encode(['success' => true]);
}

function setPrimaryPhoto() {
    global $user;

    $photoId = (int)($_POST['photo_id'] ?? 0);
    $modelId = (int)($_POST['model_id'] ?? 0);

    if (!$photoId || !$modelId) {
        echo json_encode(['success' => false, 'error' => 'Photo ID and Model ID required']);
        return;
    }

    $db = getDB();

    // Verify model ownership
    $stmt = $db->prepare('SELECT user_id, uploaded_by FROM models WHERE id = :id');
    $stmt->execute([':id' => $modelId]);
    $model = $stmt->fetch();

    if (!$model) {
        echo json_encode(['success' => false, 'error' => 'Model not found']);
        return;
    }

    $ownerId = $model['user_id'] ?? $model['uploaded_by'] ?? null;
    if ($ownerId && $ownerId != $user['id'] && !$user['is_admin'] && !canEdit()) {
        echo json_encode(['success' => false, 'error' => 'Permission denied - not model owner']);
        return;
    }

    // Clear existing primary
    $stmt = $db->prepare('UPDATE print_photos SET is_primary = 0 WHERE model_id = :model_id');
    $stmt->execute([':model_id' => $modelId]);

    // Set new primary
    $stmt = $db->prepare('UPDATE print_photos SET is_primary = 1 WHERE id = :id AND model_id = :model_id');
    $stmt->execute([':id' => $photoId, ':model_id' => $modelId]);

    echo json_encode(['success' => true]);
}

function listPrintPhotos() {
    $modelId = (int)($_GET['model_id'] ?? 0);

    if (!$modelId) {
        echo json_encode(['success' => false, 'error' => 'Model ID required']);
        return;
    }

    $db = getDB();
    $stmt = $db->prepare('
        SELECT pp.*, u.username
        FROM print_photos pp
        LEFT JOIN users u ON pp.user_id = u.id
        WHERE pp.model_id = :model_id
        ORDER BY pp.is_primary DESC, pp.created_at DESC
    ');
    $stmt->execute([':model_id' => $modelId]);

    $photos = [];
    while ($row = $stmt->fetch()) {
        $photos[] = $row;
    }

    echo json_encode(['success' => true, 'photos' => $photos]);
}

function resizeImage($filePath, $maxWidth) {
    $info = getimagesize($filePath);
    if (!$info || $info[0] <= $maxWidth) {
        return;
    }

    $mime = $info['mime'];
    switch ($mime) {
        case 'image/jpeg':
            $image = imagecreatefromjpeg($filePath);
            break;
        case 'image/png':
            $image = imagecreatefrompng($filePath);
            break;
        case 'image/gif':
            $image = imagecreatefromgif($filePath);
            break;
        case 'image/webp':
            $image = imagecreatefromwebp($filePath);
            break;
        default:
            return;
    }

    $width = $info[0];
    $height = $info[1];
    $ratio = $maxWidth / $width;
    $newWidth = $maxWidth;
    $newHeight = (int)($height * $ratio);

    $resized = imagecreatetruecolor($newWidth, $newHeight);

    // Preserve transparency for PNG and GIF
    if ($mime === 'image/png' || $mime === 'image/gif') {
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
    }

    imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    switch ($mime) {
        case 'image/jpeg':
            imagejpeg($resized, $filePath, 85);
            break;
        case 'image/png':
            imagepng($resized, $filePath, 8);
            break;
        case 'image/gif':
            imagegif($resized, $filePath);
            break;
        case 'image/webp':
            imagewebp($resized, $filePath, 85);
            break;
    }

    imagedestroy($image);
    imagedestroy($resized);
}
