<?php

/**
 * One measure's client list as CSV: each client or episode the measure looked at, whether it counted, its value and
 * why, for the clinic's own review. It holds identifiers, so it has the report's access and is audited.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\CcbhcReport\Report\CcbhcReport;
use OpenHealthcare\CcbhcReport\Report\CsvRow;
use OpenHealthcare\Common\OpenEmr;

// The same access as Bootstrap::ACL, written out: tools/footprint.php lists each check it can read.
if (!AclMain::aclCheckCore('patients', 'med')) {
    OpenEmr::deny('CCBHC Report: download refused', xl('CCBHC Report'), 'CCBHC Report');
}
$today = new DateTimeImmutable('today');
$year = (int)($_GET['year'] ?? 0);
$measure = (string)($_GET['measure'] ?? '');
if ($year < 2025 || $year > (int)$today->format('Y') || !in_array($measure, ['I-SERV', 'SDOH', 'CDF-AD', 'CDF-CH'], true)) {
    http_response_code(400);
    exit;
}
$result = (new CcbhcReport())->run($year, $today)['measures'][$measure];
$names = [];
$pids = array_values(array_unique(array_column($result->rows, 'pid')));
foreach (array_chunk($pids, 500) as $chunk) {
    foreach (QueryUtils::fetchRecords('SELECT `pid`, `pubpid`, `fname`, `lname` FROM `patient_data` WHERE `pid` IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk) as $p) {
        $names[(int)$p['pid']] = [(string)$p['pubpid'], trim($p['lname'] . ', ' . $p['fname'], ', ')];
    }
}
OpenEmr::audit('other', "CCBHC Report: $measure client list for $year downloaded");
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="ccbhc-' . strtolower($measure) . "-$year.csv\"");
$out = fopen('php://output', 'w');
CsvRow::write($out, ['chart_number', 'name', 'part', 'date', 'counted', 'value', 'why', 'age', 'payer', 'ethnicity', 'race']);
foreach ($result->rows as $r) {
    CsvRow::write($out, [$names[$r['pid']][0] ?? '', $names[$r['pid']][1] ?? '', $r['part'], $r['date'], $r['counted'] ? 'yes' : 'no', $r['value'], $r['why'],
        $r['strata']['age'] ?? '', $r['strata']['payer'] ?? '', $r['strata']['ethnicity'] ?? '', $r['strata']['race'] ?? '']);
}
fclose($out);
