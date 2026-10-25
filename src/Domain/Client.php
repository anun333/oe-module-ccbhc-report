<?php

/**
 * A client as the measures stratify them: date of birth, sex, ethnicity and race in the reporting template's groups,
 * and veteran status where the record has it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CcbhcReport\Domain;

final readonly class Client
{
    public const NOT_HISPANIC = 'not_hispanic';
    public const HISPANIC = 'hispanic';
    public const UNKNOWN = 'unknown';
    public const ETHNICITIES = [self::NOT_HISPANIC, self::HISPANIC, self::UNKNOWN];

    public const WHITE = 'white';
    public const BLACK = 'black';
    public const AIAN = 'aian';
    public const ASIAN = 'asian';
    public const NHPI = 'nhpi';
    public const MORE_THAN_ONE = 'more_than_one';
    public const RACES = [self::WHITE, self::BLACK, self::AIAN, self::ASIAN, self::NHPI, self::MORE_THAN_ONE, self::UNKNOWN];

    /** The template's sex rows (Case Load Characteristics). */
    public const SEXES = ['male', 'female', 'other', 'dont_know', 'prefer_not'];

    /** CDC Race and Ethnicity codes by their first four digits, as the UDS module reads them (data/race-ethnicity.json there). */
    private const RACE_RANGES = [
        self::AIAN => [[1002, 2027]],
        self::ASIAN => [[2028, 2053]],
        self::BLACK => [[2054, 2075]],
        self::NHPI => [[2076, 2105], [2500, 2500]],
        self::WHITE => [[2106, 2130]],
    ];

    public function __construct(
        public int $pid,
        public ?\DateTimeImmutable $dob,
        public string $sex,
        public string $ethnicity,
        public string $race,
        public ?string $veteran = null,
    ) {
    }

    public function ageOn(\DateTimeImmutable $day): ?int
    {
        return $this->dob === null ? null : $this->dob->diff($day)->y;
    }

    /**
     * The template's race group for the CDC codes of a client's race entries: one group, more than one, or unknown.
     *
     * @param list<string> $cdcCodes such as 2106-3
     */
    public static function raceGroup(array $cdcCodes): string
    {
        $groups = [];
        foreach ($cdcCodes as $code) {
            $n = (int)substr($code, 0, 4);
            foreach (self::RACE_RANGES as $group => $ranges) {
                foreach ($ranges as [$from, $to]) {
                    if ($n >= $from && $n <= $to) {
                        $groups[$group] = true;
                    }
                }
            }
        }
        return match (count($groups)) {
            0 => self::UNKNOWN,
            1 => (string)array_key_first($groups),
            default => self::MORE_THAN_ONE,
        };
    }

    /** The template's ethnicity for a CDC ethnicity code: 2135-2 or a Hispanic origin (2136 to 2185), 2186-5, else unknown. */
    public static function ethnicityGroup(string $cdcCode): string
    {
        $n = (int)substr($cdcCode, 0, 4);
        return match (true) {
            $n === 2135 || ($n >= 2136 && $n <= 2185) => self::HISPANIC,
            $n === 2186 => self::NOT_HISPANIC,
            default => self::UNKNOWN,
        };
    }

    /** The template's sex row for OpenEMR's sex list entry. */
    public static function sexGroup(string $sex): string
    {
        return match (strtolower(trim($sex))) {
            'male', 'm' => 'male',
            'female', 'f' => 'female',
            '', 'unk', 'unknown' => 'dont_know',
            'asked_declined', 'declined', 'decline' => 'prefer_not',
            default => 'other',
        };
    }
}
