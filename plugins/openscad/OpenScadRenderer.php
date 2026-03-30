<?php
/**
 * OpenSCAD Renderer
 *
 * Executes OpenSCAD to render .scad files to STL/3MF.
 * Supports local binary or Docker container execution.
 */
class OpenScadRenderer
{
    private string $binary;
    private bool $dockerMode;
    private string $dockerContainer;
    private int $timeout;

    public function __construct(array $settings = [])
    {
        $this->binary = $settings['openscad_path'] ?? 'openscad';
        $this->dockerMode = ($settings['docker_mode'] ?? '0') === '1';
        $this->dockerContainer = $settings['docker_container'] ?? 'meshsilo-openscad';
        $this->timeout = (int)($settings['render_timeout'] ?? 120);
    }

    /**
     * Check if OpenSCAD is available.
     */
    public function isAvailable(): bool
    {
        if ($this->dockerMode) {
            exec('docker inspect ' . escapeshellarg($this->dockerContainer) . ' 2>/dev/null', $output, $rc);
            return $rc === 0;
        }

        exec('which ' . escapeshellarg($this->binary) . ' 2>/dev/null', $output, $rc);
        return $rc === 0;
    }

    /**
     * Get OpenSCAD version string.
     */
    public function getVersion(): ?string
    {
        $cmd = $this->dockerMode
            ? 'docker exec ' . escapeshellarg($this->dockerContainer) . ' openscad --version 2>&1'
            : escapeshellarg($this->binary) . ' --version 2>&1';

        exec($cmd, $output, $rc);
        return $rc === 0 && !empty($output) ? trim($output[0]) : null;
    }

    /**
     * Render a .scad file to STL or 3MF.
     *
     * @param string $inputPath Absolute path to the .scad file
     * @param string $outputPath Absolute path for the output file
     * @param string $format Output format: 'stl' or '3mf'
     * @param array $overrideArgs Array of -D arguments from OpenScadParser::buildOverrides()
     * @return array ['success' => bool, 'output' => string, 'duration_ms' => int, 'file_size' => int]
     */
    public function render(string $inputPath, string $outputPath, string $format = 'stl', array $overrideArgs = []): array
    {
        $startTime = microtime(true);

        if (!file_exists($inputPath)) {
            return ['success' => false, 'output' => 'Input file not found', 'duration_ms' => 0, 'file_size' => 0];
        }

        // Build command
        $args = ['-o', $outputPath];
        $args = array_merge($args, $overrideArgs);
        $args[] = $inputPath;

        if ($this->dockerMode) {
            $cmd = 'docker exec ' . escapeshellarg($this->dockerContainer) . ' openscad';
            foreach ($args as $arg) {
                $cmd .= ' ' . escapeshellarg($arg);
            }
        } else {
            $cmd = escapeshellarg($this->binary);
            foreach ($args as $arg) {
                $cmd .= ' ' . escapeshellarg($arg);
            }
        }

        $cmd .= ' 2>&1';

        // Execute with timeout
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['success' => false, 'output' => 'Failed to start OpenSCAD process', 'duration_ms' => 0, 'file_size' => 0];
        }

        fclose($pipes[0]);

        // Read output with timeout
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $deadline = time() + $this->timeout;

        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (time() > $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return ['success' => false, 'output' => "Render timed out after {$this->timeout}s", 'duration_ms' => (int)((microtime(true) - $startTime) * 1000), 'file_size' => 0];
            }

            $output .= fread($pipes[1], 8192);
            $output .= fread($pipes[2], 8192);
            usleep(50000); // 50ms poll
        }

        $output .= stream_get_contents($pipes[1]);
        $output .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $durationMs = (int)((microtime(true) - $startTime) * 1000);
        $fileSize = file_exists($outputPath) ? filesize($outputPath) : 0;

        return [
            'success' => $exitCode === 0 && $fileSize > 0,
            'output' => trim($output),
            'duration_ms' => $durationMs,
            'file_size' => $fileSize,
            'exit_code' => $exitCode,
        ];
    }

    /**
     * Render a PNG preview image.
     */
    public function renderPreview(string $inputPath, string $outputPath, int $size = 512, array $overrideArgs = []): array
    {
        $args = [
            '-o', $outputPath,
            '--imgsize=' . $size . ',' . $size,
            '--camera=0,0,0,55,0,25,500',
            '--colorscheme=Tomorrow',
        ];
        $args = array_merge($args, $overrideArgs);
        $args[] = $inputPath;

        $startTime = microtime(true);

        if ($this->dockerMode) {
            $cmd = 'docker exec ' . escapeshellarg($this->dockerContainer) . ' openscad';
        } else {
            $cmd = escapeshellarg($this->binary);
        }

        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        $cmd .= ' 2>&1';

        exec($cmd, $output, $rc);
        $durationMs = (int)((microtime(true) - $startTime) * 1000);

        return [
            'success' => $rc === 0 && file_exists($outputPath),
            'output' => implode("\n", $output),
            'duration_ms' => $durationMs,
        ];
    }
}
