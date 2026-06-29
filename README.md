

# ⚡ UltraRequest

**A powerful, fluent HTTP client Virion for PocketMine-MP 5**

[![PocketMine-MP](https://img.shields.io/badge/PocketMine--MP-5.x-blue?style=flat-square)](https://github.com/pmmp/PocketMine-MP)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple?style=flat-square)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-MIT-green?style=flat-square)](LICENSE)
[![Virion](https://img.shields.io/badge/Type-Virion-orange?style=flat-square)](https://github.com/poggit/libasynql)

*Make HTTP requests from your PocketMine plugins with zero boilerplate — sync or async, with auth, cookies, proxies, file downloads, and more.*

</div>

---

## 📋 Table of Contents

- [Features](#-features)
- [Requirements](#-requirements)
- [Installation](#-installation)
- [Quick Start](#-quick-start)
- [Registration](#-registration)
- [Making Requests](#-making-requests)
  - [Synchronous Requests](#synchronous-requests)
  - [Asynchronous Requests](#asynchronous-requests)
- [Request Builder](#-request-builder)
- [Response Object](#-response-object)
- [Authentication](#-authentication)
- [Cookies](#-cookies)
- [Proxy Support](#-proxy-support)
- [File Downloads](#-file-downloads)
- [Client Configuration](#-client-configuration)
- [Error Handling](#-error-handling)
- [Architecture](#-architecture)
- [API Reference](#-api-reference)

---

## ✨ Features

- **Fluent, immutable API** — chain method calls cleanly without side effects
- **Sync & Async** — blocking `send()` and non-blocking `asyncSend()` using PocketMine's AsyncPool
- **Full HTTP method support** — GET, POST, PUT, DELETE, PATCH, HEAD
- **Authentication** — Basic, Bearer Token, and API Key (header or query)
- **Cookie management** — in-memory or file-persisted CookieJar with domain/path matching
- **Proxy support** — HTTP, HTTPS, SOCKS4, SOCKS5 with optional authentication
- **File downloading** — resumable downloads with progress callbacks and retry logic
- **Redirect handling** — manual redirect control with RFC 7231 compliance (301/302/303 → GET)
- **SSL control** — enable or disable SSL verification per client
- **Custom cURL options** — full escape hatch to any cURL option
- **Thread-safe async** — closures stored via `storeLocal`/`fetchLocal`, never serialized

---

## 📦 Requirements

| Requirement | Version |
|---|---|
| PocketMine-MP | 5.x |
| PHP | 8.1+ |
| PHP Extension | `curl` |

---

## 🔧 Installation

### Via Poggit Virion (recommended)

Add the following to your plugin's `plugin.yml`:

```yaml
virions:
  - UltraRequest
```

### Manual

1. Download the latest release zip
2. Extract into your plugin's `src/` directory
3. The virion will be injected automatically by the Virion infector at build time

---

## ⚡ Quick Start

```php
use Amirxd\UltraRequest\Client\Client;
use Amirxd\UltraRequest\Request\Request;
use Amirxd\UltraRequest\Request\RequestHandler;

// 1. Register once in onEnable()
RequestHandler::register($this);

// 2. Create a client
$client = new Client();

// 3. Send a request (async — won't block the server thread)
$client->asyncSend(
    Request::get("https://api.example.com/users")
        ->withHeader("Accept", "application/json"),
    onSuccess: function(array $result): void {
        $users = $result['json'];
        // handle response on main thread
    },
    onError: function(\RuntimeException $e): void {
        $this->getLogger()->error($e->getMessage());
    }
);
```

---

## 🔑 Registration

Before using `asyncSend()`, you must register your plugin once with `RequestHandler`. This gives UltraRequest access to the server's AsyncPool.

```php
// Inside your main plugin class
public function onEnable(): void {
    RequestHandler::register($this);
}
```

> **Note:** Calling `register()` more than once is safe — subsequent calls are silently ignored.

You can check registration status anywhere:

```php
if (RequestHandler::isRegistered()) {
    // safe to use asyncSend
}
```

---

## 📡 Making Requests

### Synchronous Requests

> ⚠️ **Warning:** Synchronous requests run on the **main server thread** and will freeze the server until the request completes. Only use this in async tasks or CLI scripts — never directly in event handlers or `onEnable()`.

```php
use Amirxd\UltraRequest\Client\Client;
use Amirxd\UltraRequest\Request\Request;

$client = new Client();

$response = $client->send(
    Request::post("https://api.example.com/data")
        ->withJson(["key" => "value"])
);

echo $response->getStatusCode(); // 200
echo $response->getBody();       // raw response string
```

### Asynchronous Requests

Async requests run in PocketMine's worker thread pool via `AsyncTask`. Your callbacks execute back on the **main thread** once the request completes — safe to interact with players, worlds, and plugin state.

```php
$client = new Client();

$client->asyncSend(
    request:   Request::get("https://api.example.com/status"),
    onSuccess: function(array $result): void {
        // $result keys: success, status, headers, body, json, info
        $this->getLogger()->info("Status: " . $result['status']);
        $data = $result['json']; // already decoded array
    },
    onError: function(\RuntimeException $e): void {
        $this->getLogger()->error("Request failed: " . $e->getMessage());
    }
);
```

Both callbacks are **optional**. You can omit either or both:

```php
// Fire and forget
$client->asyncSend(Request::post("https://analytics.example.com/ping"));

// Only handle errors
$client->asyncSend(
    Request::get("https://example.com"),
    onError: fn(\RuntimeException $e) => $this->getLogger()->warning($e->getMessage())
);
```

---

## 🏗️ Request Builder

All `Request` methods return a **new immutable clone** — the original is never modified.

### Static Factories

```php
Request::get("https://example.com")
Request::post("https://example.com")
Request::put("https://example.com")
Request::delete("https://example.com")
Request::patch("https://example.com")
Request::head("https://example.com")
```

### Headers

```php
$request = Request::get("https://api.example.com")
    ->withHeader("Accept", "application/json")
    ->withHeader("X-Custom", "value");

// Set multiple headers at once
$request = Request::get("https://api.example.com")
    ->withHeaders([
        "Accept"       => "application/json",
        "X-Request-ID" => "abc123",
    ]);
```

### Body

```php
// Raw body
$request = Request::post("https://api.example.com/data")
    ->withBody("raw string or form data");

// JSON body (automatically sets Content-Type: application/json)
$request = Request::post("https://api.example.com/users")
    ->withJson(["name" => "Steve", "score" => 100]);
```

### Query Parameters

```php
$request = Request::get("https://api.example.com/search")
    ->withQueryParams(["q" => "diamond", "limit" => "10"]);
// Result: https://api.example.com/search?q=diamond&limit=10

// Append to existing URL query string safely
$request = Request::get("https://api.example.com/search?page=2")
    ->withQueryParams(["q" => "sword"]);
// Result: https://api.example.com/search?page=2&q=sword
```

### URL Method Override

Some APIs use a query parameter to override the HTTP method (e.g. `?_method=DELETE`):

```php
$request = Request::post("https://api.example.com/item/5")
    ->withUrlMethod("_method", "DELETE");
// Result: https://api.example.com/item/5?_method=DELETE
```

### Redirects

```php
// Disable automatic redirect following
$request = Request::get("https://example.com")
    ->withoutRedirects();

// Limit number of redirects (default: 10)
$request = Request::get("https://example.com")
    ->withMaxRedirects(3);
```

---

## 📬 Response Object

The `HttpResponse` object is returned from `send()` and passed as `$result` in `asyncSend()`'s `onSuccess` callback.

### In `send()` (synchronous)

```php
$response = $client->send(Request::get("https://api.example.com"));

$response->getStatusCode();     // int — e.g. 200
$response->getBody();           // ?string — raw response body
$response->getJson();           // mixed — json_decode($body, true)
$response->getJson(false);      // object instead of array
$response->getHeaders();        // array<string, string> — all headers (lowercase keys)
$response->getHeader("content-type"); // ?string — single header value
$response->getInfo();           // array — raw cURL info (total_time, etc.)

$response->isSuccessful();      // bool — status 200–299
$response->isRedirect();        // bool — status 300–399
$response->isClientError();     // bool — status 400–499
$response->isServerError();     // bool — status 500–599
```

### In `asyncSend()` (asynchronous)

The `$result` array passed to `onSuccess` contains:

```php
[
    'success' => true,
    'status'  => 200,           // int
    'headers' => [...],         // array<string, string>
    'body'    => "...",         // ?string
    'json'    => [...],         // mixed — pre-decoded
    'info'    => [...],         // array — cURL info
]
```

---

## 🔐 Authentication

### Basic Auth

```php
$client = (new Client())->withBasicAuth("username", "password");
```

### Bearer Token

```php
$client = (new Client())->withBearerAuth("your-jwt-token-here");
```

### API Key (Header)

```php
use Amirxd\UltraRequest\Auth\AuthManager;

$client = (new Client())->withAuth(function(AuthManager $auth): void {
    $auth->apiKey("X-API-Key", "my-secret-key");
    // Sends header: X-API-Key: my-secret-key
});

// With a prefix:
$client = (new Client())->withAuth(function(AuthManager $auth): void {
    $auth->apiKey("Authorization", "my-secret-key", placement: "header", prefix: "Token");
    // Sends header: Authorization: Token my-secret-key
});
```

### API Key (Query Parameter)

```php
$client = (new Client())->withAuth(function(AuthManager $auth): void {
    $auth->apiKey("api_key", "my-secret-key", placement: "query");
    // Appends ?api_key=my-secret-key to every request URL
});
```

---

## 🍪 Cookies

### In-Memory CookieJar

Cookies are stored in memory only — lost when the server restarts.

```php
use Amirxd\UltraRequest\Cookie\CookieJar;

$jar    = new CookieJar();
$client = (new Client())->withCookieJar($jar);

// Cookies from Set-Cookie response headers are parsed and stored automatically.
// They are re-sent on subsequent requests to matching domains/paths.
```

### Persistent CookieJar

Pass an absolute file path to persist cookies across server restarts:

```php
$jar = new CookieJar(
    storagePath: $this->getDataFolder() . "cookies.json",
    fileLoad: true  // load existing cookies from disk on construction
);

$client = (new Client())->withCookieJar($jar);
```

### Manual Cookie Management

```php
$jar->setCookie("session", "abc123", [
    "domain"   => "example.com",
    "path"     => "/",
    "expires"  => time() + 3600,
    "secure"   => true,
    "httpOnly" => true,
]);

$cookie = $jar->getCookie("session");
$cookie->getName();    // "session"
$cookie->getValue();   // "abc123"
$cookie->isExpired();  // false

$jar->hasCookie("session");   // true
$jar->removeCookie("session");
$jar->clear(); // remove all cookies
```

---

## 🌐 Proxy Support

```php
use Amirxd\UltraRequest\Proxy\Proxy;

// HTTP proxy
$proxy  = new Proxy("http://proxy.example.com:8080");
$client = (new Client())->setProxy($proxy);

// SOCKS5 proxy
$proxy = new Proxy("socks5://proxy.example.com:1080");

// With authentication
$proxy = (new Proxy("http://proxy.example.com:8080"))
    ->withAuth("username", "password");

// Full URL format (credentials in URL)
$proxy = new Proxy("socks5://user:pass@proxy.example.com:1080");
```

**Supported proxy types:** `http`, `https`, `socks4`, `socks5`

---

## 📥 File Downloads

```php
use Amirxd\UltraRequest\File\Downloader;

$downloader = new Downloader(
    url:      "https://example.com/resource.zip",
    savePath: $this->getDataFolder() . "downloads/resource.zip"
);

$client->download($downloader);
```

### Resume Interrupted Downloads

```php
$downloader = (new Downloader($url, $savePath))
    ->withResume();
// Sends Range: bytes=<existing_size>- and appends to the file
```

### Progress Callback

```php
$downloader = (new Downloader($url, $savePath))
    ->withProgress(function(array $progress): void {
        echo sprintf(
            "%.1f%% | %s / %s | %s | ETA: %s\n",
            $progress['percent'],
            $progress['downloaded_formatted'],  // e.g. "4.50 MB"
            $progress['total_formatted'],       // e.g. "12.00 MB"
            $progress['speed_formatted'],       // e.g. "1.20 MB/s"
            $progress['eta_formatted']          // e.g. "00:06"
        );
    });
```

### Retry Logic

```php
$downloader = (new Downloader($url, $savePath))
    ->withRetries(maxRetries: 5, delaySeconds: 2);
// Retries up to 5 times with a 2-second delay between attempts
```

### Overwrite Existing Files

```php
$downloader = (new Downloader($url, $savePath))
    ->withOverwrite(); // overwrite if already exists
```

### All Downloader Options

```php
$downloader = (new Downloader($url, $savePath))
    ->withResume()                           // resume partial downloads
    ->withOverwrite()                        // overwrite existing file
    ->withRetries(3, 1)                      // retry 3 times, 1s delay
    ->withHeader("Authorization", "Bearer token") // custom headers
    ->withHeaders(["X-Custom" => "value"])   // multiple headers
    ->withProgress(fn($p) => ...)            // progress callback
    ->withChunkSize(2 * 1024 * 1024);        // 2MB chunk hint
```

---

## ⚙️ Client Configuration

All `with*` methods on `Client` return a **new immutable clone**.

```php
$client = (new Client())
    ->withTimeout(60)           // total request timeout in seconds (default: 30)
    ->withConnectTimeout(15)    // connection timeout in seconds (default: 10)
    ->withSSL(false)            // disable SSL certificate verification
    ->withBearerAuth("token")   // authentication
    ->withCookieJar($jar)       // cookie management
    ->setProxy($proxy);         // proxy server
```

### Custom cURL Options

For anything not covered by the fluent API, you can pass raw cURL options:

```php
$client = (new Client())
    ->withCurlOption(CURLOPT_INTERFACE, "eth0")
    ->withCurlOption(CURLOPT_DNS_SERVERS, "8.8.8.8");
```

---

## ⚠️ Error Handling

### Synchronous

```php
try {
    $response = $client->send(Request::get("https://example.com"));
} catch (\Exception $e) {
    // cURL errors, max redirects exceeded, etc.
    $this->getLogger()->error("Request failed: " . $e->getMessage());
}
```

### Asynchronous

```php
$client->asyncSend(
    Request::get("https://example.com"),
    onError: function(\RuntimeException $e): void {
        // Called on the main thread — safe to use $this
        $this->getLogger()->error($e->getMessage());
        // $e->getMessage() format: "[ExceptionClass] original message"
    }
);
```

### RequestHandler Not Registered

If you call `asyncSend()` before calling `RequestHandler::register($plugin)`, a `RuntimeException` is thrown immediately (on the main thread):

```php
// RuntimeException: RequestHandler is not registered.
// Call RequestHandler::register($plugin) first.
```

---

## 🏛️ Architecture

```
UltraRequest/
├── Client/
│   └── Client.php              # Main HTTP client — send(), asyncSend(), download()
├── Request/
│   ├── Request.php             # Immutable request builder
│   ├── RequestInterface.php    # Request contract
│   └── RequestHandler.php      # Plugin registry for async support
├── Response/
│   ├── HttpResponse.php        # Response wrapper
│   └── ResponseInterface.php   # Response contract
├── Auth/
│   ├── AuthManager.php         # Auth strategy selector
│   ├── AuthInterface.php       # Auth contract
│   ├── BasicAuth.php           # HTTP Basic authentication
│   ├── BearerAuth.php          # Bearer token authentication
│   └── ApiKeyAuth.php          # API key (header or query)
├── Cookie/
│   ├── CookieJar.php           # Cookie storage and matching
│   ├── CookieJarInterface.php  # CookieJar contract
│   ├── Cookie.php              # Individual cookie model
│   └── CookieInterface.php     # Cookie contract
├── Proxy/
│   ├── Proxy.php               # Proxy configuration
│   └── ProxyInterface.php      # Proxy contract
├── File/
│   └── Downloader.php          # File download helper
└── Task/
    └── AsyncRequestTask.php    # PocketMine AsyncTask wrapper
```

### How Async Works

1. `asyncSend()` creates an `AsyncRequestTask` and submits it to PocketMine's AsyncPool
2. `Request` and `Client` objects are `serialize()`d and passed to the worker thread
3. `onRun()` executes on the worker thread: unserializes both objects, performs the cURL request, and stores the result via `setResult()`
4. `onCompletion()` executes back on the **main thread**: retrieves the result and calls your `onSuccess` or `onError` closure
5. Closures are **never** sent to the worker thread — they are stored locally via `storeLocal()` / `fetchLocal()` to avoid `NonThreadSafeValueError`

---

## 📖 API Reference

### `Client`

| Method | Description |
|--------|-------------|
| `send(RequestInterface $request): HttpResponse` | Synchronous request (blocks main thread) |
| `asyncSend(Request $request, ?\Closure $onSuccess, ?\Closure $onError): void` | Non-blocking async request |
| `download(Downloader $downloader): bool` | Download a file synchronously |
| `withTimeout(int $seconds): self` | Set total request timeout |
| `withConnectTimeout(int $seconds): self` | Set connection timeout |
| `withSSL(bool $verify): self` | Enable/disable SSL verification |
| `withBasicAuth(string $user, string $pass): self` | Set Basic auth |
| `withBearerAuth(string $token): self` | Set Bearer auth |
| `withAuth(callable $callback): self` | Configure auth via AuthManager |
| `withCookieJar(CookieJarInterface $jar): self` | Attach a cookie jar |
| `setProxy(ProxyInterface $proxy): self` | Set a proxy |
| `withCurlOption(int $option, mixed $value): self` | Set a raw cURL option |

### `Request`

| Method | Description |
|--------|-------------|
| `Request::get(string $url): self` | Create a GET request |
| `Request::post(string $url): self` | Create a POST request |
| `Request::put(string $url): self` | Create a PUT request |
| `Request::delete(string $url): self` | Create a DELETE request |
| `Request::patch(string $url): self` | Create a PATCH request |
| `Request::head(string $url): self` | Create a HEAD request |
| `withHeader(string $name, string $value): self` | Add/set a header |
| `withHeaders(array $headers): self` | Merge multiple headers |
| `withBody(mixed $body): self` | Set raw request body |
| `withJson(array $data): self` | Set JSON body + Content-Type header |
| `withQueryParams(array $params): self` | Append query parameters |
| `withUrlMethod(string $name, string $value): self` | Override HTTP method via query param |
| `withoutRedirects(): self` | Disable redirect following |
| `withMaxRedirects(int $max): self` | Set max redirect limit |

### `HttpResponse`

| Method | Description |
|--------|-------------|
| `getStatusCode(): int` | HTTP status code |
| `getBody(): ?string` | Raw response body |
| `getJson(bool $assoc = true): mixed` | JSON-decoded body |
| `getHeaders(): array` | All response headers (lowercase keys) |
| `getHeader(string $name): ?string` | Single header value |
| `getInfo(): array` | Raw cURL info array |
| `isSuccessful(): bool` | Status 200–299 |
| `isRedirect(): bool` | Status 300–399 |
| `isClientError(): bool` | Status 400–499 |
| `isServerError(): bool` | Status 500–599 |

### `RequestHandler`

| Method | Description |
|--------|-------------|
| `register(PluginBase $plugin): void` | Register your plugin (call once in `onEnable`) |
| `isRegistered(): bool` | Check if a plugin has been registered |
| `getRegistrant(): ?PluginBase` | Get the registered plugin instance |

---

## 📄 License

MIT License — see [LICENSE](LICENSE) for details.

---

<div align="center">

Made with ❤️ by **Amirxd**

</div>