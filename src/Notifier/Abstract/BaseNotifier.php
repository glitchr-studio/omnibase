<?php

namespace Base\Notifier\Abstract;

use Base\Entity\User;
use BadMethodCallException;
use Base\Entity\User\Notification;
use Base\Notifier\Recipient\Recipient;
use Base\Notifier\Recipient\LocaleRecipientInterface;
use Base\Notifier\Recipient\TimezoneRecipientInterface;
use Base\Routing\AdvancedRouterInterface;
use Base\Service\BaseService;
use Base\Service\SettingBagInterface;
use DateTime;
use Doctrine\DBAL\Exception as DoctrineException;
use Base\Service\SettingBag;
use Base\Service\LocalizerInterface;
use Base\Service\ParameterBagInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface as MailerTransportException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface as NotifierTransportException;
use Symfony\Component\Notifier\Notification\Notification as SymfonyNotification;
use Symfony\Component\Notifier\Notifier as SymfonyNotifier;
use Symfony\Component\Notifier\NotifierInterface as SymfonyNotifierInterface;
use Twig\Environment;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\ORM\Cache\Exception\NonCacheableEntity;
use Doctrine\ORM\Mapping\MappingException;
use Exception;
use Symfony\Component\Notifier\Channel\ChannelPolicyInterface;

use Symfony\Component\Notifier\Recipient\EmailRecipientInterface;
use Symfony\Component\Notifier\Recipient\NoRecipient;
use Symfony\Component\Notifier\Recipient\RecipientInterface;
use Symfony\Component\Notifier\Recipient\SmsRecipientInterface;

use Symfony\Component\PropertyAccess\Exception\AccessException;
use Symfony\Contracts\Translation\TranslatorInterface;

