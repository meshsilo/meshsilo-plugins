<?php
/**
 * Advanced Analytics System
 *
 * Provides comprehensive analytics, custom dashboards,
 * and scheduled report generation with export capabilities
 */

class Analytics {
    // Report types
    const REPORT_USAGE = 'usage';
    const REPORT_STORAGE = 'storage';
    const REPORT_DOWNLOADS = 'downloads';
    const REPORT_UPLOADS = 'uploads';
    const REPORT_USERS = 'users';
    const REPORT_CATEGORIES = 'categories';
    const REPORT_CUSTOM = 'custom';

    // Export formats
    const FORMAT_CSV = 'csv';
    const FORMAT_JSON = 'json';
    const FORMAT_EXCEL = 'excel';

    // Time periods
    const PERIOD_DAY = 'day';
    const PERIOD_WEEK = 'week';
    const PERIOD_MONTH = 'month';
    const PERIOD_YEAR = 'year';

    /**
     * Get dashboard summary statistics
     */
    public static function getDashboardStats($period = 'month') {
        $db = getDB();
        $stats = [];

        $periodStart = self::getPeriodStart($period);

        // Total models
        $stats['total_models'] = $db->querySingle('SELECT COUNT(*) FROM models');

        // Models this period
        $stmt = $db->prepare('SELECT COUNT(*) FROM models WHERE created_at >= :start');
        $stmt->bindValue(':start', $periodStart, PDO::PARAM_STR);
        $stats['new_models'] = $stmt->execute()->fetchArray()[0];

        // Total storage
        $result = $db->query('SELECT SUM(file_size) as total FROM models');
        $row = $result->fetchArray(PDO::FETCH_ASSOC);
        $stats['total_storage'] = $row['total'] ?? 0;

        // Downloads this period
        $stmt = $db->prepare('
            SELECT COUNT(*) FROM activity_log
            WHERE action = :action AND created_at >= :start
        ');
        $stmt->bindValue(':action', 'download', PDO::PARAM_STR);
        $stmt->bindValue(':start', $periodStart, PDO::PARAM_STR);
        $stats['downloads'] = $stmt->execute()->fetchArray()[0];

        // Active users this period
        $stmt = $db->prepare('
            SELECT COUNT(DISTINCT user_id) FROM activity_log
            WHERE created_at >= :start AND user_id IS NOT NULL
        ');
        $stmt->bindValue(':start', $periodStart, PDO::PARAM_STR);
        $stats['active_users'] = $stmt->execute()->fetchArray()[0];

        // Total users
        $stats['total_users'] = $db->querySingle('SELECT COUNT(*) FROM users');

        // Views this period
        $stmt = $db->prepare('SELECT COUNT(*) FROM recently_viewed WHERE viewed_at >= :start');
        $stmt->bindValue(':start', $periodStart, PDO::PARAM_STR);
        $stats['views'] = $stmt->execute()->fetchArray()[0];

        // Favorites this period
        $stmt = $db->prepare('SELECT COUNT(*) FROM favorites WHERE created_at >= :start');
        $stmt->bindValue(':start', $periodStart, PDO::PARAM_STR);
        $stats['favorites'] = $stmt->execute()->fetchArray()[0];

        return $stats;
    }

    /**
     * Get time series data for charts
     */
    public static function getTimeSeries($metric, $period = 'month', $groupBy = 'day') {
        $db = getDB();
        $periodStart = self::getPeriodStart($period);

        $dateFormat = self::getDateFormat($groupBy);
        $data = [];

        switch ($metric) {
            case 'uploads':
                $dateExpr = self::buildDateFormatSQL('created_at', $dateFormat);
                $sql = "
                    SELECT $dateExpr as period, COUNT(*) as value
                    FROM models WHERE created_at >= :start
                    GROUP BY period ORDER BY period
                ";
                break;

            case 'downloads':
                $dateExpr = self::buildDateFormatSQL('created_at', $dateFormat);
                $sql = "
                    SELECT $dateExpr as period, COUNT(*) as value
                    FROM activity_log WHERE action = 'download' AND created_at >= :start
                    GROUP BY period ORDER BY period
                ";
                break;

            case 'views':
                $dateExpr = self::buildDateFormatSQL('viewed_at', $dateFormat);
                $sql = "
                    SELECT $dateExpr as period, COUNT(*) as value
                    FROM recently_viewed WHERE viewed_at >= :start
                    GROUP BY period ORDER BY period
                ";
                break;

            case 'users':
                $dateExpr = self::buildDateFormatSQL('created_at', $dateFormat);
                $sql = "
                    SELECT $dateExpr as period, COUNT(*) as value
                    FROM users WHERE created_at >= :start
                    GROUP BY period ORDER BY period
                ";
                break;

            case 'storage':
                $dateExpr = self::buildDateFormatSQL('created_at', $dateFormat);
                $sql = "
                    SELECT $dateExpr as period, SUM(file_size) as value
                    FROM models WHERE created_at >= :start
                    GROUP BY period ORDER BY period
                ";
                break;

            default:
                return [];
        }

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':start', $periodStart, PDO::PARAM_STR);
        $result = $stmt->execute();

        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $data[] = $row;
        }

        return $data;
    }

