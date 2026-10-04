<?php

namespace Tests\Base\Field;

use Base\Entity\Thread;
use Base\Entity\User\PushSubscription;
use Base\Field\Type\SelectType;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * SelectType on an entity whose repository is a plain Doctrine one
 * (`extends Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository`,
 * what make:entity writes): the field asked the repository for cacheById() /
 * cacheOneById(), which only omnibase's repositories answer, and the form
 * died on submission ("Undefined method cacheOneById").
 *
 * Driven on the application's User with its repository swapped for Doctrine's
 * own EntityRepository, the time of a test.
 */
class SelectTypePlainRepositoryTest extends KernelTestCase
{
    private ?SelectType $type = null;
    private ?EntityManagerInterface $original = null;
    /** @var object[] */
    private array $users = [];

    protected function setUp(): void
    {
        if (!class_exists('App\\Kernel') || !class_exists('App\\Entity\\User')) {
            self::markTestSkipped('Requires a host application (the omnibase harness).');
        }

        self::bootKernel();
        $request = Request::create('/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        static::getContainer()->get('request_stack')->push($request);

        $em = static::getContainer()->get('doctrine')->getManager();
        foreach (['one', 'two'] as $n) {
            $user = new \App\Entity\User();
            $name = 'plain'.$n.bin2hex(random_bytes(4));
            if (method_exists($user, 'setUsername')) {
                $user->setUsername($name);
            }
            $user->setEmail($name.'@example.org');
            $user->setPlainPassword('test-'.$name);
            $em->persist($user);
            $this->users[] = $user;
        }
        $em->flush();

        // The SelectType service, its entity manager answering a plain repository for the user class.
        $this->type = static::getContainer()->get('form.registry')->getType(SelectType::class)->getInnerType();
        $property = new \ReflectionProperty(SelectType::class, 'entityManager');
        $this->original = $property->getValue($this->type);
        $property->setValue($this->type, new class($this->original) extends EntityManagerDecorator {
            public function getRepository(string $className): EntityRepository
            {
                if (is_a($className, 'App\\Entity\\User', true)) {
                    return new EntityRepository($this->wrapped, $this->wrapped->getClassMetadata($className));
                }

                return $this->wrapped->getRepository($className);
            }
        });
    }

    protected function tearDown(): void
    {
        if ($this->type) {
            (new \ReflectionProperty(SelectType::class, 'entityManager'))->setValue($this->type, $this->original);
            $em = static::getContainer()->get('doctrine')->getManager();
            foreach ($this->users as $user) {
                if ($managed = $em->find($user::class, $user->getId())) {
                    $em->remove($managed);
                }
            }
            $em->flush();
        }
        $this->users = [];
        $this->type = null;

        parent::tearDown();
    }

    private function form(object $data, string $field, array $options = []): FormInterface
    {
        return static::getContainer()->get('form.factory')
            ->createNamedBuilder('record', FormType::class, $data, ['data_class' => $data::class, 'csrf_protection' => false, 'spam_protection' => false])
            ->add($field, SelectType::class, $options)
            ->getForm();
    }

    public function testOneEntityIsChosen(): void
    {
        $subscription = new PushSubscription();
        $form = $this->form($subscription, 'user', ['class' => $this->users[0]::class]);
        $form->submit(['user' => ['choice' => (string) $this->users[1]->getId()]]);

        $this->assertTrue($form->get('user')->isSynchronized(), (string) $form->getErrors(true));
        $this->assertSame($this->users[1]->getId(), $subscription->getUser()?->getId());
    }

    public function testTheChosenEntityIsShownAgain(): void
    {
        $subscription = (new PushSubscription())->setUser($this->users[0]);
        $view = $this->form($subscription, 'user', ['class' => $this->users[0]::class])->createView()['user'];

        $this->assertSame([$this->users[0]->getId()], $view->vars['data']);
    }

    public function testSeveralEntitiesAreChosenInTheOrderGiven(): void
    {
        $thread = new Thread();
        $form = $this->form($thread, 'owners', ['class' => $this->users[0]::class, 'multiple' => true]);
        $form->submit(['owners' => ['choice' => [(string) $this->users[1]->getId(), (string) $this->users[0]->getId()]]]);

        $this->assertTrue($form->get('owners')->isSynchronized(), (string) $form->getErrors(true));
        $this->assertSame(
            [$this->users[1]->getId(), $this->users[0]->getId()],
            array_values(array_map(fn ($u) => $u->getId(), $thread->getOwners()->toArray()))
        );

        // And shown again, from the record.
        $view = $this->form($thread, 'owners', ['class' => $this->users[0]::class, 'multiple' => true])->createView()['owners'];
        $this->assertSame([$this->users[1]->getId(), $this->users[0]->getId()], array_values($view->vars['data']));
    }
}
