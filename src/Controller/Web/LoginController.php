<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Exception\ValidationException;
use App\Http\Input\InputBag;
use App\Http\RequestAttribute;
use App\Http\Responder;
use App\Http\UrlGenerator;
use App\Service\AuthService;
use App\View\TemplateRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Sign in and registration -- the one page that answers both GET and POST.
 *
 * A failed attempt re-renders the form with the message inline (and the typed username kept),
 * rather than redirecting to a generic error page: this is the one place where an expected failure
 * is part of the page's normal life rather than an exception.
 */
final class LoginController implements RequestHandlerInterface
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly TemplateRenderer $renderer,
        private readonly Responder $responder,
        private readonly UrlGenerator $urls
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Already signed in: there is nothing to do on this page.
        if (RequestAttribute::optionalUser($request) !== null) {
            return $this->responder->redirect($this->urls->path('/'));
        }

        if ($request->getMethod() !== 'POST') {
            return $this->page('login', '', '', '');
        }

        $input = InputBag::fromBody($request);
        $mode = $input->string('mode') === 'register' ? 'register' : 'login';
        $username = $input->string('username');
        $inviteCode = $input->string('invite_code');

        try {
            if ($mode === 'register') {
                $this->auth->register($username, $input->string('password'), $inviteCode);
            } else {
                $this->auth->login($username, $input->string('password'));
            }
        } catch (ValidationException $e) {
            return $this->page($mode, $e->getMessage(), $username, $inviteCode);
        }

        return $this->responder->redirect($this->urls->path('/'));
    }

    private function page(string $mode, string $error, string $username, string $inviteCode): ResponseInterface
    {
        return $this->responder->html(
            $this->renderer->page(
                'login',
                [
                    'mode' => $mode === 'register' ? 'register' : 'login',
                    'error' => $error,
                    'username' => $username,
                    'inviteCode' => $inviteCode,
                ],
                [
                    'title' => 'Todoer — sign in',
                    'bodyClass' => 'auth-body',
                    'scripts' => ['assets/js/auth.js'],
                ]
            ),
            $error === '' ? 200 : 422
        );
    }
}
