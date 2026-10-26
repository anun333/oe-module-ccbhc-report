<?php

/**
 * CCBHC Report: the clinic-collected measures for one Measurement Year, laid out as SAMHSA's data reporting template
 * lays them out, so each row copies into the workbook; the measures not computed, with why; and the case load.
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
use OpenEMR\Core\Header;
use OpenHealthcare\CcbhcReport\Domain\Tally;
use OpenHealthcare\CcbhcReport\Labels;
use OpenHealthcare\CcbhcReport\Report\CcbhcReport;
use OpenHealthcare\CcbhcReport\Repository\Settings;
use OpenHealthcare\Common\OpenEmr;

// The same access as Bootstrap::ACL, written out: tools/footprint.php lists each check it can read.
if (!AclMain::aclCheckCore('patients', 'med')) {
    OpenEmr::deny('CCBHC Report: page refused', xl('CCBHC Report'), 'CCBHC Report');
}
$today = new DateTimeImmutable('today');
$thisYear = (int)$today->format('Y');
$year = (int)($_GET['year'] ?? 0);
// The year most recently ended until the clinic picks one; reports are due nine months after it (manual p. 23).
$year = $year >= 2025 && $year <= $thisYear ? $year : $thisYear - 1;
$settings = new Settings();
$run = (new CcbhcReport($settings))->run($year, $today);
OpenEmr::audit('other', "CCBHC Report: measures for $year viewed");
$configured = $settings->evaluationCategories() !== [] && $settings->serviceCategories() !== [];
$fmt = fn(?float $v): string => $v === null ? '' : number_format($v, 1);
$num = fn(float $n): string => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
$isAdmin = AclMain::aclCheckCore('admin', 'super');
?>
<!DOCTYPE html>
<?php // The module's own text is written in English. ?>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo xlt('CCBHC Report'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <?php echo \OpenHealthcare\CcbhcReport\Contrast::style(); ?>
</head>
<body class="body_top">
<main class="container-fluid py-3" style="max-width: 80rem">
    <h1 class="h4"><?php echo xlt('CCBHC Report: clinic-collected measures'); ?></h1>
    <p><?php echo xlt('Each table below is laid out as in the reporting template SAMHSA publishes (February 2024), so its numbers copy into the matching tab. Measures follow the Technical Specifications (February 2024) and the March 2025 errata.'); ?>
        <a href="requests.php" onclick="top.restoreSession()"><?php echo xlt('Requests for services'); ?></a> ·
        <a href="crisis.php" onclick="top.restoreSession()"><?php echo xlt('Crisis episodes'); ?></a>
        <?php if ($isAdmin) { ?> · <a href="settings.php" onclick="top.restoreSession()"><?php echo xlt('Settings'); ?></a><?php } ?></p>

    <form method="get" class="form-inline mb-3">
        <label for="year" class="mr-2"><?php echo xlt('Measurement Year'); ?></label>
        <select id="year" name="year" class="form-control mr-2">
            <?php for ($y = $thisYear; $y >= 2025; $y--) { ?>
                <option value="<?php echo attr((string)$y); ?>"<?php echo $y === $year ? ' selected' : ''; ?>><?php echo text((string)$y); ?></option>
            <?php } ?>
        </select>
        <button type="submit" class="btn btn-secondary"><?php echo xlt('Show'); ?></button>
    </form>
    <p class="small text-muted"><?php echo text(sprintf(xl('Measurement Year %1$d: January 1 to December 31, %1$d. Time to Services counts First Contacts from January 1 to November 30 (the final month waits for next year), and New Clients are those not seen in the 6 months before.'), $year)); ?></p>
    <?php if (!$configured) { ?>
        <div class="alert alert-warning py-2"><?php echo xlt('Time to Services needs the visit categories that are Initial Evaluations and Initial Clinical Services. An administrator chooses them in the settings.'); ?></div>
    <?php } ?>

    <?php foreach ($run['measures'] as $id => $m) { ?>
        <h2 class="h5 mt-4"><?php echo text(Labels::measure($id)); ?>
            <a class="small ml-2" href="download.php?year=<?php echo attr((string)$year); ?>&amp;measure=<?php echo attr($id); ?>"><?php echo xlt('Client list (CSV)'); ?></a></h2>
        <?php foreach ($m->parts as $partKey => $tally) { ?>
            <?php if (count($m->parts) > 1) { ?><h3 class="h6 mt-3"><?php echo text(Labels::part($id, $partKey)); ?></h3><?php } ?>
            <div class="row">
            <?php foreach ($tally->dimensions as $dim => $strata) { ?>
                <div class="col-lg-6">
                <table class="table table-sm table-bordered">
                    <caption class="small"><?php echo text(Labels::dimension($dim)); ?></caption>
                    <thead><tr><th scope="col"><?php echo xlt('Measure'); ?></th><th scope="col" class="text-right"><?php echo xlt('Numerator'); ?></th>
                        <th scope="col" class="text-right"><?php echo xlt('Denominator'); ?></th><th scope="col" class="text-right"><?php echo $tally->average ? xlt('Average') : xlt('Rate (percent)'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($tally->rows($dim) as $s => [$n, $d]) { ?>
                        <tr><td><?php echo text(Labels::stratum($dim, $s)); ?></td><td class="text-right"><?php echo text($num($n)); ?></td>
                            <td class="text-right"><?php echo text((string)$d); ?></td><td class="text-right"><?php echo text($fmt(Tally::result($n, $d, $tally->average))); ?></td></tr>
                    <?php } ?>
                    <?php [$tn, $td] = $tally->total(); ?>
                    <tr class="font-weight-bold"><td><?php echo xlt('Total Eligible Population'); ?></td><td class="text-right"><?php echo text($num($tn)); ?></td>
                        <td class="text-right"><?php echo text((string)$td); ?></td><td class="text-right"><?php echo text($fmt(Tally::result($tn, $td, $tally->average))); ?></td></tr>
                    </tbody>
                </table>
                </div>
            <?php } ?>
            </div>
        <?php } ?>
        <?php if ($m->notes !== []) { ?>
            <ul class="small">
            <?php foreach ($m->notes as $key => $count) { ?>
                <li><?php echo text(Labels::note($key) . ': ' . $count); ?></li>
            <?php } ?>
            </ul>
        <?php } ?>
    <?php } ?>

    <h2 class="h5 mt-4"><?php echo xlt('Required measures this module does not compute'); ?></h2>
    <ul>
        <li><strong><?php echo text(Labels::measure('DEP-REM-6')); ?></strong>: <?php echo xlt('the measure belongs to MN Community Measurement, which reserves all rights to it. It is computed here only with their permission, which has been asked for.'); ?></li>
        <li><strong><?php echo text(Labels::measure('ASC')); ?></strong>: <?php echo xlt('NCQA requires a custom license to use its measure to identify records or calculate results. It is computed here only with that license, which has been asked for.'); ?></li>
    </ul>
    <p class="small text-muted"><?php echo xlt('The optional measures (TSC, SRA-A, SRA-C, WCC-CH, CBP-AD) are not computed either: four are NCQA measures, and the suicide risk measures are reported through their electronic versions (CMS161, CMS177).'); ?></p>

    <h2 class="h5 mt-4"><?php echo xlt('Case Load Characteristics'); ?></h2>
    <?php $c = $run['caseLoad']; $total = (int)$c['total']; ?>
    <table class="table table-sm table-bordered" style="max-width: 40rem">
        <thead><tr><th scope="col"><?php echo xlt('Characteristic'); ?></th><th scope="col" class="text-right"><?php echo xlt('Number'); ?></th><th scope="col" class="text-right"><?php echo xlt('Percent'); ?></th></tr></thead>
        <tbody>
        <?php foreach (['age' => xl('Age'), 'sex' => xl('Sex'), 'ethnicity' => xl('Ethnicity'), 'race' => xl('Race'), 'insurance' => xl('Insurance Status'), 'veteran' => xl('Veteran or Military Status')] as $section => $title) { ?>
            <tr class="table-active"><th scope="row" colspan="3"><?php echo text($title); ?></th></tr>
            <?php if ($c[$section] === null) { ?>
                <tr><td colspan="3" class="small text-muted"><?php echo xlt('Not in the record: OpenEMR keeps veteran status only where the UDS module adds it.'); ?></td></tr>
            <?php } else {
                foreach ($c[$section] as $key => $n) {
                    if ($key === 'unknown' && $n === 0 && in_array($section, ['age', 'veteran'], true)) {
                        continue;
                    } ?>
                <tr><td><?php echo text(Labels::caseLoadRow($section, (string)$key)); ?></td><td class="text-right"><?php echo text((string)$n); ?></td>
                    <td class="text-right"><?php echo text($total > 0 ? number_format(100 * $n / $total, 1) : ''); ?></td></tr>
            <?php }
            } ?>
        <?php } ?>
        <tr class="font-weight-bold"><td><?php echo xlt('Total Clinic Population'); ?></td><td class="text-right"><?php echo text((string)$total); ?></td><td></td></tr>
        </tbody>
    </table>
    <p class="small text-muted"><?php echo xlt('Age on the last day of the year; insurance on the first visit of the year for each client. Gender identity, optional in the template, is not filled in.'); ?></p>
</main>
</body>
</html>
