<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\ValidationException;
use App\Http\Input\InputBag;
use App\Http\RequestAttribute;
use App\Http\Responder;
use App\Service\PushService;
use App\Session\CsrfTokenManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Push configuration and subscription management.
 *
 * The GET also hands back the session's CSRF token. That is deliberate: the service worker
 * re-subscribes on its own when the browser rotates an endpoint (pushsubscriptionchange), and that
 * POST has no page to read a <meta> tag from. It is safe because a cross-origin caller cannot read
 * this response, and any same-origin page could already read the token from its own markup.
 */
final class NotificationApiController implements RequestHandlerInterface
{
    public function __construct(
        private readonly PushService $push,
        private readonly CsrfTokenManager $csrf,
        private readonly Responder $responder
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = RequestAttribute::user($request);

        if ($request->getMethod() !== 'POST') {
            return $this->responder->json([
                'ok' => true,
                'public_key' => $this->push->publicKey(),
                'csrf_token' => $this->csrf->token(),
            ]);
        }

        $input = InputBag::fromBody($request);
        $subscription = $input->all()['subscription'] ?? [];

        switch ($input->string('action')) {
            case 'subscribe':
                $this->push->saveSubscription($user->id, is_array($subscription) ? $subscription : []);
                break;

            case 'unsubscribe':
                $this->push->removeSubscription($user->id, $input->string('endpoint'));
                break;

            default:
                throw new ValidationException('Unknown action.');
        }

        return $this->responder->json(['ok' => true]);
    }
}
