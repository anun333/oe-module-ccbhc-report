<?php

/**
 * A client's coverage on a day, in the template's insurance rows, and the payer stratum the measures use: Medicaid,
 * which "includes Title 19 CHIP" and leaves out those dually eligible for Medicare and Medicaid, or Non-Medicaid,
 * which takes dual eligibles, Medicare, Title 21 CHIP, other payers and the uninsured (manual, p. 18).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CcbhcReport\Domain;

final class Coverage
{
    // What one insurance company counts as.
    public const MEDICAID = 'medicaid';
    public const CHIP_19 = 'chip_19';
    public const CHIP_21 = 'chip_21';
    public const MEDICARE = 'medicare';
    public const MILITARY = 'military';
    public const COMMERCIAL = 'commercial';
    public const OTHER = 'other';
    public const COMPANY_KINDS = [self::MEDICAID, self::CHIP_19, self::CHIP_21, self::MEDICARE, self::MILITARY, self::COMMERCIAL, self::OTHER];

    // The template's insurance rows (Case Load Characteristics).
    public const ROW_MEDICAID = 'medicaid';
    public const ROW_CHIP = 'chip';
    public const ROW_MEDICARE = 'medicare';
    public const ROW_DUAL = 'dual';
    public const ROW_MILITARY = 'military';
    public const ROW_COMMERCIAL = 'commercial';
    public const ROW_UNINSURED = 'uninsured';
    public const ROW_OTHER = 'other';
    public const ROWS = [self::ROW_MEDICAID, self::ROW_CHIP, self::ROW_MEDICARE, self::ROW_DUAL, self::ROW_MILITARY, self::ROW_COMMERCIAL, self::ROW_UNINSURED, self::ROW_OTHER];

    // The payer strata.
    public const PAYER_MEDICAID = 'medicaid';
    public const PAYER_OTHER = 'non_medicaid';
    public const PAYERS = [self::PAYER_MEDICAID, self::PAYER_OTHER];

    /** OpenEMR's claim types (insurance_type_codes.id) that decide a company on their own; others need the clinic's choice. */
    private const CLAIM_TYPES = [2 => self::MEDICARE, 3 => self::MEDICAID, 4 => self::MILITARY, 5 => self::MILITARY, 6 => self::COMMERCIAL,
        11 => self::COMMERCIAL, 12 => self::COMMERCIAL, 13 => self::COMMERCIAL, 14 => self::COMMERCIAL, 15 => self::MEDICARE,
        17 => self::COMMERCIAL, 19 => self::COMMERCIAL];

    /** What a company counts as: the clinic's choice in the settings, else its payment typology's first digit, else its claim type. */
    public static function companyKind(?string $clinicChoice, ?string $typology, ?int $claimType): string
    {
        if ($clinicChoice !== null && in_array($clinicChoice, self::COMPANY_KINDS, true)) {
            return $clinicChoice;
        }
        $t = trim((string)$typology);
        if ($t !== '' && ctype_digit($t)) {
            $kind = match ($t[0]) {
                '1' => self::MEDICARE,
                '2' => self::MEDICAID,
                '5', '6' => self::COMMERCIAL,
                default => null,
            };
            if (str_starts_with($t, '31') || str_starts_with($t, '32')) {
                $kind = self::MILITARY; // 31 Department of Defense (TRICARE), 32 Department of Veterans Affairs
            }
            if ($kind !== null) {
                return $kind;
            }
        }
        return self::CLAIM_TYPES[$claimType ?? 0] ?? self::OTHER;
    }

    /**
     * The template's insurance row for the kinds of the policies a client held that day (none: uninsured).
     *
     * @param list<string> $kinds
     */
    public static function row(array $kinds): string
    {
        $has = array_flip($kinds);
        $medicaid = isset($has[self::MEDICAID]) || isset($has[self::CHIP_19]);
        return match (true) {
            $medicaid && isset($has[self::MEDICARE]) => self::ROW_DUAL,
            isset($has[self::MEDICAID]) => self::ROW_MEDICAID,
            isset($has[self::CHIP_19]) || isset($has[self::CHIP_21]) => self::ROW_CHIP,
            isset($has[self::MEDICARE]) => self::ROW_MEDICARE,
            isset($has[self::MILITARY]) => self::ROW_MILITARY,
            isset($has[self::COMMERCIAL]) => self::ROW_COMMERCIAL,
            $kinds === [] => self::ROW_UNINSURED,
            default => self::ROW_OTHER,
        };
    }

    /** @param list<string> $kinds */
    public static function payer(array $kinds): string
    {
        $has = array_flip($kinds);
        $medicaid = isset($has[self::MEDICAID]) || isset($has[self::CHIP_19]);
        return $medicaid && !isset($has[self::MEDICARE]) ? self::PAYER_MEDICAID : self::PAYER_OTHER;
    }
}
