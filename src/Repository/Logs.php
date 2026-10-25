<?php

/**
 * The two logs the measures need and OpenEMR's record doesn't keep: requests for services (First Contact) and crisis
 * episodes.
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

final class Logs
{
    public const BY = ['self', 'guardian'];
    public const NEEDS = ['routine', 'urgent'];

    public function addRequest(int $pid, \DateTimeImmutable $on, string $by, string $need, bool $screened, bool $insurance, string $note, int $userId): int
    {
        return (int)QueryUtils::sqlInsert(
            'INSERT INTO `ccbhc_request` (`pid`, `contacted_on`, `requested_by`, `need`, `screened`, `insurance_taken`, `note`, `created_by`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $on->format('Y-m-d'), $by, $need, $screened ? 1 : 0, $insurance ? 1 : 0, mb_substr($note, 0, 255), $userId],
        );
    }

    public function addCrisis(int $pid, \DateTimeImmutable $contacted, ?\DateTimeImmutable $served, bool $hotline, string $note, int $userId): int
    {
        return (int)QueryUtils::sqlInsert(
            'INSERT INTO `ccbhc_crisis` (`pid`, `contacted_at`, `served_at`, `hotline_only`, `note`, `created_by`) VALUES (?, ?, ?, ?, ?, ?)',
            [$pid, $contacted->format('Y-m-d H:i:s'), $served?->format('Y-m-d H:i:s'), $hotline ? 1 : 0, mb_substr($note, 0, 255), $userId],
        );
    }

    /** Records when the face-to-face service began, for an episode logged before it did. */
    public function setServed(int $id, \DateTimeImmutable $served): void
    {
        QueryUtils::sqlStatementThrowException('UPDATE `ccbhc_crisis` SET `served_at` = ? WHERE `id` = ? AND `served_at` IS NULL AND `hotline_only` = 0',
            [$served->format('Y-m-d H:i:s'), $id]);
    }

    /** @return list<array<string, mixed>> requests from $since, newest first, with the patient's name */
    public function requests(\DateTimeImmutable $since): array
    {
        return QueryUtils::fetchRecords(
            'SELECT r.`id`, r.`pid`, r.`contacted_on`, r.`requested_by`, r.`need`, r.`screened`, r.`insurance_taken`, r.`note`, p.`fname`, p.`lname`, p.`pubpid`
               FROM `ccbhc_request` AS r JOIN `patient_data` AS p ON p.`pid` = r.`pid`
              WHERE r.`contacted_on` >= ? ORDER BY r.`contacted_on` DESC, r.`id` DESC',
            [$since->format('Y-m-d')],
        );
    }

    /** @return list<array<string, mixed>> crisis episodes from $since, newest first, with the patient's name */
    public function crises(\DateTimeImmutable $since): array
    {
        return QueryUtils::fetchRecords(
            'SELECT c.`id`, c.`pid`, c.`contacted_at`, c.`served_at`, c.`hotline_only`, c.`note`, p.`fname`, p.`lname`, p.`pubpid`
               FROM `ccbhc_crisis` AS c JOIN `patient_data` AS p ON p.`pid` = c.`pid`
              WHERE c.`contacted_at` >= ? ORDER BY c.`contacted_at` DESC, c.`id` DESC',
            [$since->format('Y-m-d 00:00:00')],
        );
    }
}
