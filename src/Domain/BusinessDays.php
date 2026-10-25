<?php

/**
 * Business days as I-SERV counts them: "Monday through Friday, excluding state and federal holidays (regardless of days
 * of operation)" (Technical Specifications, February 2024, p. 32). Federal holidays are worked out with their observed
 * days (5 U.S.C. 6103: one on a Saturday is kept the Friday before, one on a Sunday the Monday after); the clinic lists
 * its state's holidays in the settings.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CcbhcReport\Domain;

final class BusinessDays
{
    /** @var array<string, true> Y-m-d => true */
    private array $holidays = [];

    /** @param list<string> $stateHolidays Y-m-d dates the clinic's state keeps */
    public function __construct(array $stateHolidays = [])
    {
        foreach ($stateHolidays as $d) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1) {
                $this->holidays[$d] = true;
            }
        }
    }

    /**
     * The business days after $from, up to and including $to: 0 when they're the same day, 1 for the next business day.
     * Negative never: a $to before $from gives 0.
     */
    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $day = $from->setTime(0, 0)->modify('+1 day');
        $end = $to->setTime(0, 0);
        $count = 0;
        while ($day <= $end) {
            if ($this->isBusinessDay($day)) {
                $count++;
            }
            $day = $day->modify('+1 day');
        }
        return $count;
    }

    public function isBusinessDay(\DateTimeImmutable $day): bool
    {
        if ((int)$day->format('N') >= 6) {
            return false;
        }
        $ymd = $day->format('Y-m-d');
        return !isset($this->holidays[$ymd]) && !in_array($ymd, self::federal((int)$day->format('Y')), true)
            && !in_array($ymd, self::federal((int)$day->format('Y') + 1), true);
    }

    /**
     * The federal holidays of a year as they are observed, in date order. New Year's Day on a Saturday is observed the
     * Friday before, in the year before; that is why isBusinessDay also asks the next year's list.
     *
     * @return list<string> Y-m-d
     */
    public static function federal(int $year): array
    {
        static $cache = [];
        if (isset($cache[$year])) {
            return $cache[$year];
        }
        $fixed = fn(int $m, int $d): \DateTimeImmutable => self::observed(new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $m, $d)));
        $nth = fn(string $which, string $weekday, string $month): \DateTimeImmutable => new \DateTimeImmutable("$which $weekday of $month $year");
        $days = [
            $fixed(1, 1),
            $nth('third', 'monday', 'january'),   // Birthday of Martin Luther King, Jr.
            $nth('third', 'monday', 'february'),  // Washington's Birthday
            $nth('last', 'monday', 'may'),        // Memorial Day
            $fixed(6, 19),                        // Juneteenth National Independence Day
            $fixed(7, 4),
            $nth('first', 'monday', 'september'), // Labor Day
            $nth('second', 'monday', 'october'),  // Columbus Day
            $fixed(11, 11),                       // Veterans Day
            $nth('fourth', 'thursday', 'november'),
            $fixed(12, 25),
        ];
        $list = array_map(fn(\DateTimeImmutable $d): string => $d->format('Y-m-d'), $days);
        sort($list);
        return $cache[$year] = $list;
    }

    private static function observed(\DateTimeImmutable $d): \DateTimeImmutable
    {
        return match ((int)$d->format('N')) {
            6 => $d->modify('-1 day'),
            7 => $d->modify('+1 day'),
            default => $d,
        };
    }
}
