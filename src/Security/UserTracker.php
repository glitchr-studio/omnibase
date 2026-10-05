<?php

namespace Base\Security;

use App\Entity\User;
use Base\Repository\User\ConnectionRepository;
use Base\Entity\User\Connection;
use Base\Enum\ConnectionState;
use Base\Routing\AdvancedRouterInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class UserTracker
{
    public const PHPUSERID = "PHPUSERID";

    /**
     * @var ConnectionRepository
     */
    protected $connectionRepository;

    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @var RequestStack
     */
    protected $requestStack;

    /**
     * @var AdvancedRouterInterface
     */
    protected $router;

    public function __construct(EntityManagerInterface $entityManager, RequestStack $requestStack, AdvancedRouterInterface $router, ConnectionRepository $connectionRepository)
    {
        $this->entityManager = $entityManager;
        $this->requestStack = $requestStack;
        $this->router = $router;

        $this->connectionRepository = $connectionRepository;
    }

    public function getUniqid(): string
    {
        $session = $this->requestStack->getSession();
        $cookies = $this->requestStack->getMainRequest()->cookies;

        $uniqid = $session?->get(self::PHPUSERID) ?? $cookies?->get(self::PHPUSERID) ?? null;
        if(!$uniqid) {

            $uniqid = uniqid("", true);
            $session->set(self::PHPUSERID, $uniqid);
            // Once output has started (a command, a test run) the cookie cannot be
            // sent and setcookie() only warns - a warning PHPUnit turns into the
            // failure of the sign-in under test. The session keeps the identifier.
            if (!headers_sent()) {
                setcookie(self::PHPUSERID, $uniqid, 0, "/", $this->router->getDomain());
            }
        }

        return $uniqid;
    }

    public function getCurrentConnection(?User $user = NULL, bool $allowNewConnection = true): ?Connection
    {
        $connection = $this->connectionRepository->findOneByUniqidAndUser($this->getUniqid(), ["user" => $user]);
        if ($connection == NULL && $user != NULL && $allowNewConnection) $connection = $this->createNewConnection($user);

        return $connection;
    }

    public function createNewConnection(User $user): Connection
    {
        $connection = new Connection($this->getUniqid(), $user);
        $this->describe($connection, $user);

        $this->entityManager->persist($connection);
        $this->entityManager->flush();

        return $connection;
    }

    /**
     * On every authenticated request (UserProvider::refreshUser): flushed
     * only when the connection is new or something about it changed - it
     * flushed the whole unit of work on every request, before the controller
     * had even run.
     */
    public function updateConnection(User $user)
    {
        $connection = $this->getCurrentConnection($user, false);
        if (!$connection) {
            $this->getCurrentConnection($user);

            return;
        }

        $before = $this->fingerprint($connection);
        $this->describe($connection, $user);
        if ($this->fingerprint($connection) !== $before) {
            $this->entityManager->flush();
        }
    }

    /**
     * Where from, with what: the request's IP, agent, host, the user's locale
     * and timezone - each only when there is one. A request without a
     * User-Agent (a monitor, curl) or without an address (a worker) got a
     * TypeError, a 500 on every page.
     */
    protected function describe(Connection $connection, User $user): void
    {
        if (is_string($ip = User::getIp()) && '' !== $ip) {
            $connection->addIp($ip);
        }
        if (is_string($agent = User::getAgent()) && '' !== $agent) {
            $connection->setAgent($agent);
        }
        if (is_string($locale = $user->getLocale()) && '' !== $locale) {
            $connection->setLocale($locale);
        }
        $connection->addHostname($this->router->getHost());
        $connection->addTimezone($user->getTimezone());
    }

    protected function fingerprint(Connection $connection): string
    {
        return serialize([$connection->getIpList(), $connection->getAgent(), $connection->getLocale(), $connection->getHostnames(), $connection->getTimezones()]);
    }
}