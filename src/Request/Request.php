<?php

declare(strict_types=1);

namespace App\Request;

class Request
{
    private array $server;
    private array $get;
    private array $post;
    private array $attributes = [];

    public function __construct(array $server = [], array $get = [], array $post = [])
    {
        $this->server = $server + $_SERVER;
        $this->get = $get + $_GET;
        $this->post = $post + $_POST;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization');
        if (!$auth) {
            return null;
        }
        if (stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        return null;
    }

    public function header(string $name): ?string
    {
        $key = strtoupper(str_replace('-', '_', $name));
        $candidates = [
            'HTTP_' . $key,
            $key,
        ];
        foreach ($candidates as $k) {
            if (isset($this->server[$k]) && $this->server[$k] !== '') {
                return $this->server[$k];
            }
        }
        // try apache_request_headers if available
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $hName => $hVal) {
                if (strcasecmp($hName, $name) === 0) {
                    return $hVal;
                }
            }
        }
        return null;
    }

    public function ip(): ?string
    {
        return $this->server['REMOTE_ADDR'] ?? null;
    }

    public function setAttribute(string $key, $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, $default = null)
    {
        return $this->attributes[$key] ?? $default;
    }

    public function get(string $key, $default = null)
    {
        return $this->get[$key] ?? $default;
    }

    public function post(string $key, $default = null)
    {
        return $this->post[$key] ?? $default;
    }
}
