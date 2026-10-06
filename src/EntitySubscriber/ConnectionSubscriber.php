<?php

namespace Base\EntitySubscriber;

use App\Entity\User;
use Base\Enum\ConnectionState;
use Base\Security\UserTracker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;


class ConnectionSubscriber implements EventSubscriberInterface
{
    /**
     * @var UserTracker
     */
    protected UserTracker $userTracker;

    /**
     * @var EntityManagerInterface
     */
    protected EntityManagerInterface $entityManager;

    public function __construct(UserTracker $userTracker, EntityManagerInterface $entityManager)
    {
        $this->userTracker = $userTracker;
        $this->entityManager = $entityManager;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckPassportEvent::class => ['onCheckPassport', 10],

            LoginSuccessEvent::class => ['onLoginSuccess', -65],
            LoginFailureEvent::class => ['onLoginFailure'],
            LogoutEvent::class => ['onLogout'],
        ];
    }

    public function onCheckPassport(CheckPassportEvent $event)
    {
        $passport = $event->getPassport();
        if(!$passport) return;

        $user = $passport->getUser();
        if (!$user instanceof User) return;

        // Looked up, not created: the credentials are not checked yet, and a
        // row per attempt was made (and flushed) before they were. A failure
        // is recorded by onLoginFailure, a success by onLoginSuccess.
        $this->userTracker->getCurrentConnection($user, false);
    }

    public function onLoginFailure(LoginFailureEvent $event)
    {
        $passport = $event->getPassport();
        if(!$passport) return;

        // The passport loads its user here, and may have none to give: an
        // identifier nobody has (UserNotFoundException), or an attempt refused
        // before the passport was given its loader - Symfony's login throttling
        // (too many attempts) refuses first of all, and UserBadge::getUser() then
        // throws a LogicException, which turned the refusal into a 500.
        $user = null;
        try { $user = $passport->getUser(); }
        catch(UserNotFoundException|\LogicException $e) { }
        if (!$user instanceof User) return;
        
        $connection = $this->userTracker->getCurrentConnection($user);
        $connection->markAsFailed();

        $this->entityManager->flush();
    }

    public function onLoginSuccess(LoginSuccessEvent $event)
    {
        $user = $event->getUser();
        if (!$user instanceof User) return;

        $connection = $this->userTracker->getCurrentConnection($user);
        $connection->markAsSucceeded();

        $this->entityManager->flush();
    }

    public function onLogout(LogoutEvent $event)
    {
        $token = $event->getToken();
        $user = $token ? $token->getUser() : null;
        if (!$user instanceof User) return;

        $connection = $this->userTracker->getCurrentConnection($user, false);
        if($connection) $connection->markAsLogout();
        
        $this->entityManager->flush();
    }
}