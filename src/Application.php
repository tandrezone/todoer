<?php

declare(strict_types=1);

namespace App;

use App\Container\Container;
use App\Http\SapiEmitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The application.
 *
 * `handle()` takes a PSR-7 request and returns a PSR-7 response with no reference to PHP's
 * superglobals, output buffering or exit() -- which is what lets the whole stack, middleware
 * included, run inside a test. `run()` is the thin SAPI adapter around it: build a request from the
 * environment, handle it, write the response out.
 */
final class Application
{
    private function __construct(private readonly Container $container)
    {
    }

    /**
     * Boots the application from the project root.
     *
     * @param string|null $basePath The sub-directory the app is served from ('' at a domain root).
     *                              Detected from the executing script when not given, so a
     *                              deployment under /todoer/ needs no configuration.
     */
    public static function boot(string $rootDir, ?string $basePath = null): self
    {
        $settingsFactory = require $rootDir . '/config/settings.php';
        $settings = $settingsFactory($rootDir);

        $containerFactory = require $rootDir . '/config/container.php';

        return new self($containerFactory($settings, $basePath ?? self::detectBasePath()));
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->container->typed(RequestHandlerInterface::class)->handle($request);
    }

    public function run(): void
    {
        $factory = $this->container->typed(Psr17Factory::class);
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);

        $this->container->typed(SapiEmitter::class)->emit($this->handle($creator->fromGlobals()));
    }

    private static function detectBasePath(): string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        if ($script === '') {
            return '';
        }

        $directory = rtrim(str_replace('\\', '/', dirname($script)), '/');

        return $directory === '/' ? '' : $directory;
    }
}
