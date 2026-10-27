<?php

/**
 * Writes one row of a client list download, with the UDS module's rule (its Report/CsvRow.php).
 *
 * Text cells that a spreadsheet would read as a formula (starting with =, +, -
 * or @) are prefixed with an apostrophe, so opening the file can't run one. A
 * negative number such as -12 is a number, not a formula, and is left alone.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CcbhcReport\Report;

final class CsvRow
{
    /**
     * @param resource          $out
     * @param array<int|string> $cells
     */
    public static function write($out, array $cells): void
    {
        fputcsv($out, array_map(self::cell(...), array_values($cells)), escape: '');
    }

    public static function cell(int|string $value): int|string
    {
        if (!is_string($value) || preg_match('/^-\d+(?:\.\d+)?$/D', $value) === 1) {
            return $value;
        }
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'" . $value : $value;
    }
}
