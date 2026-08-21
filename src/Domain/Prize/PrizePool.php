<?php

declare(strict_types=1);

namespace App\Domain\Prize;

/**
 * The prize pool seeded on first run.
 *
 * These are deliberately real-life favours rather than points or badges: the whole premise of the
 * app is that finishing your share of the housework buys you something a housemate has to
 * actually do.
 */
final class PrizePool
{
    /** @return list<string> */
    public static function defaults(): array
    {
        return [
            '1 hour of uninterrupted rest / nap time, no interruptions allowed',
            'A 20-minute massage from the runner-up',
            'Pick the movie or show for the next two movie nights',
            'Skip one chore of your choice this week',
            'Breakfast in bed, served by the runner-up',
            'Choose the restaurant or takeout for the next dinner out',
            '30 minutes of extra guilt-free screen time',
            'Someone else does your laundry this week',
            'First shower / bathroom priority for a day',
            'Winner picks the weekend activity',
            'A handwritten "why I appreciate you" note from the others',
            'Free coffee or tea, made for you for 3 days straight',
            'Skip dish duty for a week',
            'Control the music playlist for a full day',
            'A surprise treat or dessert, bought by the runner-up',
            'One "get out of a task" free pass for next week',
            'A 15-minute foot rub from the runner-up',
            'Pick where to eat out next',
            'A lazy Sunday morning: no chores, no alarms',
            'The runner-up handles your least favorite chore next week',
        ];
    }
}
