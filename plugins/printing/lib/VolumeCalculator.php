<?php
/**
 * Volume Calculator for 3D Models
 *
 * Calculates mesh volume for STL and 3MF files
 * Used for print cost estimation
 */

class VolumeCalculator {
    /**
     * Calculate volume from a 3D model file
     *
     * @param string $filePath Path to the model file
     * @param string $fileType File type (stl, 3mf)
     * @return float|null Volume in cubic centimeters, or null on failure
     */
    public static function calculateVolume($filePath, $fileType = null) {
        if (!is_file($filePath)) {
            return null;
        }

        $fileType = $fileType ?: strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        switch ($fileType) {
            case 'stl':
                return self::calculateSTLVolume($filePath);
            case '3mf':
                return self::calculate3MFVolume($filePath);
            default:
                return null;
        }
    }

    /**
     * Calculate volume from STL file
     *
     * Uses the signed volume method for calculating mesh volume
     * Assumes the model is manifold (watertight)
     */
    private static function calculateSTLVolume($filePath) {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        // Check if binary or ASCII STL
        if (substr($content, 0, 5) === 'solid' && strpos($content, 'facet') !== false) {
            return self::calculateASCIISTLVolume($content);
        } else {
            return self::calculateBinarySTLVolume($filePath);
        }
    }

    /**
     * Calculate volume from binary STL
     */
    private static function calculateBinarySTLVolume($filePath) {
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return null;
        }

        // Skip 80-byte header
        fseek($handle, 80);

        // Read number of triangles (little-endian uint32)
        $numTriangles = unpack('V', fread($handle, 4))[1];

        $volume = 0.0;

        // Each triangle is 50 bytes: 12 bytes normal + 3*12 bytes vertices + 2 bytes attribute
        for ($i = 0; $i < $numTriangles; $i++) {
            // Skip normal vector (12 bytes)
            fseek($handle, 12, SEEK_CUR);

            // Read vertices (3 vertices * 3 floats * 4 bytes = 36 bytes)
            $v1 = unpack('f3', fread($handle, 12));
            $v2 = unpack('f3', fread($handle, 12));
            $v3 = unpack('f3', fread($handle, 12));

            // Skip attribute byte count
            fseek($handle, 2, SEEK_CUR);

            // Calculate signed volume of tetrahedron formed with origin
            $volume += self::signedVolumeOfTriangle(
                [$v1[1], $v1[2], $v1[3]],
                [$v2[1], $v2[2], $v2[3]],
                [$v3[1], $v3[2], $v3[3]]
            );
        }

        fclose($handle);

