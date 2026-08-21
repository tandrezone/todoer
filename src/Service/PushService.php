<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ValidationException;
use App\Repository\PushSubscriptionRepository;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Web Push delivery, and the VAPID keypair that signs it.
 *
 * Keys resolve from the environment first (so a real deployment can keep them outside the project
 * and rotate them centrally) and otherwise from data/vapid.json, generated on first use with mode
 * 0600. That auto-generation is the point: push used to need two environment variables nobody had
 * set, so it was quietly off on every ordinary installation.
 *
 * Everything here is best-effort. Push is a side effect of finishing a task or starting a game, and
 * an unreachable push service must never turn "task done" into a 500 -- failures are logged, dead
 * subscriptions are dropped, and the caller is not told.
 */
final class PushService
{
    private ?array $keys = null;

    private bool $keysResolved = false;

    /** @param array<string, mixed> $settings */
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly LoggerInterface $logger,
        private readonly array $settings
    ) {
    }

    public function isEnabled(): bool
    {
        return class_exists(WebPush::class) && $this->vapidKeys() !== null;
    }

    public function publicKey(): ?string
    {
        return $this->vapidKeys()['publicKey'] ?? null;
    }

    public function send(int $userId, string $title, string $body): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $subscriptions = $this->subscriptions->forUser($userId);
        if ($subscriptions === []) {
            return;
        }

        $keys = $this->vapidKeys();
        if ($keys === null) {
            return;
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => (string) ($this->settings['subject'] ?? 'mailto:admin@example.com'),
                    'publicKey' => $keys['publicKey'],
                    'privateKey' => $keys['privateKey'],
                ],
            ]);

            foreach ($subscriptions as $subscription) {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $subscription['endpoint'],
                        'publicKey' => $subscription['p256dh'],
                        'authToken' => $subscription['auth'],
                    ]),
                    (string) json_encode(['title' => $title, 'body' => $body])
                );
            }

            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    continue;
                }

                $endpoint = (string) $report->getRequest()->getUri();
                $status = $report->getResponse()?->getStatusCode();
                // 404/410: the browser threw the subscription away (uninstalled PWA, cleared site
                // data). 403: it was signed with a key this endpoint no longer accepts, i.e. our
                // keypair changed. Either way the row is dead weight -- drop it so we stop
                // retrying, and the client re-subscribes on its next visit.
                if (in_array($status, [403, 404, 410], true)) {
                    $this->subscriptions->deleteByEndpoint($endpoint);
                }
                $this->logger->warning('Web Push delivery failed', [
                    'endpoint' => $endpoint,
                    'reason' => $report->getReason(),
                ]);
            }
        } catch (Throwable $e) {
            $this->logger->error('Web Push send failed', ['exception' => $e]);
        }
    }

    /** @param array<string, mixed> $subscription The browser's PushSubscription JSON. */
    public function saveSubscription(int $userId, array $subscription): void
    {
        $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
        $keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
        $p256dh = trim((string) ($keys['p256dh'] ?? ''));
        $auth = trim((string) ($keys['auth'] ?? ''));

        if ($endpoint === '' || $p256dh === '' || $auth === '' || strlen($endpoint) > 2000) {
            throw new ValidationException('Invalid push subscription.');
        }

        $this->subscriptions->save($userId, $endpoint, $p256dh, $auth);
    }

    public function removeSubscription(int $userId, string $endpoint): void
    {
        $this->subscriptions->delete($userId, trim($endpoint));
    }

    /** @return array{publicKey: string, privateKey: string}|null */
    private function vapidKeys(): ?array
    {
        if ($this->keysResolved) {
            return $this->keys;
        }
        $this->keysResolved = true;

        $envPublic = (string) ($this->settings['public_key'] ?? '');
        $envPrivate = (string) ($this->settings['private_key'] ?? '');
        if ($envPublic !== '' && $envPrivate !== '') {
            return $this->keys = ['publicKey' => $envPublic, 'privateKey' => $envPrivate];
        }

        $file = (string) ($this->settings['key_file'] ?? '');
        if ($file === '') {
            return $this->keys = null;
        }

        if (is_readable($file)) {
            $stored = json_decode((string) file_get_contents($file), true);
            if (is_array($stored) && !empty($stored['publicKey']) && !empty($stored['privateKey'])) {
                return $this->keys = [
                    'publicKey' => (string) $stored['publicKey'],
                    'privateKey' => (string) $stored['privateKey'],
                ];
            }
        }

        return $this->keys = $this->generateKeys($file);
    }

    /**
     * Mints a keypair and stores it. Returns null (having logged why) when the library or the
     * crypto extensions it needs are missing -- push then stays off and the rest of the app is
     * unaffected.
     *
     * @return array{publicKey: string, privateKey: string}|null
     */
    private function generateKeys(string $file): ?array
    {
        if (!class_exists(VAPID::class)) {
            $this->logger->notice('Push disabled: minishlink/web-push is not installed (run `composer install`).');

            return null;
        }

        try {
            $generated = VAPID::createVapidKeys();
        } catch (Throwable $e) {
            $this->logger->error('Could not generate VAPID keys', ['exception' => $e]);

            return null;
        }

        $keys = ['publicKey' => (string) $generated['publicKey'], 'privateKey' => (string) $generated['privateKey']];

        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            $this->logger->error('Generated VAPID keys but could not create ' . $directory . ' -- push will stay off.');

            return null;
        }
        // Written with an exclusive lock and tight permissions: the private key is what proves
        // pushes come from this installation.
        if (@file_put_contents($file, json_encode($keys, JSON_PRETTY_PRINT), LOCK_EX) === false) {
            $this->logger->error('Generated VAPID keys but could not write ' . $file . ' -- push will stay off.');

            return null;
        }
        @chmod($file, 0600);

        return $keys;
    }
}
