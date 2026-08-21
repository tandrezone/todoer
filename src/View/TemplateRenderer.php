<?php

declare(strict_types=1);

namespace App\View;

use App\Http\UrlGenerator;
use App\Session\CsrfTokenManager;
use RuntimeException;
use Throwable;

/**
 * Renders a plain-PHP template to a string.
 *
 * Templates are markup with the smallest possible amount of PHP in them: a loop, a conditional, and
 * output. They receive no services and cannot query anything -- whatever they show, a controller
 * decided to pass them.
 *
 * Every template gets `$e()`, the escaper, and the convention in this project is that *all*
 * interpolation goes through it. `htmlspecialchars` with ENT_QUOTES also escapes single quotes, so
 * the helper is safe inside single-quoted attributes too, and ENT_SUBSTITUTE means invalid UTF-8
 * becomes a replacement character rather than an empty string (which is how escaping silently
 * "succeeds" while dropping content).
 */
final class TemplateRenderer
{
    /** @var array<string, mixed> */
    private array $globals;

    /** @param array<string, mixed> $globals */
    public function __construct(
        private readonly string $templateDir,
        private readonly UrlGenerator $urls,
        private readonly CsrfTokenManager $csrf,
        array $globals = []
    ) {
        $this->globals = $globals;
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $file = $this->templateDir . '/' . ltrim($template, '/') . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Template not found: ' . $template);
        }

        $scope = array_merge($this->globals, [
            'e' => static fn(mixed $value): string => htmlspecialchars(
                (string) $value,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ),
            'url' => $this->urls,
            'csrfToken' => $this->csrf->token(),
            'basePath' => $this->urls->basePath(),
            'partial' => fn(string $name, array $partialData = []): string => $this->render($name, $partialData),
        ], $data);

        ob_start();
        try {
            (static function (array $scope, string $file): void {
                extract($scope, EXTR_SKIP);
                require $file;
            })($scope, $file);
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * Renders a page template inside the site layout.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $layout Layout options: title, bodyClass, scripts, activeNav.
     */
    public function page(string $template, array $data = [], array $layout = []): string
    {
        return $this->render('layout', array_merge([
            'title' => 'Todoer',
            'bodyClass' => '',
            'scripts' => [],
            'activeNav' => '',
            'user' => $data['user'] ?? null,
            'group' => $data['group'] ?? null,
            'content' => $this->render($template, $data),
        ], $layout));
    }
}
