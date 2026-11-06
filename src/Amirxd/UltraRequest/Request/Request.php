<?php

namespace Amirxd\UltraRequest\Request;

class Request implements RequestInterface {
    private string $method = 'GET';
    private string $url;
    private array $headers = [];
    private mixed $body = null;
    private bool $followRedirects = true;
    private ?int $maxRedirects = 10;
    private array $queryParams = [];
    private ?array $urlMethod = null;

    public function __construct(string $method, string $url) {
        $method = strtoupper(trim($method));
        $parts = parse_url($url);

        if (!preg_match("@^[A-Z0-9!#$%&'*+.^_\`|~-]+$@", $method)) {
            throw new \InvalidArgumentException("Invalid HTTP method: " . $method);
        }

        if ($url === '' || $parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException("A valid absolute HTTP URL is required");
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new \InvalidArgumentException("Only HTTP and HTTPS URLs are supported");
        }

        $this->method = $method;
        $this->url = $url;
    }

    public static function get(string $url): self    { return new self('GET',    $url); }
    public static function post(string $url): self   { return new self('POST',   $url); }
    public static function put(string $url): self    { return new self('PUT',    $url); }
    public static function delete(string $url): self { return new self('DELETE', $url); }
    public static function patch(string $url): self  { return new self('PATCH',  $url); }
    public static function head(string $url): self   { return new self('HEAD',   $url); }

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
        $clone->headers = array_merge($clone->headers, $headers);
        return $clone;
    }

    public function withBody(mixed $body): self {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }

    public function withJson(array $data): self {
        $json = json_encode($data, JSON_THROW_ON_ERROR);

        $clone = clone $this;
        $clone->headers['Content-Type'] = 'application/json';
        $clone->body = $json;

        return $clone;
    }

    public function withQueryParams(array $params): self {
        $clone = clone $this;
        $clone->queryParams = array_merge($clone->queryParams, $params);
        return $clone;
    }

    public function withoutRedirects(): self {
        $clone = clone $this;
        $clone->followRedirects = false;
        return $clone;
    }

    public function withMaxRedirects(int $max): self {
        if ($max < 0) {
            throw new \InvalidArgumentException("Maximum redirects cannot be negative");
        }

        $clone = clone $this;
        $clone->maxRedirects = $max;

        return $clone;
    }

    public function withUrlMethod(string $name, string $value): self {
        $clone = clone $this;
        $clone->urlMethod = ['name' => $name, 'value' => $value];
        return $clone;
    }

    public function getMethod(): string { return $this->method; }

    public function getUrl(): string {
        $allParams = $this->queryParams;
        $urlMethod = $this->getUrlMethod();

        if ($urlMethod !== null && isset($urlMethod['name'], $urlMethod['value'])) {
            $allParams[$urlMethod['name']] = $urlMethod['value'];
        }

        if ($allParams === []) {
            return $this->url;
        }

        $url = $this->url;
        $fragment = '';
        $fragmentPosition = strpos($url, '#');

        if ($fragmentPosition !== false) {
            $fragment = substr($url, $fragmentPosition);
            $url = substr($url, 0, $fragmentPosition);
        }

        $existingParams = [];
        $queryPosition = strpos($url, '?');

        if ($queryPosition !== false) {
            parse_str(substr($url, $queryPosition + 1), $existingParams);
            $url = substr($url, 0, $queryPosition);
        }

        $query = http_build_query(array_merge($existingParams, $allParams));

        return $url . ($query !== '' ? '?' . $query : '') . $fragment;
    }

    public function getHeaders(): array        { return $this->headers; }
    public function getUrlMethod(): ?array      { return $this->urlMethod; }
    public function getBody(): mixed            { return $this->body; }
    public function followsRedirects(): bool    { return $this->followRedirects; }
    public function getMaxRedirects(): ?int     { return $this->maxRedirects; }
}
