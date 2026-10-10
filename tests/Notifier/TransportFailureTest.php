<?php

namespace Tests\Base\Notifier;

use Base\Notifier\Recipient\Recipient;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;
use Tests\Base\Http\HttpTestTrait;

/**
 * A message that cannot leave never brings down the page that sent it: a
 * shop's payment page answered 500 when its mail server's host was unknown
 * ("Connection could not be established with host smtp-relay.sendinblue.com").
 * The failure of the transport - thrown as it is, or wrapped by Messenger
 * when the message is handled at once (sync://) - is said in the log; any
 * other fault still surfaces.
 */
class TransportFailureTest extends KernelTestCase
{
    use HttpTestTrait;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->bootHost();
    }

    private function notifierFailingWith(\Throwable $failure): object
    {
        $failing = new class($failure) implements NotifierInterface {
            public function __construct(private readonly \Throwable $failure)
            {
            }

            public function send(Notification $notification, \Symfony\Component\Notifier\Recipient\RecipientInterface ...$recipients): void
            {
                throw $this->failure;
            }
        };
        $notifier = static::getContainer()->get('base.notifier');
        // the transport behind it, failing as a mail server out of reach does
        (new \ReflectionProperty(\Base\Notifier\Abstract\BaseNotifier::class, 'notifier'))->setValue($notifier, $failing);
        $logged = &$this->logged;
        $notifier->setLogger(new class($logged) extends AbstractLogger {
            public function __construct(private array &$logged)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = $level.': '.strtr((string) $message, ['{subject}' => (string) ($context['subject'] ?? ''), '{error}' => (string) ($context['error'] ?? '')]);
            }
        });

        return $notifier;
    }

    public function testAnUnreachableMailServerIsLoggedNotThrown(): void
    {
        $unreachable = new TransportException('Connection could not be established with host "smtp-relay.sendinblue.com:587": getaddrinfo failed: Name or service not known');

        foreach ([$unreachable, new HandlerFailedException(new Envelope(new \stdClass()), [$unreachable])] as $failure) {
            $this->logged = [];
            $this->notifierFailingWith($failure)->send(new Notification('Votre commande est payée', ['email']), new Recipient('client@example.org'));

            $this->assertCount(1, $this->logged, $failure::class);
            $this->assertStringStartsWith('error: The notification "Votre commande est payée" could not be sent', $this->logged[0]);
            $this->assertStringContainsString('smtp-relay.sendinblue.com', $this->logged[0]);
        }
    }

    public function testAnyOtherFaultStillSurfaces(): void
    {
        $bug = new \LogicException('A template that does not render.');
        $notifier = $this->notifierFailingWith(new HandlerFailedException(new Envelope(new \stdClass()), [$bug]));

        $this->expectException(HandlerFailedException::class);
        $notifier->send(new Notification('Votre commande est payée', ['email']), new Recipient('client@example.org'));
    }
}
