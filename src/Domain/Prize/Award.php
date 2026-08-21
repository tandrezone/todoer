<?php

declare(strict_types=1);

namespace App\Domain\Prize;

use App\Domain\Enum\ListType;
use App\Domain\Period\Period;

/** A won prize: who topped a group's period, what they get, and whether they've redeemed it. */
final class Award
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $username,
        public readonly string $color,
        public readonly ListType $listType,
        public readonly string $periodKey,
        public readonly int $points,
        public readonly string $prize,
        public readonly bool $claimed,
        public readonly ?string $awardedAt
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['user_id'],
            (string) $row['username'],
            (string) $row['color'],
            ListType::from((string) $row['list_type']),
            (string) $row['period_key'],
            (int) $row['points'],
            (string) $row['prize'],
            (bool) $row['claimed'],
            isset($row['awarded_at']) ? (string) $row['awarded_at'] : null
        );
    }

    /** @return array<string, mixed> */
    public function toArray(int $viewerId): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'username' => $this->username,
            'color' => $this->color,
            'list_type' => $this->listType->value,
            'period_key' => $this->periodKey,
            'period_label' => (new Period($this->listType, $this->periodKey))->label(),
            'points' => $this->points,
            'prize' => $this->prize,
            'claimed' => $this->claimed ? 1 : 0,
            'awarded_at' => $this->awardedAt,
            'is_mine' => $this->userId === $viewerId,
        ];
    }
}
