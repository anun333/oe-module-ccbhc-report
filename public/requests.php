<?php

/**
 * Requests for services: the front desk logs the day a person, or their guardian, asked the clinic for services (the
 * First Contact Time to Services counts from), whether it is routine or urgent, and whether the preliminary screening
 * and insurance were taken then. A referral from another provider is not a First Contact. The list shows each recent
 * request and whether an Initial Evaluation has happened.
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
use OpenEMR\Core\Header;
use OpenHealthcare\CcbhcReport\Report\CcbhcReport;
use OpenHealthcare\CcbhcReport\Repository\Logs;
use OpenHealthcare\CcbhcReport\Repository\Settings;
use OpenHealthcare\CcbhcReport\Worklist\RequestsSource;
use OpenHealthcare\Common\OpenEmr;

// The same access as Bootstrap::REQUEST_ACL, written out: patients/demo to see; write or addonly to log.
if (!AclMain::aclCheckCore('patients', 'demo')) {
    OpenEmr::deny('CCBHC Report: requests page refused', xl('Requests for Services'), 'CCBHC Report');
}
$canLog = AclMain::aclCheckCore('patients', 'demo', '', 'write') || AclMain::aclCheckCore('patients', 'demo', '', 'addonly');
$logs = new Logs();
$today = new DateTimeImmutable('today');
$errors = [];
$done = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $canLog) {
    OpenEmr::requireCsrf($_POST['csrf_token_form'] ?? '');
    $chart = trim((string)($_POST['chart'] ?? ''));
    $patient = QueryUtils::fetchRecords('SELECT `pid` FROM `patient_data` WHERE `pubpid` = ?', [$chart]);
    $on = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_POST['contacted_on'] ?? ''));
    $by = (string)($_POST['requested_by'] ?? '');
    $need = (string)($_POST['need'] ?? '');
    if (count($patient) !== 1) {
        $errors[] = xl('Enter the chart number of one patient. Register a new person first.');
    }
    if ($on === false || $on > $today) {
        $errors[] = xl('The date of the request must be today or earlier.');
    }
    if (!in_array($by, Logs::BY, true) || !in_array($need, Logs::NEEDS, true)) {
        $errors[] = xl('Choose who asked and whether the need is routine or urgent.');
    }
    if ($errors === [] && $on !== false) {
        $pid = (int)$patient[0]['pid'];
        $id = $logs->addRequest($pid, $on, $by, $need, ($_POST['screened'] ?? '') === '1', ($_POST['insurance'] ?? '') === '1', trim((string)($_POST['note'] ?? '')), (int)OpenEmr::session('authUserID'));
        OpenEmr::audit('patient-record-insert', "CCBHC Report: request for services $id logged", $pid);
        $done = xl('The request is logged.');
    }
}
OpenEmr::audit('other', 'CCBHC Report: requests for services viewed');
$settings = new Settings();
$days = (new CcbhcReport($settings))->businessDays();
$requests = $logs->requests($today->modify('-90 days'));
$categories = $settings->evaluationCategories();
$codes = array_map('strval', (array)(CcbhcReport::codes()['iserv']['evaluation_cpt'] ?? []));
$evaluatedOn = function (int $pid, string $from) use ($categories, $codes): ?string {
    $rows = QueryUtils::fetchRecords(
        'SELECT MIN(DATE(fe.`date`)) AS d FROM `form_encounter` AS fe WHERE fe.`pid` = ? AND DATE(fe.`date`) >= ? AND ('
        . ($categories === [] ? '0' : 'fe.`pc_catid` IN (' . implode(', ', array_fill(0, count($categories), '?')) . ')')
        . " OR EXISTS (SELECT 1 FROM `billing` AS b WHERE b.`encounter` = fe.`encounter` AND b.`pid` = fe.`pid` AND b.`activity` = 1 AND b.`code_type` = 'CPT4' AND b.`code` IN ("
        . implode(', ', array_fill(0, count($codes), '?')) . ')))',
        [$pid, $from, ...$categories, ...$codes],
    );
    return $rows[0]['d'] ?? null;
};
$csrf = OpenEmr::csrfToken();
$chartNow = '';
$sessionPid = (int)(OpenEmr::session('pid') ?? 0);
if ($sessionPid > 0) {
    $chartNow = (string)(QueryUtils::fetchRecords('SELECT `pubpid` FROM `patient_data` WHERE `pid` = ?', [$sessionPid])[0]['pubpid'] ?? '');
}
?>
<!DOCTYPE html>
<?php // The module's own text is written in English. ?>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo xlt('Requests for Services'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <?php echo \OpenHealthcare\CcbhcReport\Contrast::style(); ?>
</head>
<body class="body_top">
<main class="container-fluid py-3" style="max-width: 80rem">
    <h1 class="h4"><?php echo xlt('Requests for Services'); ?></h1>
    <p><?php echo xlt('Log the day a person, or their guardian, asks for services: the CCBHC measures count the time to their first evaluation and first clinical service from it. A referral from another provider is not a request; log the day the person contacts the clinic.'); ?></p>
    <?php foreach ($errors as $e) { ?>
        <div class="alert alert-danger py-2"><?php echo text($e); ?></div>
    <?php } ?>
    <?php if ($done !== '') { ?>
        <div class="alert alert-success py-2"><?php echo text($done); ?></div>
    <?php } ?>

    <?php if ($canLog) { ?>
    <form method="post" class="border rounded p-3 mb-4">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
        <div class="form-row">
            <div class="form-group col-md-3">
                <label for="chart"><?php echo xlt('Chart number'); ?></label>
                <input type="text" class="form-control" id="chart" name="chart" value="<?php echo attr($chartNow); ?>" required>
            </div>
            <div class="form-group col-md-3">
                <label for="contacted_on"><?php echo xlt('Date of the request'); ?></label>
                <input type="date" class="form-control" id="contacted_on" name="contacted_on" value="<?php echo attr($today->format('Y-m-d')); ?>" max="<?php echo attr($today->format('Y-m-d')); ?>" required>
            </div>
            <fieldset class="form-group col-md-3">
                <legend class="col-form-label pt-0"><?php echo xlt('Who asked'); ?></legend>
                <div class="form-check"><input class="form-check-input" type="radio" name="requested_by" id="by-self" value="self" checked><label class="form-check-label" for="by-self"><?php echo xlt('The person'); ?></label></div>
                <div class="form-check"><input class="form-check-input" type="radio" name="requested_by" id="by-guardian" value="guardian"><label class="form-check-label" for="by-guardian"><?php echo xlt('A parent or guardian'); ?></label></div>
            </fieldset>
            <fieldset class="form-group col-md-3">
                <legend class="col-form-label pt-0"><?php echo xlt('Need'); ?></legend>
                <div class="form-check"><input class="form-check-input" type="radio" name="need" id="need-routine" value="routine" checked><label class="form-check-label" for="need-routine"><?php echo xlt('Routine (evaluation within 10 business days)'); ?></label></div>
                <div class="form-check"><input class="form-check-input" type="radio" name="need" id="need-urgent" value="urgent"><label class="form-check-label" for="need-urgent"><?php echo xlt('Urgent (within 1 business day)'); ?></label></div>
            </fieldset>
        </div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="screened" id="screened" value="1"><label class="form-check-label" for="screened"><?php echo xlt('Preliminary screening and risk assessment done at the contact'); ?></label></div>
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="insurance" id="insurance" value="1"><label class="form-check-label" for="insurance"><?php echo xlt('Insurance information taken'); ?></label></div>
        <div class="form-group">
            <label for="note"><?php echo xlt('Note'); ?></label>
            <input type="text" class="form-control" id="note" name="note" maxlength="255">
        </div>
        <button type="submit" class="btn btn-primary"><?php echo xlt('Log the request'); ?></button>
    </form>
    <?php } ?>

    <h2 class="h5"><?php echo xlt('Requests in the last 90 days'); ?></h2>
    <?php if ($requests === []) { ?>
        <p class="text-muted"><?php echo xlt('None logged.'); ?></p>
    <?php } else { ?>
    <div class="table-responsive">
    <table class="table table-sm table-striped">
        <thead><tr><th scope="col"><?php echo xlt('Date'); ?></th><th scope="col"><?php echo xlt('Patient'); ?></th><th scope="col"><?php echo xlt('Need'); ?></th>
            <th scope="col"><?php echo xlt('Initial evaluation'); ?></th><th scope="col" class="text-right"><?php echo xlt('Business days'); ?></th></tr></thead>
        <tbody>
        <?php foreach ($requests as $r) {
            $contact = new DateTimeImmutable((string)$r['contacted_on']);
            $evaluated = $evaluatedOn((int)$r['pid'], (string)$r['contacted_on']);
            $need = (string)$r['need'] === 'urgent' ? 'urgent' : 'routine';
            $count = $days->between($contact, $evaluated === null ? $today : new DateTimeImmutable($evaluated));
            $late = $count > RequestsSource::DUE_WITHIN[$need]; ?>
            <tr>
                <td><?php echo text(oeFormatShortDate((string)$r['contacted_on'])); ?></td>
                <td><?php echo text(trim($r['lname'] . ', ' . $r['fname'], ', ') . ' (' . $r['pubpid'] . ')'); ?></td>
                <td><?php echo $need === 'urgent' ? xlt('Urgent') : xlt('Routine'); ?></td>
                <td><?php echo $evaluated === null ? xlt('Not yet') : text(oeFormatShortDate($evaluated)); ?></td>
                <td class="text-right<?php echo $late ? ' text-danger font-weight-bold' : ''; ?>"><?php echo text((string)$count); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    </div>
    <?php } ?>
</main>
</body>
</html>
