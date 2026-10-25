<?php

/**
 * Time to Services (I-SERV), SAMHSA (Technical Specifications, February 2024, pp. 31 to 39, with the March 2025 errata):
 *   1. the average Business Days from First Contact to the Initial Evaluation, for New Clients;
 *   2. the average Business Days from First Contact to the Initial Clinical Service, for New Clients;
 *   3. the average hours from a crisis contact to a face-to-face Crisis Service, for each Crisis Episode.
 * Clients 12 and older at the end of the year; stratified by age, payer, ethnicity and race.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CcbhcReport\Measure;

use OpenHealthcare\CcbhcReport\Domain\BusinessDays;
use OpenHealthcare\CcbhcReport\Domain\Client;
use OpenHealthcare\CcbhcReport\Domain\Coverage;
use OpenHealthcare\CcbhcReport\Domain\MeasureResult;
use OpenHealthcare\CcbhcReport\Domain\Records;
use OpenHealthcare\CcbhcReport\Domain\Tally;

final class TimeToServices
{
    public const EVALUATION = 'evaluation';
    public const SERVICE = 'service';
    public const CRISIS = 'crisis';

    /** "Any who received an Initial Evaluation after the last day of the Measurement Year are treated as having been evaluated 31 days after First Contact" (p. 35; the same for submeasure 2). */
    public const AFTER_YEAR_DAYS = 31;
    /** A crisis service after the year counts as 24 hours (p. 38). */
    public const AFTER_YEAR_HOURS = 24;

    /**
     * @param list<int>    $evaluationCategories visit categories (pc_catid) the clinic marks as initial evaluations
     * @param list<int>    $serviceCategories    visit categories the clinic marks as clinical services
     * @param list<string> $evaluationCodes      billed codes that are an evaluation on their own, as "CPT4:90791"
     */
    public function __construct(
        private readonly BusinessDays $businessDays,
        private readonly array $evaluationCategories,
        private readonly array $serviceCategories,
        private readonly array $evaluationCodes,
    ) {
    }

    public function compute(Records $r): MeasureResult
    {
        $dims = [Tally::AGE => Tally::AGES, Tally::PAYER => Coverage::PAYERS, Tally::ETHNICITY => Client::ETHNICITIES, Tally::RACE => Client::RACES];
        $result = new MeasureResult('I-SERV', [
            self::EVALUATION => new Tally($dims, true),
            self::SERVICE => new Tally($dims, true),
            self::CRISIS => new Tally($dims, true),
        ]);
        $this->contacts($r, $result);
        $this->crises($r, $result);
        return $result;
    }

    private function contacts(Records $r, MeasureResult $result): void
    {
        $start = $r->start();
        // "excluding the final month of that year" (p. 35): First Contacts in December wait for next year's report.
        $lastContact = new \DateTimeImmutable(sprintf('%04d-11-30', $r->year));
        $end = $r->end();
        $requests = $r->requests;
        usort($requests, fn(array $a, array $b): int => [$a['pid'], $a['contacted']] <=> [$b['pid'], $b['contacted']]);
        $lastCounted = [];
        foreach ($requests as $req) {
            $pid = $req['pid'];
            $contact = $req['contacted'];
            // "Only one contact in a six-month period will count" (p. 33).
            if (isset($lastCounted[$pid]) && $lastCounted[$pid] > $contact->modify('-6 months')) {
                $result->row($pid, 'contact', $contact, false, '', 'another First Contact within the 6 months before');
                continue;
            }
            $lastCounted[$pid] = $contact;
            if ($contact < $start || $contact > $lastContact) {
                continue;
            }
            $visits = $r->visits[$pid] ?? [];
            $seenBefore = array_filter($visits, fn(array $v): bool => $v['date'] < $contact && $v['date'] >= $contact->modify('-6 months'));
            if ($seenBefore !== []) {
                $result->row($pid, 'contact', $contact, false, '', 'not a New Client: seen in the 6 months before');
                continue;
            }
            $age = ($r->clients[$pid] ?? null)?->ageOn($end);
            if ($age === null || $age < 12) {
                $result->row($pid, 'contact', $contact, false, '', $age === null ? 'no date of birth' : 'under 12 at the end of the year');
                continue;
            }
            $strata = [Tally::AGE => $age <= 17 ? '12_17' : '18_plus'] + $r->strata($pid);
            $evaluation = null;
            foreach ($visits as $v) {
                if ($v['date'] >= $contact && (in_array($v['category'], $this->evaluationCategories, true) || array_intersect($v['codes'], $this->evaluationCodes) !== [])) {
                    $evaluation = $v['date'];
                    break;
                }
            }
            $this->observe($result, self::EVALUATION, $pid, $strata, $contact, $evaluation, $end, 'no Initial Evaluation yet', 'never_evaluated');
            $service = null;
            $from = $evaluation !== null && $evaluation > $contact ? $evaluation : $contact;
            foreach ($visits as $v) {
                if ($v['date'] >= $from && in_array($v['category'], $this->serviceCategories, true)) {
                    $service = $v['date'];
                    break;
                }
            }
            $this->observe($result, self::SERVICE, $pid, $strata, $contact, $service, $end, 'no Initial Clinical Service yet', 'never_served');
        }
    }

    /** @param array<string, string> $strata */
    private function observe(MeasureResult $result, string $part, int $pid, array $strata, \DateTimeImmutable $contact, ?\DateTimeImmutable $done, \DateTimeImmutable $end, string $none, string $noteKey): void
    {
        if ($done === null) {
            // "Exclude ... all eligible New Clients who never received an Initial Evaluation. Indicate in Additional Notes ... the number so excluded."
            $result->note($noteKey);
            $result->row($pid, $part, $contact, false, '', $none);
            return;
        }
        $days = $done > $end ? self::AFTER_YEAR_DAYS : $this->businessDays->between($contact, $done);
        $result->parts[$part]->add($strata, $days);
        $result->row($pid, $part, $contact, true, (string)$days, $done > $end ? 'after the year: counted as 31' : 'business days to ' . $done->format('Y-m-d'), $strata);
    }

    private function crises(Records $r, MeasureResult $result): void
    {
        $from = $r->start()->modify('-1 day');   // the 24 hours before the year
        $until = $r->end();                      // the year less its last 24 hours: contacts before Dec 31 00:00
        $yearEnd = $r->end()->modify('+1 day');
        $episodes = array_values(array_filter($r->crises, fn(array $c): bool => !$c['hotline']));
        usort($episodes, fn(array $a, array $b): int => [$a['pid'], $a['contacted']] <=> [$b['pid'], $b['contacted']]);
        $last = [];
        foreach ($episodes as $c) {
            $pid = $c['pid'];
            // A Crisis Episode begins with a contact "for the first time in a 24-hour period" (p. 33).
            $previous = $last[$pid] ?? null;
            $last[$pid] = $c['contacted'];
            if ($previous !== null && $c['contacted']->getTimestamp() - $previous->getTimestamp() < 86400) {
                $result->row($pid, self::CRISIS, $c['contacted'], false, '', 'within 24 hours of the episode before');
                continue;
            }
            if ($c['contacted'] < $from || $c['contacted'] >= $until) {
                continue;
            }
            $age = ($r->clients[$pid] ?? null)?->ageOn($r->end());
            if ($age === null || $age < 12) {
                $result->row($pid, self::CRISIS, $c['contacted'], false, '', $age === null ? 'no date of birth' : 'under 12 at the end of the year');
                continue;
            }
            if ($c['served'] === null) {
                $result->note('crisis_never_served');
                $result->row($pid, self::CRISIS, $c['contacted'], false, '', 'no face-to-face crisis service');
                continue;
            }
            $hours = $c['served'] >= $yearEnd ? self::AFTER_YEAR_HOURS : max(0, $c['served']->getTimestamp() - $c['contacted']->getTimestamp()) / 3600;
            $strata = [Tally::AGE => $age <= 17 ? '12_17' : '18_plus'] + $r->strata($pid);
            $result->parts[self::CRISIS]->add($strata, round($hours, 2));
            $result->row($pid, self::CRISIS, $c['contacted'], true, (string)round($hours, 2), 'hours to the face-to-face crisis service', $strata);
        }
    }
}
