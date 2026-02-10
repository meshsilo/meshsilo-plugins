<?php
/**
 * Mesh Analyzer for STL Files
 *
 * Analyzes STL files for common mesh issues:
 * - Non-manifold edges
 * - Inverted normals
 * - Holes in the mesh
 * - Degenerate triangles
 *
 * Optional dependency: admesh CLI tool for repair
 */

class MeshAnalyzer {
    // Issue severity levels
    const SEVERITY_INFO = 'info';
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_ERROR = 'error';

    /**
     * Analyze a mesh file for issues
     *
     * @param string $filePath Path to STL file
     * @return array Analysis results
     */
    public static function analyze($filePath) {
        if (!file_exists($filePath)) {
            return ['error' => 'File not found'];
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension !== 'stl') {
            return ['error' => 'Only STL files can be analyzed'];
        }

        // Try admesh first (more accurate)
        if (self::isAdmeshAvailable()) {
            return self::analyzeWithAdmesh($filePath);
        }

        // Fall back to basic PHP analysis
        return self::analyzeBasic($filePath);
    }

    /**
     * Analyze STL using admesh CLI tool
     */
    private static function analyzeWithAdmesh($filePath) {
        $command = sprintf('admesh --info "%s" 2>&1', escapeshellarg($filePath));
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            return self::analyzeBasic($filePath);
        }

        $outputText = implode("\n", $output);

        $result = [
            'tool' => 'admesh',
            'is_manifold' => true,
            'issues' => [],
            'stats' => [],
            'raw_output' => $outputText
        ];

        // Parse admesh output
        foreach ($output as $line) {
            // Total facets
            if (preg_match('/Number of facets\s*:\s*(\d+)/', $line, $m)) {
                $result['stats']['facets'] = (int)$m[1];
            }
            // Edges fixed
            if (preg_match('/Edges fixed\s*:\s*(\d+)/', $line, $m)) {
                $fixed = (int)$m[1];
                if ($fixed > 0) {
                    $result['issues'][] = [
                        'type' => 'edges_need_fixing',
                        'count' => $fixed,
                        'severity' => self::SEVERITY_WARNING,
                        'message' => "$fixed edges need fixing"
                    ];
                    $result['is_manifold'] = false;
                }
            }
            // Facets removed
            if (preg_match('/Facets removed\s*:\s*(\d+)/', $line, $m)) {
                $removed = (int)$m[1];
                if ($removed > 0) {
                    $result['issues'][] = [
                        'type' => 'degenerate_facets',
                        'count' => $removed,
                        'severity' => self::SEVERITY_WARNING,
                        'message' => "$removed degenerate facets detected"
                    ];
                }
            }
            // Facets added
            if (preg_match('/Facets added\s*:\s*(\d+)/', $line, $m)) {
                $added = (int)$m[1];
                if ($added > 0) {
                    $result['issues'][] = [
                        'type' => 'holes',
                        'count' => $added,
                        'severity' => self::SEVERITY_WARNING,
                        'message' => "$added holes detected (would add $added facets)"
                    ];
                    $result['is_manifold'] = false;
                }
            }
            // Backwards edges
            if (preg_match('/Backwards edges\s*:\s*(\d+)/', $line, $m)) {
                $backwards = (int)$m[1];
                if ($backwards > 0) {
                    $result['issues'][] = [
                        'type' => 'inverted_normals',
                        'count' => $backwards,
                        'severity' => self::SEVERITY_WARNING,
                        'message' => "$backwards inverted normals detected"
                    ];
                }
            }
            // Normals fixed
            if (preg_match('/Normals fixed\s*:\s*(\d+)/', $line, $m)) {
                $normals = (int)$m[1];
                if ($normals > 0) {
                    $result['issues'][] = [
                        'type' => 'normals_need_fixing',
                        'count' => $normals,
                        'severity' => self::SEVERITY_INFO,
                        'message' => "$normals normals need recalculating"
                    ];
                }
            }
            // Bounding box
            if (preg_match('/Min.*:\s*([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)/', $line, $m)) {
                $result['stats']['min'] = [(float)$m[1], (float)$m[2], (float)$m[3]];
            }
            if (preg_match('/Max.*:\s*([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)/', $line, $m)) {
                $result['stats']['max'] = [(float)$m[1], (float)$m[2], (float)$m[3]];
            }
        }

        // Determine overall status
        $result['can_repair'] = !$result['is_manifold'] && self::isAdmeshAvailable();
        $result['issue_count'] = count($result['issues']);
        $result['max_severity'] = self::getMaxSeverity($result['issues']);

