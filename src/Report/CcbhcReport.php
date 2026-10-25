<?php

/**
 * Runs the clinic-collected measures this module computes for one Measurement Year, with the code lists in
 * data/codes.json and the site's settings.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CcbhcReport\Report;

use OpenHealthcare\CcbhcReport\Domain\BusinessDays;
use OpenHealthcare\CcbhcReport\Domain\MeasureResult;
use OpenHealthcare\CcbhcReport\Domain\Records;
use OpenHealthcare\CcbhcReport\Measure\CaseLoad;
use OpenHealthcare\CcbhcReport\Measure\DepressionScreening;
use OpenHealthcare\CcbhcReport\Measure\SocialDrivers;
use OpenHealthcare\CcbhcReport\Measure\TimeToServices;
use OpenHealthcare\CcbhcReport\Repository\RecordSource;
use OpenHealthcare\CcbhcReport\Repository\Settings;

final class CcbhcReport
{
    public function __construct(private readonly Settings $settings = new Settings())
    {
    }

    /** @return array<string, mixed> data/codes.json */
    public static function codes(): array
    {
        $data = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/data/codes.json'), true, 16, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $list a list's "cpt" and "hcpcs" codes
     * @return list<string> as the visits carry them: "CPT4:99213", "HCPCS:G0438"
     */
    public static function billed(array $list): array
    {
        return [...array_map(fn(string $c): string => "CPT4:$c", (array)($list['cpt'] ?? [])), ...array_map(fn(string $c): string => "HCPCS:$c", (array)($list['hcpcs'] ?? []))];
    }

    public function businessDays(): BusinessDays
    {
        return new BusinessDays($this->settings->stateHolidays());
    }

    /** @return array{records: Records, measures: array<string, MeasureResult>, caseLoad: array<string, mixed>} */
    public function run(int $year, \DateTimeImmutable $today): array
    {
        $codes = self::codes();
        $records = (new RecordSource($this->settings, (array)$codes['cdf']['bipolar_icd10']))->forYear($year, $today);
        $hcpcs = fn(string $c): string => "HCPCS:$c";
        $reporting = ['positive_with_plan' => $hcpcs($codes['cdf']['positive_with_plan']), 'negative' => $hcpcs($codes['cdf']['negative']), 'exception' => $hcpcs($codes['cdf']['exception'])];
        $measures = [
            'I-SERV' => (new TimeToServices($this->businessDays(), $this->settings->evaluationCategories(), $this->settings->serviceCategories(),
                array_map(fn(string $c): string => "CPT4:$c", (array)$codes['iserv']['evaluation_cpt'])))->compute($records),
            'SDOH' => (new SocialDrivers(self::billed($codes['sdoh']), $hcpcs($codes['sdoh']['met'])))->compute($records),
            'CDF-AD' => (new DepressionScreening(DepressionScreening::ADULT, self::billed($codes['cdf']), $reporting))->compute($records),
            'CDF-CH' => (new DepressionScreening(DepressionScreening::CHILD, self::billed($codes['cdf']), $reporting))->compute($records),
        ];
        return ['records' => $records, 'measures' => $measures, 'caseLoad' => (new CaseLoad())->compute($records)];
    }
}