abstract class BaseNotifier implements BaseNotifierInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @param $method
     * @param $arguments
     * @return mixed
     */
    public function __call($method, $arguments): mixed
    {
        $action = str_starts_with($method, "render") ? "render" : null;
        $action ??= str_starts_with($method, "sendAdmins") ? "sendAdmins" : null;
        $action ??= str_starts_with($method, "send") ? "send" : null;
        if (!$action) {
            throw new AccessException("Unexpected action received. Templated notification \"$method\" should starts with either  \"send\", \"sendAdmins\", or \"render\".");
        }

        $method = lcfirst(substr($method, strlen($action)));
        if (!method_exists($this::class, $method)) {
            throw new AccessException("Templated notification \"$method\" not found in class \"" . get_class($this) . "\".");
        }
        if (str_starts_with($method, "admin")) {
            throw new AccessException("Templated notification \"" . $this::class . "::$method\" starts with \"admins\". This is a reserved word in \"" . self::class . "\"");
        }

        $notification = $this->$method(...$arguments);
        if ($notification == null) {
            return $this;
        }
        if (!$notification instanceof Notification) {
            throw new AccessException("Templated notification \"" . $this::class . "::$method\" must return a \"" . Notification::class . "\" object.");
        }


        $arguments = [];
        if ($action == "send") {
            $arguments = [$notification->getImportance()];
        }
        if ($action == "sendAdmins") {
            $arguments = [$notification->getImportance()];
        }

        return $notification->$action(...$arguments);
    }

    /**
     * @var SymfonyNotifierInterface
     */
    protected SymfonyNotifierInterface $notifier;

    /**
     * @var Environment
     */
    protected Environment $twig;

    /**
     * @var ChannelPolicyInterface
     */
    protected ChannelPolicyInterface $policy;

    /**
     * @var EntityManagerInterface
     */
    protected EntityManagerInterface $entityManager;

    /**
     * @var ParameterBagInterface
     */
    protected ParameterBagInterface $parameterBag;

    /**
     * @var AdvancedRouterInterface
     */
    protected AdvancedRouterInterface $router;

    /**
     * @var LocalizerInterface
     */
    protected LocalizerInterface $localizer;

    /**
     * @var SettingBagInterface
     */
    protected SettingBagInterface $settingBag;

    /**
     * @var TranslatorInterface
     */
    protected TranslatorInterface $translator;

    protected bool $debug;

    protected ?string $mailer;

    /** * @var ?RecipientInterface */
    protected $technicalRecipient;
    /** * @var bool */
    protected ?bool $technicalLoopback;
    /** * @var RecipientInterface */
    protected RecipientInterface $testRecipient;

    /**
     * @var array
     */
    protected array $options;

    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @var bool
     */
    protected bool $useMailer;

    /**
     * @var array
     */
    protected array $testRecipients;

    /**
     * @var array
     */
    protected array $adminRecipients;

    public function getTestRecipients(): array
    {
        return $this->testRecipients;
    }

    public function getEnvironment(): Environment
    {
        return $this->twig;
    }

    public function __construct(SymfonyNotifierInterface $notifier, ChannelPolicyInterface $policy, EntityManagerInterface $entityManager, ParameterBagInterface $parameterBag, TranslatorInterface $translator, LocalizerInterface $localizer, AdvancedRouterInterface $router, Environment $twig, SettingBag $settingBag, bool $debug = false)
    {
        $this->twig = $twig;
        $this->notifier = $notifier;

        $this->policy = $policy;
        $this->router = $router;

        $this->useMailer = $parameterBag->get("base.notifier.mailer") && class_exists(Mailer::class);
        $this->adminRole = $parameterBag->get("base.notifier.admin_role");
        $this->options = $parameterBag->get("base.notifier.options") ?? [];

        $this->testRecipients = array_map(fn($r) => new Recipient($r), $parameterBag->get("base.notifier.test_recipients"));

        $technicalEmail = $parameterBag->get("base.notifier.technical_recipient.email") ?? "postmaster@" . $this->router->getDomain();
        $technicalPhone = $parameterBag->get("base.notifier.technical_recipient.phone");
        $this->technicalRecipient = ($technicalEmail || $technicalPhone) ? new Recipient($technicalEmail, $technicalPhone) : null;
        $this->technicalLoopback  = $parameterBag->get("base.notifier.technical_loopback");

        $this->entityManager = $entityManager;
        $this->settingBag = $settingBag;
        $this->localizer = $localizer;
        $this->translator = $translator;

        $this->debug = $debug;

        $this->adminRecipients = [];
    }

    public function getPolicy(): ChannelPolicyInterface
    {
        return $this->policy;
    }

    public function hasLoopback(): bool
    {
        return $this->technicalLoopback;
    }

    public function isTest(Recipient $recipient): bool
    {
        if ($this->technicalLoopback) {
            return true;
        }
        
        if (!$this->debug) {
            return false;
        }

        if (!$recipient instanceof EmailRecipientInterface) {
            return false;
        }

        $email = array_keys(mailparse($recipient->getEmail()));
        foreach ($this->testRecipients as $testRecipient) {
            if (preg_match('/' . $testRecipient->getEmail() . '/', begin($email))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @var string
     */
    protected string $adminRole;

    /**
     * @param $i
     * @return RecipientInterface|null
     */
    public function getAdminRecipient($i = 0): ?RecipientInterface
    {
        $this->initializeAdminRecipients();

        return $this->notifier instanceof SymfonyNotifier ? $this->notifier->getAdminRecipients()[$i] ?? null : null;
    }

    public function getAdminRecipients(): array
    {
        $this->initializeAdminRecipients();
        return $this->notifier instanceof SymfonyNotifier ? $this->notifier->getAdminRecipients() : null;
    }

    /**
     * @return $this|array
     */
    protected function initializeAdminRecipients()
    {
        if (!$this->adminRole) {
            return [];
        }
        if ($this->adminRecipients) {
            return $this;
        }

        foreach (array_filter($this->getAdminUsers()) as $adminUser) {
            $this->adminRecipients[] = $adminUser->getRecipient();
        }

        foreach (array_unique_map(fn($r) => $r->getEmail(), $this->adminRecipients) as $adminRecipient) {
            $this->notifier->addAdminRecipient($adminRecipient);
        }

        return $this;
    }

    /**
     * @return array
     */
    protected function getAdminUsers()
    {
        try {
            $userRepository = $this->entityManager->getRepository(User::class);
            $adminUsers = $userRepository->cacheByRoles($this->adminRole)->getResult();
        } catch (MappingException|NonCacheableEntity|BadMethodCallException|DoctrineException|DriverException|InvalidFieldNameException|TableNotFoundException $e) {
            $adminUsers = [];
        }

        return $adminUsers;
    }

    public function getTechnicalRecipient(): Recipient
    {
        $defaultMail = mailparse($this->technicalRecipient->getEmail());

        try {
            $mail = $this->settingBag->getScalar("base.settings.mail");
        } catch (Exception $e) {
            $mail = null;
        }

        if (!$mail && $this->getAdminRecipient() instanceof EmailRecipientInterface) {
            $mail = $this->getAdminRecipient()?->getEmail();
        }
        if (!$mail) {
            $mail = array_keys($defaultMail)[0] ?? null;
        }
        if (!$mail) {
            return new NoRecipient();
        }


        $mail = trim(explode("<", $mail)[1] ?? $mail, ">");
        try {
            $mailName = $this->settingBag->getScalar("base.settings.mail.name");
        } catch (Exception $e) {
            $mailName = null;
        }

        if(!$mailName) {
            $mailName = trim(explode("<", $mail)[0]);
        }
        if(!$mailName) {
            $mailName = first($defaultMail) ?? null;
        }
        if(!$mailName) {
            $mailName = trim(mb_ucwords(
                str_replace(
                    [".", "_"],
                    [" ", " "],
                    explode("@", $mail)[0]
                )
            ));
        }

        try {
            $phone = $this->settingBag->getScalar("base.settings.phone");
        } catch (Exception $e) {
            $phone = null;
        }

        if (!$phone && $this->getAdminRecipient() instanceof SmsRecipientInterface) {
            $phone = $this->getAdminRecipient()?->getPhone();
        }
        if (!$phone) {
            $phone = $this->technicalRecipient->getPhone();
        }

        return new Recipient($mailName . " <" . $mail . ">", $phone);
    }

    /**
     * @var boolean
     */
    protected bool $markAsAdmin;

    /**
     * @return bool
     */
    public function isMarkAsAdmin()
    {
        return $this->markAsAdmin;
    }

    /**
     * @param bool $markAsAdmin
     * @param Notification|null $notification
     * @return $this
     */
    public function markAsAdmin(bool $markAsAdmin, ?Notification $notification = null)
    {
        $this->markAsAdmin = $markAsAdmin;

        $notification?->markAsAdmin();

        return $this;
    }

    /**
     * @var bool
     */
    protected bool $enable = true;

    /**
     * @return $this|mixed
     */
    public function enable()
    {
        $this->enable = true;
        return $this;
    }

    /**
     * @return $this|mixed
     */
    public function disable()
    {
        $this->enable = false;
        return $this;
    }

    public function getTranslator(): TranslatorInterface
    {
        return $this->translator;
    }

    /**
     * @param TranslatorInterface $translator
     * @return $this
     */
    public function setTranslator(TranslatorInterface $translator)
    {
        $this->translator = $translator;
        return $this;
    }

    public function getRouter(): AdvancedRouterInterface
    {
        return $this->router;
    }

    /**
     * @param AdvancedRouterInterface $router
     * @return $this
     */
    public function setRouter(AdvancedRouterInterface $router)
    {
        $this->router = $router;
        return $this;
    }

    /**
     * @param string $importance
     * @return string[]
     */
    public function getDefaultChannels(string $importance)
    {
        return $this->getPolicy()->getChannels($importance);
    }

    /**
     * @param $importance
     * @param RecipientInterface $recipient
     * @return array
     */
    protected function getUserChannels($importance, RecipientInterface $recipient): array
    {
        $channels = [];
        foreach ($this->getDefaultChannels($importance) as $channel) {
            // Replace email by email+ for user..
            // Users should not receive the default admin email sent by Symfony notifier
            if (str_starts_with($channel, "email")) {
                $channel = "email+";
            }

            // If no recipient, only browser notification is allowed to be sent.
            if ($recipient instanceof NoRecipient && !str_starts_with($channel, "browser")) {
                continue;
            } // If recipient implement SMS interface, check if sms is allowed and phone number available
            elseif (str_starts_with($channel, "sms")) {
                if ($recipient instanceof SmsRecipientInterface && empty($recipient->getPhone())) {
                    continue;
                }
            } // If recipient implement Email interface, check if email is available
            elseif (str_starts_with($channel, "email")) {
                if (!$this->useMailer) {
                    continue;
                }
                if ($recipient instanceof EmailRecipientInterface && empty($recipient->getEmail())) {
                    continue;
                }
            } // Only admin can receive chat message..
            elseif (str_starts_with($channel, "chat/")) {
                continue;
            }

            $channels[] = $channel;
        }

        return array_unique($channels);
    }

    /**
     * @param $importance
     * @param RecipientInterface $recipient
     * @return array
     */
    protected function getAdminChannels($importance, RecipientInterface $recipient): array
    {
        $channels = [];
        foreach ($this->getDefaultChannels($importance) as $channel) {
            // Replace email by email+ for user..
            // Users should not receive the default admin email sent by Symfony notifier
            if (str_starts_with($channel, "email")) {
                $channel = "email+";
            } // I suppose admin should receive notification by email when user is browser notified.
            elseif (str_starts_with($channel, "browser")) {
                $channel = "email+";
            } // If no recipient, only browser notification is allowed to be sent.
            elseif ($recipient instanceof NoRecipient) {
                continue;
            } // If recipient implement SMS interface, check if sms is allowed and phone number available
            elseif (str_starts_with($channel, "sms")) {
                if ($recipient instanceof SmsRecipientInterface && empty($recipient->getPhone())) {
                    continue;
                }
            } // If recipient implement Email interface, check if email is available
            elseif (str_starts_with($channel, "email+")) {
                if (!$this->useMailer) {
                    continue;
                }
                if ($recipient instanceof EmailRecipientInterface && empty($recipient->getEmail())) {
                    continue;
                }
            } elseif (str_starts_with($channel, "chat/")) {
                $firstAdminRecipient = $this->getAdminRecipients()[0] ?? null;
                if ($recipient != $firstAdminRecipient) {
                    continue;
                }
            }

            $channels[] = $channel;
        }

        return array_unique($channels);
    }

    public function send(SymfonyNotification $notification, RecipientInterface ...$recipients): void
    {
        if (!$this->enable) {
            return;
        }

        $localeBak = $this->localizer->getLocale();
        $timezoneBak = date_default_timezone_get();

        foreach ($recipients as $recipient) {

            // Send notification with proper locale
            $locale = $this->localizer->getLocale($recipient instanceof LocaleRecipientInterface ? $recipient->getLocale() : null);
            $this->localizer->setLocale($locale);

            // Send notification with proper timezone
            $timezone = $recipient instanceof TimezoneRecipientInterface ? $recipient->getTimezone() : "UTC";
            date_default_timezone_set($timezone);

            // Payload.. A message that cannot leave (the mail server unreachable, its
            // host unknown) is said in the log, never thrown at the page that sent it:
            // the payment page answered 500 when the confirmation could not go out.
            // Through the queue, Messenger retries it; handled at once (sync://), the
            // failure is all there is to say.
            try {
                $this->notifier->send($notification, $recipient);
            } catch (\Throwable $e) {
                if (!self::isTransportFailure($e)) {
                    throw $e;
                }
                $this->logger?->error('The notification "{subject}" could not be sent: {error}', [
                    'subject' => $notification->getSubject(),
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        // Put back previous locale and timezone
        $this->localizer->setLocale($localeBak);
        date_default_timezone_set($timezoneBak);
    }

    /** A transport that failed - the mailer's, a notifier channel's, wrapped by Messenger when handled at once - and not a fault of the message itself. */
    private static function isTransportFailure(\Throwable $e): bool
    {
        if ($e instanceof MailerTransportException || $e instanceof NotifierTransportException) {
            return true;
        }
        if (class_exists(HandlerFailedException::class) && $e instanceof HandlerFailedException) {
            $failures = $e->getWrappedExceptions(null, true);

            return [] !== $failures && [] === array_filter($failures, static fn (\Throwable $f) => !self::isTransportFailure($f) && !$f instanceof HandlerFailedException);
        }

        return false;
    }

    /**
     * @param Notification $notification
     * @param RecipientInterface ...$recipients
     * @return $this|mixed
     * @throws Exception
     */
    public function sendUsers(Notification $notification, RecipientInterface ...$recipients)
    {
        // Set importance of the notification
        $this->markAsAdmin(false);

        $prevChannels = $notification->getChannels();
        $notification->setChannels([]);

        if (empty($recipients)) { // Display browser+ notification !
            $recipients = [new NoRecipient()];
        }

        // Determine recipient information
        $browserNotificationOnce = false;
        foreach (array_unique($recipients) as $i => $recipient) {
            
            // Set selected channels, if any
            $channels = $this->getUserChannels($notification->getImportance(), $recipient);
            if (empty($channels)) continue; // No user channel found.. nothing to send

            // Only send browser notification once
            if ($browserNotificationOnce) {
                $channels = array_filter($channels, fn($c) => !str_starts_with($c, "browser"));
            } elseif (array_starts_with($channels, "browser")) {
                $browserNotificationOnce = true;
            }

            $prevChannels = array_merge($prevChannels, $channels);
            $notification->setChannels($channels);
            $notification->markAsReadIfNeeded($channels);

            // Payload...
            $this->send($notification, $recipient);
        }

        $notification->setChannels($prevChannels);
        $notification->setSentAt(new DateTime("now"));

        return $this;
    }

    /**
     * @param array $channels
     * @param Notification $notification
     * @param ...$recipients
     * @return $this|mixed
     */
    public function sendUsersBy(array $channels, Notification $notification, ...$recipients)
    {
        // Set importance of the notification
        $this->markAsAdmin(false);

        $prevChannels = $notification->getChannels();
        $notification->setChannels([]);

        foreach (array_unique($recipients) as $recipient) {

            // Determine channels
            $channels = array_intersect($channels, $this->getUserChannels($notification->getImportance(), $recipient));
            $prevChannels = array_merge($prevChannels, $channels);

            if (empty($channels)) {
                continue;
            }
            $notification->setChannels($channels);

            // Payload...
            $this->send($notification, $recipient);
        }

        $notification->setChannels($prevChannels);
        $notification->setSentAt(new DateTime("now"));

        return $this;
    }

    /**
     * @param Notification $notification
     * @return $this|mixed
     */
    /**
     * @param Notification $notification
     * @return $this
     */
    public function sendAdmins(Notification $notification)
    {
        // Set importance of the notification
        $this->markAsAdmin(true, $notification);

        // Reset channels and keep here them for later use
        $prevChannels = $notification->getChannels();
        $notification->setChannels([]);

        // Back up channels and importance variables..
        // to be restored at the end of the method
        $recipients = $this->getAdminRecipients() ?? [new NoRecipient()];
        foreach (array_unique($recipients) as $recipient) {
            
            // Set selected channels, if any
            $channels = $this->getAdminChannels($notification->getImportance(), $recipient);

            if (empty($channels)) {
                return $this;
            }
            $notification->setChannels($channels);

            // Payload..
            $this->send($notification, $recipient);
        }

        $notification->setChannels($prevChannels);

        $this->markAsAdmin(false);
        return $this;
    }
}
