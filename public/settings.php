<?php

/**
 * CCBHC Report settings, for administrators: the visit categories that are Initial Evaluations and Initial Clinical
 * Services, the state holidays business days leave out, and how each insurance company counts for the payer strata.
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
use OpenHealthcare\CcbhcReport\Domain\BusinessDays;
use OpenHealthcare\CcbhcReport\Domain\Coverage;
use OpenHealthcare\CcbhcReport\Repository\Settings;
use OpenHealthcare\Common\OpenEmr;

if (!AclMain::aclCheckCore('admin', 'super')) {
    OpenEmr::deny('CCBHC Report: settings refused', xl('CCBHC Report settings'), 'CCBHC Report');
}
$settings = new Settings();
$saved = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    OpenEmr::requireCsrf($_POST['csrf_token_form'] ?? '');
    $user = (int)OpenEmr::session('authUserID');
    $settings->save(Settings::EVALUATION_CATEGORIES, array_map('intval', (array)($_POST['evaluation'] ?? [])), $user);
    $settings->save(Settings::SERVICE_CATEGORIES, array_map('intval', (array)($_POST['service'] ?? [])), $user);
    preg_match_all('/\d{4}-\d{2}-\d{2}/', (string)($_POST['holidays'] ?? ''), $m);
    $settings->save(Settings::STATE_HOLIDAYS, array_values(array_unique($m[0])), $user);
    $kinds = [];
    foreach ((array)($_POST['kind'] ?? []) as $id => $kind) {
        if (in_array($kind, Coverage::COMPANY_KINDS, true)) {
            $kinds[(int)$id] = $kind;
        }
    }
    $settings->save(Settings::INSURANCE_KINDS, $kinds, $user);
    OpenEmr::audit('other', 'CCBHC Report: settings saved');
    $saved = true;
    $settings = new Settings();
}
$categories = QueryUtils::fetchRecords('SELECT `pc_catid`, `pc_catname` FROM `openemr_postcalendar_categories` WHERE `pc_cattype` = 0 ORDER BY `pc_catname`');
$companies = QueryUtils::fetchRecords('SELECT `id`, `name`, `cqm_sop`, `ins_type_code` FROM `insurance_companies` ORDER BY `name`');
$evaluation = array_flip($settings->evaluationCategories());
$service = array_flip($settings->serviceCategories());
$choices = $settings->insuranceKinds();
$year = (int)date('Y');
$kindLabel = [
    Coverage::MEDICAID => xl('Medicaid'), Coverage::CHIP_19 => xl('CHIP under Medicaid (Title 19)'), Coverage::CHIP_21 => xl('Separate CHIP (Title 21)'),
    Coverage::MEDICARE => xl('Medicare'), Coverage::MILITARY => xl('VHA or TRICARE'), Coverage::COMMERCIAL => xl('Commercial'), Coverage::OTHER => xl('Other'),
];
$csrf = OpenEmr::csrfToken();
?>
<!DOCTYPE html>
<?php // The module's own text is written in English. ?>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo xlt('CCBHC Report settings'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <?php echo \OpenHealthcare\CcbhcReport\Contrast::style(); ?>
</head>
<body class="body_top">
<main class="container-fluid py-3" style="max-width: 70rem">
    <h1 class="h4"><?php echo xlt('CCBHC Report settings'); ?></h1>
    <p><a href="index.php" onclick="top.restoreSession()"><?php echo xlt('Back to the report'); ?></a></p>
    <?php if ($saved) { ?>
        <div class="alert alert-success py-2"><?php echo xlt('Saved.'); ?></div>
    <?php } ?>
    <form method="post">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
        <div class="row">
            <fieldset class="col-md-6">
                <legend class="h6"><?php echo xlt('Visit categories that are an Initial Evaluation'); ?></legend>
                <p class="small text-muted"><?php echo xlt('A visit billed 90791 or 90792 counts as one too.'); ?></p>
                <?php foreach ($categories as $c) { ?>
                    <div class="form-check"><label class="form-check-label">
                        <input class="form-check-input" type="checkbox" name="evaluation[]" value="<?php echo attr((string)$c['pc_catid']); ?>"<?php echo isset($evaluation[(int)$c['pc_catid']]) ? ' checked' : ''; ?>>
                        <?php echo text(xl_appt_category((string)$c['pc_catname'])); ?></label></div>
                <?php } ?>
            </fieldset>
            <fieldset class="col-md-6">
                <legend class="h6"><?php echo xlt('Visit categories that are an Initial Clinical Service'); ?></legend>
                <p class="small text-muted"><?php echo xlt('The first clinical service after, or at, the evaluation: therapy, medication management, case management.'); ?></p>
                <?php foreach ($categories as $c) { ?>
                    <div class="form-check"><label class="form-check-label">
                        <input class="form-check-input" type="checkbox" name="service[]" value="<?php echo attr((string)$c['pc_catid']); ?>"<?php echo isset($service[(int)$c['pc_catid']]) ? ' checked' : ''; ?>>
                        <?php echo text(xl_appt_category((string)$c['pc_catname'])); ?></label></div>
                <?php } ?>
            </fieldset>
        </div>
        <div class="form-group mt-3">
            <label for="holidays"><?php echo xlt('State holidays (one date per line, as YYYY-MM-DD)'); ?></label>
            <textarea class="form-control" id="holidays" name="holidays" rows="4" style="max-width: 20rem"><?php echo text(implode("\n", $settings->stateHolidays())); ?></textarea>
            <small class="form-text text-muted"><?php echo text(sprintf(xl('Federal holidays are already left out, on their observed days. This year: %s.'), implode(', ', BusinessDays::federal($year)))); ?></small>
        </div>
        <h2 class="h5 mt-4"><?php echo xlt('Insurance companies'); ?></h2>
        <p class="small"><?php echo xlt('The payer strata put Medicaid, with CHIP under Medicaid (Title 19), apart from everyone else, dual eligibles included. A company is read from its payment type when it has one, else its claim type. Choose here where that is wrong or unclear, for example a CHIP plan.'); ?></p>
        <table class="table table-sm" style="max-width: 50rem">
            <thead><tr><th scope="col"><?php echo xlt('Company'); ?></th><th scope="col"><?php echo xlt('Read as'); ?></th><th scope="col"><?php echo xlt('Your choice'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($companies as $co) {
                $read = Coverage::companyKind(null, (string)($co['cqm_sop'] ?? ''), $co['ins_type_code'] === null ? null : (int)$co['ins_type_code']); ?>
                <tr><td><?php echo text((string)$co['name']); ?></td><td><?php echo text($kindLabel[$read]); ?></td>
                    <td><label class="sr-only" for="kind-<?php echo attr((string)$co['id']); ?>"><?php echo xlt('Your choice'); ?></label>
                        <select class="form-control form-control-sm" id="kind-<?php echo attr((string)$co['id']); ?>" name="kind[<?php echo attr((string)$co['id']); ?>]">
                            <option value=""><?php echo xlt('As read'); ?></option>
                            <?php foreach ($kindLabel as $k => $label) { ?>
                                <option value="<?php echo attr($k); ?>"<?php echo ($choices[(int)$co['id']] ?? '') === $k ? ' selected' : ''; ?>><?php echo text($label); ?></option>
                            <?php } ?>
                        </select></td></tr>
            <?php } ?>
            </tbody>
        </table>
        <button type="submit" class="btn btn-primary"><?php echo xlt('Save'); ?></button>
    </form>
</main>
</body>
</html>
