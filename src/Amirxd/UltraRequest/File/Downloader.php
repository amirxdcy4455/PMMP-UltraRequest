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
        $clone = clone $this; $clone->progressCallback = $callback; return $clone;
    }

    public function withChunkSize(int $bytes): self {
        $clone = clone $this; $clone->chunkSize = $bytes; return $clone;
    }

    public function withHeader(string $name, string $value): self {
        $clone = clone $this; $clone->headers[$name] = $value; return $clone;
    }

    public function withHeaders(array $headers): self {
        $clone = clone $this;
        $clone->headers = array_merge($clone->headers, $headers);
        return $clone;
    }

    public function withOverwrite(bool $overwrite = true): self {
        $clone = clone $this; $clone->overwrite = $overwrite; return $clone;
    }

    public function withRetries(int $maxRetries, int $delaySeconds = 1): self {
        $clone = clone $this;
        $clone->maxRetries  = $maxRetries;
        $clone->retryDelay  = $delaySeconds;
        return $clone;
    }

    public function getUrl(): string      { return $this->url; }
    public function getSavePath(): string { return $this->savePath; }
    public function getFilename(): string { return $this->filename; }

    public function canResume(): bool {
        return $this->resume && file_exists($this->savePath);
    }

    public function getExistingSize(): int {
        return $this->canResume() ? (int)filesize($this->savePath) : 0;
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

    public function save(HttpResponse $response): bool {
        if (!$response->isSuccessful() && $response->getStatusCode() !== 206) return false;

        $dir = dirname($this->savePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $fp = fopen($this->savePath, $this->canResume() ? 'ab' : 'wb');
        if ($fp === false) return false;

        $bytesWritten = fwrite($fp, $response->getBody() ?? '');
        fclose($fp);
        return $bytesWritten !== false;
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
