<?php

/**
 * A computed measure: its sub-measures' tallies, the counts its notes need (those excluded, those with a denominator
 * exception), and one row per client or episode for the clinic's own review.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CcbhcReport\Domain;

final class MeasureResult
{
    /** @var array<string, int> note key => count */
    public array $notes = [];

    /** @var list<array{pid: int, part: string, date: string, counted: bool, value: string, why: string, strata: array<string, string>}> */
    public array $rows = [];

    /**
     * @param string               $id      I-SERV, SDOH, CDF-AD, CDF-CH, CASELOAD
     * @param array<string, Tally> $parts   sub-measure key => its tally
     */
    public function __construct(public readonly string $id, public readonly array $parts)
    {
    }

    public function note(string $key, int $add = 1): void
    {
        $this->notes[$key] = ($this->notes[$key] ?? 0) + $add;
    }

    /** @param array<string, string> $strata the strata it was counted in, when it was in the denominator */
    public function row(int $pid, string $part, ?\DateTimeImmutable $date, bool $counted, string $value, string $why, array $strata = []): void
    {
        $this->rows[] = ['pid' => $pid, 'part' => $part, 'date' => $date?->format('Y-m-d') ?? '', 'counted' => $counted, 'value' => $value, 'why' => $why, 'strata' => $strata];
    }
}
