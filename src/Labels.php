<?php

/**
 * The words on the page: measures, sub-measures and strata as SAMHSA's data reporting template names them, so a row
 * here is the row to copy into the workbook; and the notes. No apostrophes or quote marks (OpenEMR's xl() shows one as
 * a backtick).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CcbhcReport;

use OpenHealthcare\CcbhcReport\Domain\Tally;

final class Labels
{
    public static function measure(string $id): string
    {
        return match ($id) {
            'I-SERV' => xl('Time to Services (I-SERV)'),
            'SDOH' => xl('Screening for Social Drivers of Health (SDOH)'),
            'CDF-AD' => xl('Screening for Depression and Follow-Up Plan: Age 18 and Older (CDF-AD)'),
            'CDF-CH' => xl('Screening for Depression and Follow-Up Plan: Ages 12 to 17 (CDF-CH)'),
            'DEP-REM-6' => xl('Depression Remission at Six Months (DEP-REM-6)'),
            'ASC' => xl('Preventive Care and Screening: Unhealthy Alcohol Use: Screening and Brief Counseling (ASC)'),
            default => $id,
        };
    }

    public static function part(string $measure, string $part): string
    {
        return match ("$measure/$part") {
            'I-SERV/evaluation' => xl('Submeasure 1: Average Time to Initial Evaluation (business days)'),
            'I-SERV/service' => xl('Submeasure 2: Average Time to Initial Clinical Services (business days)'),
            'I-SERV/crisis' => xl('Submeasure 3: Average Time to Crisis Services (hours)'),
            default => self::measure($measure),
        };
    }

    public static function dimension(string $dim): string
    {
        return match ($dim) {
            Tally::AGE => xl('Stratification by Age'),
            Tally::PAYER => xl('Stratification by Payer'),
            Tally::ETHNICITY => xl('Stratification by Ethnicity (Hispanic or Latino)'),
            Tally::RACE => xl('Stratification by Race'),
            default => $dim,
        };
    }

    public static function stratum(string $dim, string $s): string
    {
        return match ("$dim/$s") {
            'age/12_17' => xl('Age 12-17 years'),
            'age/18_plus' => xl('Age 18 years and older'),
            'payer/medicaid' => xl('Medicaid'),
            'payer/non_medicaid' => xl('Non-Medicaid (including dually eligible for Medicare and Medicaid)'),
            'ethnicity/not_hispanic' => xl('Not Hispanic or Latino'),
            'ethnicity/hispanic' => xl('Hispanic or Latino'),
            'ethnicity/unknown', 'race/unknown' => xl('Unknown'),
            'race/white' => xl('White or Caucasian'),
            'race/black' => xl('Black or African American'),
            'race/aian' => xl('American Indian or Alaska Native'),
            'race/asian' => xl('Asian'),
            'race/nhpi' => xl('Native Hawaiian or Other Pacific Islander'),
            'race/more_than_one' => xl('More than one race'),
            default => $s,
        };
    }

    public static function note(string $key): string
    {
        return match ($key) {
            'never_evaluated' => xl('New Clients excluded because they never received an Initial Evaluation (report in Additional Notes)'),
            'never_served' => xl('New Clients excluded because they never received an Initial Clinical Service (report in Additional Notes)'),
            'crisis_never_served' => xl('Crisis Episodes excluded because no face-to-face Crisis Service was given'),
            'adults_no_qualifying_code' => xl('Adults seen this year with no visit billed under a code the measure lists (not in the denominator: check the billing)'),
            'excluded_bipolar' => xl('Clients excluded for bipolar disorder before the visit'),
            'exception' => xl('Clients removed as a denominator exception (G8433: a documented reason for not screening)'),
            default => $key,
        };
    }

    /** The case load table's rows, as the template names them. */
    public static function caseLoadRow(string $section, string $key): string
    {
        return match ("$section/$key") {
            'age/0_11' => xl('0-11 years'),
            'age/12_17' => xl('12-17 years'),
            'age/18_64' => xl('18-64 years'),
            'age/65_plus' => xl('65+ years'),
            'sex/male' => xl('Male'),
            'sex/female' => xl('Female'),
            'sex/other' => xl('Other'),
            'sex/dont_know', 'veteran/unknown' => xl('Do not know'),
            'sex/prefer_not' => xl('Prefer not to state'),
            'insurance/medicaid' => xl('Medicaid (not Dually-Eligible)'),
            'insurance/chip' => xl('CHIP'),
            'insurance/medicare' => xl('Medicare (not Dually-Eligible)'),
            'insurance/dual' => xl('Medicare and Medicaid Dually-Eligible'),
            'insurance/military' => xl('VHA/TRICARE'),
            'insurance/commercial' => xl('Commercially insured'),
            'insurance/uninsured' => xl('Uninsured'),
            'insurance/other' => xl('Other'),
            'veteran/active' => xl('Active Duty Military'),
            'veteran/veteran' => xl('Prior Military Service/Veteran'),
            'veteran/neither' => xl('Neither'),
            'age/unknown' => xl('Unknown age (no date of birth)'),
            default => $section === 'ethnicity' ? self::stratum('ethnicity', $key) : ($section === 'race' ? self::stratum('race', $key) : $key),
        };
    }
}
