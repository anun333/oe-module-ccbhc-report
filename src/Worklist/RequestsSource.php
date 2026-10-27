<?php

/**
 * "Requests for services" in the Staff Worklist: each request logged in the last 90 days with no Initial Evaluation yet,
 * with the business days since it came in. The CCBHC criteria want an evaluation within 10 business days of a routine
 * request and within 1 of an urgent one (criterion 2.b.1); past that the row is high priority. Recording the
 * evaluation visit clears the row. Built on openhealthcare-common's Worklist contract (1.5.0), so it loads without the
 * Worklist's classes.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CcbhcReport\Worklist;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\CcbhcReport\Report\CcbhcReport;
use OpenHealthcare\CcbhcReport\Repository\Settings;
use OpenHealthcare\Common\Contract\Worklist\ItemKey;
use OpenHealthcare\Common\Contract\Worklist\PatientRef;
use OpenHealthcare\Common\Contract\Worklist\WorkItem;
use OpenHealthcare\Common\Contract\Worklist\WorkSource;

final class RequestsSource implements WorkSource
{
    public const SOURCE = 'ccbhcreq';
    private const WINDOW_DAYS = 90;
    /** Criterion 2.b.1: business days to the Initial Evaluation by need. */
    public const DUE_WITHIN = ['routine' => 10, 'urgent' => 1];

    public function __construct(private readonly \DateTimeImmutable $now, private readonly Settings $settings = new Settings())
    {
    }

    public function id(): string
    {
        return self::SOURCE;
    }

    public function collect(?int $pid = null): array
    {
        $today = $this->now->setTime(0, 0);
        $categories = $this->settings->evaluationCategories();
        $codes = array_map('strval', (array)(CcbhcReport::codes()['iserv']['evaluation_cpt'] ?? []));
        $evaluated = '(' . ($categories === [] ? '0' : 'fe.pc_catid IN (' . implode(', ', array_fill(0, count($categories), '?')) . ')')
            . " OR EXISTS (SELECT 1 FROM billing AS b WHERE b.encounter = fe.encounter AND b.pid = fe.pid AND b.activity = 1 AND b.code_type = 'CPT4' AND b.code IN ("
            . implode(', ', array_fill(0, max(1, count($codes)), '?')) . ')))';
        $rows = QueryUtils::fetchRecords(
            'SELECT r.id, r.pid, r.contacted_on, r.need, ' . PatientRef::SQL_COLUMNS . ' FROM ccbhc_request AS r JOIN patient_data AS pd ON pd.pid = r.pid
              WHERE r.contacted_on >= ?' . ($pid === null ? '' : ' AND r.pid = ?') . "
                AND NOT EXISTS (SELECT 1 FROM form_encounter AS fe WHERE fe.pid = r.pid AND DATE(fe.date) >= r.contacted_on AND $evaluated)",
            [$today->modify('-' . self::WINDOW_DAYS . ' days')->format('Y-m-d'), ...($pid === null ? [] : [$pid]), ...$categories, ...($codes === [] ? [''] : $codes)],
        );
        $days = (new CcbhcReport($this->settings))->businessDays();
        $items = [];
        foreach ($rows as $r) {
            $contact = new \DateTimeImmutable((string)$r['contacted_on']);
            $need = (string)$r['need'] === 'urgent' ? 'urgent' : 'routine';
            $waited = $days->between($contact, $today);
            $late = $waited > self::DUE_WITHIN[$need];
            $items[] = new WorkItem(
                key: new ItemKey(self::SOURCE, (int)$r['pid'], (string)(int)$r['id']),
                patient: PatientRef::fromRow((int)$r['pid'], $r, $this->now),
                title: $need === 'urgent' ? xl('Urgent request for services: no initial evaluation yet') : xl('Request for services: no initial evaluation yet'),
                detail: sprintf(xl('%1$d business days since the request on %2$s; due within %3$d'), $waited, $contact->format('Y-m-d'), self::DUE_WITHIN[$need]),
                priority: $late ? WorkItem::PRIORITY_HIGH : WorkItem::PRIORITY_NORMAL,
                since: $contact,
                ownerId: null,
                actionLabel: xl('Open the chart'),
                actionUrl: '/interface/patient_file/summary/demographics.php?set_pid=' . (int)$r['pid'],
                actionMode: 'chart',
                dismissible: true,
            );
        }
        return $items;
    }
}