    /**
     * Get top items report
     */
    public static function getTopItems($type, $limit = 10, $period = 'month') {
        $db = getDB();
        $periodStart = self::getPeriodStart($period);
        $data = [];

        switch ($type) {
            case 'models':
                // Most downloaded models
                $stmt = $db->prepare("
                    SELECT m.id, m.name, m.download_count, COUNT(a.id) as period_downloads
                    FROM models m
                    LEFT JOIN activity_log a ON a.entity_id = m.id
                        AND a.entity_type = 'model' AND a.action = 'download'
                        AND a.created_at >= :start
                    GROUP BY m.id
                    ORDER BY period_downloads DESC, m.download_count DESC
                    LIMIT :limit
                ");
                $stmt->bindValue(':start', $periodStart, PDO::PARAM_STR);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                break;

            case 'categories':
                // Most active categories
                $stmt = $db->prepare("
                    SELECT c.id, c.name, COUNT(m.id) as model_count,
                           SUM(m.download_count) as total_downloads
                    FROM categories c
                    LEFT JOIN models m ON m.category_id = c.id
                    GROUP BY c.id
                    ORDER BY model_count DESC
                    LIMIT :limit
                ");
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                break;

            case 'users':
                // Most active users
                $stmt = $db->prepare("
                    SELECT u.id, u.username, COUNT(a.id) as activity_count
                    FROM users u
                    LEFT JOIN activity_log a ON a.user_id = u.id AND a.created_at >= :start
                    GROUP BY u.id
                    ORDER BY activity_count DESC
                    LIMIT :limit
                ");
                $stmt->bindValue(':start', $periodStart, PDO::PARAM_STR);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                break;

            case 'tags':
                // Most used tags
                $stmt = $db->prepare("
                    SELECT t.id, t.name, t.color, COUNT(mt.model_id) as usage_count
                    FROM tags t
                    LEFT JOIN model_tags mt ON mt.tag_id = t.id
                    GROUP BY t.id
                    ORDER BY usage_count DESC
                    LIMIT :limit
                ");
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                break;

            default:
                return [];
        }

        $result = $stmt->execute();
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $data[] = $row;
        }

        return $data;
    }

    /**
     * Get storage breakdown by category
     */
    public static function getStorageBreakdown() {
        $db = getDB();

        $sql = "
            SELECT c.id, c.name, COUNT(m.id) as model_count,
                   COALESCE(SUM(m.file_size), 0) as total_size
            FROM categories c
            LEFT JOIN models m ON m.category_id = c.id
            GROUP BY c.id
            ORDER BY total_size DESC
        ";

        $result = $db->query($sql);
        $data = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $data[] = $row;
        }

        // Add uncategorized
        $uncategorized = $db->query("
            SELECT COUNT(*) as model_count, COALESCE(SUM(file_size), 0) as total_size
            FROM models WHERE category_id IS NULL
        ")->fetchArray(PDO::FETCH_ASSOC);

        if ($uncategorized['model_count'] > 0) {
            $data[] = [
                'id' => null,
                'name' => 'Uncategorized',
                'model_count' => $uncategorized['model_count'],
                'total_size' => $uncategorized['total_size']
            ];
        }

        return $data;
    }

    /**
     * Get user activity distribution
     */
    public static function getUserActivityDistribution($period = 'month') {
        $db = getDB();
        $periodStart = self::getPeriodStart($period);

        $stmt = $db->prepare("
            SELECT action, COUNT(*) as count
            FROM activity_log
            WHERE created_at >= :start
            GROUP BY action
            ORDER BY count DESC
        ");
        $stmt->bindValue(':start', $periodStart, PDO::PARAM_STR);

        $result = $stmt->execute();
        $data = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $data[] = $row;
        }

        return $data;
    }

    /**
     * Generate a full report
     */
    public static function generateReport($type, $options = []) {
        $period = $options['period'] ?? 'month';
        $format = $options['format'] ?? self::FORMAT_JSON;

        $report = [
            'type' => $type,
            'generated_at' => date('Y-m-d H:i:s'),
            'period' => $period,
            'period_start' => self::getPeriodStart($period),
            'period_end' => date('Y-m-d H:i:s'),
            'data' => []
        ];

        switch ($type) {
            case self::REPORT_USAGE:
                $report['data'] = [
                    'summary' => self::getDashboardStats($period),
                    'uploads_trend' => self::getTimeSeries('uploads', $period),
                    'downloads_trend' => self::getTimeSeries('downloads', $period),
                    'views_trend' => self::getTimeSeries('views', $period),
                    'activity_distribution' => self::getUserActivityDistribution($period)
                ];
                break;

            case self::REPORT_STORAGE:
                $report['data'] = [
                    'breakdown' => self::getStorageBreakdown(),
                    'growth_trend' => self::getTimeSeries('storage', $period),
                    'top_models_by_size' => self::getTopModelsBySize(10)
                ];
                break;

            case self::REPORT_DOWNLOADS:
                $report['data'] = [
                    'trend' => self::getTimeSeries('downloads', $period),
                    'top_models' => self::getTopItems('models', 20, $period),
                    'by_category' => self::getDownloadsByCategory($period)
                ];
                break;

            case self::REPORT_UPLOADS:
                $report['data'] = [
                    'trend' => self::getTimeSeries('uploads', $period),
                    'by_user' => self::getUploadsByUser($period),
                    'by_category' => self::getUploadsByCategory($period)
                ];
                break;

            case self::REPORT_USERS:
                $report['data'] = [
                    'growth_trend' => self::getTimeSeries('users', $period),
                    'top_users' => self::getTopItems('users', 20, $period),
                    'active_users' => self::getActiveUserStats($period)
                ];
                break;

            case self::REPORT_CATEGORIES:
                $report['data'] = [
                    'storage' => self::getStorageBreakdown(),
                    'activity' => self::getTopItems('categories', 50, $period)
                ];
                break;
        }

        return self::formatReport($report, $format);
    }

    /**
     * Get top models by file size
     */
    private static function getTopModelsBySize($limit) {
        $db = getDB();

        $stmt = $db->prepare("
            SELECT id, name, file_size, created_at
            FROM models
            ORDER BY file_size DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        $result = $stmt->execute();
        $data = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $data[] = $row;
        }

        return $data;
    }

    /**
     * Get downloads grouped by category
     */
    private static function getDownloadsByCategory($period) {
        $db = getDB();
        $periodStart = self::getPeriodStart($period);

        $stmt = $db->prepare("
            SELECT c.id, c.name, COUNT(a.id) as downloads
            FROM categories c
            LEFT JOIN models m ON m.category_id = c.id
            LEFT JOIN activity_log a ON a.entity_id = m.id
                AND a.entity_type = 'model' AND a.action = 'download'
                AND a.created_at >= :start
            GROUP BY c.id
            ORDER BY downloads DESC
        ");
        $stmt->bindValue(':start', $periodStart, PDO::PARAM_STR);

        $result = $stmt->execute();
        $data = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $data[] = $row;
        }

        return $data;
    }

    /**
     * Get uploads grouped by user
     */
    private static function getUploadsByUser($period) {
        $db = getDB();
        $periodStart = self::getPeriodStart($period);

        $stmt = $db->prepare("
            SELECT u.id, u.username, COUNT(a.id) as uploads
            FROM users u
            LEFT JOIN activity_log a ON a.user_id = u.id
                AND a.action = 'upload' AND a.created_at >= :start
            GROUP BY u.id
            HAVING uploads > 0
            ORDER BY uploads DESC
        ");
        $stmt->bindValue(':start', $periodStart, PDO::PARAM_STR);

        $result = $stmt->execute();
        $data = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $data[] = $row;
        }

        return $data;
    }

    /**
     * Get uploads grouped by category
     */
    private static function getUploadsByCategory($period) {
        $db = getDB();
        $periodStart = self::getPeriodStart($period);

        $stmt = $db->prepare("
            SELECT c.id, c.name, COUNT(m.id) as uploads
            FROM categories c
            LEFT JOIN models m ON m.category_id = c.id AND m.created_at >= :start
            GROUP BY c.id
            ORDER BY uploads DESC
        ");
        $stmt->bindValue(':start', $periodStart, PDO::PARAM_STR);

        $result = $stmt->execute();
        $data = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $data[] = $row;
        }

        return $data;
    }

    /**
     * Get active user statistics
     */
    private static function getActiveUserStats($period) {
        $db = getDB();
        $periodStart = self::getPeriodStart($period);

        // Active users (any activity)
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT user_id) as active
            FROM activity_log
            WHERE created_at >= :start AND user_id IS NOT NULL
        ");
        $stmt->bindValue(':start', $periodStart, PDO::PARAM_STR);
        $active = $stmt->execute()->fetchArray()[0];

        // New users this period
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM users WHERE created_at >= :start
        ");
        $stmt->bindValue(':start', $periodStart, PDO::PARAM_STR);
        $new = $stmt->execute()->fetchArray()[0];

        // Total users
        $total = $db->querySingle('SELECT COUNT(*) FROM users');

        return [
            'active' => $active,
            'new' => $new,
            'total' => $total,
            'active_percentage' => $total > 0 ? round(($active / $total) * 100, 1) : 0
        ];
    }

    /**
     * Format report for output
     */
    private static function formatReport($report, $format) {
        switch ($format) {
            case self::FORMAT_CSV:
                return self::reportToCSV($report);

            case self::FORMAT_EXCEL:
                // For now, return CSV (can be opened in Excel)
                return self::reportToCSV($report);

            case self::FORMAT_JSON:
            default:
                return [
                    'filename' => "report_{$report['type']}_" . date('Y-m-d') . '.json',
                    'content' => json_encode($report, JSON_PRETTY_PRINT),
                    'mime_type' => 'application/json'
                ];
        }
    }

    /**
     * Convert report to CSV format
     */
    private static function reportToCSV($report) {
        $output = fopen('php://temp', 'r+');

        // Write report header
        fputcsv($output, ['Report Type', $report['type']]);
        fputcsv($output, ['Generated', $report['generated_at']]);
        fputcsv($output, ['Period', $report['period']]);
        fputcsv($output, ['Period Start', $report['period_start']]);
        fputcsv($output, []);

        // Write each data section
        foreach ($report['data'] as $section => $data) {
            fputcsv($output, ["=== $section ==="]);

            if (is_array($data) && !empty($data)) {
                // Check if it's an associative array or list of arrays
                if (isset($data[0]) && is_array($data[0])) {
                    // List of records - use first record keys as headers
                    fputcsv($output, array_keys($data[0]));
                    foreach ($data as $row) {
                        fputcsv($output, array_values($row));
                    }
                } else {
                    // Key-value pairs
                    foreach ($data as $key => $value) {
                        if (is_array($value)) {
                            $value = json_encode($value);
                        }
                        fputcsv($output, [$key, $value]);
                    }
                }
            }

            fputcsv($output, []);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return [
            'filename' => "report_{$report['type']}_" . date('Y-m-d') . '.csv',
            'content' => $csv,
            'mime_type' => 'text/csv'
        ];
    }

    /**
     * Get scheduled reports
     */
    public static function getScheduledReports($activeOnly = true) {
        $db = getDB();

        $sql = 'SELECT * FROM scheduled_reports';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name ASC';

        $result = $db->query($sql);
        $reports = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $row['filters'] = json_decode($row['filters'], true) ?: [];
            $row['recipients'] = json_decode($row['recipients'], true) ?: [];
            $reports[] = $row;
        }

        return $reports;
    }

    /**
     * Get a single scheduled report
     */
    public static function getScheduledReport($id) {
        $db = getDB();

        $stmt = $db->prepare('SELECT * FROM scheduled_reports WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $result = $stmt->execute();
        $report = $result->fetchArray(PDO::FETCH_ASSOC);

        if ($report) {
            $report['filters'] = json_decode($report['filters'], true) ?: [];
            $report['recipients'] = json_decode($report['recipients'], true) ?: [];
        }

        return $report;
    }

    /**
     * Create a scheduled report
     */
    public static function createScheduledReport($data) {
        $db = getDB();

        $stmt = $db->prepare('
            INSERT INTO scheduled_reports (name, report_type, filters, schedule, recipients, format, is_active, created_at, updated_at)
            VALUES (:name, :report_type, :filters, :schedule, :recipients, :format, :is_active, :created_at, :updated_at)
        ');

        $stmt->bindValue(':name', $data['name'], PDO::PARAM_STR);
        $stmt->bindValue(':report_type', $data['report_type'], PDO::PARAM_STR);
        $stmt->bindValue(':filters', json_encode($data['filters'] ?? []), PDO::PARAM_STR);
        $stmt->bindValue(':schedule', $data['schedule'], PDO::PARAM_STR);
        $stmt->bindValue(':recipients', json_encode($data['recipients'] ?? []), PDO::PARAM_STR);
        $stmt->bindValue(':format', $data['format'] ?? 'csv', PDO::PARAM_STR);
        $stmt->bindValue(':is_active', $data['is_active'] ?? 1, PDO::PARAM_INT);
        $stmt->bindValue(':created_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);

        if ($stmt->execute()) {
            return $db->lastInsertRowID();
        }

        return false;
    }

    /**
     * Update a scheduled report
     */
    public static function updateScheduledReport($id, $data) {
        $db = getDB();

        $stmt = $db->prepare('
            UPDATE scheduled_reports
            SET name = :name, report_type = :report_type, filters = :filters,
                schedule = :schedule, recipients = :recipients, format = :format,
                is_active = :is_active, updated_at = :updated_at
            WHERE id = :id
        ');

        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':name', $data['name'], PDO::PARAM_STR);
        $stmt->bindValue(':report_type', $data['report_type'], PDO::PARAM_STR);
        $stmt->bindValue(':filters', json_encode($data['filters'] ?? []), PDO::PARAM_STR);
        $stmt->bindValue(':schedule', $data['schedule'], PDO::PARAM_STR);
        $stmt->bindValue(':recipients', json_encode($data['recipients'] ?? []), PDO::PARAM_STR);
        $stmt->bindValue(':format', $data['format'] ?? 'csv', PDO::PARAM_STR);
        $stmt->bindValue(':is_active', $data['is_active'] ?? 1, PDO::PARAM_INT);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);

        return $stmt->execute() !== false;
    }

    /**
     * Delete a scheduled report
     */
    public static function deleteScheduledReport($id) {
        $db = getDB();

        $stmt = $db->prepare('DELETE FROM scheduled_reports WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute() !== false;
    }

    /**
     * Run a scheduled report and send to recipients
     */
    public static function runScheduledReport($reportId) {
        $report = self::getScheduledReport($reportId);
        if (!$report) {
            return false;
        }

        // Generate the report
        $options = [
            'period' => $report['filters']['period'] ?? 'month',
            'format' => $report['format']
        ];

        $result = self::generateReport($report['report_type'], $options);

        // Log the execution
        self::logReportExecution($reportId, true);

        // Send to recipients if configured
        if (!empty($report['recipients'])) {
            self::sendReportToRecipients($report, $result);
        }

        return $result;
    }

    /**
     * Log report execution
     */
    private static function logReportExecution($reportId, $success, $error = null) {
        $db = getDB();

        $stmt = $db->prepare('
            INSERT INTO report_log (report_id, executed_at, success, error_message)
            VALUES (:report_id, :executed_at, :success, :error)
        ');

        $stmt->bindValue(':report_id', $reportId, PDO::PARAM_INT);
        $stmt->bindValue(':executed_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':success', $success ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':error', $error, PDO::PARAM_STR);

        return $stmt->execute() !== false;
    }

    /**
     * Send report to recipients via email
     */
    private static function sendReportToRecipients($report, $result) {
        // This would integrate with an email system
        // For now, we trigger an event that can be handled by webhooks
        if (class_exists('Events')) {
            Events::dispatch('report.generated', [
                'report_id' => $report['id'],
                'report_name' => $report['name'],
                'recipients' => $report['recipients'],
                'filename' => $result['filename'],
                'content_preview' => substr($result['content'], 0, 500)
            ]);
        }

        return true;
    }

    /**
     * Get report execution history
     */
    public static function getReportHistory($reportId = null, $limit = 50) {
        $db = getDB();

        $sql = "
            SELECT rl.*, sr.name as report_name
            FROM report_log rl
            LEFT JOIN scheduled_reports sr ON rl.report_id = sr.id
        ";

        if ($reportId) {
            $sql .= " WHERE rl.report_id = :report_id";
        }

        $sql .= " ORDER BY rl.executed_at DESC LIMIT :limit";

        $stmt = $db->prepare($sql);
        if ($reportId) {
            $stmt->bindValue(':report_id', $reportId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        $result = $stmt->execute();
        $history = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $history[] = $row;
        }

        return $history;
    }

    /**
     * Get period start date
     */
    private static function getPeriodStart($period) {
        switch ($period) {
            case self::PERIOD_DAY:
                return date('Y-m-d 00:00:00');
            case self::PERIOD_WEEK:
                return date('Y-m-d 00:00:00', strtotime('-7 days'));
            case self::PERIOD_MONTH:
                return date('Y-m-d 00:00:00', strtotime('-30 days'));
            case self::PERIOD_YEAR:
                return date('Y-m-d 00:00:00', strtotime('-365 days'));
            default:
                return date('Y-m-d 00:00:00', strtotime('-30 days'));
        }
    }

    /**
     * Get date format string for the database type
     */
    private static function getDateFormat($groupBy) {
        $db = getDB();
        $isMySQL = $db->getType() === 'mysql';

        switch ($groupBy) {
            case 'hour':
                return $isMySQL ? '%Y-%m-%d %H:00' : '%Y-%m-%d %H:00';
            case 'day':
                return '%Y-%m-%d';
            case 'week':
                return $isMySQL ? '%Y-W%v' : '%Y-W%W';
            case 'month':
                return '%Y-%m';
            case 'year':
                return '%Y';
            default:
                return '%Y-%m-%d';
        }
    }

    /**
     * Build date formatting SQL expression for the database type
     */
    private static function buildDateFormatSQL($column, $format) {
        $db = getDB();
        if ($db->getType() === 'mysql') {
            return "DATE_FORMAT($column, '$format')";
        } else {
            return "strftime('$format', $column)";
        }
    }

    // =====================
    // Print Analytics Methods
    // =====================

    /**
     * Get print history statistics
     */
    public static function getPrintStats($period = 'month') {
        $db = getDB();
        $periodStart = self::getPeriodStart($period);

        $stats = [];

        // Total printed models (parts with is_printed = 1)
        $stats['total_printed'] = $db->querySingle('SELECT COUNT(*) FROM models WHERE is_printed = 1');

        // Printed this period
        $stmt = $db->prepare('SELECT COUNT(*) FROM models WHERE is_printed = 1 AND printed_at >= :start');
        $stmt->bindValue(':start', $periodStart, PDO::PARAM_STR);
        $stats['printed_this_period'] = $stmt->execute()->fetchArray()[0];

        // Print success rate (models marked printed vs total models)
        $totalModels = $db->querySingle('SELECT COUNT(*) FROM models WHERE parent_id IS NOT NULL');
        $printedModels = $db->querySingle('SELECT COUNT(*) FROM models WHERE is_printed = 1');
        $stats['print_rate'] = $totalModels > 0 ? round(($printedModels / $totalModels) * 100, 1) : 0;

        // By print type (FDM vs SLA)
        $stmt = $db->prepare("SELECT print_type, COUNT(*) as count FROM models WHERE is_printed = 1 AND print_type IS NOT NULL GROUP BY print_type");
        $result = $stmt->execute();
        $stats['by_type'] = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $stats['by_type'][$row['print_type']] = $row['count'];
        }

        return $stats;
    }

    /**
     * Get print activity time series
     */
    public static function getPrintTimeSeries($period = 'month', $groupBy = 'day') {
        $db = getDB();
        $periodStart = self::getPeriodStart($period);
        $dateFormat = self::getDateFormat($groupBy);
        $dateExpr = self::buildDateFormatSQL('printed_at', $dateFormat);

        $stmt = $db->prepare("
            SELECT $dateExpr as period, COUNT(*) as value
            FROM models
            WHERE is_printed = 1 AND printed_at >= :start AND printed_at IS NOT NULL
            GROUP BY period ORDER BY period
        ");
        $stmt->bindValue(':start', $periodStart, PDO::PARAM_STR);

        $result = $stmt->execute();
        $data = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $data[] = $row;
        }

        return $data;
    }

    /**
     * Get most printed models
     */
    public static function getMostPrintedModels($limit = 10) {
        $db = getDB();

        // Get models with the most printed parts
        $stmt = $db->prepare("
            SELECT m.id, m.name, m.part_count,
                   (SELECT COUNT(*) FROM models p WHERE p.parent_id = m.id AND p.is_printed = 1) as printed_parts
            FROM models m
            WHERE m.parent_id IS NULL
            HAVING printed_parts > 0
            ORDER BY printed_parts DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        $result = $stmt->execute();
        $data = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $data[] = $row;
        }

        return $data;
    }

    /**
     * Get print type distribution
     */
    public static function getPrintTypeDistribution() {
        $db = getDB();

        $result = $db->query("
            SELECT print_type, COUNT(*) as count
            FROM models
            WHERE is_printed = 1 AND print_type IS NOT NULL
            GROUP BY print_type
        ");

        $data = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $data[] = $row;
        }

        return $data;
    }

    /**
     * Get material usage estimates (based on file sizes as a proxy)
     */
    public static function getMaterialUsageEstimate($period = 'month') {
        $db = getDB();
        $periodStart = self::getPeriodStart($period);
        $dateFormat = self::getDateFormat('day');
        $dateExpr = self::buildDateFormatSQL('printed_at', $dateFormat);

        $stmt = $db->prepare("
            SELECT $dateExpr as period,
                   SUM(CASE WHEN print_type = 'fdm' THEN file_size ELSE 0 END) as fdm_size,
                   SUM(CASE WHEN print_type = 'sla' THEN file_size ELSE 0 END) as sla_size
            FROM models
            WHERE is_printed = 1 AND printed_at >= :start AND printed_at IS NOT NULL
            GROUP BY period ORDER BY period
        ");
        $stmt->bindValue(':start', $periodStart, PDO::PARAM_STR);

        $result = $stmt->execute();
        $data = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $data[] = $row;
        }

        return $data;
    }

    /**
     * Get print queue statistics
     */
    public static function getPrintQueueStats($userId = null) {
        $db = getDB();

        $stats = [];

        // Queue size
        if ($userId) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM print_queue WHERE user_id = :user_id');
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stats['queue_size'] = $stmt->execute()->fetchArray()[0];
        } else {
            $stats['queue_size'] = $db->querySingle('SELECT COUNT(*) FROM print_queue');
        }

        // Total printed from queue
        if ($userId) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM print_queue WHERE user_id = :user_id AND is_printed = 1');
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stats['completed'] = $stmt->execute()->fetchArray()[0];
        } else {
            $stats['completed'] = $db->querySingle('SELECT COUNT(*) FROM print_queue WHERE is_printed = 1');
        }

        // Pending
        $stats['pending'] = $stats['queue_size'] - $stats['completed'];

        return $stats;
    }

    /**
     * Get saved dashboard widgets
     */
    public static function getDashboardWidgets($userId = null) {
        $db = getDB();

        if ($userId) {
            $stmt = $db->prepare('SELECT * FROM dashboard_widgets WHERE user_id = :user_id ORDER BY position');
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        } else {
            // Get default widgets
            $stmt = $db->prepare('SELECT * FROM dashboard_widgets WHERE user_id IS NULL ORDER BY position');
        }

        $result = $stmt->execute();
        $widgets = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $row['config'] = json_decode($row['config'], true) ?: [];
            $widgets[] = $row;
        }

        return $widgets;
    }

    /**
     * Save dashboard widget configuration
     */
    public static function saveDashboardWidget($userId, $widgetType, $config, $position) {
        $db = getDB();

        // Check if widget exists
        $stmt = $db->prepare('
            SELECT id FROM dashboard_widgets
            WHERE user_id = :user_id AND widget_type = :widget_type
        ');
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':widget_type', $widgetType, PDO::PARAM_STR);
        $existing = $stmt->execute()->fetchArray();

        if ($existing) {
            $stmt = $db->prepare('
                UPDATE dashboard_widgets
                SET config = :config, position = :position, updated_at = :updated_at
                WHERE id = :id
            ');
            $stmt->bindValue(':id', $existing['id'], PDO::PARAM_INT);
        } else {
            $stmt = $db->prepare('
                INSERT INTO dashboard_widgets (user_id, widget_type, config, position, created_at, updated_at)
                VALUES (:user_id, :widget_type, :config, :position, :created_at, :updated_at)
            ');
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':widget_type', $widgetType, PDO::PARAM_STR);
            $stmt->bindValue(':created_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        }

        $stmt->bindValue(':config', json_encode($config), PDO::PARAM_STR);
        $stmt->bindValue(':position', $position, PDO::PARAM_INT);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);

        return $stmt->execute() !== false;
    }
}
