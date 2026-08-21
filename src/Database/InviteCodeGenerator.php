<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

/**
 * A short, human-typeable invite code that isn't already in use.
 *
 * The alphabet leaves out 0/O/1/I/L because these codes get read out loud and copied off screens.
 * Randomness comes from random_int(), which is cryptographically secure -- an invite code is a
 * bearer token for everything a group can see.
 */
final class InviteCodeGenerator
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const LENGTH = 8;

    public static function generate(PDO $pdo): string
    {
        $check = $pdo->prepare('SELECT 1 FROM groups WHERE invite_code = ?');

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $code = '';
            for ($i = 0; $i < self::LENGTH; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $check->execute([$code]);
            if ($check->fetchColumn() === false) {
                return $code;
            }
        }

        throw new RuntimeException('Could not generate a unique invite code.');
    }
}
