<?php
/**
 * Demo Mode Management for Silo
 *
 * Provides functionality to run Silo in demo mode with:
 * - Sample models from legal sources (NASA, public domain)
 * - Periodic reset of data via CLI cron job
 * - Demo banner display
 * - Demo users created from environment variables or random passwords
 *
 * Environment variables for demo credentials:
 *   DEMO_USER, DEMO_PASSWORD - Regular demo user
 *   DEMO_ADMIN_USER, DEMO_ADMIN_PASSWORD - Demo admin user
 *
 * Demo mode can only be enabled during installation (install.php).
 */

class DemoMode {
    private $db;

    // Sample models from legal sources (CC0 / Public Domain)
    // These are small, simple models suitable for demo purposes
    private static $sampleModels = [
        [
            'name' => 'Apollo 11 Command Module',
            'description' => 'NASA Apollo 11 Command Module - Historic spacecraft that carried astronauts to the Moon.',
            'creator' => 'NASA',
            'source_url' => 'https://nasa3d.arc.nasa.gov/detail/apollo-11-background',
            'category' => 'Art',
            'file_url' => 'https://nasa3d.arc.nasa.gov/shared_assets/models/apollo-csm-background/apollo-csm-background.stl',
            'license' => 'Public Domain'
        ],
        [
            'name' => 'Curiosity Rover',
            'description' => 'NASA Mars Science Laboratory Curiosity Rover - Exploring the Red Planet since 2012.',
            'creator' => 'NASA',
            'source_url' => 'https://nasa3d.arc.nasa.gov/detail/curiosity',
            'category' => 'Mechanical',
            'file_url' => 'https://nasa3d.arc.nasa.gov/shared_assets/models/curiosity/curiosity.stl',
            'license' => 'Public Domain'
        ],
        [
            'name' => 'Voyager Spacecraft',
            'description' => 'NASA Voyager spacecraft - The farthest human-made objects from Earth.',
            'creator' => 'NASA',
            'source_url' => 'https://nasa3d.arc.nasa.gov/detail/voyager',
            'category' => 'Mechanical',
            'file_url' => 'https://nasa3d.arc.nasa.gov/shared_assets/models/voyager/voyager.stl',
            'license' => 'Public Domain'
        ],
        [
            'name' => 'Space Shuttle Discovery',
            'description' => 'NASA Space Shuttle Discovery - One of the most traveled spacecraft in history.',
            'creator' => 'NASA',
            'source_url' => 'https://nasa3d.arc.nasa.gov/detail/space-shuttle',
            'category' => 'Mechanical',
            'file_url' => 'https://nasa3d.arc.nasa.gov/shared_assets/models/space-shuttle/space-shuttle.stl',
            'license' => 'Public Domain'
        ],
        [
            'name' => 'Hubble Space Telescope',
            'description' => 'NASA Hubble Space Telescope - Revolutionizing our view of the universe since 1990.',
            'creator' => 'NASA',
            'source_url' => 'https://nasa3d.arc.nasa.gov/detail/hubble',
            'category' => 'Mechanical',
            'file_url' => 'https://nasa3d.arc.nasa.gov/shared_assets/models/hubble/hubble.stl',
            'license' => 'Public Domain'
        ],
        [
            'name' => 'International Space Station',
            'description' => 'NASA International Space Station - Humanity\'s orbiting laboratory.',
            'creator' => 'NASA',
            'source_url' => 'https://nasa3d.arc.nasa.gov/detail/iss',
            'category' => 'Mechanical',
            'file_url' => 'https://nasa3d.arc.nasa.gov/shared_assets/models/iss/iss.stl',
            'license' => 'Public Domain'
        ],
    ];

    // Simple geometric shapes as fallback (these can be generated)
    private static $fallbackModels = [
        [
            'name' => 'Calibration Cube',
            'description' => 'A simple 20mm calibration cube for testing your 3D printer.',
            'creator' => 'Silo Demo',
            'category' => 'Tools',
            'license' => 'CC0'
        ],
        [
            'name' => 'Benchy Boat',
            'description' => 'The classic 3D printing benchmark model.',
            'creator' => 'Creative Tools (CC-BY)',
            'source_url' => 'https://www.thingiverse.com/thing:763622',
            'category' => 'Tools',
            'license' => 'CC-BY'
        ],
    ];

