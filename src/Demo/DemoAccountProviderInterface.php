<?php

namespace Base\Demo;

/**
 * Declares demonstration accounts: implemented by a bundle for the roles of
 * its trade (a practitioner, a secretary, a patient) and by the application
 * for its own. Autoconfigured (tag base.demo_account_provider); the
 * application's declaration replaces a bundle's for the same identifier.
 *
 *     final class ShopDemoAccounts implements DemoAccountProviderInterface
 *     {
 *         public function getDemoAccounts(): iterable
 *         {
 *             yield new DemoAccount('client', 'demo.client.label', 'demo.client.description');
 *         }
 *     }
 */
interface DemoAccountProviderInterface
{
    /** @return iterable<DemoAccount> */
    public function getDemoAccounts(): iterable;
}
