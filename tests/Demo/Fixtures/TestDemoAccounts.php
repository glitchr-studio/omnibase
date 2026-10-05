<?php

namespace Tests\Base\Demo\Fixtures;

use Base\Demo\DemoAccount;
use Base\Demo\DemoAccountProviderInterface;

/** What a bundle of a trade declares: a member of staff, a member of the public. */
final class TestDemoAccounts implements DemoAccountProviderInterface
{
    public const STAFF = 'demo-staff@example.org';
    public const MEMBER = 'demo-member@example.org';

    public function getDemoAccounts(): iterable
    {
        yield new DemoAccount(self::STAFF, 'Staff of the practice', 'The agenda, the patients, the back office.', ['ROLE_ADMIN'], position: 1);
        yield new DemoAccount(self::MEMBER, 'Member of the public', 'Books, pays, reads their own file.', position: 2);
    }
}