    public function __construct() {
        $this->db = getDB();
    }

    /**
     * Check if demo mode is enabled
     */
    public static function isEnabled() {
        return getSetting('demo_mode', '0') === '1';
    }

    /**
     * Enable demo mode
     */
    public static function enable() {
        setSetting('demo_mode', '1');
        setSetting('demo_last_reset', time());
    }

    /**
     * Disable demo mode
     */
    public static function disable() {
        setSetting('demo_mode', '0');
    }

    /**
     * Get time until next scheduled reset (if auto-reset is enabled)
     */
    public static function getNextResetTime() {
        $resetInterval = (int)getSetting('demo_reset_interval', 3600); // Default 1 hour
        $lastReset = (int)getSetting('demo_last_reset', 0);

        if ($resetInterval <= 0 || $lastReset <= 0) {
            return null;
        }

        return $lastReset + $resetInterval;
    }

    /**
     * Check if auto-reset is due
     */
    public static function isResetDue() {
        $nextReset = self::getNextResetTime();
        return $nextReset !== null && time() >= $nextReset;
    }

    /**
     * Get sample models configuration
     */
    public static function getSampleModels() {
        return self::$sampleModels;
    }

    /**
     * Reset the database to demo state
     *
     * @param bool $downloadModels Whether to download sample models from the internet
     * @param callable|null $progressCallback Optional callback for progress updates
     * @return array Result with success status and messages
     */
    public function resetToDemo($downloadModels = true, $progressCallback = null) {
        $messages = [];
        $errors = [];

        try {
            $this->db->getPDO()->beginTransaction();

            // Step 1: Clear existing data (except admin user)
            // Clean junction tables first (SQLite foreign key cascades may not be enabled)
            $this->progress($progressCallback, 'Clearing model relationships...');
            $this->db->exec('DELETE FROM model_tags');
            $this->db->exec('DELETE FROM model_categories');
            try { $this->db->exec('DELETE FROM recently_viewed'); } catch (\Exception $e) {}
            try { $this->db->exec('DELETE FROM print_queue'); } catch (\Exception $e) {}
            $messages[] = 'Cleared model relationships';

            $this->progress($progressCallback, 'Clearing existing models...');
            $this->db->exec('DELETE FROM models');
            $messages[] = 'Cleared all models';

            $this->progress($progressCallback, 'Clearing tags...');
            $this->db->exec('DELETE FROM tags');
            $messages[] = 'Cleared all tags';

            $this->progress($progressCallback, 'Clearing favorites...');
            $this->db->exec('DELETE FROM favorites');
            $messages[] = 'Cleared favorites';

            $this->progress($progressCallback, 'Clearing activity log...');
            $this->db->exec('DELETE FROM activity_log');
            $messages[] = 'Cleared activity log';

            // Step 2: Reset categories to defaults
            $this->progress($progressCallback, 'Resetting categories...');
            $this->db->exec('DELETE FROM categories');
            $defaultCategories = ['Functional', 'Decorative', 'Tools', 'Gaming', 'Art', 'Mechanical'];
            $stmt = $this->db->prepare('INSERT INTO categories (name) VALUES (:name)');
            foreach ($defaultCategories as $cat) {
                $stmt->execute([':name' => $cat]);
            }
            $messages[] = 'Reset categories to defaults';

            // Step 3: Reset collections
            $this->progress($progressCallback, 'Resetting collections...');
            $this->db->exec('DELETE FROM collections');
            $demoCollections = [
                ['NASA 3D Models', 'Public domain 3D models from NASA'],
                ['Smithsonian Collection', 'Historical artifacts from the Smithsonian'],
                ['Calibration Models', 'Models for printer calibration and testing']
            ];
            $stmt = $this->db->prepare('INSERT INTO collections (name, description) VALUES (:name, :desc)');
            foreach ($demoCollections as $coll) {
                $stmt->execute([':name' => $coll[0], ':desc' => $coll[1]]);
            }
            $messages[] = 'Created demo collections';

            // Step 4: Create demo tags
            $this->progress($progressCallback, 'Creating demo tags...');
            $demoTags = [
                ['nasa', '#1e40af'],
                ['space', '#7c3aed'],
                ['public-domain', '#059669'],
                ['spacecraft', '#dc2626'],
                ['historic', '#d97706'],
                ['demo', '#6b7280']
            ];
            $stmt = $this->db->prepare('INSERT INTO tags (name, color) VALUES (:name, :color)');
            foreach ($demoTags as $tag) {
                $stmt->execute([':name' => $tag[0], ':color' => $tag[1]]);
            }
            $messages[] = 'Created demo tags';

            // Step 5: Reset non-admin users (keep admin accounts)
            $this->progress($progressCallback, 'Resetting demo users...');
            $this->db->exec('DELETE FROM users WHERE is_admin = 0');

            // Create demo user (regular) - credentials from environment or secure random
            $demoUsername = getenv('DEMO_USER') ?: 'demo';
            $demoUserPassword = getenv('DEMO_PASSWORD') ?: bin2hex(random_bytes(8));
            $demoPassword = password_hash($demoUserPassword, PASSWORD_DEFAULT);
            $stmt = $this->db->prepare('INSERT OR IGNORE INTO users (username, email, password, is_admin) VALUES (:u, :e, :p, 0)');
            $stmt->execute([':u' => $demoUsername, ':e' => $demoUsername . '@example.com', ':p' => $demoPassword]);
            $demoUserId = $this->db->lastInsertId();
            if ($demoUserId) {
                $stmt = $this->db->prepare("INSERT OR IGNORE INTO user_groups (user_id, group_id) SELECT :uid, id FROM groups WHERE name = 'Users'");
                $stmt->execute([':uid' => $demoUserId]);
            }
            $credentialSource = getenv('DEMO_PASSWORD') ? '[from DEMO_PASSWORD env]' : $demoUserPassword;
            $messages[] = "Created demo user ({$demoUsername} / {$credentialSource})";

            // Create demo admin - credentials from environment or secure random
            $demoAdminUsername = getenv('DEMO_ADMIN_USER') ?: 'demoadmin';
            $demoAdminPasswordPlain = getenv('DEMO_ADMIN_PASSWORD') ?: bin2hex(random_bytes(12));
            $demoAdminPassword = password_hash($demoAdminPasswordPlain, PASSWORD_DEFAULT);
            $stmt = $this->db->prepare('INSERT OR IGNORE INTO users (username, email, password, is_admin) VALUES (:u, :e, :p, 1)');
            $stmt->execute([':u' => $demoAdminUsername, ':e' => $demoAdminUsername . '@example.com', ':p' => $demoAdminPassword]);
            $demoAdminId = $this->db->lastInsertId();
            if ($demoAdminId) {
                $stmt = $this->db->prepare("INSERT OR IGNORE INTO user_groups (user_id, group_id) SELECT :uid, id FROM groups WHERE name = 'Admin'");
                $stmt->execute([':uid' => $demoAdminId]);
            }
            $adminCredentialSource = getenv('DEMO_ADMIN_PASSWORD') ? '[from DEMO_ADMIN_PASSWORD env]' : $demoAdminPasswordPlain;
            $messages[] = "Created demo admin ({$demoAdminUsername} / {$adminCredentialSource})";

            $this->db->getPDO()->commit();

            // Step 6: Clear assets folder
            $this->progress($progressCallback, 'Clearing assets folder...');
            $assetsDir = __DIR__ . '/../../../assets';
            if (is_dir($assetsDir)) {
                $this->clearDirectory($assetsDir);
                $messages[] = 'Cleared assets folder';
            }

            // Step 7: Download/create sample models
            if ($downloadModels) {
                $this->progress($progressCallback, 'Installing sample models...');
                $modelResults = $this->installSampleModels($progressCallback);
                $messages = array_merge($messages, $modelResults['messages']);
                $errors = array_merge($errors, $modelResults['errors']);
            }

            // Step 8: Install models from seed directory (storage/demo-seed/)
            $seedDir = __DIR__ . '/../../../storage/demo-seed';
            if (is_dir($seedDir)) {
                // Check if the directory has any model files (not just .gitkeep)
                $seedFiles = array_filter(scandir($seedDir), function($f) {
                    return $f !== '.' && $f !== '..' && $f !== '.gitkeep' && $f !== 'manifest.json';
                });
                if (!empty($seedFiles)) {
                    $this->progress($progressCallback, 'Installing seed models...');
                    $seedResults = $this->installSeedModels($seedDir, $progressCallback);
                    $messages = array_merge($messages, $seedResults['messages']);
                    $errors = array_merge($errors, $seedResults['errors']);
                } else {
                    $this->progress($progressCallback, 'Seed directory is empty, skipping...');
                    $messages[] = 'Seed directory (storage/demo-seed/) exists but contains no model files';
                }
            } else {
                $this->progress($progressCallback, 'No seed directory found, skipping...');
                $messages[] = 'No seed directory at storage/demo-seed/ (optional)';
            }

            // Step 9: Count created models
            $modelsCreated = 0;
            try {
                $stmt = $this->db->query('SELECT COUNT(*) as cnt FROM models WHERE parent_id IS NULL');
                $row = $stmt->fetch();
                $modelsCreated = (int)($row['cnt'] ?? 0);
            } catch (\Exception $e) {}

            // Step 10: Update demo mode settings
            setSetting('demo_last_reset', time());
            $messages[] = 'Demo mode reset complete at ' . date('Y-m-d H:i:s');

            return [
                'success' => true,
                'messages' => $messages,
                'errors' => $errors,
                'models_created' => $modelsCreated
            ];

        } catch (Exception $e) {
            if ($this->db->getPDO()->inTransaction()) {
                $this->db->getPDO()->rollBack();
            }
            return [
                'success' => false,
                'messages' => $messages,
                'errors' => array_merge($errors, [$e->getMessage()])
            ];
        }
    }

