<?php

declare(strict_types=1);

namespace App\Core;

/**
 * A response the front controller knows how to send.
 *
 * Controllers return one of these rather than echoing, so that headers can
 * still be set after the body is built and so redirects are unambiguous.
 */
final class Response
{
    /** @param array<string,string> $headers */
    private function __construct(
        private string $body = '',
        private int $status = 200,
        private array $headers = []
    ) {
    }

    /** @param array<string,string> $headers */
    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, array_merge(
            ['Content-Type' => 'text/html; charset=UTF-8'],
            $headers
        ));
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    /**
     * 303 See Other is the correct status after a successful POST: it tells the
     * browser to follow up with a GET, which is what makes POST-redirect-GET
     * work and stops a refresh from resubmitting the form (FR-CART-09).
     */
    public static function redirect(string $url, int $status = 303): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    public static function notFound(string $body = ''): self
    {
        return self::html($body, 404);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }

            foreach (self::securityHeaders() as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        echo $this->body;
    }

    /**
     * Baseline security headers (NFR-SEC-08).
     *
     * Bootstrap and the Inter fonts are self-hosted, so the policy is 'self'
     * only - no CDN origin is trusted and the application works offline.
     * 'unsafe-inline' is permitted for styles because Bootstrap components set
     * style attributes at runtime; it is NOT permitted for scripts. Phase 3
     * adds nonces once there is dynamic script worth guarding.
     */
    /** @return array<string,string> */
    private static function securityHeaders(): array
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'X-Frame-Options'        => 'DENY',
            'Permissions-Policy'     => 'geolocation=(), microphone=(), camera=(self)',
        ];

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "font-src 'self' data:",
            "img-src 'self' data:",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
        ]);

        $headers['Content-Security-Policy'] = $csp;

        return $headers;
    }
}
