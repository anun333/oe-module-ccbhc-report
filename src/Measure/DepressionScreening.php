<?php

/**
 * Screening for Depression and Follow-Up Plan, CMS: CDF-AD (18 and older) and CDF-CH (12 to 17), as the March 2025
 * errata's Appendices A and B give them. A client with a qualifying visit in the year is in the denominator; they are in
 * the numerator when the most recent standardized screen on the visit day or in the 14 days before it was negative, or
 * positive with a follow-up plan documented on the visit day. Bipolar disorder before the visit excludes; a documented
 * reason for not screening (G8433) is an exception, applied only when the numerator isn't met. The depression
 * exclusion is gone in the current Core Set (errata, p. 3). Stratified by payer, ethnicity and race; no age split.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CcbhcReport\Measure;

use OpenHealthcare\CcbhcReport\Domain\Client;
use OpenHealthcare\CcbhcReport\Domain\Coverage;
use OpenHealthcare\CcbhcReport\Domain\MeasureResult;
use OpenHealthcare\CcbhcReport\Domain\Records;
use OpenHealthcare\CcbhcReport\Domain\Tally;

final class DepressionScreening
{
    public const ADULT = 'CDF-AD';
    public const CHILD = 'CDF-CH';

    /**
     * @param string       $measure         ADULT or CHILD
     * @param list<string> $qualifyingCodes Table CDF-A's codes, as "CPT4:99213", "HCPCS:G0438"
     * @param array{positive_with_plan: string, negative: string, exception: string} $reporting the HCPCS reporting codes, as "HCPCS:G8431"
     */
    public function __construct(private readonly string $measure, private readonly array $qualifyingCodes, private readonly array $reporting)
    {
    }

    public function compute(Records $r): MeasureResult
    {
        $result = new MeasureResult($this->measure, ['screened' => new Tally([Tally::PAYER => Coverage::PAYERS, Tally::ETHNICITY => Client::ETHNICITIES, Tally::RACE => Client::RACES])]);
        foreach (array_keys($r->visits) as $pid) {
            $client = $r->clients[$pid] ?? null;
            if ($client === null) {
                continue;
            }
            $qualifying = array_values(array_filter($r->visitsInYear($pid), fn(array $v): bool => array_intersect($v['codes'], $this->qualifyingCodes) !== []
                && $this->inBand($client->ageOn($v['date']))));
            if ($qualifying === []) {
                continue;
            }
            // "never had a diagnosis of bipolar disorder prior to the qualifying encounter used to evaluate the numerator"
            $eligible = array_values(array_filter($qualifying, fn(array $v): bool => array_filter($r->bipolar[$pid] ?? [], fn(\DateTimeImmutable $d): bool => $d <= $v['date']) === []));
            if ($eligible === []) {
                $result->note('excluded_bipolar');
                $result->row($pid, 'screened', $qualifying[0]['date'], false, '', 'excluded: bipolar disorder before the visit');
                continue;
            }
            [$met, $why, $on] = $this->met($r, $pid, $eligible);
            if (!$met && array_filter($eligible, fn(array $v): bool => in_array($this->reporting['exception'], $v['codes'], true)) !== []) {
                $result->note('exception');
                $result->row($pid, 'screened', $eligible[0]['date'], false, '', 'exception: a documented reason for not screening (G8433)');
                continue;
            }
            $result->parts['screened']->add($r->strata($pid), $met ? 1 : 0);
            $result->row($pid, 'screened', $on ?? $eligible[0]['date'], $met, $met ? '1' : '0', $why, $r->strata($pid));
        }
        return $result;
    }

    private function inBand(?int $age): bool
    {
        return $age !== null && ($this->measure === self::ADULT ? $age >= 18 : $age >= 12 && $age <= 17);
    }

    /**
     * Whether any eligible visit meets the numerator, why, and on which visit.
     *
     * @param list<array{date: \DateTimeImmutable, category: int, codes: list<string>}> $visits
     * @return array{0: bool, 1: string, 2: ?\DateTimeImmutable}
     */
    private function met(Records $r, int $pid, array $visits): array
    {
        $why = 'no standardized depression screen on a qualifying visit or in the 14 days before';
        foreach ($visits as $v) {
            $day = $v['date'];
            if (in_array($this->reporting['negative'], $v['codes'], true)) {
                return [true, 'negative screen reported (G8510)', $day];
            }
            if (in_array($this->reporting['positive_with_plan'], $v['codes'], true)) {
                return [true, 'positive screen with a follow-up plan reported (G8431)', $day];
            }
            // "The measure assesses the most recent depression screening completed either during the qualifying
            // encounter or within the 14 calendar days prior to that encounter."
            $latest = null;
            foreach ($r->screens[$pid] ?? [] as $s) {
                if ($s['date'] <= $day && $s['date'] >= $day->modify('-14 days') && ($latest === null || $s['date'] >= $latest['date'])) {
                    $latest = $s;
                }
            }
            if ($latest === null) {
                continue;
            }
            if (!$latest['positive']) {
                return [true, 'negative ' . $latest['tool'] . ' on ' . $latest['date']->format('Y-m-d'), $day];
            }
            $followed = array_filter($r->followUps[$pid] ?? [], fn(\DateTimeImmutable $d): bool => $d->format('Y-m-d') === $day->format('Y-m-d')) !== [];
            if ($followed) {
                return [true, 'positive ' . $latest['tool'] . ', with a follow-up the day of the visit', $day];
            }
            $why = 'positive ' . $latest['tool'] . ' on ' . $latest['date']->format('Y-m-d') . ', no follow-up recorded the day of the visit';
        }
        return [false, $why, null];
    }
}
