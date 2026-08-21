<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Builds application URLs that survive being served from a sub-directory.
 *
 * Templates and the front-end both need this: the app is routinely mounted at the domain root
 * during development (`php -S`) and under something like /todoer/ on a home server, and
 * hard-coded absolute paths break the second case (that is exactly what the service worker
 * scope comment in the original code was working around).
 */
final class UrlGenerator
{
    public function __construct(
        private readonly string $basePath = '',
        private readonly string $publicDir = ''
    ) {
    }

    public function basePath(): string
    {
        return $this->basePath === '' ? '/' : $this->basePath . '/';
    }

    public function path(string $path = '/'): string
    {
        $path = '/' . ltrim($path, '/');

        return $this->basePath . ($path === '/' ? '/' : rtrim($path, '/'));
    }

    /**
     * An asset URL with a cache-busting version derived from the file's modification time, so a
     * deployed CSS/JS change is picked up without anyone having to hard-reload.
     */
    public function asset(string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');
        $url = $this->basePath . '/' . $relativePath;

        if ($this->publicDir !== '') {
            $file = $this->publicDir . '/' . $relativePath;
            $version = is_file($file) ? (int) filemtime($file) : 0;
            if ($version > 0) {
                $url .= '?v=' . $version;
            }
        }

        return $url;
    }
}
