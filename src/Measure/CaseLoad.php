<?php

/**
 * The reporting template's Case Load Characteristics: each client seen in the year, by age (on the last day of the
 * year), sex, ethnicity, race, insurance (on their first visit of the year) and, where the record keeps it, veteran or
 * military status.
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
use OpenHealthcare\CcbhcReport\Domain\Records;

final class CaseLoad
{
    public const AGES = ['0_11', '12_17', '18_64', '65_plus', 'unknown'];
    public const VETERANS = ['active', 'veteran', 'neither', 'unknown'];

    /**
     * @return array{total: int, age: array<string, int>, sex: array<string, int>, ethnicity: array<string, int>, race: array<string, int>, insurance: array<string, int>, veteran: ?array<string, int>}
     */
    public function compute(Records $r): array
    {
        $out = ['total' => 0, 'age' => array_fill_keys(self::AGES, 0), 'sex' => array_fill_keys(Client::SEXES, 0),
            'ethnicity' => array_fill_keys(Client::ETHNICITIES, 0), 'race' => array_fill_keys(Client::RACES, 0),
            'insurance' => array_fill_keys(Coverage::ROWS, 0), 'veteran' => null];
        $hasVeteran = false;
        $veterans = array_fill_keys(self::VETERANS, 0);
        foreach (array_keys($r->visits) as $pid) {
            $client = $r->clients[$pid] ?? null;
            if ($client === null || $r->visitsInYear($pid) === []) {
                continue;
            }
            $out['total']++;
            $age = $client->ageOn($r->end());
            $out['age'][match (true) {
                $age === null => 'unknown',
                $age <= 11 => '0_11',
                $age <= 17 => '12_17',
                $age <= 64 => '18_64',
                default => '65_plus',
            }]++;
            $out['sex'][Client::sexGroup($client->sex)]++;
            $out['ethnicity'][$client->ethnicity]++;
            $out['race'][$client->race]++;
            $out['insurance'][Coverage::row($r->coverageKinds[$pid] ?? [])]++;
            if ($client->veteran !== null) {
                $hasVeteran = true;
                $veterans[in_array($client->veteran, self::VETERANS, true) ? $client->veteran : 'unknown']++;
            }
        }
        if ($hasVeteran) {
            $out['veteran'] = $veterans;
        }
        return $out;
    }
}
