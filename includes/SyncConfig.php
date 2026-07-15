<?php
/**
 * Configuration management for ChurchTools WP Calendar Sync
 *
 * @package Ctwpsync
 */

/**
 * Immutable configuration for sync operations
 *
 * Uses PHP 8.2 readonly class to ensure configuration cannot be modified after creation
 */
readonly class SyncConfig {
    /**
     * Valid log levels, from least to most verbose.
     * ERROR = errors only, INFO = errors + info (default), DEBUG = everything.
     */
    public const LOG_LEVELS = ['ERROR', 'INFO', 'DEBUG'];

    /**
     * Create a new sync configuration
     *
     * @param string $url ChurchTools URL
     * @param string $apiToken API authentication token
     * @param array $calendars Array of calendar configurations [{id: int, name: string, category: string}, ...]
     * @param int $importPast Days in the past to import
     * @param int $importFuture Days in the future to import
     * @param int $resourceTypeForCategories Resource type ID for category mapping (-1 to disable)
     * @param string $emImageAttr Events Manager custom attribute for image URLs (empty to disable)
     * @param bool $enableTagCategories Whether to sync CT appointment tags as categories
     * @param string $logLevel Log verbosity: ERROR, INFO or DEBUG
     */
    public function __construct(
        public string $url,
        public string $apiToken,
        public array $calendars,
        public int $importPast,
        public int $importFuture,
        public int $resourceTypeForCategories = -1,
        public string $emImageAttr = '',
        public bool $enableTagCategories = false,
        public string $logLevel = 'INFO',
    ) {}

    /**
     * Normalise an arbitrary value to a valid log level, defaulting to INFO.
     *
     * @param mixed $level
     * @return string One of self::LOG_LEVELS
     */
    public static function sanitizeLogLevel(mixed $level): string {
        $level = is_string($level) ? strtoupper(trim($level)) : '';
        return in_array($level, self::LOG_LEVELS, true) ? $level : 'INFO';
    }

    /**
     * Create configuration from POST data
     *
     * @return self|null Returns null if URL validation fails
     */
    public static function fromPost(): ?self {
        // Validate and sanitize URL
        $url = rtrim(trim($_POST['ctwpsync_url'] ?? ''), '/') . '/';
        $url = esc_url_raw($url);
        if (empty($url) || $url === '/' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        // Validate and clamp import range values (reasonable limits: -365 to 730 days)
        $importPast = (int)trim($_POST['ctwpsync_import_past'] ?? '0');
        $importPast = max(-365, min(365, $importPast));

        $importFuture = (int)trim($_POST['ctwpsync_import_future'] ?? '380');
        $importFuture = max(-365, min(730, $importFuture));

        return new self(
            url: $url,
            apiToken: trim($_POST['ctwpsync_apitoken'] ?? ''),
            calendars: self::parseCalendarsFromPost(),
            importPast: $importPast,
            importFuture: $importFuture,
            resourceTypeForCategories: (int)trim($_POST['ctwpsync_resourcetype_for_categories'] ?? '-1'),
            emImageAttr: sanitize_text_field(trim($_POST['ctwpsync_em_image_attr'] ?? '')),
            enableTagCategories: isset($_POST['ctwpsync_enable_tag_categories']),
            logLevel: self::sanitizeLogLevel($_POST['ctwpsync_log_level'] ?? 'INFO'),
        );
    }

    /**
     * Create configuration from stored options array
     *
     * @param array $options Stored options from WordPress
     * @return self|null
     */
    public static function fromOptions(array $options): ?self {
        if (empty($options['url']) || empty($options['apitoken'])) {
            return null;
        }

        // Validate URL format
        $url = $options['url'];
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        // Clamp import range values
        $importPast = (int)($options['import_past'] ?? 0);
        $importPast = max(-365, min(365, $importPast));

        $importFuture = (int)($options['import_future'] ?? 380);
        $importFuture = max(-365, min(730, $importFuture));

        return new self(
            url: $url,
            apiToken: $options['apitoken'],
            calendars: $options['calendars'] ?? [],
            importPast: $importPast,
            importFuture: $importFuture,
            resourceTypeForCategories: (int)($options['resourcetype_for_categories'] ?? -1),
            emImageAttr: $options['em_image_attr'] ?? '',
            enableTagCategories: $options['enable_tag_categories'] ?? false,
            logLevel: self::sanitizeLogLevel($options['log_level'] ?? 'INFO'),
        );
    }

    /**
     * Convert configuration to array for storage
     *
     * @return array
     */
    public function toArray(): array {
        return [
            'url' => $this->url,
            'apitoken' => $this->apiToken,
            'calendars' => $this->calendars,
            'import_past' => $this->importPast,
            'import_future' => $this->importFuture,
            'resourcetype_for_categories' => $this->resourceTypeForCategories,
            'em_image_attr' => $this->emImageAttr,
            'enable_tag_categories' => $this->enableTagCategories,
            'log_level' => $this->logLevel,
        ];
    }

    /**
     * Parse calendars from POST data
     *
     * @return array Array of calendar configurations
     */
    private static function parseCalendarsFromPost(): array {
        $calendars = [];
        if (isset($_POST['ctwpsync_calendars']) && is_array($_POST['ctwpsync_calendars'])) {
            foreach ($_POST['ctwpsync_calendars'] as $calData) {
                // Validate calendar ID is numeric and positive
                if (!empty($calData['id']) && is_numeric($calData['id']) && (int)$calData['id'] > 0) {
                    $calendars[] = [
                        'id' => (int)$calData['id'],
                        'name' => sanitize_text_field(trim($calData['name'] ?? '')),
                        'category' => sanitize_text_field(trim($calData['category'] ?? '')),
                    ];
                }
            }
        }
        return $calendars;
    }

    /**
     * Get the from date for sync (based on importPast)
     *
     * @return string Date string in Y-m-d format
     */
    public function getFromDate(): string {
        if ($this->importPast < 0) {
            return date('Y-m-d', strtotime('+' . ($this->importPast * -1) . ' days'));
        }
        return date('Y-m-d', strtotime('-' . $this->importPast . ' days'));
    }

    /**
     * Get the to date for sync (based on importFuture)
     *
     * @return string Date string in Y-m-d format
     */
    public function getToDate(): string {
        if ($this->importFuture < 0) {
            return date('Y-m-d', strtotime('-' . ($this->importFuture * -1) . ' days'));
        }
        return date('Y-m-d', strtotime('+' . $this->importFuture . ' days'));
    }

    /**
     * Get array of calendar IDs for sync
     *
     * @return array Array of integer calendar IDs
     */
    public function getCalendarIds(): array {
        return array_map(fn($cal) => (int)$cal['id'], $this->calendars);
    }

    /**
     * Get calendar categories mapping array
     *
     * Maps calendar ID to category name
     *
     * @return array Associative array of calendar ID => category name
     */
    public function getCategoryMapping(): array {
        $mapping = [];
        foreach ($this->calendars as $cal) {
            $mapping[(int)$cal['id']] = !empty($cal['category']) ? $cal['category'] : null;
        }
        return $mapping;
    }
}
