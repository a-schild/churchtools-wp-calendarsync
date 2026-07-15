<?php
/**
 * Logging functionality for ChurchTools WP Calendar Sync
 *
 * @package Ctwpsync
 */

/**
 * Log levels for the sync process
 */
enum LogLevel: string {
    case DEBUG = 'DBG';
    case INFO = 'INF';
    case ERROR = 'ERR';
}

/**
 * Logger class for ChurchTools WP Calendar Sync
 *
 * Provides structured logging with configurable log levels
 */
readonly class SyncLogger {
    /**
     * Create a new logger instance
     *
     * @param string $logFile Path to the log file
     * @param bool $debugEnabled Whether debug logging is enabled
     * @param bool $infoEnabled Whether info logging is enabled
     * @param int $maxBytes Rotate the log once it reaches this size (0 disables rotation)
     */
    public function __construct(
        private string $logFile,
        private bool $debugEnabled = false,
        private bool $infoEnabled = true,
        private int $maxBytes = 5242880, // 5 MB
    ) {}

    /**
     * Log a message at the specified level
     *
     * @param LogLevel $level The log level
     * @param string $message The message to log
     */
    public function log(LogLevel $level, string $message): void {
        if ($level === LogLevel::DEBUG && !$this->debugEnabled) {
            return;
        }

        if ($level === LogLevel::INFO && !$this->infoEnabled) {
            return;
        }

        $this->rotateIfNeeded();
        $timestamp = $this->timestamp();
        error_log("[{$timestamp}] {$level->value}: {$message}\n", 3, $this->logFile);
    }

    /**
     * Timestamp for a log line. Uses the WordPress site timezone when available
     * (so it matches the times shown on the dashboard); falls back to server
     * time so the logger still works outside WordPress.
     *
     * @return string Formatted timestamp, e.g. "2026-07-15 16:04:12"
     */
    private function timestamp(): string {
        return function_exists('wp_date')
            ? wp_date('Y-m-d H:i:s')
            : date('Y-m-d H:i:s');
    }

    /**
     * Rotate the log file if it has grown past the configured size limit.
     *
     * Keeps a single previous generation ({logFile}.1) and starts a fresh log,
     * preventing unbounded growth of the log file over time.
     */
    private function rotateIfNeeded(): void {
        if ($this->maxBytes <= 0) {
            return;
        }
        $size = @filesize($this->logFile);
        if ($size !== false && $size >= $this->maxBytes) {
            // Overwrite any existing previous generation with the current file
            @rename($this->logFile, $this->logFile . '.1');
        }
    }

    /**
     * Log a debug message
     *
     * @param string $message The message to log
     */
    public function debug(string $message): void {
        $this->log(LogLevel::DEBUG, $message);
    }

    /**
     * Log an info message
     *
     * @param string $message The message to log
     */
    public function info(string $message): void {
        $this->log(LogLevel::INFO, $message);
    }

    /**
     * Log an error message
     *
     * @param string $message The message to log
     */
    public function error(string $message): void {
        $this->log(LogLevel::ERROR, $message);
    }
}