        return $result;
    }

    /**
     * Basic PHP-based STL analysis (less accurate than admesh)
     */
    private static function analyzeBasic($filePath) {
        $result = [
            'tool' => 'basic',
            'is_manifold' => null, // Unknown without proper analysis
            'issues' => [],
            'stats' => []
        ];

        $content = file_get_contents($filePath);
        if ($content === false) {
            return ['error' => 'Could not read file'];
        }

        // Detect binary vs ASCII STL
        $isBinary = substr($content, 0, 5) !== 'solid' || strpos($content, 'facet') === false;

        if ($isBinary) {
            // Binary STL: 80 byte header + 4 byte count + triangles
            $facetCount = unpack('V', substr($content, 80, 4))[1] ?? 0;
            $result['stats']['facets'] = $facetCount;
            $result['stats']['format'] = 'binary';

            // Check file size consistency
            $expectedSize = 84 + ($facetCount * 50);
            $actualSize = strlen($content);
            if (abs($actualSize - $expectedSize) > 4) {
                $result['issues'][] = [
                    'type' => 'size_mismatch',
                    'severity' => self::SEVERITY_WARNING,
                    'message' => "File size doesn't match facet count (expected $expectedSize, got $actualSize bytes)"
                ];
            }
        } else {
            // ASCII STL
            $result['stats']['format'] = 'ascii';
            preg_match_all('/facet\s+normal/i', $content, $matches);
            $result['stats']['facets'] = count($matches[0]);
        }

        // Very basic manifold check: model should have at least some facets
        if (($result['stats']['facets'] ?? 0) < 4) {
            $result['issues'][] = [
                'type' => 'insufficient_facets',
                'severity' => self::SEVERITY_ERROR,
                'message' => 'Model has fewer than 4 facets - cannot form a valid solid'
            ];
            $result['is_manifold'] = false;
        }

        $result['issue_count'] = count($result['issues']);
        $result['max_severity'] = self::getMaxSeverity($result['issues']);
        $result['can_repair'] = false; // Basic analysis can't determine repairability
        $result['admesh_available'] = self::isAdmeshAvailable();

        if (!self::isAdmeshAvailable()) {
            $result['issues'][] = [
                'type' => 'limited_analysis',
                'severity' => self::SEVERITY_INFO,
                'message' => 'Install admesh for detailed mesh analysis'
            ];
        }

        return $result;
    }

    /**
     * Repair a mesh file using admesh
     *
     * @param string $inputPath Path to STL file
     * @param string|null $outputPath Output path (null = overwrite)
     * @return array Result with success status
     */
    public static function repair($inputPath, $outputPath = null) {
        if (!self::isAdmeshAvailable()) {
            return ['success' => false, 'error' => 'admesh is not available'];
        }

        if (!file_exists($inputPath)) {
            return ['success' => false, 'error' => 'File not found'];
        }

        // Create backup
        $backupPath = $inputPath . '.backup';
        if (!copy($inputPath, $backupPath)) {
            return ['success' => false, 'error' => 'Could not create backup'];
        }

        $output = $outputPath ?: $inputPath;

        // Run admesh with repair options
        $command = sprintf(
            'admesh --fill-holes --fix-normal --normal-values --remove-unconnected --exact -b "%s" "%s" 2>&1',
            escapeshellarg($output),
            escapeshellarg($inputPath)
        );

        exec($command, $cmdOutput, $returnCode);

        if ($returnCode !== 0) {
            // Restore from backup
            if ($outputPath === null) {
                rename($backupPath, $inputPath);
            }
            return [
                'success' => false,
                'error' => 'Repair failed',
                'output' => implode("\n", $cmdOutput)
            ];
        }

        // Clean up backup
        @unlink($backupPath);

        return [
            'success' => true,
            'output_path' => $output,
            'output' => implode("\n", $cmdOutput)
        ];
    }

    /**
     * Check if admesh is available
     */
    public static function isAdmeshAvailable() {
        static $available = null;

        if ($available === null) {
            exec('which admesh 2>/dev/null', $output, $returnCode);
            $available = $returnCode === 0;
        }

        return $available;
    }

    /**
     * Get the maximum severity from a list of issues
     */
    private static function getMaxSeverity($issues) {
        $severities = [self::SEVERITY_INFO => 0, self::SEVERITY_WARNING => 1, self::SEVERITY_ERROR => 2];
        $max = -1;
        $maxSeverity = null;

        foreach ($issues as $issue) {
            $level = $severities[$issue['severity']] ?? 0;
            if ($level > $max) {
                $max = $level;
                $maxSeverity = $issue['severity'];
            }
        }

        return $maxSeverity;
    }

    /**
     * Update model's mesh status in database
     */
    public static function updateModelMeshStatus($modelId, $analysisResult) {
        $db = getDB();

        // Ensure columns exist
        self::ensureMeshColumns($db);

        $isManifold = $analysisResult['is_manifold'] ?? null;
        $errors = !empty($analysisResult['issues']) ? json_encode($analysisResult['issues']) : null;

        $stmt = $db->prepare('UPDATE models SET is_manifold = :manifold, mesh_errors = :errors WHERE id = :id');
        $stmt->bindValue(':manifold', $isManifold === null ? null : ($isManifold ? 1 : 0), PDO::PARAM_INT);
        $stmt->bindValue(':errors', $errors, PDO::PARAM_STR);
        $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);

        return $stmt->execute() !== false;
    }

    /**
     * Ensure mesh analysis columns exist
     */
    private static function ensureMeshColumns($db) {
        static $checked = false;
        if ($checked) return;

        try {
            if ($db->getType() === 'mysql') {
                $db->exec('ALTER TABLE models ADD COLUMN is_manifold TINYINT DEFAULT NULL');
                $db->exec('ALTER TABLE models ADD COLUMN mesh_errors TEXT DEFAULT NULL');
            } else {
                $db->exec('ALTER TABLE models ADD COLUMN is_manifold INTEGER DEFAULT NULL');
                $db->exec('ALTER TABLE models ADD COLUMN mesh_errors TEXT DEFAULT NULL');
            }
        } catch (Exception $e) {
            // Columns probably already exist
        }

        $checked = true;
    }

    /**
     * Get mesh status for a model
     */
    public static function getMeshStatus($model) {
        if (!isset($model['is_manifold'])) {
            return null;
        }

        if ($model['is_manifold'] === null) {
            return null; // Not analyzed yet
        }

        return [
            'is_manifold' => (bool)$model['is_manifold'],
            'issues' => !empty($model['mesh_errors']) ? json_decode($model['mesh_errors'], true) : []
        ];
    }
}