    /**
     * Install sample models
     */
    private function installSampleModels($progressCallback = null) {
        $messages = [];
        $errors = [];
        $assetsDir = __DIR__ . '/../../../assets';

        if (!is_dir($assetsDir)) {
            mkdir($assetsDir, 0755, true);
        }

        // Get category IDs
        $categories = [];
        $stmt = $this->db->query('SELECT id, name FROM categories');
        while ($row = $stmt->fetch()) {
            $categories[$row['name']] = $row['id'];
        }

        // Get tag IDs
        $tags = [];
        $stmt = $this->db->query('SELECT id, name FROM tags');
        while ($row = $stmt->fetch()) {
            $tags[$row['name']] = $row['id'];
        }

        $modelCount = 0;

        foreach (self::$sampleModels as $model) {
            $this->progress($progressCallback, "Downloading: {$model['name']}...");

            try {
                // Download the model file
                $fileContent = $this->downloadFile($model['file_url']);

                if ($fileContent === false) {
                    $errors[] = "Failed to download: {$model['name']}";
                    continue;
                }

                // Generate filename and save
                $filename = $this->sanitizeFilename($model['name']) . '.stl';
                $filePath = $assetsDir . '/' . $filename;

                if (file_put_contents($filePath, $fileContent) === false) {
                    $errors[] = "Failed to save: {$model['name']}";
                    continue;
                }

                $fileSize = strlen($fileContent);

                // Insert model into database
                $stmt = $this->db->prepare('
                    INSERT INTO models (name, filename, file_path, file_size, file_type, description, creator, source_url, created_at)
                    VALUES (:name, :filename, :path, :size, :type, :desc, :creator, :source, CURRENT_TIMESTAMP)
                ');
                $stmt->execute([
                    ':name' => $model['name'],
                    ':filename' => $filename,
                    ':path' => 'assets/' . $filename,
                    ':size' => $fileSize,
                    ':type' => 'stl',
                    ':desc' => $model['description'],
                    ':creator' => $model['creator'],
                    ':source' => $model['source_url'] ?? null
                ]);

                $modelId = $this->db->lastInsertId();

                // Assign category
                if (isset($model['category']) && isset($categories[$model['category']])) {
                    $stmt = $this->db->prepare('INSERT INTO model_categories (model_id, category_id) VALUES (:mid, :cid)');
                    $stmt->execute([':mid' => $modelId, ':cid' => $categories[$model['category']]]);
                }

                // Assign tags
                $modelTags = ['demo', 'public-domain'];
                if (strpos(strtolower($model['creator']), 'nasa') !== false) {
                    $modelTags[] = 'nasa';
                    $modelTags[] = 'space';
                }

                foreach ($modelTags as $tagName) {
                    if (isset($tags[$tagName])) {
                        $stmt = $this->db->prepare('INSERT INTO model_tags (model_id, tag_id) VALUES (:mid, :tid)');
                        $stmt->execute([':mid' => $modelId, ':tid' => $tags[$tagName]]);
                    }
                }

                $modelCount++;
                $messages[] = "Installed: {$model['name']}";

            } catch (Exception $e) {
                $errors[] = "Error with {$model['name']}: " . $e->getMessage();
            }
        }

        // If no models were downloaded, create simple placeholder models
        if ($modelCount === 0) {
            $this->progress($progressCallback, 'Creating placeholder models...');
            $placeholderResult = $this->createPlaceholderModels($assetsDir, $categories, $tags);
            $messages = array_merge($messages, $placeholderResult['messages']);
            $errors = array_merge($errors, $placeholderResult['errors']);
        }

        $messages[] = "Installed $modelCount sample models";

        return [
            'messages' => $messages,
            'errors' => $errors
        ];
    }

    /**
     * Create simple placeholder STL models
     */
    private function createPlaceholderModels($assetsDir, $categories, $tags) {
        $messages = [];
        $errors = [];

        // Simple cube STL (20mm calibration cube)
        // This is the simplest valid STL file - an ASCII STL cube
        $cubeStl = $this->generateCubeSTL(20);

        $filename = 'calibration-cube-20mm.stl';
        $filePath = $assetsDir . '/' . $filename;

        if (file_put_contents($filePath, $cubeStl) !== false) {
            $stmt = $this->db->prepare('
                INSERT INTO models (name, filename, file_path, file_size, file_type, description, creator, created_at)
                VALUES (:name, :filename, :path, :size, :type, :desc, :creator, CURRENT_TIMESTAMP)
            ');
            $stmt->execute([
                ':name' => 'Calibration Cube (20mm)',
                ':filename' => $filename,
                ':path' => 'assets/' . $filename,
                ':size' => strlen($cubeStl),
                ':type' => 'stl',
                ':desc' => 'A simple 20mm calibration cube for testing your 3D printer. Perfect for checking dimensional accuracy.',
                ':creator' => 'Silo Demo'
            ]);

            $modelId = $this->db->lastInsertId();

            // Assign to Tools category
            if (isset($categories['Tools'])) {
                $stmt = $this->db->prepare('INSERT INTO model_categories (model_id, category_id) VALUES (:mid, :cid)');
                $stmt->execute([':mid' => $modelId, ':cid' => $categories['Tools']]);
            }

            // Assign demo tag
            if (isset($tags['demo'])) {
                $stmt = $this->db->prepare('INSERT INTO model_tags (model_id, tag_id) VALUES (:mid, :tid)');
                $stmt->execute([':mid' => $modelId, ':tid' => $tags['demo']]);
            }

            $messages[] = 'Created placeholder calibration cube';
        } else {
            $errors[] = 'Failed to create placeholder cube';
        }

        return [
            'messages' => $messages,
            'errors' => $errors
        ];
    }

    /**
     * Generate a simple cube STL file
     */
    private function generateCubeSTL($size = 20) {
        $half = $size / 2;

        // ASCII STL format for a simple cube
        $stl = "solid cube\n";

        // Define the 12 triangles (2 per face, 6 faces)
        $triangles = [
            // Bottom face (Z = -half)
            [[-$half, -$half, -$half], [$half, -$half, -$half], [$half, $half, -$half], [0, 0, -1]],
            [[-$half, -$half, -$half], [$half, $half, -$half], [-$half, $half, -$half], [0, 0, -1]],
            // Top face (Z = +half)
            [[-$half, -$half, $half], [$half, $half, $half], [$half, -$half, $half], [0, 0, 1]],
            [[-$half, -$half, $half], [-$half, $half, $half], [$half, $half, $half], [0, 0, 1]],
            // Front face (Y = -half)
            [[-$half, -$half, -$half], [-$half, -$half, $half], [$half, -$half, $half], [0, -1, 0]],
            [[-$half, -$half, -$half], [$half, -$half, $half], [$half, -$half, -$half], [0, -1, 0]],
            // Back face (Y = +half)
            [[-$half, $half, -$half], [$half, $half, $half], [-$half, $half, $half], [0, 1, 0]],
            [[-$half, $half, -$half], [$half, $half, -$half], [$half, $half, $half], [0, 1, 0]],
            // Left face (X = -half)
            [[-$half, -$half, -$half], [-$half, $half, $half], [-$half, -$half, $half], [-1, 0, 0]],
            [[-$half, -$half, -$half], [-$half, $half, -$half], [-$half, $half, $half], [-1, 0, 0]],
            // Right face (X = +half)
            [[$half, -$half, -$half], [$half, -$half, $half], [$half, $half, $half], [1, 0, 0]],
            [[$half, -$half, -$half], [$half, $half, $half], [$half, $half, -$half], [1, 0, 0]],
        ];

        foreach ($triangles as $tri) {
            $normal = $tri[3];
            $stl .= sprintf("  facet normal %.6f %.6f %.6f\n", $normal[0], $normal[1], $normal[2]);
            $stl .= "    outer loop\n";
            for ($i = 0; $i < 3; $i++) {
                $stl .= sprintf("      vertex %.6f %.6f %.6f\n", $tri[$i][0], $tri[$i][1], $tri[$i][2]);
            }
            $stl .= "    endloop\n";
            $stl .= "  endfacet\n";
        }

        $stl .= "endsolid cube\n";

        return $stl;
    }

    /**
     * Install models from the seed directory (storage/demo-seed/).
     *
     * Place 3D model files in storage/demo-seed/ and they will be
     * copied into assets/ on every demo reset.
     *
     * Optional: create storage/demo-seed/manifest.json to set metadata:
     * [
     *   {
     *     "file": "my-model.stl",
     *     "name": "My Custom Model",
     *     "description": "A cool demo model",
     *     "creator": "Your Name",
     *     "category": "Tools",
     *     "tags": ["demo", "custom"],
     *     "license": "CC0"
     *   }
     * ]
     *
     * Files without a manifest entry get auto-named from filename.
     */
    private function installSeedModels($seedDir, $progressCallback = null) {
        $messages = [];
        $errors = [];
        $assetsDir = __DIR__ . '/../../../assets';

        if (!is_dir($assetsDir)) {
            mkdir($assetsDir, 0755, true);
        }

        // Load manifest if available
        $manifest = [];
        $manifestFile = $seedDir . '/manifest.json';
        if (file_exists($manifestFile)) {
            $manifestData = json_decode(file_get_contents($manifestFile), true);
            if (is_array($manifestData)) {
                foreach ($manifestData as $entry) {
                    if (isset($entry['file'])) {
                        $manifest[$entry['file']] = $entry;
                    }
                }
            }
        }

        // Get allowed extensions
        $allowedExts = defined('ALLOWED_EXTENSIONS') ? ALLOWED_EXTENSIONS : ['stl', '3mf', 'obj', 'ply', 'glb', 'gltf'];

        // Get category and tag IDs for linking
        $categories = [];
        $stmt = $this->db->query('SELECT id, name FROM categories');
        while ($row = $stmt->fetch()) {
            $categories[$row['name']] = $row['id'];
        }

        $tags = [];
        $stmt = $this->db->query('SELECT id, name FROM tags');
        while ($row = $stmt->fetch()) {
            $tags[$row['name']] = $row['id'];
        }

        // Scan seed directory for model files (non-recursive)
        $files = scandir($seedDir);
        $modelCount = 0;

        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === 'manifest.json') {
                continue;
            }

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts)) {
                continue;
            }

