<?php

/**
 * The measures' rules as the Technical Specifications (February 2024) and the March 2025 errata state them, on
 * synthetic records.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CcbhcReport\Tests;

use OpenHealthcare\CcbhcReport\Domain\BusinessDays;
use OpenHealthcare\CcbhcReport\Domain\Client;
use OpenHealthcare\CcbhcReport\Domain\Coverage;
use OpenHealthcare\CcbhcReport\Domain\Records;
use OpenHealthcare\CcbhcReport\Domain\Tally;
use OpenHealthcare\CcbhcReport\Measure\CaseLoad;
use OpenHealthcare\CcbhcReport\Measure\DepressionScreening;
use OpenHealthcare\CcbhcReport\Measure\SocialDrivers;
use OpenHealthcare\CcbhcReport\Measure\TimeToServices;
use PHPUnit\Framework\TestCase;

final class MeasuresTest extends TestCase
{
    private const EVAL = 10;
    private const SERVICE = 11;
    private const OTHER = 5;

    private static function d(string $ymd): \DateTimeImmutable
    {
        return new \DateTimeImmutable($ymd);
    }

    private static function client(int $pid, string $dob, string $race = Client::WHITE): Client
    {
        return new Client($pid, self::d($dob), 'Female', Client::NOT_HISPANIC, $race);
    }

    /** @param array<string, mixed> $over */
    private static function records(array $over): Records
    {
        $a = $over + ['clients' => [], 'visits' => [], 'coverage' => [], 'requests' => [], 'crises' => [], 'screens' => [], 'followUps' => [], 'bipolar' => [], 'sdoh' => []];
        return new Records(2025, $a['clients'], $a['visits'], $a['coverage'], $a['requests'], $a['crises'], $a['screens'], $a['followUps'], $a['bipolar'], $a['sdoh']);
    }

    /** @param list<string> $codes */
    private static function visit(string $date, int $category = self::OTHER, array $codes = []): array
    {
        return ['date' => self::d($date), 'category' => $category, 'codes' => $codes];
    }

    public function testFederalHolidaysAreTheirObservedDays(): void
    {
        self::assertSame(['2025-01-01', '2025-01-20', '2025-02-17', '2025-05-26', '2025-06-19', '2025-07-04', '2025-09-01', '2025-10-13', '2025-11-11', '2025-11-27', '2025-12-25'],
            BusinessDays::federal(2025));
        // 2026-07-04 is a Saturday: kept the Friday before. 2022-01-01 was a Saturday: kept 2021-12-31.
        self::assertContains('2026-07-03', BusinessDays::federal(2026));
        $bd = new BusinessDays(['2025-03-31']);
        self::assertFalse($bd->isBusinessDay(self::d('2021-12-31')));
        self::assertFalse($bd->isBusinessDay(self::d('2025-03-31')), 'a state holiday from the settings');
        self::assertTrue($bd->isBusinessDay(self::d('2025-04-01')));
    }

    public function testBusinessDaysCountTheDaysAfterTheContactUpToTheService(): void
    {
        $bd = new BusinessDays();
        self::assertSame(0, $bd->between(self::d('2025-03-03'), self::d('2025-03-03')), 'the same day');
        self::assertSame(1, $bd->between(self::d('2025-07-03'), self::d('2025-07-07')), 'over the July 4 holiday and a weekend');
        self::assertSame(10, $bd->between(self::d('2025-03-03'), self::d('2025-03-17')), 'two working weeks');
        self::assertSame(0, $bd->between(self::d('2025-03-10'), self::d('2025-03-03')), 'never negative');
    }

    public function testRaceEthnicityAndCoverageFallIntoTheTemplatesRows(): void
    {
        self::assertSame(Client::WHITE, Client::raceGroup(['2106-3']));
        self::assertSame(Client::ASIAN, Client::raceGroup(['2034-7', '2039-6']), 'two Asian subgroups are one group');
        self::assertSame(Client::MORE_THAN_ONE, Client::raceGroup(['2106-3', '2054-5']));
        self::assertSame(Client::UNKNOWN, Client::raceGroup([]));
        self::assertSame(Client::HISPANIC, Client::ethnicityGroup('2148-5'), 'a Hispanic origin (Mexican)');
        self::assertSame(Client::NOT_HISPANIC, Client::ethnicityGroup('2186-5'));
        self::assertSame(Client::UNKNOWN, Client::ethnicityGroup(''));

        self::assertSame([Coverage::ROW_DUAL, Coverage::PAYER_OTHER], [Coverage::row(['medicaid', 'medicare']), Coverage::payer(['medicaid', 'medicare'])], 'dual eligibles are Non-Medicaid');
        self::assertSame([Coverage::ROW_CHIP, Coverage::PAYER_MEDICAID], [Coverage::row(['chip_19']), Coverage::payer(['chip_19'])], 'Title 19 CHIP is Medicaid');
        self::assertSame([Coverage::ROW_CHIP, Coverage::PAYER_OTHER], [Coverage::row(['chip_21']), Coverage::payer(['chip_21'])], 'Title 21 CHIP is not');
        self::assertSame(Coverage::ROW_UNINSURED, Coverage::row([]));
        self::assertSame(Coverage::MILITARY, Coverage::companyKind(null, '311', null), 'TRICARE by its payment typology');
        self::assertSame(Coverage::CHIP_21, Coverage::companyKind('chip_21', '2', 3), 'the clinic choice wins');
        self::assertSame(Coverage::MEDICAID, Coverage::companyKind(null, '', 3), 'else the claim type');
    }

    public function testTimeToServicesCountsNewClientsFirstContactsInTheFirstElevenMonths(): void
    {
        $measure = new TimeToServices(new BusinessDays(), [self::EVAL], [self::SERVICE], ['CPT4:90791']);
        $r = self::records([
            'clients' => [1 => self::client(1, '1990-01-01'), 2 => self::client(2, '1990-01-01'), 3 => self::client(3, '2015-06-01'),
                4 => self::client(4, '1990-01-01'), 5 => self::client(5, '2010-01-01'), 6 => self::client(6, '1990-01-01')],
            'visits' => [
                1 => [self::visit('2025-03-05', self::EVAL), self::visit('2025-03-12', self::SERVICE)],
                2 => [self::visit('2025-01-15'), self::visit('2025-03-10', self::EVAL)],           // seen 2 months before: not new
                3 => [self::visit('2025-04-02', self::EVAL)],                                       // 10 at the end of the year
                4 => [self::visit('2025-06-02', self::OTHER, ['CPT4:90791'])],                      // an evaluation by its code; no service
                5 => [self::visit('2026-01-05', self::EVAL), self::visit('2026-01-06', self::SERVICE)], // after the year
            ],
            'requests' => [
                ['id' => 1, 'pid' => 1, 'contacted' => self::d('2025-03-03')],
                ['id' => 2, 'pid' => 1, 'contacted' => self::d('2025-05-01')],                      // within 6 months of the first
                ['id' => 3, 'pid' => 2, 'contacted' => self::d('2025-03-07')],
                ['id' => 4, 'pid' => 3, 'contacted' => self::d('2025-04-01')],
                ['id' => 5, 'pid' => 4, 'contacted' => self::d('2025-06-02')],
                ['id' => 6, 'pid' => 5, 'contacted' => self::d('2025-11-20')],
                ['id' => 7, 'pid' => 6, 'contacted' => self::d('2025-12-02')],                      // the final month
            ],
        ]);
        $res = $measure->compute($r);
        $eval = $res->parts[TimeToServices::EVALUATION];
        // pid 1: 2 business days; pid 4: 0 (same day); pid 5: after the year, 31; pid 5 is 15, the others adults.
        self::assertSame([33.0, 3], $eval->total());
        self::assertSame([31.0, 1], $eval->rows(Tally::AGE)['12_17']);
        $service = $res->parts[TimeToServices::SERVICE];
        self::assertSame([38.0, 2], $service->total(), 'pid 1: 7 business days; pid 5: 31; pid 4 never served');
        self::assertSame(1, $res->notes['never_served']);
        self::assertArrayNotHasKey('never_evaluated', $res->notes);
        self::assertSame(11.0, Tally::result(...[...$eval->total(), true]));
    }

    public function testCrisisEpisodesAreFaceToFaceAndAtLeast24HoursApart(): void
    {
        $measure = new TimeToServices(new BusinessDays(), [], [], []);
        $at = fn(string $s): \DateTimeImmutable => new \DateTimeImmutable($s);
        $r = self::records([
            'clients' => [1 => self::client(1, '1990-01-01'), 2 => self::client(2, '2012-01-01')],
            'crises' => [
                ['id' => 1, 'pid' => 1, 'contacted' => $at('2025-02-01 10:00'), 'served' => $at('2025-02-01 11:30'), 'hotline' => false],
                ['id' => 2, 'pid' => 1, 'contacted' => $at('2025-02-01 20:00'), 'served' => $at('2025-02-01 21:00'), 'hotline' => false], // same episode
                ['id' => 3, 'pid' => 1, 'contacted' => $at('2025-03-01 10:00'), 'served' => null, 'hotline' => true],                   // crisis line only
                ['id' => 4, 'pid' => 2, 'contacted' => $at('2025-12-30 22:00'), 'served' => $at('2026-01-01 02:00'), 'hotline' => false], // served after the year
                ['id' => 5, 'pid' => 2, 'contacted' => $at('2025-12-31 06:00'), 'served' => $at('2025-12-31 07:00'), 'hotline' => false], // the last 24 hours
                ['id' => 6, 'pid' => 1, 'contacted' => $at('2025-05-01 09:00'), 'served' => null, 'hotline' => false],
            ],
        ]);
        $res = $measure->compute($r);
        $crisis = $res->parts[TimeToServices::CRISIS];
        self::assertSame([25.5, 2], $crisis->total(), '1.5 hours, and 24 for the one served after the year');
        self::assertSame(1, $res->notes['crisis_never_served']);
    }

    public function testSocialDriversNeedsAllFiveDomainsForAdultsWithAQualifyingVisit(): void
    {
        $measure = new SocialDrivers(['CPT4:99213'], 'HCPCS:M1207');
        $r = self::records([
            'clients' => [1 => self::client(1, '1980-01-01'), 2 => self::client(2, '1980-01-01'), 3 => self::client(3, '1980-01-01'), 4 => self::client(4, '2010-01-01'), 5 => self::client(5, '1980-01-01')],
            'visits' => [
                1 => [self::visit('2025-04-01', self::OTHER, ['CPT4:99213'])],
                2 => [self::visit('2025-04-01', self::OTHER, ['CPT4:99213', 'HCPCS:M1207'])],
                3 => [self::visit('2025-04-01', self::OTHER, ['CPT4:99213'])],
                4 => [self::visit('2025-04-01', self::OTHER, ['CPT4:99213'])],   // 15
                5 => [self::visit('2025-04-01', self::OTHER, ['CPT4:99999'])],   // no qualifying code
            ],
            'sdoh' => [1 => [self::d('2025-04-01')], 3 => [self::d('2024-11-01')]],
        ]);
        $res = $measure->compute($r);
        self::assertSame([2.0, 3], $res->parts['screened']->total(), 'screened in the year by the assessment or M1207; last year does not count');
        self::assertSame(1, $res->notes['adults_no_qualifying_code']);
    }

    public function testDepressionScreeningReadsTheMostRecentScreenInThe14DaysBeforeTheVisit(): void
    {
        $codes = ['positive_with_plan' => 'HCPCS:G8431', 'negative' => 'HCPCS:G8510', 'exception' => 'HCPCS:G8433'];
        $measure = new DepressionScreening(DepressionScreening::ADULT, ['CPT4:90834'], $codes);
        $v = fn(string $date, array $extra = []): array => self::visit($date, self::OTHER, ['CPT4:90834', ...$extra]);
        $s = fn(string $date, bool $positive): array => ['date' => self::d($date), 'tool' => 'PHQ-9', 'positive' => $positive];
        $r = self::records([
            'clients' => array_combine(range(1, 8), array_map(fn(int $p): Client => self::client($p, '1980-01-01'), range(1, 8))) + [9 => self::client(9, '2010-01-01')],
            'visits' => [1 => [$v('2025-05-10')], 2 => [$v('2025-05-10')], 3 => [$v('2025-05-10')], 4 => [$v('2025-05-10')],
                5 => [$v('2025-05-10')], 6 => [$v('2025-05-10', ['HCPCS:G8433'])], 7 => [$v('2025-05-10', ['HCPCS:G8510'])], 8 => [$v('2025-05-10')], 9 => [$v('2025-05-10')]],
            'screens' => [
                1 => [$s('2025-04-30', false)],                       // negative 10 days before: met
                2 => [$s('2025-05-10', true)],                        // positive, no follow-up: not met
                3 => [$s('2025-05-10', true)],                        // positive with a follow-up that day: met
                4 => [$s('2025-04-25', false)],                       // 15 days before: not counted
                5 => [$s('2025-05-01', false), $s('2025-05-09', true)], // the most recent is positive, no follow-up
                8 => [$s('2025-05-10', false)],                       // bipolar: excluded
            ],
            'followUps' => [3 => [self::d('2025-05-10')], 5 => [self::d('2025-05-01')]],
            'bipolar' => [8 => [self::d('2024-02-01')]],
        ]);
        $res = $measure->compute($r);
        // In the denominator: 1, 2, 3, 4, 5, 7 (6 is an exception, 8 excluded, 9 is 15). Met: 1, 3, 7.
        self::assertSame([3.0, 6], $res->parts['screened']->total());
        self::assertEquals(['excluded_bipolar' => 1, 'exception' => 1], $res->notes);
        self::assertSame(50.0, Tally::result(...[...$res->parts['screened']->total(), false]));

        $child = (new DepressionScreening(DepressionScreening::CHILD, ['CPT4:90834'], $codes))->compute($r);
        self::assertSame([0.0, 1], $child->parts['screened']->total(), 'the 15-year-old is in CDF-CH');
    }

    public function testCaseLoadCountsEachClientSeenInTheYearOnce(): void
    {
        $r = self::records([
            'clients' => [1 => self::client(1, '2020-01-01', Client::ASIAN), 2 => self::client(2, '1950-01-01'), 3 => self::client(3, '1990-01-01')],
            'visits' => [1 => [self::visit('2025-02-01'), self::visit('2025-03-01')], 2 => [self::visit('2025-02-01')], 3 => [self::visit('2024-12-01')]],
            'coverage' => [1 => ['chip_19'], 2 => ['medicare', 'medicaid']],
        ]);
        $c = (new CaseLoad())->compute($r);
        self::assertSame(2, $c['total']);
        self::assertSame(1, $c['age']['0_11']);
        self::assertSame(1, $c['age']['65_plus']);
        self::assertSame(1, $c['insurance']['chip']);
        self::assertSame(1, $c['insurance']['dual']);
        self::assertSame(1, $c['race']['asian']);
        self::assertNull($c['veteran'], 'no veteran field in the record: not reported');
    }
}
