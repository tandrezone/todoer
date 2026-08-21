<?php

declare(strict_types=1);

namespace App\Domain\Period;

use App\Domain\Enum\ListType;
use DateTimeImmutable;
use DateTimeZone;

/**
 * One instance of a list: "the daily list for 2026-08-21", "the weekly list for 2026-34".
 *
 * The period key is the game's clock. It is a sortable string so "has this period elapsed?" is a
 * string comparison, and it is what scopes tasks, start/stop state, closed periods and awards:
 *   daily   -> YYYY-MM-DD
 *   weekly  -> YYYY-WW (ISO year and week)
 *   monthly -> YYYY-MM
 *
 * This object also owns the window shorthand -> datetime conversion, because that conversion only
 * makes sense against a specific period (a weekly window says "Thursday", and which Thursday is
 * decided by the week the task belongs to).
 */
final class Period
{
    public function __construct(
        public readonly ListType $listType,
        public readonly string $key
    ) {
    }

    public static function forTimestamp(ListType $listType, int $timestamp, ?DateTimeZone $timezone = null): self
    {
        $moment = (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone($timezone ?? new DateTimeZone(date_default_timezone_get()));

        return new self($listType, match ($listType) {
            ListType::Daily => $moment->format('Y-m-d'),
            // ISO year + zero-padded ISO week, so it sorts chronologically as a string.
            ListType::Weekly => $moment->format('o-W'),
            ListType::Monthly => $moment->format('Y-m'),
        });
    }

    /** Human-readable heading for the period, e.g. "Thu, 21 Aug 2026" or "Week 34 (17 Aug - 23 Aug 2026)". */
    public function label(): string
    {
        switch ($this->listType) {
            case ListType::Daily:
                $date = DateTimeImmutable::createFromFormat('Y-m-d', $this->key);

                return $date === false ? $this->key : $date->format('D, j M Y');

            case ListType::Weekly:
                [$year, $week] = array_pad(explode('-', $this->key, 2), 2, '1');
                $start = (new DateTimeImmutable())->setISODate((int) $year, (int) $week)->setTime(0, 0);
                $end = $start->modify('+6 days');

                return 'Week ' . (int) $week . ' (' . $start->format('j M') . ' - ' . $end->format('j M Y') . ')';

            case ListType::Monthly:
                $date = DateTimeImmutable::createFromFormat('Y-m-d', $this->key . '-01');

                return $date === false ? $this->key : $date->format('F Y');
        }
    }

    /**
     * Resolves the window shorthand the UI captures into a full "Y-m-d H:i:s" datetime inside
     * this period, so window_start/window_end are ordinary datetimes everywhere else (sorting,
     * deadline maths) no matter which grain they were entered in.
     *
     * $value by list type:
     *   daily   -> "HH:MM"   (from <input type="time">)
     *   weekly  -> "1".."7"  (Mon=1 .. Sun=7)
     *   monthly -> "1".."31" (day of month, clamped to that month's last day)
     *
     * The weekly and monthly shorthands carry no time of day, so a window *start* resolves to
     * 00:00 and a window *end* to 23:59 on the resolved date. An empty or unparseable value
     * means "no window", which is a legitimate state rather than an error.
     */
    public function resolveWindow(?string $value, bool $endOfDay): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        switch ($this->listType) {
            case ListType::Daily:
                if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $matches) !== 1) {
                    return null;
                }

                return $this->key . ' ' . sprintf('%02d:%02d:00', (int) $matches[1], (int) $matches[2]);

            case ListType::Weekly:
                $day = (int) $value;
                if ($day < 1 || $day > 7) {
                    return null;
                }
                [$year, $week] = array_pad(explode('-', $this->key, 2), 2, '1');

                return (new DateTimeImmutable())
                    ->setISODate((int) $year, (int) $week, $day)
                    ->setTime($endOfDay ? 23 : 0, $endOfDay ? 59 : 0)
                    ->format('Y-m-d H:i:s');

            case ListType::Monthly:
                $day = (int) $value;
                if ($day < 1 || $day > 31) {
                    return null;
                }
                $firstOfMonth = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $this->key . '-01 00:00:00');
                if ($firstOfMonth === false) {
                    return null;
                }
                $day = min($day, (int) $firstOfMonth->format('t'));

                return $firstOfMonth
                    ->setDate((int) $firstOfMonth->format('Y'), (int) $firstOfMonth->format('n'), $day)
                    ->setTime($endOfDay ? 23 : 0, $endOfDay ? 59 : 0)
                    ->format('Y-m-d H:i:s');
        }
    }
}
