<?php

/**
 * What CCBHC Report adds to OpenEMR: menu entries for the report (patients/med) and for logging requests for services
 * (patients/demo, the front desk), and a "Requests for services" tab in the Staff Worklist.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CcbhcReport;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Menu\MenuEvent;
use OpenHealthcare\CcbhcReport\Worklist\RequestsSource;
use OpenHealthcare\Common\Contract\Worklist\Worklist;
use OpenHealthcare\Common\Contract\Worklist\WorkSource;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class Bootstrap
{
    public const MODULE_INSTALLATION_PATH = '/interface/modules/custom_modules/oe-module-ccbhc-report';
    /** The access a user needs for the report and the crisis log: section and value, as AclMain checks them. */
    public const ACL = ['patients', 'med'];
    /** The access for logging requests for services, which the front desk takes. */
    public const REQUEST_ACL = ['patients', 'demo'];

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addMenuItem(...));
        $this->eventDispatcher->addListener('staff_worklist.collect_sources', $this->addWorklistTab(...));
    }

    public function addMenuItem(MenuEvent $event): MenuEvent
    {
        $item = new \stdClass();
        $item->requirement = 0;
        $item->target = 'ccbhcr';
        $item->menu_id = 'ccbhcr0';
        $item->label = xlt('CCBHC Report');
        $item->url = self::MODULE_INSTALLATION_PATH . '/public/index.php';
        $item->children = [];
        $item->acl_req = self::ACL;
        $item->global_req = [];
        // After Messages, or at the end.
        $requests = new \stdClass();
        $requests->requirement = 0;
        $requests->target = 'ccbhcq';
        $requests->menu_id = 'ccbhcq0';
        $requests->label = xlt('Requests for Services');
        $requests->url = self::MODULE_INSTALLATION_PATH . '/public/requests.php';
        $requests->children = [];
        $requests->acl_req = self::REQUEST_ACL;
        $requests->global_req = [];
        $menu = $event->getMenu();
        $position = count($menu);
        foreach ($menu as $i => $entry) {
            if (($entry->menu_id ?? '') === 'msg0') {
                $position = (int)$i + 1;
                break;
            }
        }
        array_splice($menu, $position, 0, [$item, $requests]);
        $event->setMenu($menu);
        return $event;
    }

    /**
     * "Requests for services" in the Staff Worklist (0.8.0 or later), for whoever logs them (the front desk). The tab is
     * built on openhealthcare-common's contract; where a module copied in by hand loaded an older copy of the library
     * first, the contract isn't there and the tab is left out rather than stop the page.
     */
    public function addWorklistTab(object $event): void
    {
        if (!interface_exists(WorkSource::class) || !AclMain::aclCheckCore('patients', 'demo')) {
            return;
        }
        Worklist::contribute($event, new RequestsSource(Worklist::now($event)), xl('Requests for services'), xl('Front desk'));
    }
}
