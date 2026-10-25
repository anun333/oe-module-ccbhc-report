<?php

/**
 * Screening for Social Drivers of Health (SDOH), CMS, in the public domain (Technical Specifications, February 2024,
 * pp. 61 to 64): clients 18 and older with a qualifying visit in the year, and of them those "screened for food
 * insecurity, housing instability, transportation needs, utility difficulties, and interpersonal safety" in the year:
 * all five. Stratified by payer, ethnicity and race.
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

final class SocialDrivers
{
    /**
     * @param list<string> $qualifyingCodes the denominator's visit codes, as "CPT4:99213", "HCPCS:G0438"
     * @param string       $metCode         the reporting code for "performance met", as "HCPCS:M1207"
     */
    public function __construct(private readonly array $qualifyingCodes, private readonly string $metCode)
    {
    }

    public function compute(Records $r): MeasureResult
    {
        $result = new MeasureResult('SDOH', ['screened' => new Tally([Tally::PAYER => Coverage::PAYERS, Tally::ETHNICITY => Client::ETHNICITIES, Tally::RACE => Client::RACES])]);
        $start = $r->start();
        $end = $r->end();
        foreach (array_keys($r->visits) as $pid) {
            $client = $r->clients[$pid] ?? null;
            $visits = $r->visitsInYear($pid);
            if ($visits === [] || $client === null) {
                continue;
            }
            $qualifying = array_values(array_filter($visits, fn(array $v): bool => array_intersect($v['codes'], $this->qualifyingCodes) !== []
                && ($client->ageOn($v['date']) ?? 0) >= 18));
            if ($qualifying === []) {
                if (($client->ageOn($end) ?? 0) >= 18) {
                    $result->note('adults_no_qualifying_code');
                }
                continue;
            }
            $screened = array_filter($r->sdohScreens[$pid] ?? [], fn(\DateTimeImmutable $d): bool => $d >= $start && $d <= $end) !== []
                || array_filter($visits, fn(array $v): bool => in_array($this->metCode, $v['codes'], true)) !== [];
            $result->parts['screened']->add($r->strata($pid), $screened ? 1 : 0);
            $result->row($pid, 'screened', $qualifying[0]['date'], $screened, $screened ? '1' : '0',
                $screened ? 'screened for all five domains in the year' : 'no screening of all five domains in the year', $r->strata($pid));
        }
        return $result;
    }
}
