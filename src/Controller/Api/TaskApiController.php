<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Domain\Enum\ListType;
use App\Exception\ValidationException;
use App\Http\Input\InputBag;
use App\Http\RequestAttribute;
use App\Http\Responder;
use App\Service\AssignmentService;
use App\Service\NotificationService;
use App\Service\TaskBoardService;
use App\Service\TaskService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The task endpoint: GET returns the whole dashboard payload, POST performs one action on it.
 *
 * The action names are the ones the front-end already sent, so this refactor is invisible to
 * assets/js/app.js. Each branch does nothing but read validated input and call a service -- there is
 * no game logic in this file, which is the point: the same rules apply whether a task is completed
 * from the dashboard, an import, or a future CLI command.
 *
 * The caller's group comes from the session-resolved membership on the request, never from the
 * request body, so no shape of payload can point this endpoint at another group's tasks.
 */
final class TaskApiController implements RequestHandlerInterface
{
    public function __construct(
        private readonly TaskBoardService $board,
        private readonly TaskService $tasks,
        private readonly AssignmentService $assignment,
        private readonly NotificationService $notifications,
        private readonly Responder $responder
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = RequestAttribute::user($request);
        $membership = RequestAttribute::membership($request);

        if ($request->getMethod() !== 'POST') {
            return $this->responder->json($this->board->dashboard($user, $membership));
        }

        $input = InputBag::fromBody($request);

        return match ($input->string('action')) {
            'notifications_read' => $this->markNotificationsRead($user->id, $input),
            'add' => $this->responder->json([
                'ok' => true,
                'id' => $this->tasks->add($user, $membership, $input),
            ]),
            'edit' => $this->ok(fn() => $this->tasks->edit($user, $membership, $input)),
            'delete' => $this->ok(fn() => $this->tasks->delete($user, $membership, $input)),
            'complete' => $this->responder->json(
                ['ok' => true] + $this->tasks->complete($user, $membership, $input)
            ),
            'reopen' => $this->responder->json(
                ['ok' => true] + $this->tasks->reopen($user, $membership, $input)
            ),
            'start' => $this->responder->json(['ok' => true] + $this->assignment->startGame(
                $membership->group->id,
                ListType::fromRequest($input->string('list_type'))
            )),
            'stop' => $this->responder->json(['ok' => true] + $this->assignment->stopGame(
                $membership->group->id,
                ListType::fromRequest($input->string('list_type'))
            )),
            default => throw new ValidationException('Unknown action.'),
        };
    }

    private function markNotificationsRead(int $userId, InputBag $input): ResponseInterface
    {
        $ids = array_map('intval', array_filter($input->list('ids'), static fn(mixed $id): bool => is_numeric($id)));
        $this->notifications->markRead($userId, array_values($ids));

        return $this->responder->json(['ok' => true]);
    }

    /** @param callable(): void $action */
    private function ok(callable $action): ResponseInterface
    {
        $action();

        return $this->responder->json(['ok' => true]);
    }
}
