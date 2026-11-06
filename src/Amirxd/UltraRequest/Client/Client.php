<?php

namespace Amirxd\UltraRequest\Client;

use Amirxd\UltraRequest\Request\RequestInterface;
use Amirxd\UltraRequest\Response\HttpResponse;
use Amirxd\UltraRequest\Cookie\CookieJarInterface;
use Amirxd\UltraRequest\Proxy\ProxyInterface;
use Amirxd\UltraRequest\Auth\AuthManager;
use Amirxd\UltraRequest\File\Downloader;
use Amirxd\UltraRequest\Request\Request;
use Amirxd\UltraRequest\Request\RequestHandler;
use Amirxd\UltraRequest\Task\AsyncRequestTask;
use Closure;

class Client {
    private ?CookieJarInterface $cookieJar = null;
    private ?ProxyInterface $proxy = null;
    private array $defaultHeaders = [];
    private int $timeout = 30;
    private int $connectTimeout = 10;
    private bool $verifySSL = true;
    private ?AuthManager $auth = null;
    private array $curlOptions = [];

    public function __construct() {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('cURL extension is required for Amirxd\UltraRequest');
        }
    }

    public function withAuth(callable $callback): self {
        $manager = new AuthManager();
        $callback($manager);
        $clone = clone $this;
        $clone->auth = $manager;
        return $clone;
    }

    public function withSSL(bool $verify = true): self {
        $clone = clone $this; $clone->verifySSL = $verify; return $clone;
    }

    public function withBasicAuth(string $username, string $password): self {
        $clone = clone $this;
        $clone->auth = (new AuthManager())->basic($username, $password);
        return $clone;
    }

    public function withBearerAuth(string $token): self {
        $clone = clone $this;
        $clone->auth = (new AuthManager())->bearer($token);
        return $clone;
    }

    public function withTimeout(int $seconds): self {
        $clone = clone $this; $clone->timeout = $seconds; return $clone;
    }

    public function withConnectTimeout(int $seconds): self {
        $clone = clone $this; $clone->connectTimeout = $seconds; return $clone;
    }

    public function setProxy(ProxyInterface $proxy): self {
        $clone = clone $this; $clone->proxy = $proxy; return $clone;
    }

    public function withCookieJar(CookieJarInterface $cookieJar): self {
        $clone = clone $this; $clone->cookieJar = $cookieJar; return $clone;
    }

    public function withCurlOption(int $option, mixed $value): self {
        $clone = clone $this; $clone->curlOptions[$option] = $value; return $clone;
    }

    /**
     * Downloads a file directly to disk. The response body is streamed by cURL,
     * so large files are never accumulated in memory.
     */
    public function download(Downloader $downloader): bool {
        if (!$downloader->shouldDownload()) {
            throw new \RuntimeException("File already exists: " . $downloader->getSavePath());
        }

        $maxRetries = $downloader->getMaxRetries();
        $attempt = 0;

        do {
            try {
                $request = $downloader->getRequest();
                $includeAuth = true;
                $redirectCount = 0;
                $visited = [];
                $currentUrl = $this->buildRequestUrl($request, $includeAuth);

                while (true) {
                    if (isset($visited[$currentUrl])) {
                        throw new \RuntimeException("Redirect loop detected while downloading: " . $currentUrl);
                    }

                    $visited[$currentUrl] = true;
                    $result = $this->executeDownloadRequest(
                        $currentUrl,
                        $request,
                        $downloader,
                        $includeAuth
                    );

                    if (!$result['success']) {
                        $message = $result['writeError'] ?? $result['error'];
                        throw new \RuntimeException(
                            "Download request failed (" . $result['errno'] . "): " . $message
                        );
                    }

                    // Keep cookies returned by intermediate redirect responses as well.
                    if ($this->cookieJar !== null && $result['rawHeaders'] !== '') {
                        $this->saveCookiesFromResponse($result['rawHeaders'], $currentUrl);
                    }

                    $statusCode = $result['status'];
                    $location = $result['headers']['location'] ?? null;

                    if ($this->isRedirectStatus($statusCode) && $location !== null && $location !== '') {
                        $maxRedirects = $request->getMaxRedirects() ?? 10;

                        if ($redirectCount >= $maxRedirects) {
                            throw new \RuntimeException("Maximum redirects ($maxRedirects) exceeded");
                        }

                        $targetUrl = $this->resolveRelativeUrl($currentUrl, $location);
                        $sameOrigin = $this->isSameOrigin($currentUrl, $targetUrl);

                        // Credentials are deliberately not restored after leaving the original origin.
                        $includeAuth = $includeAuth && $sameOrigin;
                        $request = $this->buildRedirectRequest($request, $targetUrl, $statusCode, $sameOrigin);
                        $currentUrl = $this->buildRequestUrl($request, $includeAuth);
                        $redirectCount++;
                        continue;
                    }

                    if ($statusCode < 200 || $statusCode >= 300) {
                        throw new \RuntimeException("Server returned HTTP status " . $statusCode);
                    }

                    if ($result['writeError'] !== null) {
                        throw new \RuntimeException($result['writeError']);
                    }

                    return true;
                }
            } catch (\Throwable $e) {
                $attempt++;

                if ($maxRetries === null || $attempt >= $maxRetries) {
                    throw new \RuntimeException(
                        "Download failed after $attempt attempt(s): " . $e->getMessage(),
                        0,
                        $e
                    );
                }

                if ($downloader->getRetryDelay() > 0) {
                    sleep($downloader->getRetryDelay());
                }
            }
        } while (true);
    }

    /**
     * Performs one streamed download request. Redirects are handled by download()
     * so that credentials and resume state can be checked before following them.
     */
    private function executeDownloadRequest(
        string $url,
        RequestInterface $request,
        Downloader $downloader,
        bool $includeAuth
    ): array {
        $ch = curl_init();

        if ($ch === false) {
            throw new \RuntimeException("Failed to initialize cURL");
        }

        $fileHandle = null;
        $statusCode = 0;
        $headers = [];
        $rawHeaders = '';
        $writeError = null;
        $initialSize = $downloader->canResume() ? $downloader->getExistingSize() : 0;

        try {
            $options = $this->buildBaseOptions($url, $request);
            $options = $this->addHeaders($options, $request, $includeAuth);
            $options = $this->addCookies($options, $request);
            $options = $this->addProxy($options);
            $options = $this->addCustomCurlOptions($options);

            if (!$includeAuth) {
                unset(
                    $options[CURLOPT_USERPWD],
                    $options[CURLOPT_USERNAME],
                    $options[CURLOPT_PASSWORD]
                );
            }

            // Override transfer options after custom options to guarantee streaming.
            $options[CURLOPT_URL] = $url;
            $options[CURLOPT_RETURNTRANSFER] = false;
            $options[CURLOPT_HEADER] = false;
            $options[CURLOPT_FOLLOWLOCATION] = false;
            $options[CURLOPT_UNRESTRICTED_AUTH] = false;
            $options[CURLOPT_BUFFERSIZE] = max(1024, min(10 * 1024 * 1024, $downloader->getChunkSize()));

            $options[CURLOPT_HEADERFUNCTION] = function ($handle, string $line) use (
                &$statusCode,
                &$headers,
                &$rawHeaders,
                &$fileHandle,
                &$writeError,
                &$initialSize,
                $downloader
            ): int {
                $rawHeaders .= $line;
                $trimmed = trim($line);

                if (preg_match('/^HTTP\/\S+\s+(\d{3})/i', $trimmed, $matches)) {
                    $statusCode = (int) $matches[1];
                    $headers = [];
                    return strlen($line);
                }

                if ($trimmed === '') {
                    $mode = null;

                    if ($statusCode === 200) {
                        // A server may ignore Range and return the complete file.
                        // In that case the old partial file must be truncated.
                        $initialSize = 0;
                        $mode = 'wb';
                    } elseif ($statusCode === 206) {
                        $contentRange = $headers['content-range'] ?? '';

                        if (
                            !preg_match('/^bytes\s+(\d+)-(\d+)\/(?:\d+|\*)$/i', $contentRange, $range)
                            || (int) $range[1] !== $initialSize
                        ) {
                            $writeError = "Invalid Content-Range header for resumed download";
                            return strlen($line);
                        }

                        $mode = $initialSize > 0 ? 'ab' : 'wb';
                    }

                    if ($mode !== null) {
                        $directory = dirname($downloader->getSavePath());

                        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                            $writeError = "Unable to create download directory: " . $directory;
                            return strlen($line);
                        }

                        $fileHandle = fopen($downloader->getSavePath(), $mode);

                        if ($fileHandle === false) {
                            $fileHandle = null;
                            $writeError = "Unable to open download target: " . $downloader->getSavePath();
                        }
                    }

                    return strlen($line);
                }

                $separator = strpos($trimmed, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($trimmed, 0, $separator)));
                    $value = trim(substr($trimmed, $separator + 1));
                    $headers[$name] = $value;
                }

                return strlen($line);
            };

            $options[CURLOPT_WRITEFUNCTION] = function ($handle, string $data) use (
                &$fileHandle,
                &$statusCode,
                &$writeError
            ): int {
                $length = strlen($data);

                // Ignore redirect and error response bodies; they are not file data.
                if ($statusCode < 200 || $statusCode >= 300) {
                    return $length;
                }

                if ($writeError !== null || !is_resource($fileHandle)) {
                    return 0;
                }

                $written = 0;
                while ($written < $length) {
                    $result = fwrite($fileHandle, substr($data, $written));

                    if ($result === false || $result === 0) {
                        $writeError = "Unable to write downloaded data to disk";
                        return 0;
                    }

                    $written += $result;
                }

                return $length;
            };

            $options[CURLOPT_NOPROGRESS] = false;
            $options[CURLOPT_PROGRESSFUNCTION] = function (
                $resource,
                $downloadSize,
                $downloaded,
                $uploadSize,
                $uploaded
            ) use ($downloader, &$initialSize): int {
                $total = $downloadSize > 0 ? $downloadSize + $initialSize : 0;
                $downloader->callProgress($downloaded + $initialSize, $total);
                return 0;
            };

            $this->applyCurlOptions($ch, $options);

            $success = curl_exec($ch);
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            $info = curl_getinfo($ch);

            if (is_resource($fileHandle)) {
                fclose($fileHandle);
                $fileHandle = null;
            }

            if ($this->cookieJar !== null && $rawHeaders !== '') {
                $this->saveCookiesFromResponse($rawHeaders, $url);
            }

            return [
                'success' => $success !== false,
                'status' => (int) ($info['http_code'] ?? $statusCode),
                'headers' => $headers,
                'rawHeaders' => $rawHeaders,
                'error' => $error,
                'errno' => $errno,
                'writeError' => $writeError,
                'info' => $info
            ];
        } finally {
            if (is_resource($fileHandle)) {
                fclose($fileHandle);
            }

            curl_close($ch);
        }
    }

    /**
     * Starts a request and follows redirects manually. The redirect context is local
     * to this call, so concurrent requests using the same Client cannot reset it.
     */
    public function send(RequestInterface $request): HttpResponse {
        return $this->sendWithRedirects($request, 0, [], true);
    }

    private function sendWithRedirects(
        RequestInterface $request,
        int $redirectCount,
        array $visited,
        bool $includeAuth
    ): HttpResponse {
        $url = $this->buildRequestUrl($request, $includeAuth);

        if (isset($visited[$url])) {
            throw new \RuntimeException("Redirect loop detected: " . $url);
        }

        $visited[$url] = true;
        $ch = curl_init();

        if ($ch === false) {
            throw new \RuntimeException("Failed to initialize cURL");
        }

        try {
            $options = $this->buildBaseOptions($url, $request);
            $options = $this->addHeaders($options, $request, $includeAuth);
            $options = $this->addCookies($options, $request);
            $options = $this->addProxy($options);
            $options = $this->addCustomCurlOptions($options);

            // Redirects are followed manually to prevent cross-origin credential leaks.
            $options[CURLOPT_URL] = $url;
            $options[CURLOPT_FOLLOWLOCATION] = false;
            $options[CURLOPT_UNRESTRICTED_AUTH] = false;

            if (!$includeAuth) {
                unset(
                    $options[CURLOPT_USERPWD],
                    $options[CURLOPT_USERNAME],
                    $options[CURLOPT_PASSWORD]
                );
            }

            $this->applyCurlOptions($ch, $options);

            $response = curl_exec($ch);
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            $info = curl_getinfo($ch);

            if ($response === false) {
                throw new \RuntimeException("cURL Error ($errno): $error");
            }

            $httpResponse = $this->parseResponse($response, $info, $request, $url);

            if ($request->followsRedirects() && $this->shouldRedirect($httpResponse)) {
                $location = $httpResponse->getHeader('Location');

                if ($location !== null && $location !== '') {
                    $maxRedirects = $request->getMaxRedirects() ?? 10;

                    if ($redirectCount >= $maxRedirects) {
                        throw new \RuntimeException("Maximum redirects ($maxRedirects) exceeded");
                    }

                    $targetUrl = $this->resolveRelativeUrl($url, $location);
                    $sameOrigin = $this->isSameOrigin($url, $targetUrl);

                    // Once a request leaves its original origin, never re-attach its credentials.
                    $nextIncludeAuth = $includeAuth && $sameOrigin;
                    $nextRequest = $this->buildRedirectRequest(
                        $request,
                        $targetUrl,
                        $httpResponse->getStatusCode(),
                        $sameOrigin
                    );

                    return $this->sendWithRedirects(
                        $nextRequest,
                        $redirectCount + 1,
                        $visited,
                        $nextIncludeAuth
                    );
                }
            }

            return $httpResponse;
        } finally {
            curl_close($ch);
        }
    }

    private function shouldRedirect(HttpResponse $response): bool {
        return $this->isRedirectStatus($response->getStatusCode());
    }

    private function isRedirectStatus(int $statusCode): bool {
        return in_array($statusCode, [301, 302, 303, 307, 308], true);
    }

    /**
     * Builds a safe follow-up request. Authorization and cookie headers are removed
     * whenever a redirect changes origin.
     */
    private function buildRedirectRequest(
        RequestInterface $originalRequest,
        string $targetUrl,
        int $statusCode,
        bool $sameOrigin
    ): RequestInterface {
        $method = $originalRequest->getMethod();
        $body = $originalRequest->getBody();

        if (($statusCode === 303 && $method !== 'HEAD') || (in_array($statusCode, [301, 302], true) && $method === 'POST')) {
            $method = 'GET';
            $body = null;
        }

        $request = new Request($method, $targetUrl);

        foreach ($originalRequest->getHeaders() as $name => $value) {
            $normalizedName = strtolower($name);

            if (in_array($normalizedName, ['content-length', 'host'], true)) {
                continue;
            }

            if ($body === null && in_array($normalizedName, ['content-type', 'transfer-encoding'], true)) {
                continue;
            }

            if (!$sameOrigin && $this->isSensitiveHeader($normalizedName)) {
                continue;
            }

            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $request = $request->withBody($body);
        }

        $request = $request->withMaxRedirects($originalRequest->getMaxRedirects() ?? 10);

        if (!$originalRequest->followsRedirects()) {
            $request = $request->withoutRedirects();
        }

        return $request;
    }

    private function isSensitiveHeader(string $name): bool {
        return in_array(
            strtolower($name),
            ['authorization', 'proxy-authorization', 'cookie', 'cookie2'],
            true
        );
    }

    /**
     * Resolves absolute, protocol-relative, root-relative, path-relative,
     * query-only and fragment-only redirect locations.
     */
    private function resolveRelativeUrl(string $baseUrl, string $relativeUrl): string {
        if (parse_url($relativeUrl, PHP_URL_SCHEME) !== null) {
            return $relativeUrl;
        }

        $base = parse_url($baseUrl);

        if ($base === false || !isset($base['scheme'], $base['host'])) {
            throw new \InvalidArgumentException("Invalid base URL: " . $baseUrl);
        }

        $scheme = $base['scheme'];
        $authority = $base['host'];

        if (isset($base['port'])) {
            $authority .= ':' . $base['port'];
        }

        if (str_starts_with($relativeUrl, '//')) {
            return $scheme . ':' . $relativeUrl;
        }

        $relative = parse_url($relativeUrl);
        if ($relative === false) {
            throw new \InvalidArgumentException("Invalid redirect URL: " . $relativeUrl);
        }

        $basePath = $base['path'] ?? '/';
        $relativePath = $relative['path'] ?? '';

        if ($relativePath === '') {
            $path = $basePath;
            $query = array_key_exists('query', $relative)
                ? '?' . $relative['query']
                : (isset($base['query']) ? '?' . $base['query'] : '');
        } else {
            if (str_starts_with($relativePath, '/')) {
                $path = $relativePath;
            } else {
                $directory = substr($basePath, 0, strrpos($basePath, '/') + 1);
                $path = $directory . $relativePath;
            }

            $path = $this->normalizeUrlPath($path);
            $query = isset($relative['query']) ? '?' . $relative['query'] : '';
        }

        $fragment = isset($relative['fragment'])
            ? '#' . $relative['fragment']
            : '';

        return $scheme . '://' . $authority . $path . $query . $fragment;
    }

    private function normalizeUrlPath(string $path): string {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        $normalized = '/' . implode('/', $segments);

        if (str_ends_with($path, '/') && !str_ends_with($normalized, '/')) {
            $normalized .= '/';
        }

        return $normalized;
    }

    private function isSameOrigin(string $firstUrl, string $secondUrl): bool {
        $first = parse_url($firstUrl);
        $second = parse_url($secondUrl);

        if ($first === false || $second === false) {
            return false;
        }

        $firstScheme = strtolower($first['scheme'] ?? '');
        $secondScheme = strtolower($second['scheme'] ?? '');
        $firstHost = strtolower($first['host'] ?? '');
        $secondHost = strtolower($second['host'] ?? '');

        $firstPort = $first['port'] ?? ($firstScheme === 'https' ? 443 : 80);
        $secondPort = $second['port'] ?? ($secondScheme === 'https' ? 443 : 80);

        return $firstScheme === $secondScheme
            && $firstHost === $secondHost
            && $firstPort === $secondPort;
    }

    /**
     * Adds query-based authentication to the effective URL without dropping
     * existing query parameters or fragments.
     */
    private function buildRequestUrl(RequestInterface $request, bool $includeAuth): string {
        $url = $request->getUrl();
        $queryParams = $includeAuth && $this->auth !== null
            ? $this->auth->getQueryParams()
            : [];

        if ($queryParams === []) {
            return $url;
        }

        $fragment = '';
        $fragmentPosition = strpos($url, '#');

        if ($fragmentPosition !== false) {
            $fragment = substr($url, $fragmentPosition);
            $url = substr($url, 0, $fragmentPosition);
        }

        $query = [];
        $queryPosition = strpos($url, '?');

        if ($queryPosition !== false) {
            parse_str(substr($url, $queryPosition + 1), $query);
            $url = substr($url, 0, $queryPosition);
        }

        $query = array_merge($query, $queryParams);
        $encodedQuery = http_build_query($query);

        return $url . ($encodedQuery !== '' ? '?' . $encodedQuery : '') . $fragment;
    }

    public function asyncSend(Request $request, ?Closure $onSuccess = null, ?Closure $onError = null): void {
        $registrant = RequestHandler::getRegistrant();

        if ($registrant === null) {
            throw new \RuntimeException('RequestHandler is not registered. Call RequestHandler::register($plugin) first.');
        }

        $registrant->getServer()->getAsyncPool()->submitTask(
            new AsyncRequestTask($request, $this, $onSuccess, $onError)
        );
    }

    private function shouldRedirect(HttpResponse $response): bool {
        return in_array($response->getStatusCode(), [301, 302, 303, 307, 308]);
    }

    private function buildRedirectRequest(RequestInterface $originalRequest, string $location, HttpResponse $response): RequestInterface {
        $this->redirectCount++;
        $this->redirectHistory[] = $originalRequest->getUrl();

        if (!preg_match('/^https?:\/\//i', $location)) {
            $location = $this->resolveRelativeUrl($originalRequest->getUrl(), $location);
        }

        $statusCode = $response->getStatusCode();
        if (in_array($statusCode, [301, 302, 303])) {
            $newMethod = 'GET';
            $newBody   = null;
        } else {
            $newMethod = $originalRequest->getMethod();
            $newBody   = $originalRequest->getBody();
        }

        $class      = get_class($originalRequest);
        $newRequest = new $class($newMethod, $location);

        foreach ($originalRequest->getHeaders() as $name => $value) {
            if (!in_array(strtolower($name), ['content-length', 'content-type'])) {
                $newRequest = $newRequest->withHeader($name, $value);
            }
        }

        if ($newBody !== null) $newRequest = $newRequest->withBody($newBody);

        $reflection = new \ReflectionClass($originalRequest);
        if ($reflection->hasProperty('followRedirects')) {
            $prop = $reflection->getProperty('followRedirects');
            $prop->setAccessible(true);
            $prop->setValue($newRequest, $prop->getValue($originalRequest));
        }

        return $newRequest;
    }

    private function resolveRelativeUrl(string $baseUrl, string $relativeUrl): string {
        $baseParts = parse_url($baseUrl);
        $scheme    = $baseParts['scheme'] ?? 'http';
        $host      = $baseParts['host']   ?? '';
        $port      = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';

        if (str_starts_with($relativeUrl, '/')) {
            return $scheme . '://' . $host . $port . $relativeUrl;
        }

        $path = $baseParts['path'] ?? '';
        $path = dirname($path);
        if ($path !== '/' && !str_ends_with($path, '/')) $path .= '/';
        return $scheme . '://' . $host . $port . $path . $relativeUrl;
    }

    private function buildBaseOptions(string $url, RequestInterface $request): array {
        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifySSL,
            CURLOPT_SSL_VERIFYHOST => $this->verifySSL ? 2 : 0,
            CURLOPT_ENCODING       => '',
        ];

        $method = $request->getMethod();
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
        } elseif ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        }

        $body = $request->getBody();
        if ($body !== null) $options[CURLOPT_POSTFIELDS] = $body;

        return $options;
    }

    private function addHeaders(array $options, RequestInterface $request, bool $includeAuth = true): array {
        $headers = [];

        foreach ($this->defaultHeaders as $key => $value) {
            if ($includeAuth || !$this->isSensitiveHeader($key)) {
                $headers[] = $key . ': ' . $value;
            }
        }

        if ($includeAuth && $this->auth !== null) {
            foreach ($this->auth->getHeaders() as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
        }

        foreach ($request->getHeaders() as $key => $value) {
            if ($includeAuth || !$this->isSensitiveHeader($key)) {
                $headers[] = $key . ': ' . $value;
            }
        }

        if ($headers !== []) {
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        return $options;
    }

    private function addCookies(array $options, RequestInterface $request): array {
        if ($this->cookieJar) {
            $cookies = $this->cookieJar->match($request->getUrl());
            if (!empty($cookies)) {
                $options[CURLOPT_COOKIE] = implode('; ', array_map(fn($c) => $c->getName() . '=' . $c->getValue(), $cookies));
            }
            $options[CURLOPT_COOKIEFILE] = '';
        }
        return $options;
    }

    private function addProxy(array $options): array {
        if ($this->proxy) {
            foreach ($this->proxy->getCurlOptions() as $key => $value) $options[$key] = $value;
        }
        return $options;
    }

    private function addCustomCurlOptions(array $options): array {
        foreach ($this->curlOptions as $option => $value) $options[$option] = $value;
        return $options;
    }

    private function applyCurlOptions(mixed $ch, array $options): void {
        foreach ($options as $option => $value) {
            if (is_int($option) && $option > 0) curl_setopt($ch, $option, $value);
        }
    }

    private function parseResponse(
        string $response,
        array $info,
        RequestInterface $request,
        ?string $effectiveUrl = null
    ): HttpResponse {
        $headerSize = (int) ($info['header_size'] ?? 0);
        $rawHeaders = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        if ($this->cookieJar !== null) {
            $this->saveCookiesFromResponse($rawHeaders, $effectiveUrl ?? $request->getUrl());
        }

        return new HttpResponse($body, $rawHeaders, (int) ($info['http_code'] ?? 0), $info);
    }

    private function saveCookiesFromResponse(string $rawHeaders, string $url): void {
        $domain = parse_url($url, PHP_URL_HOST);
        preg_match_all('/^Set-Cookie:\s*(.*)$/mi', $rawHeaders, $matches);
        foreach ($matches[1] as $cookieString) {
            $this->cookieJar->fromString($cookieString, $domain ?? '');
        }
    }
}
