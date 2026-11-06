<?php

namespace Amirxd\UltraRequest\File;

use Amirxd\UltraRequest\Request\Request;
use Amirxd\UltraRequest\Response\HttpResponse;

/**
 * File downloader helper.
 *
 * In a PocketMine plugin always pass an absolute $savePath, e.g.:
 *   new Downloader($url, $plugin->getDataFolder() . "downloads/file.zip")
 */
class Downloader {
    private string $url;
    private string $savePath;
    private string $filename;
    private bool $resume = false;
    private mixed $progressCallback = null;
    private int $chunkSize = 1024 * 1024;
    private array $headers = [];
    private bool $overwrite = false;
    private ?int $maxRetries = 3;
    private int $retryDelay = 1;
    private int $barWidth = 50;
    private int $lastPercent = -1;
    private int $lastBytes = 0;
    private float $lastTime = 0.0;
    private array $speedHistory = [];
    private int $maxSpeedHistory = 5;

    /**
     * @param string      $url      URL to download.
     * @param string|null $savePath Absolute path where the file will be saved.
     *                              If null, uses the filename from the URL in the
     *                              current working directory (CLI only — avoid in PM).
     */
    public function __construct(string $url, ?string $savePath = null) {
        $this->url = $url;
        if ($savePath === null) {
            $this->filename = basename(parse_url($url, PHP_URL_PATH) ?? '') ?: 'download';
            $this->savePath = getcwd() . DIRECTORY_SEPARATOR . $this->filename;
        } else {
            $this->savePath = $savePath;
            $this->filename = basename($savePath);
        }
    }

    public function withResume(bool $resume = true): self {
        $clone = clone $this; $clone->resume = $resume; return $clone;
    }

    public function withProgress(callable $callback): self {
        $clone = clone $this;
        $clone->progressCallback = $callback;

        return $clone;
    }

    public function withChunkSize(int $bytes): self {
        if ($bytes < 1024) {
            throw new \InvalidArgumentException("Chunk size must be at least 1024 bytes");
        }

        $clone = clone $this;
        $clone->chunkSize = $bytes;

        return $clone;
    }

    public function getChunkSize(): int {
        return $this->chunkSize;
    }

    public function withHeader(string $name, string $value): self {
        if (!preg_match("@^[!#$%&'*+.^_\`|~0-9A-Za-z-]+$@", $name)) {
            throw new \InvalidArgumentException("Invalid HTTP header name: " . $name);
        }

        if (strpbrk($value, "\r\n") !== false) {
            throw new \InvalidArgumentException("HTTP header values cannot contain line breaks");
        }

        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    public function withHeaders(array $headers): self {
        $clone = clone $this;

        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new \InvalidArgumentException("HTTP headers must use string names and values");
            }

            $clone = $clone->withHeader($name, $value);
        }

