<?php

namespace Base\Console\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Base\Entity\User\Token;
use Base\Enum\UserRole;
use Base\Subscriber\PasswordChangeSubscriber;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * An account made from the command line - someone's own to test a site, a colleague's -,
 * verified and approved, with a password it did not choose: generated and shown once (or
 * given with --password), to be changed at its first sign-in (a "change-password" token,
 * PasswordChangeSubscriber) unless --keep-password. A plain Symfony command (not
 * Base\Console\Command, which wants a ConsoleOutput): it runs from scripts and tests too.
 */
#[AsCommand(name: 'user:create', description: 'An account, its password to change at the first sign-in')]
class UserCreateCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Its username')
            ->addArgument('email', InputArgument::REQUIRED, 'Its e-mail address')
            ->addOption('role', 'r', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Its role(s): USER, ADMIN, SUPERADMIN...', ['USER'])
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Its password (otherwise generated)')
            ->addOption('keep-password', null, InputOption::VALUE_NONE, 'Do not ask for a new password at the first sign-in');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = (string) $input->getArgument('username');
        $email = (string) $input->getArgument('email');

        $users = $this->entityManager->getRepository(User::class);
        if ($users->findOneBy(['email' => $email]) || (property_exists(User::class, 'username') && $users->findOneBy(['username' => $username]))) {
            $io->error(sprintf('An account already has the username "%s" or the address %s.', $username, $email));

            return self::FAILURE;
        }

        $roles = [];
        foreach ((array) $input->getOption('role') as $role) {
            $role = strtoupper(preg_replace('/^ROLE_/i', '', (string) $role));
            if (!UserRole::hasKey($role)) {
                $io->error(sprintf('Unknown role "%s".', $role));

                return self::FAILURE;
            }
            $roles[] = UserRole::getValue($role);
        }

        // Generated: four groups of four, letters and digits without the ones read alike (0/O, 1/l/I).
        $password = $input->getOption('password');
        if (!$password) {
            $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $password = implode('-', array_map(fn () => implode('', array_map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)], range(1, 4))), range(1, 4)));
        }

        $user = new User();
        if (method_exists($user, 'setUsername')) {
            $user->setUsername($username);
        }
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setPlainPassword($password);
        $user->verify();
        $user->approve();
        if (!$input->getOption('keep-password')) {
            (new Token(PasswordChangeSubscriber::TOKEN))->setUser($user);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('%s (%s), %s.', $username, $email, implode(', ', $roles)));
        $io->writeln(sprintf('Password: <info>%s</info>%s', $password, $input->getOption('keep-password') ? '' : ' - to be changed at the first sign-in'));

        return self::SUCCESS;
    }
}
