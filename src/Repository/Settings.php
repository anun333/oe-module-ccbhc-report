<?php

/**
 * The site's settings for the CCBHC measures: what OpenEMR's record can't say on its own.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CcbhcReport\Repository;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\CcbhcReport\Domain\Coverage;

final class Settings
{
    public const EVALUATION_CATEGORIES = 'evaluation_categories';
    public const SERVICE_CATEGORIES = 'service_categories';
    public const STATE_HOLIDAYS = 'state_holidays';
    public const INSURANCE_KINDS = 'insurance_kinds';

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    /** @return list<int> visit categories (pc_catid) that are an Initial Evaluation */
    public function evaluationCategories(): array
    {
        return self::ints($this->all()[self::EVALUATION_CATEGORIES] ?? []);
    }

    /** @return list<int> visit categories that are an Initial Clinical Service */
    public function serviceCategories(): array
    {
        return self::ints($this->all()[self::SERVICE_CATEGORIES] ?? []);
    }

    /** @return list<string> Y-m-d */
    public function stateHolidays(): array
    {
        return array_values(array_filter((array)($this->all()[self::STATE_HOLIDAYS] ?? []), fn(mixed $d): bool => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1));
    }

    /** @return array<int, string> insurance_companies.id => Coverage::COMPANY_KINDS, where the clinic chose */
    public function insuranceKinds(): array
    {
        $out = [];
        foreach ((array)($this->all()[self::INSURANCE_KINDS] ?? []) as $id => $kind) {
            if (is_string($kind) && in_array($kind, Coverage::COMPANY_KINDS, true)) {
                $out[(int)$id] = $kind;
            }
        }
        return $out;
    }

    public function save(string $name, mixed $value, int $userId): void
    {
        QueryUtils::sqlStatementThrowException(
            'REPLACE INTO `ccbhc_report_setting` (`name`, `value`, `updated_by`) VALUES (?, ?, ?)',
            [$name, json_encode($value), $userId]
        );
        $this->values = null;
    }

    /** @return array<string, mixed> */
    private function all(): array
    {
        if ($this->values === null) {
            $this->values = [];
            foreach (QueryUtils::fetchRecords('SELECT `name`, `value` FROM `ccbhc_report_setting`') as $row) {
                $this->values[(string)$row['name']] = json_decode((string)$row['value'], true);
            }
        }
        return $this->values;
    }

    /** @return list<int> */
    private static function ints(mixed $values): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array)$values), fn(int $v): bool => $v > 0)));
    }
}