            $srcPath = $seedDir . '/' . $file;
            if (!is_file($srcPath)) {
                continue;
            }

            $this->progress($progressCallback, "Installing seed model: $file...");

            try {
                // Copy file to assets
                $destPath = $assetsDir . '/' . $file;
                if (!copy($srcPath, $destPath)) {
                    $errors[] = "Failed to copy seed file: $file";
                    continue;
                }

                $fileSize = filesize($destPath);

                // Get metadata from manifest or auto-generate from filename
                $meta = $manifest[$file] ?? [];
                $modelName = $meta['name'] ?? $this->filenameToTitle($file);
                $description = $meta['description'] ?? '';
                $creator = $meta['creator'] ?? 'Demo';
                $license = $meta['license'] ?? null;
                $sourceUrl = $meta['source_url'] ?? null;

                // Insert model into database
                $stmt = $this->db->prepare('
                    INSERT INTO models (name, filename, file_path, file_size, file_type, description, creator, source_url, created_at)
                    VALUES (:name, :filename, :path, :size, :type, :desc, :creator, :source, CURRENT_TIMESTAMP)
                ');
                $stmt->execute([
                    ':name' => $modelName,
                    ':filename' => $file,
                    ':path' => 'assets/' . $file,
                    ':size' => $fileSize,
                    ':type' => $ext,
                    ':desc' => $description,
                    ':creator' => $creator,
                    ':source' => $sourceUrl
                ]);

                $modelId = $this->db->lastInsertId();

                // Assign category if specified
                $catName = $meta['category'] ?? null;
                if ($catName && isset($categories[$catName])) {
                    $stmt = $this->db->prepare('INSERT INTO model_categories (model_id, category_id) VALUES (:mid, :cid)');
                    $stmt->execute([':mid' => $modelId, ':cid' => $categories[$catName]]);
                }

                // Assign tags if specified
                $modelTags = $meta['tags'] ?? ['demo'];
                foreach ($modelTags as $tagName) {
                    if (isset($tags[$tagName])) {
                        $stmt = $this->db->prepare('INSERT INTO model_tags (model_id, tag_id) VALUES (:mid, :tid)');
                        $stmt->execute([':mid' => $modelId, ':tid' => $tags[$tagName]]);
                    }
                }

                $modelCount++;
                $messages[] = "Installed seed model: $modelName";

            } catch (Exception $e) {
                $errors[] = "Error with seed file $file: " . $e->getMessage();
            }
        }

