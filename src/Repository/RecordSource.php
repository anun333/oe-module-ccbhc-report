<?php

/**
 * Reads one Measurement Year's records from OpenEMR and the module's logs into Records: visits with their billed codes,
 * clients, coverage on each client's first visit of the year, requests for services, crisis episodes, depression
 * screens, same-day follow-ups, bipolar diagnoses and social needs screenings. Tables an older OpenEMR lacks (the SDOH
 * assessment before 7.0.4, the PHQ-9 form where it was never registered) are read only where they exist.
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
use OpenHealthcare\CcbhcReport\Domain\Client;
use OpenHealthcare\CcbhcReport\Domain\Coverage;
use OpenHealthcare\CcbhcReport\Domain\Records;
use OpenHealthcare\Common\QuestionnaireStatus;

final class RecordSource
{
    private const CHUNK = 500;
    /** PHQ-9 and PHQ-2 total scores, and the cut-off at which each is positive (Kroenke 2001, 2003). */
    private const TOOLS = ['44261-6' => ['PHQ-9', 10], '55758-7' => ['PHQ-2', 3]];
    private const PHQ9_COLUMNS = ['interest_score', 'hopeless_score', 'sleep_score', 'fatigue_score', 'appetite_score', 'failure_score', 'focus_score', 'psychomotor_score', 'suicide_score'];
    private const SDOH_DOMAINS = ['food_insecurity', 'housing_instability', 'transportation_insecurity', 'utilities_insecurity', 'interpersonal_safety'];
    private const VETERAN = ['veteran' => 'veteran', 'active_duty' => 'active', 'not_veteran' => 'neither'];

    /** @param list<string> $bipolarCodes ICD-10-CM codes, with or without the dot */
    public function __construct(private readonly Settings $settings, private readonly array $bipolarCodes)
    {
    }

    public function forYear(int $year, \DateTimeImmutable $today): Records
    {
        $start = new \DateTimeImmutable(sprintf('%04d-01-01', $year));
        $end = new \DateTimeImmutable(sprintf('%04d-12-31', $year));
        // Visits from six months before the year (the New Client look-back) to the end of the next year (services after it).
        $visits = $this->visits($start->modify('-6 months'), $end->modify('+1 year'));
        $requests = array_map(fn(array $r): array => ['id' => (int)$r['id'], 'pid' => (int)$r['pid'], 'contacted' => new \DateTimeImmutable((string)$r['contacted_on'])],
            QueryUtils::fetchRecords('SELECT `id`, `pid`, `contacted_on` FROM `ccbhc_request` WHERE `contacted_on` BETWEEN ? AND ?',
                [$start->modify('-6 months')->format('Y-m-d'), $end->format('Y-m-d')]));
        $crises = array_map(fn(array $c): array => ['id' => (int)$c['id'], 'pid' => (int)$c['pid'], 'contacted' => new \DateTimeImmutable((string)$c['contacted_at']),
            'served' => $c['served_at'] === null ? null : new \DateTimeImmutable((string)$c['served_at']), 'hotline' => (int)$c['hotline_only'] === 1],
            QueryUtils::fetchRecords('SELECT `id`, `pid`, `contacted_at`, `served_at`, `hotline_only` FROM `ccbhc_crisis` WHERE `contacted_at` >= ? AND `contacted_at` < ?',
                [$start->modify('-2 days')->format('Y-m-d 00:00:00'), $end->modify('+1 day')->format('Y-m-d 00:00:00')]));
        $pids = array_values(array_unique(array_merge(array_keys($visits), array_column($requests, 'pid'), array_column($crises, 'pid'))));
        $clients = $this->clients($pids);
        return new Records(
            $year,
            $clients,
            $visits,
            $this->coverage($visits, $requests, $start, $end),
            $requests,
            $crises,
            $this->screens($pids, $start->modify('-14 days'), $end),
            $this->followUps($pids, $start, $end),
            $this->bipolar($pids, $end),
            $this->sdohScreens($pids, $start, $end),
        );
    }

    /** @return array<int, list<array{date: \DateTimeImmutable, category: int, codes: list<string>}>> */
    private function visits(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT fe.`pid`, fe.`encounter`, DATE(fe.`date`) AS d, COALESCE(fe.`pc_catid`, 0) AS cat FROM `form_encounter` AS fe
              WHERE fe.`date` >= ? AND fe.`date` < ? ORDER BY fe.`pid`, fe.`date`, fe.`encounter`',
            [$from->format('Y-m-d'), $to->modify('+1 day')->format('Y-m-d')],
        );
        $codes = [];
        foreach (array_chunk(array_values(array_unique(array_map(fn(array $r): int => (int)$r['encounter'], $rows))), self::CHUNK) as $chunk) {
            foreach (QueryUtils::fetchRecords("SELECT `encounter`, `code_type`, `code` FROM `billing` WHERE `activity` = 1 AND `code_type` IN ('CPT4', 'HCPCS')
                AND `encounter` IN (" . self::marks($chunk) . ')', $chunk) as $b) {
                $codes[(int)$b['encounter']][] = strtoupper(trim((string)$b['code_type'])) . ':' . strtoupper(trim((string)$b['code']));
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['pid']][] = ['date' => new \DateTimeImmutable((string)$r['d']), 'category' => (int)$r['cat'], 'codes' => array_values(array_unique($codes[(int)$r['encounter']] ?? []))];
        }
        return $out;
    }

    /**
     * @param list<int> $pids
     * @return array<int, Client>
     */
    private function clients(array $pids): array
    {
        $race = $this->cdcCodes('race');
        $ethnicity = $this->cdcCodes('ethnicity');
        // uds_veteran_status is the UDS Report module's demographics field, not OpenEMR's: read only where UDS added it.
        $veteran = QueryUtils::fetchRecords("SHOW COLUMNS FROM `patient_data` LIKE 'uds_veteran_status'") !== [];
        $out = [];
        foreach (array_chunk($pids, self::CHUNK) as $chunk) {
            foreach (QueryUtils::fetchRecords('SELECT `pid`, `DOB`, `sex`, `race`, `ethnicity`' . ($veteran ? ', `uds_veteran_status`' : '') . ' FROM `patient_data` WHERE `pid` IN (' . self::marks($chunk) . ')', $chunk) as $p) {
                $races = array_values(array_filter(array_map(fn(string $o): string => $race[$o] ?? '', explode('|', (string)$p['race']))));
                // Hispanic or Latino if any entry says so, else Not Hispanic if one says that, else unknown.
                $groups = array_map(fn(string $o): string => Client::ethnicityGroup($ethnicity[$o] ?? ''), explode('|', (string)$p['ethnicity']));
                $eth = in_array(Client::HISPANIC, $groups, true) ? Client::HISPANIC : (in_array(Client::NOT_HISPANIC, $groups, true) ? Client::NOT_HISPANIC : Client::UNKNOWN);
                $dob = (string)$p['DOB'];
                $out[(int)$p['pid']] = new Client(
                    (int)$p['pid'],
                    preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) === 1 && $dob !== '0000-00-00' ? new \DateTimeImmutable($dob) : null,
                    (string)$p['sex'],
                    $eth,
                    Client::raceGroup($races),
                    $veteran ? (self::VETERAN[(string)($p['uds_veteran_status'] ?? '')] ?? 'unknown') : null,
                );
            }
        }
        return $out;
    }

    /**
     * Each client's coverage kinds on their first visit of the year, or their first request for services in it when they
     * had no visit ("the insurance status at the time of the first visit during the Measurement Year will be applied
     * for the entire year", manual p. 18).
     *
     * @param array<int, list<array{date: \DateTimeImmutable, category: int, codes: list<string>}>> $visits
     * @param list<array{id: int, pid: int, contacted: \DateTimeImmutable}>                        $requests
     * @return array<int, list<string>>
     */
    private function coverage(array $visits, array $requests, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $day = [];
        foreach ($visits as $pid => $list) {
            foreach ($list as $v) {
                if ($v['date'] >= $start && $v['date'] <= $end) {
                    $day[$pid] = $v['date'];
                    break;
                }
            }
        }
        foreach ($requests as $r) {
            if (!isset($day[$r['pid']]) && $r['contacted'] >= $start) {
                $day[$r['pid']] = $r['contacted'];
            }
        }
        $choices = $this->settings->insuranceKinds();
        $companies = [];
        foreach (QueryUtils::fetchRecords('SELECT `id`, `cqm_sop`, `ins_type_code` FROM `insurance_companies`') as $c) {
            $companies[(int)$c['id']] = Coverage::companyKind($choices[(int)$c['id']] ?? null, (string)($c['cqm_sop'] ?? ''), $c['ins_type_code'] === null ? null : (int)$c['ins_type_code']);
        }
        $out = [];
        foreach (array_chunk(array_keys($day), self::CHUNK) as $chunk) {
            $policies = QueryUtils::fetchRecords("SELECT `pid`, `type`, `provider`, `date`, `date_end` FROM `insurance_data`
                WHERE `pid` IN (" . self::marks($chunk) . ") AND `provider` IS NOT NULL AND `provider` <> '' AND `provider` <> '0' ORDER BY `pid`, `type`, `date`", $chunk);
            $held = [];
            foreach ($policies as $p) {
                $pid = (int)$p['pid'];
                $on = $day[$pid]->format('Y-m-d');
                $from = (string)($p['date'] ?? '');
                $until = (string)($p['date_end'] ?? '');
                if (($from === '' || $from === '0000-00-00' || $from <= $on) && ($until === '' || $until === '0000-00-00' || $until >= $on)) {
                    // The latest policy of each type that had started by that day.
                    $held[$pid][(string)$p['type']] = $companies[(int)$p['provider']] ?? Coverage::OTHER;
                }
            }
            foreach ($chunk as $pid) {
                $out[$pid] = array_values(array_unique($held[$pid] ?? []));
            }
        }
        return $out;
    }

    /**
     * Depression screens: OpenEMR's PHQ-9 form, and saved questionnaires with a PHQ-9 or PHQ-2 total.
     *
     * @param list<int> $pids
     * @return array<int, list<array{date: \DateTimeImmutable, tool: string, positive: bool}>>
     */
    private function screens(array $pids, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $out = [];
        if ($pids === []) {
            return $out;
        }
        $range = [$from->format('Y-m-d'), $to->modify('+1 day')->format('Y-m-d')];
        if (self::tableExists('form_phq9')) {
            foreach (array_chunk($pids, self::CHUNK) as $chunk) {
                foreach (QueryUtils::fetchRecords('SELECT s.*, DATE(f.`date`) AS d FROM `form_phq9` AS s JOIN `forms` AS f ON f.`form_id` = s.`id` AND f.`formdir` = \'phq9\' AND f.`deleted` = 0 AND f.`pid` = s.`pid`
                    WHERE s.`pid` IN (' . self::marks($chunk) . ') AND f.`date` >= ? AND f.`date` < ?', [...$chunk, ...$range]) as $s) {
                    $total = 0;
                    $answered = 0;
                    foreach (self::PHQ9_COLUMNS as $col) {
                        $v = trim((string)($s[$col] ?? ''));
                        if (preg_match('/^[0-3]$/', $v) === 1) {
                            $total += (int)$v;
                            $answered++;
                        }
                    }
                    // A PHQ-9 total needs all nine answered (manual p. 47, for DEP-REM-6); a partial form is no screen.
                    if ($answered === 9) {
                        $out[(int)$s['pid']][] = ['date' => new \DateTimeImmutable((string)$s['d']), 'tool' => 'PHQ-9', 'positive' => $total >= 10];
                    }
                }
            }
        }
        // A draft isn't a screen: from OpenEMR's runtime 0.4.0 a submitted response is saved completed, and
        // before it every response was saved in-progress (openhealthcare-common's QuestionnaireStatus).
        // A response whose questionnaire form was deleted from its visit isn't a screen; core leaves the response row
        // behind (forms.deleted = 1). A response saved without its own copy of the Questionnaire takes the
        // registered one's, which says the same codes.
        [$counted, $countedBinds] = QuestionnaireStatus::condition('qr');
        foreach (array_chunk($pids, self::CHUNK) as $chunk) {
            foreach (QueryUtils::fetchRecords('SELECT qr.`patient_id`, DATE(qr.`create_time`) AS d, COALESCE(NULLIF(qr.`questionnaire`, \'\'), rep.`questionnaire`) AS `questionnaire`, qr.`questionnaire_response`
                FROM `questionnaire_response` AS qr
                LEFT JOIN `questionnaire_repository` AS rep ON rep.`id` = qr.`questionnaire_foreign_id`
                WHERE qr.`patient_id` IN (' . self::marks($chunk) . ') AND qr.`create_time` >= ? AND qr.`create_time` < ? AND ' . $counted . '
                AND NOT EXISTS (
                    SELECT 1 FROM `form_questionnaire_assessments` AS dq
                    JOIN `forms` AS df ON df.`form_id` = dq.`id` AND df.`pid` = dq.`pid` AND df.`formdir` = \'questionnaire_assessments\'
                    WHERE dq.`response_id` = qr.`response_id` AND df.`deleted` = 1)', [...$chunk, ...$range, ...$countedBinds]) as $q) {
                $json = json_decode((string)$q['questionnaire_response'], true);
                // A response's items carry only their linkId; the Questionnaire saved with it says which LOINC code each is.
                $codesOf = [];
                self::linkCodes(json_decode((string)$q['questionnaire'], true)['item'] ?? [], $codesOf);
                foreach (self::TOOLS as $code => [$tool, $cutoff]) {
                    $total = is_array($json) ? self::answerFor($json['item'] ?? [], $code, $codesOf) : null;
                    if ($total !== null) {
                        $out[(int)$q['patient_id']][] = ['date' => new \DateTimeImmutable((string)$q['d']), 'tool' => $tool, 'positive' => $total >= $cutoff];
                        break; // a PHQ-9 that carries the PHQ-2 inside it is one screen, the PHQ-9
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Each Questionnaire item's codes by its linkId, at any depth.
     *
     * @param array<string, list<string>> $out
     */
    private static function linkCodes(mixed $items, array &$out): void
    {
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && is_string($item['linkId'] ?? null)) {
                $out[$item['linkId']] = array_values(array_filter(array_map(fn(mixed $c): string => is_array($c) ? (string)($c['code'] ?? '') : '', (array)($item['code'] ?? []))));
                self::linkCodes($item['item'] ?? [], $out);
            }
        }
    }

    /**
     * The numeric answer to the item coded $code, at any depth: coded in the response itself, through the
     * Questionnaire's codes for its linkId, or with the code as its linkId.
     *
     * @param array<string, list<string>> $codesOf linkId => codes
     */
    private static function answerFor(mixed $items, string $code, array $codesOf = []): ?float
    {
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $linkId = is_string($item['linkId'] ?? null) ? $item['linkId'] : '';
            $codes = [...array_column(array_filter((array)($item['code'] ?? []), 'is_array'), 'code'), ...($codesOf[$linkId] ?? [])];
            if (in_array($code, $codes, true) || $linkId === $code) {
                foreach ((array)($item['answer'] ?? []) as $a) {
                    foreach (['valueInteger', 'valueDecimal'] as $k) {
                        if (is_array($a) && is_numeric($a[$k] ?? null)) {
                            return (float)$a[$k];
                        }
                    }
                }
            }
            $inner = self::answerFor($item['item'] ?? [], $code, $codesOf);
            if ($inner !== null) {
                return $inner;
            }
        }
        return null;
    }

    /**
     * Days with a follow-up for depression on record: a referral, or a care plan with an intervention.
     *
     * @param list<int> $pids
     * @return array<int, list<\DateTimeImmutable>>
     */
    private function followUps(array $pids, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $out = [];
        $range = [$from->format('Y-m-d'), $to->modify('+1 day')->format('Y-m-d')];
        foreach (array_chunk($pids, self::CHUNK) as $chunk) {
            $rows = array_merge(
                QueryUtils::fetchRecords("SELECT `pid`, DATE(`date`) AS d FROM `transactions` WHERE `title` = 'LBTref' AND `pid` IN (" . self::marks($chunk) . ') AND `date` >= ? AND `date` < ?', [...$chunk, ...$range]),
                QueryUtils::fetchRecords("SELECT f.`pid`, DATE(f.`date`) AS d FROM `forms` AS f JOIN `form_care_plan` AS c ON c.`id` = f.`form_id` AND c.`pid` = f.`pid`
                    WHERE f.`formdir` = 'care_plan' AND f.`deleted` = 0 AND c.`care_plan_type` = 'intervention' AND f.`pid` IN (" . self::marks($chunk) . ') AND f.`date` >= ? AND f.`date` < ?', [...$chunk, ...$range]),
            );
            foreach ($rows as $r) {
                $out[(int)$r['pid']][] = new \DateTimeImmutable((string)$r['d']);
            }
        }
        return $out;
    }

    /**
     * Days from which a bipolar disorder diagnosis is on record: a problem list entry's start, or a visit it was billed on.
     *
     * @param list<int> $pids
     * @return array<int, list<\DateTimeImmutable>>
     */
    private function bipolar(array $pids, \DateTimeImmutable $to): array
    {
        $codes = array_map(fn(string $c): string => str_replace('.', '', strtoupper($c)), $this->bipolarCodes);
        $isBipolar = function (string $diagnosis) use ($codes): bool {
            foreach (preg_split('/[;,]/', $diagnosis) ?: [] as $d) {
                $d = strtoupper(trim($d));
                if (str_starts_with($d, 'ICD10:') && in_array(str_replace('.', '', substr($d, 6)), $codes, true)) {
                    return true;
                }
            }
            return false;
        };
        $out = [];
        foreach (array_chunk($pids, self::CHUNK) as $chunk) {
            // A problem marked entered-in-error or refuted is no diagnosis (core's condition-verification list).
            foreach (QueryUtils::fetchRecords("SELECT `pid`, `diagnosis`, COALESCE(`begdate`, `date`) AS d FROM `lists` WHERE `type` = 'medical_problem' AND `diagnosis` LIKE '%F31%'
                AND COALESCE(`verification`, '') NOT IN ('entered-in-error', 'refuted')
                AND `pid` IN (" . self::marks($chunk) . ')', $chunk) as $l) {
                $d = substr((string)$l['d'], 0, 10);
                if ($isBipolar((string)$l['diagnosis']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && $d !== '0000-00-00') {
                    $out[(int)$l['pid']][] = new \DateTimeImmutable($d);
                }
            }
            foreach (QueryUtils::fetchRecords("SELECT b.`pid`, b.`code`, DATE(fe.`date`) AS d FROM `billing` AS b JOIN `form_encounter` AS fe ON fe.`encounter` = b.`encounter` AND fe.`pid` = b.`pid`
                WHERE b.`activity` = 1 AND b.`code_type` = 'ICD10' AND b.`code` LIKE 'F31%' AND b.`pid` IN (" . self::marks($chunk) . ') AND fe.`date` < ?', [...$chunk, $to->modify('+1 day')->format('Y-m-d')]) as $b) {
                if ($isBipolar('ICD10:' . $b['code'])) {
                    $out[(int)$b['pid']][] = new \DateTimeImmutable((string)$b['d']);
                }
            }
        }
        return $out;
    }

    /**
     * Days of a social needs screening of all five domains: OpenEMR's SDOH assessment (7.0.4 and later) with each
     * answered, and not declined.
     *
     * @param list<int> $pids
     * @return array<int, list<\DateTimeImmutable>>
     */
    private function sdohScreens(array $pids, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $out = [];
        if (!self::tableExists('form_history_sdoh')) {
            return $out;
        }
        $answered = implode(' AND ', array_map(fn(string $c): string => "COALESCE(`$c`, '') <> ''", self::SDOH_DOMAINS));
        foreach (array_chunk($pids, self::CHUNK) as $chunk) {
            foreach (QueryUtils::fetchRecords("SELECT `pid`, COALESCE(`assessment_date`, DATE(`created_at`)) AS d FROM `form_history_sdoh`
                WHERE `pid` IN (" . self::marks($chunk) . ") AND COALESCE(`declined_flag`, 0) = 0 AND $answered
                  AND COALESCE(`assessment_date`, DATE(`created_at`)) BETWEEN ? AND ?", [...$chunk, $from->format('Y-m-d'), $to->format('Y-m-d')]) as $s) {
                $out[(int)$s['pid']][] = new \DateTimeImmutable((string)$s['d']);
            }
        }
        return $out;
    }

    /** @return array<string, string> list option id => its CDC code, from the entry's notes (as OpenEMR keeps them) */
    private function cdcCodes(string $list): array
    {
        $out = [];
        foreach (QueryUtils::fetchRecords('SELECT `option_id`, `notes` FROM `list_options` WHERE `list_id` = ?', [$list]) as $o) {
            if (preg_match('/^\d{4}-\d$/', trim((string)$o['notes'])) === 1) {
                $out[(string)$o['option_id']] = trim((string)$o['notes']);
            }
        }
        return $out;
    }

    private static function tableExists(string $table): bool
    {
        return QueryUtils::fetchRecords('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]) !== [];
    }

    /** @param list<int> $values */
    private static function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
