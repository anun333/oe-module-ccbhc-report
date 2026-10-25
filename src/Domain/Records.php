<?php

/**
 * Everything the measures read for one Measurement Year, already pulled from OpenEMR and the module's logs, so the
 * measures are plain rules over plain data (and tested that way).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CcbhcReport\Domain;

final class Records
{
    /**
     * @param array<int, Client>                                                                               $clients        pid => client
     * @param array<int, list<array{date: \DateTimeImmutable, category: int, codes: list<string>}>>            $visits         pid => visits by date, codes as "CPT4:90791", "HCPCS:G8431"
     * @param array<int, list<string>>                                                                         $coverageKinds  pid => the kinds of the policies held on the client's first visit of the year (Coverage::COMPANY_KINDS)
     * @param list<array{id: int, pid: int, contacted: \DateTimeImmutable}>                                    $requests       requests for services (First Contact)
     * @param list<array{id: int, pid: int, contacted: \DateTimeImmutable, served: ?\DateTimeImmutable, hotline: bool}> $crises
     * @param array<int, list<array{date: \DateTimeImmutable, tool: string, positive: bool}>>                  $screens        pid => depression screens
     * @param array<int, list<\DateTimeImmutable>>                                                             $followUps      pid => days with a follow-up for depression (a referral or a care plan intervention)
     * @param array<int, list<\DateTimeImmutable>>                                                             $bipolar        pid => days a bipolar disorder diagnosis was recorded from
     * @param array<int, list<\DateTimeImmutable>>                                                             $sdohScreens    pid => days with a social needs screening of all five domains
     */
    public function __construct(
        public readonly int $year,
        public readonly array $clients,
        public readonly array $visits,
        public readonly array $coverageKinds,
        public readonly array $requests,
        public readonly array $crises,
        public readonly array $screens,
        public readonly array $followUps,
        public readonly array $bipolar,
        public readonly array $sdohScreens,
    ) {
    }

    public function start(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('%04d-01-01', $this->year));
    }

    /** The last day of the year (its end is the end of this day). */
    public function end(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('%04d-12-31', $this->year));
    }

    /** @return array<string, string> the client's ethnicity, race and payer strata */
    public function strata(int $pid): array
    {
        $c = $this->clients[$pid] ?? null;
        return [
            Tally::PAYER => Coverage::payer($this->coverageKinds[$pid] ?? []),
            Tally::ETHNICITY => $c?->ethnicity ?? Client::UNKNOWN,
            Tally::RACE => $c?->race ?? Client::UNKNOWN,
        ];
    }

    /** @return list<array{date: \DateTimeImmutable, category: int, codes: list<string>}> the client's visits in the year */
    public function visitsInYear(int $pid): array
    {
        $start = $this->start();
        $end = $this->end();
        return array_values(array_filter($this->visits[$pid] ?? [], fn(array $v): bool => $v['date'] >= $start && $v['date'] <= $end));
    }
}
