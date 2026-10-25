<?php

/**
 * One measure's (or sub-measure's) numerator and denominator by each stratification, as the reporting template lays
 * them out: each stratification its own table with its own total, not crossed with the others (manual p. 18).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CcbhcReport\Domain;

final class Tally
{
    public const AGE = 'age';
    public const PAYER = 'payer';
    public const ETHNICITY = 'ethnicity';
    public const RACE = 'race';
    public const AGES = ['12_17', '18_plus'];

    /** @var array<string, array<string, array{0: float, 1: int}>> dimension => stratum => [numerator, denominator] */
    private array $cells = [];

    /**
     * @param array<string, list<string>> $dimensions dimension => its strata, in the template's order
     * @param bool                        $average    the numerator is a sum averaged over the denominator (I-SERV), else a count and a rate
     */
    public function __construct(public readonly array $dimensions, public readonly bool $average = false)
    {
        foreach ($dimensions as $dim => $strata) {
            foreach ($strata as $s) {
                $this->cells[$dim][$s] = [0.0, 0];
            }
        }
    }

    /**
     * One member of the denominator, in its stratum of each dimension, adding $value to the numerator.
     *
     * @param array<string, string> $strata dimension => stratum
     */
    public function add(array $strata, float $value): void
    {
        foreach ($this->dimensions as $dim => $known) {
            $s = $strata[$dim] ?? '';
            if (!in_array($s, $known, true)) {
                throw new \InvalidArgumentException("No stratum \"$s\" in $dim");
            }
            $this->cells[$dim][$s][0] += $value;
            $this->cells[$dim][$s][1]++;
        }
    }

    /** @return array<string, array{0: float, 1: int}> the dimension's strata */
    public function rows(string $dimension): array
    {
        return $this->cells[$dimension] ?? [];
    }

    /** @return array{0: float, 1: int} the dimension's total, the same in every dimension */
    public function total(): array
    {
        $first = array_key_first($this->cells);
        $num = 0.0;
        $den = 0;
        foreach ($first === null ? [] : $this->cells[$first] as [$n, $d]) {
            $num += $n;
            $den += $d;
        }
        return [$num, $den];
    }

    /** The rate (a percentage) or average to one decimal place, as the template rounds it; null with an empty denominator. */
    public static function result(float $numerator, int $denominator, bool $average): ?float
    {
        if ($denominator === 0) {
            return null;
        }
        return round($average ? $numerator / $denominator : 100 * $numerator / $denominator, 1);
    }
}
