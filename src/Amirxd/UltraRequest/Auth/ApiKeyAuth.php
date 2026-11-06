<?php

namespace Amirxd\UltraRequest\Auth;

class ApiKeyAuth implements AuthInterface {
    private string $key;
    private string $value;
    private string $placement;
    private ?string $prefix;

    public function __construct(string $key, string $value, string $placement = 'header', ?string $prefix = null) {
        if (!in_array($placement, ['header', 'query'], true)) {
            throw new \InvalidArgumentException("API key placement must be either 'header' or 'query'");
        }

        if ($key === '' || strpbrk($key, "\r\n") !== false) {
            throw new \InvalidArgumentException("API key name cannot be empty or contain line breaks");
        }

        if (strpbrk($value, "\r\n") !== false || ($prefix !== null && strpbrk($prefix, "\r\n") !== false)) {
            throw new \InvalidArgumentException("API key values and prefixes cannot contain line breaks");
        }

        $this->key = $key;
        $this->value = $value;
        $this->placement = $placement;
        $this->prefix = $prefix;
    }

    public function getHeaders(): array {
        if ($this->placement !== 'header') return [];
        $value = $this->prefix ? $this->prefix . ' ' . $this->value : $this->value;
        return [$this->key => $value];
    }

    public function getQueryParams(): array {
        if ($this->placement !== 'query') return [];
        return [$this->key => $this->value];
    }

    public function getType(): string { return 'api_key'; }
}
