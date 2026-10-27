<?php

/**
 * "Requests for services" as the Staff Worklist collects it. The tab is built on openhealthcare-common's Worklist
 * contract, so it's tested here without the Worklist installed.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace {
    if (!function_exists('xl')) {
        function xl(string $text): string
        {
            return $text;
        }
    }
}

namespace OpenHealthcare\CcbhcReport\Tests {

    use OpenEMR\Common\Database\QueryUtils;
    use OpenHealthcare\CcbhcReport\Worklist\RequestsSource;
    use OpenHealthcare\Common\Contract\Worklist\WorkItem;
    use OpenHealthcare\Common\Contract\Worklist\Worklist;
    use PHPUnit\Framework\TestCase;

    if (!class_exists(QueryUtils::class)) {
        require_once __DIR__ . '/Stubs/QueryUtils.php';
    }

    final class RequestsSourceTest extends TestCase
    {
        private const NOW = '2026-10-26 09:00:00'; // a Monday

        protected function setUp(): void
        {
            QueryUtils::$results = [];
            QueryUtils::$calls = [];
        }

        /** @return array<string, mixed> */
        private static function request(int $id, int $pid, string $on, string $need): array
        {
            return ['id' => $id, 'pid' => $pid, 'contacted_on' => $on, 'need' => $need,
                'fname' => 'Jan', 'lname' => 'Doe', 'DOB' => '1980-02-03', 'pubpid' => "P$pid", 'deceased_date' => null, 'portal_access' => 0];
        }

        public function testEachRequestWithoutAnEvaluationIsAnItemHighOnceItsLate(): void
        {
            QueryUtils::$results = [
                [['name' => 'evaluation_categories', 'value' => '[12]']],
                [self::request(31, 7, '2026-10-22', 'urgent'), self::request(32, 9, '2026-10-20', 'routine')],
            ];
            $items = (new RequestsSource(new \DateTimeImmutable(self::NOW)))->collect();

            $this->assertContainsOnlyInstancesOf(WorkItem::class, $items);
            $this->assertSame(['ccbhcreq:7:31', 'ccbhcreq:9:32'], array_map(static fn(WorkItem $i): string => (string)$i->key, $items));
            [$urgent, $routine] = $items;
            // Urgent: due within 1 business day, and the 23rd and 26th have passed. Routine: 4 of 10.
            $this->assertSame([WorkItem::PRIORITY_HIGH, 'Urgent request for services: no initial evaluation yet'], [$urgent->priority, $urgent->title]);
            $this->assertSame('2 business days since the request on 2026-10-22; due within 1', $urgent->detail);
            $this->assertSame([WorkItem::PRIORITY_NORMAL, '4 business days since the request on 2026-10-20; due within 10'], [$routine->priority, $routine->detail]);
            $this->assertSame(['chart', '/interface/patient_file/summary/demographics.php?set_pid=7'], [$urgent->actionMode, $urgent->actionUrl]);
            // The evaluation is a visit of the chosen category or one billed 90791/90792.
            $this->assertSame(['2026-07-28', 12, '90791', '90792'], QueryUtils::$calls[1]['binds']);
        }

        public function testOnePatientsRequestsAreCheckedOnTheirOwn(): void
        {
            QueryUtils::$results = [[], [self::request(31, 7, '2026-10-22', 'urgent')]];
            $items = (new RequestsSource(new \DateTimeImmutable(self::NOW)))->collect(7);
            $this->assertSame(['ccbhcreq:7:31'], array_map(static fn(WorkItem $i): string => (string)$i->key, $items));
            $this->assertStringContainsString('AND r.pid = ?', QueryUtils::$calls[1]['sql']);
            $this->assertSame(['2026-07-28', 7, '90791', '90792'], QueryUtils::$calls[1]['binds']);
        }

        public function testTheTabReachesAWorklistThroughTheContract(): void
        {
            $event = new class (new \DateTimeImmutable(self::NOW)) {
                /** @var list<array{object, string, string}> */
                public array $got = [];

                public function __construct(public readonly \DateTimeImmutable $now)
                {
                }

                public function contribute(object $source, string $label, string $ownerRole): void
                {
                    $this->got[] = [$source, $label, $ownerRole];
                }
            };
            $this->assertTrue(Worklist::contribute($event, new RequestsSource(Worklist::now($event)), 'Requests for services', 'Front desk'));
            $this->assertSame('ccbhcreq', $event->got[0][0]->id());
        }
    }
}
