<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\User\User;
use App\Exception\ValidationException;
use App\Repository\UserRepository;
use App\Session\SessionInterface;

/**
 * Sign-in, sign-out and registration.
 *
 * Two security properties worth calling out, both inherited from the original and kept
 * deliberately. The session id is rotated on every privilege change (anonymous -> authenticated),
 * so a session id an attacker handed the victim before sign-in cannot be reused afterwards. And a
 * failed sign-in says "wrong username or password" without distinguishing which, so the form is
 * not a username oracle.
 */
final class AuthService
{
    private const SESSION_KEY = 'user_id';

    public function __construct(
        private readonly UserRepository $users,
        private readonly GroupService $groups,
        private readonly SessionInterface $session,
        private readonly PasswordHasher $passwords
    ) {
    }

    public function currentUser(): ?User
    {
        $userId = $this->session->get(self::SESSION_KEY);
        if (!is_int($userId) && !(is_string($userId) && $userId !== '' && ctype_digit($userId))) {
            return null;
        }

        $user = $this->users->find((int) $userId);
        if ($user === null) {
            // The account is gone (removed underneath a live session): drop the session rather
            // than letting every later request re-look-up a user that will never exist again.
            $this->session->remove(self::SESSION_KEY);
        }

        return $user;
    }

    public function login(string $username, string $password): User
    {
        $user = $this->users->findByUsernameWithCredentials($username);
        $hash = $user?->passwordHash();

        if ($user === null || $hash === null || !$this->passwords->verify($password, $hash)) {
            throw new ValidationException('Wrong username or password.');
        }

        $this->startSessionFor($user);

        return $user;
    }

    /**
     * Creates an account. Every account lands in exactly one group:
     *   * with an invite code -> that group, as a member (this is how someone joins housemates who
     *     are already playing)
     *   * without one -> a fresh personal group with this user as its admin; they can add people
     *     later, and until they do, their tasks, leaderboard and prize list are visible to nobody
     *
     * A bad invite code is a hard failure rather than a silent fallback to a personal group: being
     * quietly dropped into your own empty group when you meant to join your household is worse than
     * being told the code was wrong.
     */
    public function register(string $username, string $password, string $inviteCode = ''): User
    {
        $username = trim($username);
        if ($username === '' || strlen($password) < User::MIN_PASSWORD_LENGTH) {
            throw new ValidationException('Pick a username and a password of at least ' . User::MIN_PASSWORD_LENGTH . ' characters.');
        }
        if (mb_strlen($username) > User::MAX_USERNAME_LENGTH) {
            throw new ValidationException('Usernames can be at most ' . User::MAX_USERNAME_LENGTH . ' characters.');
        }
        if ($this->users->usernameExists($username)) {
            throw new ValidationException('That username is already taken.');
        }

        $joinGroupId = null;
        $inviteCode = strtoupper(trim($inviteCode));
        if ($inviteCode !== '') {
            $group = $this->groups->findByInviteCode($inviteCode);
            if ($group === null) {
                throw new ValidationException('That invite code does not match any group.');
            }
            $joinGroupId = $group->id;
        }

        $userId = $this->users->create(
            $username,
            $this->passwords->hash($password),
            User::colorForIndex($this->users->count())
        );

        if ($joinGroupId !== null) {
            $this->groups->placeUserInGroup($userId, $joinGroupId);
        } else {
            $this->groups->createGroupFor($userId, $this->groups->defaultGroupName($userId, $username));
        }

        $user = $this->users->find($userId);
        if ($user === null) {
            throw new ValidationException('Could not create that account.');
        }
        $this->startSessionFor($user);

        return $user;
    }

    public function logout(): void
    {
        $this->session->destroy();
    }

    private function startSessionFor(User $user): void
    {
        $this->session->regenerateId();
        $this->session->set(self::SESSION_KEY, $user->id);
    }
}
