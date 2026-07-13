<?php
/**
 * Print Photo Actions
 * - Upload print photos
 * - Delete print photos
 * - Set primary photo
 */

require_once __DIR__ . '/../../../includes/config.php';

$user = printingRequireLogin();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

printingRequireCsrf($action, ['upload', 'delete', 'set_primary']);

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
        printingFail('Invalid action');
}

function uploadPrintPhoto() {
    $user = getCurrentUser();

    $modelId = (int)($_POST['model_id'] ?? 0);
    $caption = trim($_POST['caption'] ?? '');

    if (!$modelId) {
        printingFail('Model ID required');
    }

    $db = getDB();
    printingRequireModelOwner($db, $modelId, $user);

    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        printingFail('No photo uploaded');
    }

    $file = $_FILES['photo'];
    // Map of accepted MIME types to their canonical extensions. The stored
    // extension is derived from the finfo-validated MIME, never the
    // attacker-controlled client filename (prevents polyglot .php uploads).
    $mimeToExt = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset($mimeToExt[$mimeType])) {
        printingFail('Invalid image type. Allowed: JPG, PNG, GIF, WebP');
    }

    // Create photos directory if needed
    $photosDir = UPLOAD_PATH . 'photos/' . $modelId;
    if (!is_dir($photosDir)) {
        mkdir($photosDir, 0755, true);
    }

    // Generate unique filename (extension derived from validated MIME, not the client filename)
    $ext = $mimeToExt[$mimeType];
    $filename = uniqid('print_') . '.' . $ext;
    $filePath = $photosDir . '/' . $filename;
    $relativePath = 'photos/' . $modelId . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        printingFail('Failed to save photo', 500);
    }

    // Resize if too large (max 2000px width)
    resizeImage($filePath, 2000);

    // Insert into database
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

    printingOk([
        'photo' => [
            'id' => $photoId,
            'filename' => $filename,
            'file_path' => $relativePath,
            'caption' => $caption
        ]
    ]);
}

function deletePrintPhoto() {
    $user = getCurrentUser();

    $photoId = (int)($_POST['photo_id'] ?? 0);

    if (!$photoId) {
        printingFail('Photo ID required');
    }

    $db = getDB();

    // Get photo info
    $stmt = $db->prepare('SELECT * FROM print_photos WHERE id = :id');
    $stmt->execute([':id' => $photoId]);
    $photo = $stmt->fetch();

    if (!$photo) {
        printingFail('Photo not found', 404);
    }

    // Check permission (owner or admin)
    if ($photo['user_id'] != $user['id'] && !$user['is_admin']) {
        printingFail('Permission denied', 403);
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

    printingOk();
}

function setPrimaryPhoto() {
    $user = getCurrentUser();

    $photoId = (int)($_POST['photo_id'] ?? 0);
    $modelId = (int)($_POST['model_id'] ?? 0);

    if (!$photoId || !$modelId) {
        printingFail('Photo ID and Model ID required');
    }

    $db = getDB();
    printingRequireModelOwner($db, $modelId, $user);

    // Clear existing primary
    $stmt = $db->prepare('UPDATE print_photos SET is_primary = 0 WHERE model_id = :model_id');
    $stmt->execute([':model_id' => $modelId]);

    // Set new primary
    $stmt = $db->prepare('UPDATE print_photos SET is_primary = 1 WHERE id = :id AND model_id = :model_id');
    $stmt->execute([':id' => $photoId, ':model_id' => $modelId]);

    printingOk();
}

function listPrintPhotos() {
    $modelId = (int)($_GET['model_id'] ?? 0);

    if (!$modelId) {
        printingFail('Model ID required');
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

    printingOk(['photos' => $photos]);
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