        // Convert from mm^3 to cm^3 (divide by 1000)
        return abs($volume) / 1000.0;
    }

    /**
     * Calculate volume from ASCII STL
     */
    private static function calculateASCIISTLVolume($content) {
        $volume = 0.0;

        // Parse facets
        preg_match_all('/facet\s+normal\s+[\d.e+-]+\s+[\d.e+-]+\s+[\d.e+-]+\s+outer\s+loop\s+vertex\s+([\d.e+-]+)\s+([\d.e+-]+)\s+([\d.e+-]+)\s+vertex\s+([\d.e+-]+)\s+([\d.e+-]+)\s+([\d.e+-]+)\s+vertex\s+([\d.e+-]+)\s+([\d.e+-]+)\s+([\d.e+-]+)\s+endloop\s+endfacet/i', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $v1 = [(float)$match[1], (float)$match[2], (float)$match[3]];
            $v2 = [(float)$match[4], (float)$match[5], (float)$match[6]];
            $v3 = [(float)$match[7], (float)$match[8], (float)$match[9]];

            $volume += self::signedVolumeOfTriangle($v1, $v2, $v3);
        }

        // Convert from mm^3 to cm^3
        return abs($volume) / 1000.0;
    }

    /**
     * Calculate volume from 3MF file
     *
     * 3MF files are ZIP archives containing XML mesh data
     */
    private static function calculate3MFVolume($filePath) {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return null;
        }

        $totalVolume = 0.0;

        // Look for model files (typically 3D/3dmodel.model)
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('/\.model$/i', $name)) {
                $content = $zip->getFromIndex($i);
                $volume = self::parseModelXML($content);
                if ($volume !== null) {
                    $totalVolume += $volume;
                }
            }
        }

        $zip->close();

        return $totalVolume > 0 ? $totalVolume : null;
    }

    /**
     * Parse 3MF model XML and calculate volume
     */
    private static function parseModelXML($xmlContent) {
        // Suppress XML errors
        $previousValue = libxml_use_internal_errors(true);

        $xml = simplexml_load_string($xmlContent);
        if ($xml === false) {
            libxml_use_internal_errors($previousValue);
            return null;
        }

        // Register namespace
        $namespaces = $xml->getNamespaces(true);
        $ns = '';
        foreach ($namespaces as $prefix => $uri) {
            if (strpos($uri, '3dmanufacturing') !== false) {
                $ns = $prefix ?: '';
                break;
            }
        }

        $volume = 0.0;

        // Find mesh elements
        if ($ns) {
            $xml->registerXPathNamespace('m', $namespaces[$ns] ?? 'http://schemas.microsoft.com/3dmanufacturing/core/2015/02');
            $meshes = $xml->xpath('//m:mesh');
        } else {
            $meshes = $xml->xpath('//*[local-name()="mesh"]');
        }

        foreach ($meshes as $mesh) {
            // Get vertices
            $vertices = [];
            $vertexElements = $mesh->xpath('.//*[local-name()="vertex"]');
            foreach ($vertexElements as $v) {
                $vertices[] = [
                    (float)$v['x'],
                    (float)$v['y'],
                    (float)$v['z']
                ];
            }

            // Get triangles and calculate volume
            $triangles = $mesh->xpath('.//*[local-name()="triangle"]');
            foreach ($triangles as $t) {
                $v1 = (int)$t['v1'];
                $v2 = (int)$t['v2'];
                $v3 = (int)$t['v3'];

                if (isset($vertices[$v1], $vertices[$v2], $vertices[$v3])) {
                    $volume += self::signedVolumeOfTriangle(
                        $vertices[$v1],
                        $vertices[$v2],
                        $vertices[$v3]
                    );
                }
            }
        }

        libxml_use_internal_errors($previousValue);

        // Convert from mm^3 to cm^3
        return abs($volume) / 1000.0;
    }

    /**
     * Calculate signed volume of a triangle with the origin
     *
     * Using the cross product method for signed tetrahedron volume
     */
    private static function signedVolumeOfTriangle($v1, $v2, $v3) {
        // Calculate cross product (v2-v1) x (v3-v1)
        $ax = $v2[0] - $v1[0];
        $ay = $v2[1] - $v1[1];
        $az = $v2[2] - $v1[2];

        $bx = $v3[0] - $v1[0];
        $by = $v3[1] - $v1[1];
        $bz = $v3[2] - $v1[2];

        // Cross product
        $cx = $ay * $bz - $az * $by;
        $cy = $az * $bx - $ax * $bz;
        $cz = $ax * $by - $ay * $bx;

        // Dot with v1 and divide by 6
        return ($v1[0] * $cx + $v1[1] * $cy + $v1[2] * $cz) / 6.0;
    }

    /**
     * Estimate print cost based on volume
     *
     * @param float $volumeCm3 Volume in cubic centimeters
     * @param string $material Material type (pla, petg, abs, resin)
     * @return array Cost estimate with details
     */
    public static function estimateCost($volumeCm3, $material = 'pla') {
        // Material densities in g/cm^3
        $densities = [
            'pla' => 1.24,
            'petg' => 1.27,
            'abs' => 1.04,
            'tpu' => 1.21,
            'resin' => 1.10,
        ];

        // Default material prices per kg (user-configurable)
        $defaultPrices = [
            'pla' => 20.00,
            'petg' => 25.00,
            'abs' => 22.00,
            'tpu' => 35.00,
            'resin' => 40.00,
        ];

        // Get user-configured prices from settings
        $priceKey = "material_price_{$material}";
        $pricePerKg = (float)(getSetting($priceKey) ?: ($defaultPrices[$material] ?? 25.00));
        $currency = getSetting('currency', 'USD');

        $density = $densities[$material] ?? 1.20;
        $weightGrams = $volumeCm3 * $density;
        $weightKg = $weightGrams / 1000;
        $cost = $weightKg * $pricePerKg;

        // Apply infill factor (assume 20% infill by default reduces material use)
        $infillFactor = (float)(getSetting('default_infill', 20) / 100);
        $adjustedCost = $cost * $infillFactor;

        return [
            'volume_cm3' => round($volumeCm3, 2),
            'weight_grams' => round($weightGrams, 1),
            'material' => $material,
            'density' => $density,
            'price_per_kg' => $pricePerKg,
            'raw_cost' => round($cost, 2),
            'infill_factor' => $infillFactor * 100,
            'estimated_cost' => round($adjustedCost, 2),
            'currency' => $currency,
        ];
    }

    /**
     * Update model volume in database
     *
     * @param int $modelId Model ID
     * @param float $volumeCm3 Volume in cubic centimeters
     * @return bool Success
     */
    public static function updateModelVolume($modelId, $volumeCm3) {
        $db = getDB();

        // Ensure column exists
        self::ensureVolumeColumn($db);

        $stmt = $db->prepare('UPDATE models SET volume_cm3 = :volume WHERE id = :id');
        $stmt->bindValue(':volume', $volumeCm3, PDO::PARAM_STR);
        $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);

        return $stmt->execute() !== false;
    }

    /**
     * Ensure the volume_cm3 column exists
     */
    private static function ensureVolumeColumn($db) {
        static $checked = false;
        if ($checked) return;

        try {
            if ($db->getType() === 'mysql') {
                $db->exec('ALTER TABLE models ADD COLUMN volume_cm3 DOUBLE DEFAULT NULL');
            } else {
                $db->exec('ALTER TABLE models ADD COLUMN volume_cm3 REAL DEFAULT NULL');
            }
        } catch (Exception $e) {
            // Column probably already exists
        }

        $checked = true;
    }

    /**
     * Get model volume from database (or calculate if not stored)
     *
     * @param array $model Model record
     * @return float|null Volume in cm^3
     */
    public static function getModelVolume($model) {
        try {
            // Return cached volume if available
            if (!empty($model['volume_cm3'])) {
                return (float)$model['volume_cm3'];
            }

            // Calculate and cache
            $filePath = getAbsoluteFilePath($model);
            if ($filePath && is_file($filePath)) {
                $volume = self::calculateVolume($filePath, $model['file_type']);
                if ($volume !== null) {
                    self::updateModelVolume($model['id'], $volume);
                    return $volume;
                }
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