        // Also scan subdirectories as multi-part models
        foreach ($files as $dir) {
            $dirPath = $seedDir . '/' . $dir;
            if ($dir === '.' || $dir === '..' || !is_dir($dirPath) || $dir === '.git') {
                continue;
            }

            $this->progress($progressCallback, "Installing seed multi-part model: $dir...");

            try {
                $meta = $manifest[$dir] ?? [];
                $modelName = $meta['name'] ?? $this->filenameToTitle($dir);
                $description = $meta['description'] ?? '';
                $creator = $meta['creator'] ?? 'Demo';

                // Create parent model
                $stmt = $this->db->prepare('
                    INSERT INTO models (name, description, creator, file_type, file_size, file_path, part_count, created_at)
                    VALUES (:name, :desc, :creator, :type, 0, :path, 0, CURRENT_TIMESTAMP)
                ');
                $stmt->execute([
                    ':name' => $modelName,
                    ':desc' => $description,
                    ':creator' => $creator,
                    ':type' => 'stl',
                    ':path' => ''
                ]);
                $parentId = $this->db->lastInsertId();

                // Scan subdirectory for part files
                $partFiles = scandir($dirPath);
                $partCount = 0;

                foreach ($partFiles as $partFile) {
                    if ($partFile === '.' || $partFile === '..') continue;
                    $partExt = strtolower(pathinfo($partFile, PATHINFO_EXTENSION));
                    if (!in_array($partExt, $allowedExts)) continue;

                    $partSrc = $dirPath . '/' . $partFile;
                    if (!is_file($partSrc)) continue;

                    // Copy part file to assets
                    $destFile = $this->sanitizeFilename($dir) . '-' . $partFile;
                    $destPath = $assetsDir . '/' . $destFile;
                    if (!copy($partSrc, $destPath)) continue;

                    $partSize = filesize($destPath);

                    $stmt = $this->db->prepare('
                        INSERT INTO models (name, filename, file_path, file_size, file_type, parent_id, created_at)
                        VALUES (:name, :filename, :path, :size, :type, :parent, CURRENT_TIMESTAMP)
                    ');
                    $stmt->execute([
                        ':name' => $this->filenameToTitle($partFile),
                        ':filename' => $destFile,
                        ':path' => 'assets/' . $destFile,
                        ':size' => $partSize,
                        ':type' => $partExt,
                        ':parent' => $parentId
                    ]);
                    $partCount++;
                }

                // Update parent part count
                if ($partCount > 0) {
                    $stmt = $this->db->prepare('UPDATE models SET part_count = :count WHERE id = :id');
                    $stmt->execute([':count' => $partCount, ':id' => $parentId]);
                }

                // Assign category and tags
                $catName = $meta['category'] ?? null;
                if ($catName && isset($categories[$catName])) {
                    $stmt = $this->db->prepare('INSERT INTO model_categories (model_id, category_id) VALUES (:mid, :cid)');
                    $stmt->execute([':mid' => $parentId, ':cid' => $categories[$catName]]);
                }
                $modelTags = $meta['tags'] ?? ['demo'];
                foreach ($modelTags as $tagName) {
                    if (isset($tags[$tagName])) {
                        $stmt = $this->db->prepare('INSERT INTO model_tags (model_id, tag_id) VALUES (:mid, :tid)');
                        $stmt->execute([':mid' => $parentId, ':tid' => $tags[$tagName]]);
                    }
                }

                $modelCount++;
                $messages[] = "Installed seed multi-part model: $modelName ($partCount parts)";

            } catch (Exception $e) {
                $errors[] = "Error with seed directory $dir: " . $e->getMessage();
            }
        }

        if ($modelCount > 0) {
            $messages[] = "Installed $modelCount seed models total";
        }

        return [
            'messages' => $messages,
            'errors' => $errors
        ];
    }

    /**
     * Convert a filename to a human-readable title
     */
    private function filenameToTitle($filename) {
        // Remove extension
        $name = pathinfo($filename, PATHINFO_FILENAME);
        // Replace separators with spaces
        $name = str_replace(['-', '_', '.'], ' ', $name);
        // Title case
        return ucwords(trim($name));
    }

    /**
     * Download a file from URL
     */
    private function downloadFile($url) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'Silo Demo Mode/1.0'
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ]);

        return @file_get_contents($url, false, $context);
    }

    /**
     * Sanitize a string for use as a filename
     */
    private function sanitizeFilename($name) {
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9]+/', '-', $name);
        $name = trim($name, '-');
        return $name;
    }

    /**
     * Clear a directory of all files
     */
    private function clearDirectory($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
    }

    /**
     * Call progress callback if provided
     */
    private function progress($callback, $message) {
        if (is_callable($callback)) {
            $callback($message);
        }
    }
}
