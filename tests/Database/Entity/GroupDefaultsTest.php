<?php

namespace Tests\Base\Database\Entity;

use Base\Entity\User\Group;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A group created in code is persisted as it is: its icon (NOT NULL, no
 * default in the column) has one of its own, and an emptied icon falls back
 * on it.
 */
class GroupDefaultsTest extends KernelTestCase
{
    public function testTheIconHasADefault(): void
    {
        $group = new Group();
        $this->assertSame(Group::DEFAULT_ICON, $group->getIcon());
        $this->assertSame('fa-solid fa-star', $group->setIcon('fa-solid fa-star')->getIcon());
        $this->assertSame(Group::DEFAULT_ICON, $group->setIcon(null)->getIcon());
        $this->assertSame(Group::DEFAULT_ICON, $group->setIcon(' ')->getIcon());
    }

    public function testANewGroupIsPersisted(): void
    {
        if (!class_exists('App\\Kernel')) {
            self::markTestSkipped('Requires the host application kernel (the omnibase harness, or an application\'s suite).');
        }

        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $group = (new Group())->setName('test-group-'.bin2hex(random_bytes(4)));
        $em->persist($group);
        $em->flush();
        $id = $group->getId();
        $this->assertNotNull($id);

        $em->clear();
        $found = $em->find(Group::class, $id);
        $this->assertSame(Group::DEFAULT_ICON, $found->getIcon());

        $em->remove($found);
        $em->flush();
    }
}
