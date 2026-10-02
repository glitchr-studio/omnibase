<?php

namespace App\Controller\Admin;

use Base\Admin\Config\MenuItem;
use Base\Admin\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The back office's front door (omnibase/admin): /admin, with every
 * plugin's screens in the menu. An application writes this class itself,
 * with its own order and words; the CRUDs behind the links are the
 * plugins'.
 */
class DashboardController extends AbstractDashboardController
{
    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        return parent::index();
    }

    public function configureWidgetItems(): iterable
    {
        yield MenuItem::section('The shop', 'fa-solid fa-store')->setSubItems([
            MenuItem::linkToCrud(\Base\Marketplace\Entity\Store::class, 'Stores', 'fa-solid fa-shop'),
            MenuItem::linkToCrud(\Base\Marketplace\Entity\Product::class, 'Products', 'fa-solid fa-box'),
            MenuItem::linkToCrud(\Base\Marketplace\Entity\Order::class, 'Orders', 'fa-solid fa-receipt'),
            MenuItem::linkToCrud(\Base\Marketplace\Entity\Order\Method\PaymentMethod::class, 'Payment methods', 'fa-solid fa-credit-card'),
            MenuItem::linkToCrud(\Base\Marketplace\Entity\Order\Method\ShippingMethod::class, 'Shipping methods', 'fa-solid fa-truck'),
        ]);
        yield MenuItem::section('The forge', 'fa-solid fa-hammer')->setSubItems([
            MenuItem::linkToCrud(\Base\Forge\Entity\Software::class, 'Software', 'fa-solid fa-cube'),
            MenuItem::linkToCrud(\Base\Forge\Entity\License::class, 'Licences', 'fa-solid fa-key'),
            MenuItem::linkToCrud(\Base\Forge\Entity\Project::class, 'Projects', 'fa-solid fa-diagram-project'),
            MenuItem::linkToCrud(\Base\Forge\Entity\Quote::class, 'Quotes', 'fa-solid fa-file-invoice'),
        ]);
        yield MenuItem::section('The forum', 'fa-solid fa-comments')->setSubItems([
            MenuItem::linkToCrud(\Base\Forum\Entity\Category::class, 'Categories', 'fa-solid fa-folder'),
            MenuItem::linkToCrud(\Base\Forum\Entity\Topic::class, 'Topics', 'fa-solid fa-comment'),
        ]);
        yield MenuItem::section('Members and the site', 'fa-solid fa-users')->setSubItems([
            MenuItem::linkToCrud(\Base\Entity\User::class, 'Accounts', 'fa-solid fa-user'),
            MenuItem::linkToCrud(\Base\Entity\Layout\Setting::class, 'Settings', 'fa-solid fa-sliders'),
            MenuItem::linkToRoute('admin_trash', [], 'Trash', 'fa-solid fa-trash-can'),
        ]);
    }
}
