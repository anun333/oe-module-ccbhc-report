<?php

/**
 * Crisis episodes: when a crisis contact came in for a person, and when a face-to-face crisis service (mobile crisis or
 * a walk-in crisis visit) began. Contacts handled on the crisis line alone are logged as such and not measured
 * (Time to Services applies "to face to face crisis services (e.g. mobile crisis) but not to crisis hotlines").
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
use OpenHealthcare\CcbhcReport\Guide;
use OpenHealthcare\CcbhcReport\Repository\Logs;
use OpenHealthcare\Common\OpenEmr;

// The same access as Bootstrap::ACL, written out: patients/med to see; write or addonly to log.
if (!AclMain::aclCheckCore('patients', 'med')) {
    OpenEmr::deny('CCBHC Report: crisis page refused', xl('Crisis Episodes'), 'CCBHC Report');
}
$canLog = AclMain::aclCheckCore('patients', 'med', '', 'write') || AclMain::aclCheckCore('patients', 'med', '', 'addonly');
$logs = new Logs();
$now = new DateTimeImmutable();
$errors = [];
$done = '';
$at = static function (string $field): ?DateTimeImmutable {
    $v = trim((string)($_POST[$field] ?? ''));
    $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $v);
    return $d === false ? null : $d;
};
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $canLog) {
    OpenEmr::requireCsrf($_POST['csrf_token_form'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'log') {
        $patient = QueryUtils::fetchRecords('SELECT `pid` FROM `patient_data` WHERE `pubpid` = ?', [trim((string)($_POST['chart'] ?? ''))]);
        $contacted = $at('contacted_at');
        $served = $at('served_at');
        $hotline = ($_POST['hotline'] ?? '') === '1';
        if (count($patient) !== 1) {
            $errors[] = xl('Enter the chart number of one patient.');
        }
        if ($contacted === null || $contacted > $now) {
            $errors[] = xl('The time of the contact must be now or earlier.');
        } elseif ($served !== null && ($served < $contacted || $served > $now)) {
            $errors[] = xl('The face-to-face service must begin after the contact, and not later than now.');
        }
        if ($hotline && $served !== null) {
            $errors[] = xl('A contact handled on the crisis line alone has no face-to-face service time.');
        }
        if ($errors === [] && $contacted !== null) {
            $pid = (int)$patient[0]['pid'];
            $id = $logs->addCrisis($pid, $contacted, $served, $hotline, trim((string)($_POST['note'] ?? '')), (int)OpenEmr::session('authUserID'));
            OpenEmr::audit('patient-record-insert', "CCBHC Report: crisis episode $id logged", $pid);
            $done = xl('The crisis episode is logged.');
        }
    } elseif ($action === 'served') {
        $served = $at('served_at');
        $id = (int)($_POST['id'] ?? 0);
        $row = QueryUtils::fetchRecords('SELECT `pid`, `contacted_at` FROM `ccbhc_crisis` WHERE `id` = ?', [$id])[0] ?? null;
        if ($row === null || $served === null || $served < new DateTimeImmutable((string)$row['contacted_at']) || $served > $now) {
            $errors[] = xl('The face-to-face service must begin after the contact, and not later than now.');
        } else {
            $logs->setServed($id, $served);
            OpenEmr::audit('patient-record-update', "CCBHC Report: crisis episode $id served", (int)$row['pid']);
            $done = xl('The service time is recorded.');
        }
    }
}
OpenEmr::audit('other', 'CCBHC Report: crisis episodes viewed');
$episodes = $logs->crises($now->modify('-90 days'));
$csrf = OpenEmr::csrfToken();
$local = fn(?string $dt): string => $dt === null ? '' : oeFormatShortDate(substr($dt, 0, 10)) . ' ' . substr($dt, 11, 5);
?>
<!DOCTYPE html>
<?php // The page is in the signed-in user's OpenEMR language, so translated text is announced in it. ?>
<html lang="<?php echo attr(OpenEmr::pageLang()); ?>" dir="<?php echo attr(OpenEmr::pageDir()); ?>">
<head>
    <meta charset="utf-8">
    <title><?php echo xlt('Crisis Episodes'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <?php echo \OpenHealthcare\CcbhcReport\Contrast::style(); ?>
</head>
<body class="body_top">
<main class="container-fluid py-3" style="max-width: 80rem">
    <h1 class="h4"><?php echo xlt('Crisis Episodes'); ?></h1>
    <p class="small mb-2"><a href="<?php echo attr(Guide::url()); ?>" target="_blank" rel="noopener"><?php echo xlt('Help'); ?><span class="sr-only"> <?php echo xlt('(opens in a new window)'); ?></span></a></p>
    <p><?php echo xlt('Log each crisis contact and when face-to-face crisis care began. A new episode starts with a contact more than 24 hours after the last one for the same person. Contacts handled on the crisis line alone are logged but not measured.'); ?>
        <a href="index.php" onclick="top.restoreSession()"><?php echo xlt('Back to the report'); ?></a></p>
    <?php foreach ($errors as $e) { ?>
        <div class="alert alert-danger py-2"><?php echo text($e); ?></div>
    <?php } ?>
    <?php if ($done !== '') { ?>
        <div class="alert alert-success py-2"><?php echo text($done); ?></div>
    <?php } ?>
    <?php if ($canLog) { ?>
    <form method="post" class="border rounded p-3 mb-4">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
        <input type="hidden" name="action" value="log">
        <div class="form-row">
            <div class="form-group col-md-3">
                <label for="chart"><?php echo xlt('Chart number'); ?></label>
                <input type="text" class="form-control" id="chart" name="chart" required>
            </div>
            <div class="form-group col-md-3">
                <label for="contacted_at"><?php echo xlt('Contact came in'); ?></label>
                <input type="datetime-local" class="form-control" id="contacted_at" name="contacted_at" required>
            </div>
            <div class="form-group col-md-3">
                <label for="served_at"><?php echo xlt('Face-to-face care began (if it has)'); ?></label>
                <input type="datetime-local" class="form-control" id="served_at" name="served_at">
            </div>
        </div>
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="hotline" id="hotline" value="1"><label class="form-check-label" for="hotline"><?php echo xlt('Handled on the crisis line alone'); ?></label></div>
        <div class="form-group">
            <label for="note"><?php echo xlt('Note'); ?></label>
            <input type="text" class="form-control" id="note" name="note" maxlength="255">
        </div>
        <button type="submit" class="btn btn-primary"><?php echo xlt('Log the episode'); ?></button>
    </form>
    <?php } ?>
    <h2 class="h5"><?php echo xlt('Episodes in the last 90 days'); ?></h2>
    <?php if ($episodes === []) { ?>
        <p class="text-muted"><?php echo xlt('None logged.'); ?></p>
    <?php } else { ?>
    <div class="table-responsive">
    <table class="table table-sm table-striped">
        <thead><tr><th scope="col"><?php echo xlt('Contact'); ?></th><th scope="col"><?php echo xlt('Patient'); ?></th><th scope="col"><?php echo xlt('Face-to-face care began'); ?></th><th scope="col"><?php echo xlt('Note'); ?></th></tr></thead>
        <tbody>
        <?php foreach ($episodes as $e) { ?>
            <tr>
                <td><?php echo text($local((string)$e['contacted_at'])); ?></td>
                <td><?php echo text(trim($e['lname'] . ', ' . $e['fname'], ', ') . ' (' . $e['pubpid'] . ')'); ?></td>
                <td>
                    <?php if ((int)$e['hotline_only'] === 1) { ?>
                        <?php echo xlt('Crisis line only'); ?>
                    <?php } elseif ($e['served_at'] !== null) { ?>
                        <?php echo text($local((string)$e['served_at'])); ?>
                    <?php } elseif ($canLog) { ?>
                        <form method="post" class="form-inline">
                            <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
                            <input type="hidden" name="action" value="served">
                            <input type="hidden" name="id" value="<?php echo attr((string)$e['id']); ?>">
                            <label class="sr-only" for="served-<?php echo attr((string)$e['id']); ?>"><?php echo xlt('Face-to-face care began'); ?></label>
                            <input type="datetime-local" class="form-control form-control-sm mr-1" id="served-<?php echo attr((string)$e['id']); ?>" name="served_at" required>
                            <button type="submit" class="btn btn-sm btn-outline-primary"><?php echo xlt('Record'); ?></button>
                        </form>
                    <?php } else { ?>
                        <?php echo xlt('Not yet'); ?>
                    <?php } ?>
                </td>
                <td><?php echo text((string)$e['note']); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    </div>
    <?php } ?>
</main>
</body>
</html>