        return $clone;
    }

    public function withOverwrite(bool $overwrite = true): self {
        $clone = clone $this; $clone->overwrite = $overwrite; return $clone;
    }

    public function withRetries(int $maxRetries, int $delaySeconds = 1): self {
        if ($maxRetries < 1 || $delaySeconds < 0) {
            throw new \InvalidArgumentException("Retries must be positive and delay cannot be negative");
        }

        $clone = clone $this;
        $clone->maxRetries  = $maxRetries;
        $clone->retryDelay  = $delaySeconds;
        return $clone;
    }

    public function getUrl(): string      { return $this->url; }
    public function getSavePath(): string { return $this->savePath; }
    public function getFilename(): string { return $this->filename; }

    public function canResume(): bool {
        return $this->resume && is_file($this->savePath);
    }

    public function getExistingSize(): int {
        if (!$this->canResume()) {
            return 0;
        }

        $size = filesize($this->savePath);

        return $size === false ? 0 : (int) $size;
    }

    public function getRequest(): Request {
        $request = Request::get($this->url);
        foreach ($this->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($this->canResume()) {
            $request = $request->withHeader('Range', 'bytes=' . $this->getExistingSize() . '-');
        }
        return $request;
    }

    public function shouldDownload(): bool {
        if (!$this->overwrite && file_exists($this->savePath) && !$this->resume) return false;
        return true;
    }

    /**
     * Saves a buffered response. A 200 response always replaces the old file;
     * a 206 response is appended only when its Content-Range starts at the
     * current partial-file size.
     */
    public function save(HttpResponse $response): bool {
        $statusCode = $response->getStatusCode();

        if ($statusCode === 200) {
            $mode = 'wb';
        } elseif ($statusCode === 206) {
            $contentRange = $response->getHeader('content-range') ?? '';

            if (!preg_match('/^bytes\\s+(\\d+)-(\\d+)\\/(?:\\d+|\\*)$/i', $contentRange, $matches)) {
                return false;
            }

            $existingSize = $this->canResume() ? $this->getExistingSize() : 0;

            if ((int) $matches[1] !== $existingSize) {
                return false;
            }

            $mode = $existingSize > 0 ? 'ab' : 'wb';
        } else {
            return false;
        }

        $directory = dirname($this->savePath);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            return false;
        }

        $file = fopen($this->savePath, $mode);

        if ($file === false) {
            return false;
        }

        try {
            $body = $response->getBody() ?? '';
            $length = strlen($body);
            $written = 0;

            while ($written < $length) {
                $result = fwrite($file, substr($body, $written));

                if ($result === false || $result === 0) {
                    return false;
                }

                $written += $result;
            }

            return true;
        } finally {
            fclose($file);
        }
    }

    public function getProgress(float $downloaded, float $total): array {
        $percent = $total > 0 ? round(($downloaded / $total) * 100, 2) : 0.0;
        $speed   = $this->calculateSpeed($downloaded);
        $eta     = $this->calculateETA($downloaded, $total);
        return [
            'percent'              => $percent,
            'downloaded'           => $downloaded,
            'downloaded_formatted' => $this->formatBytes($downloaded),
            'total'                => $total,
            'total_formatted'      => $total > 0 ? $this->formatBytes($total) : 'unknown',
            'speed'                => $speed,
            'speed_formatted'      => $this->formatBytes($speed) . '/s',
            'eta'                  => $eta,
            'eta_formatted'        => $this->formatTime($eta),
        ];
    }

    private function calculateSpeed(float $currentBytes): float {
        $currentTime = microtime(true);
        if ($this->lastTime === 0.0) {
            $this->lastTime  = $currentTime;
            $this->lastBytes = (int)$currentBytes;
            return 0.0;
        }
        $timeDiff = $currentTime - $this->lastTime;
        if ($timeDiff <= 0.001) return $this->getAverageSpeed();

        $currentSpeed = ($currentBytes - $this->lastBytes) / $timeDiff;
        $this->speedHistory[] = $currentSpeed;
        if (count($this->speedHistory) > $this->maxSpeedHistory) array_shift($this->speedHistory);

        if ($timeDiff >= 0.3) {
            $this->lastBytes = (int)$currentBytes;
            $this->lastTime  = $currentTime;
        }
        return $this->getAverageSpeed();
    }

    private function getAverageSpeed(): float {
        if (empty($this->speedHistory)) return 0.0;
        $avg       = array_sum($this->speedHistory) / count($this->speedHistory);
        $lastSpeed = end($this->speedHistory);
        if ($lastSpeed < $avg * 0.3) return ($lastSpeed * 0.7) + ($avg * 0.3);
        return max(0.0, $avg);
    }

    private function calculateETA(float $downloaded, float $total): int {
        if ($downloaded <= 0 || $total <= 0 || $total <= $downloaded) return 0;
        $speed = $this->getAverageSpeed();
        return $speed > 0 ? (int)(($total - $downloaded) / $speed) : 0;
    }

    public function callProgress(mixed $downloaded, mixed $total): void {
        if ($this->progressCallback !== null) {
            call_user_func($this->progressCallback, $this->getProgress((float)$downloaded, (float)$total));
        }
    }

    public function formatBytes(float $bytes, int $precision = 2): string {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $pow   = min((int)floor(log($bytes) / log(1024)), count($units) - 1);
        return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
    }

    private function formatTime(int $seconds): string {
        if ($seconds <= 0) return 'unknown';
        $h = (int)floor($seconds / 3600);
        $m = (int)floor(($seconds % 3600) / 60);
        $s = $seconds % 60;
        if ($h > 0) return sprintf('%02d:%02d:%02d', $h, $m, $s);
        if ($m > 0) return sprintf('%02d:%02d', $m, $s);
        return sprintf('%02ds', $s);
    }

    public function getMaxRetries(): ?int { return $this->maxRetries; }
    public function getRetryDelay(): int  { return $this->retryDelay; }
}
