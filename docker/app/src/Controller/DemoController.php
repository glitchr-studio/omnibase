<?php

namespace App\Controller;

use App\Entity\User;
use Base\Field\Type\EmojiPickerType;
use Base\Marketplace\Entity\Order\Method\PaymentMethod;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Store;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * The demonstrator's one page: it seeds two members and the stores the
 * plugins sell through, signs a visitor in, and reports on the wiring -
 * then hands over to the plugins themselves.
 *
 * Everything here is demo scaffolding: a real application seeds from a
 * fixture or the back office and signs members in through base's security
 * controllers; nothing in this file is a pattern to copy.
 */
class DemoController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly KernelInterface $kernel,
        private readonly RouterInterface $router,
    ) {
    }

    #[Route('/', name: 'demo_index')]
    public function index(): Response
    {
        $sections = [];
        $demo = function (string $title, string $about, callable $fn) use (&$sections) {
            try {
                $sections[] = ['title' => $title, 'about' => $about, 'ok' => true, 'output' => $fn()];
            } catch (\Throwable $e) {
                $sections[] = ['title' => $title, 'about' => $about, 'ok' => false, 'output' => $e::class.': '.$e->getMessage()];
            }
        };

        $demo('Seed', 'Two members (Marki, an admin; Chimbo), the shop\'s store "boutique" with three products, the forge\'s "support" and "logiciels" stores, two payment methods - created once, found again on every reload.', fn () => $this->seed());

        $demo('Plugins', 'Every omnibase/* bundle the kernel runs, from config/bundles.php.', function () {
            $lines = [];
            foreach ($this->kernel->getBundles() as $bundle) {
                if (str_starts_with($bundle::class, 'Base\\') || str_starts_with($bundle::class, 'Git\\') || str_starts_with($bundle::class, 'Omnistate\\')) {
                    $lines[] = sprintf('%-40s %s', $bundle::class, $bundle->getPath());
                }
            }

            return implode("\n", $lines);
        });

        $demo('Routes', 'What each plugin added to the router.', function () {
            $counts = [];
            foreach ($this->router->getRouteCollection()->all() as $name => $route) {
                foreach (['marketplace', 'forge', 'forum', 'mailbox', 'git', 'admin', 'wikidoc'] as $prefix) {
                    if (str_starts_with($name, $prefix.'_') || str_starts_with($name, $prefix.'.')) {
                        $counts[$prefix] = ($counts[$prefix] ?? 0) + 1;
                    }
                }
            }
            ksort($counts);

            return implode("\n", array_map(static fn ($p, $n) => sprintf('%-12s %d', $p, $n), array_keys($counts), $counts)) ?: 'none';
        });

        return $this->render('demo/index.html.twig', ['sections' => $sections]);
    }

    /** The other member: the mailbox and the forum are conversations. */
    #[Route('/switch/{username}', name: 'demo_switch')]
    public function switch(string $username): Response
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
        if ($user) {
            $this->security->login($user, 'security.authenticator.form_login.main');
        }

        return $this->redirectToRoute('demo_index');
    }

    /**
     * The bundle's form fields whose script loads something - the emoji
     * picker's data -, on a page of the layout: what they fetch, they fetch
     * from the site (tests/Http/NothingFromElsewhereHttpTest).
     */
    #[Route('/fields', name: 'demo_fields')]
    public function fields(FormFactoryInterface $forms): Response
    {
        $form = $forms->createNamedBuilder('fields')
            ->add('mood', EmojiPickerType::class, ['required' => false])
            ->getForm();

        return $this->render('demo/fields.html.twig', ['form' => $form->createView()]);
    }

    /** A page with an address in each language, as a site's legal pages have (tests/Http/PageLanguageHttpTest). */
    #[Route(['en' => '/language', 'fr' => '/langue'], name: 'demo_language')]
    public function language(): Response
    {
        return $this->render('demo/language.html.twig');
    }

    /** The same, at one address for every language: the visitor's choice, or the language they were last shown, decides. */
    #[Route('/welcome', name: 'demo_welcome')]
    public function welcome(): Response
    {
        return $this->render('demo/language.html.twig');
    }

    private function seed(): string
    {
        $created = [];
        $users = $this->entityManager->getRepository(User::class);
        if (!$marki = $users->findOneBy(['username' => 'Marki'])) {
            $marki = $this->member('Marki', 'marki@example.org', ['ROLE_ADMIN']);
            $created[] = 'member Marki (admin)';
        }
        if (!$users->findOneBy(['username' => 'Chimbo'])) {
            $this->member('Chimbo', 'chimbo@example.org', ['ROLE_USER']);
            $created[] = 'member Chimbo';
        }

        $stores = [];
        foreach ([['boutique', 'Boutique', 'A small shop in euros.'], ['support', 'Support', 'Prepaid support hours and quotes (the forge).'], ['logiciels', 'Logiciels', 'Software licences (the forge).']] as [$slug, $title, $excerpt]) {
            if (!$store = $this->entityManager->getRepository(Store::class)->findOneBy(['slug' => $slug])) {
                $store = new Store();
                $store->setTitle($title);
                $store->setSlug($slug);
                $store->setExcerpt($excerpt);
                $store->setCurrency('EUR');
                $store->setOpen(true);
                $this->entityManager->persist($store);
                $created[] = 'store '.$title;
            }
            $stores[$slug] = $store;
        }

        foreach ([['mug', 'Mug', 1290, null], ['poster', 'Poster', 990, 20], ['badges', 'Three badges', 490, 100]] as [$slug, $title, $price, $stock]) {
            if (!$this->entityManager->getRepository(Product::class)->findOneBy(['slug' => $slug])) {
                $product = new Product(null, $stores['boutique'], $price, 'EUR');
                $product->setTitle($title);
                $product->setSlug($slug);
                $product->setExcerpt($title.' from the demo shop.');
                $product->setStock($stock);
                $this->entityManager->persist($product);
                $created[] = 'product '.$title;
            }
        }

        foreach ([['demo', 'Pay now (demo gateway)', 'demo'], ['virement', 'Bank transfer', 'manual']] as [$slug, $label, $gateway]) {
            if (!$this->entityManager->getRepository(PaymentMethod::class)->findOneBy(['slug' => $slug])) {
                $method = new PaymentMethod();
                $method->setSlug($slug);
                $method->setLabel($label);
                $method->setGatewayFactory($gateway);
                $this->entityManager->persist($method);
                $created[] = 'payment method '.$slug;
            }
        }

        $this->entityManager->flush();
        if (!$this->getUser()) {
            $this->security->login($marki, 'security.authenticator.form_login.main');
        }

        return ($created ? "created:\n  ".implode("\n  ", $created) : 'already seeded')."\nsigned in as ".($this->getUser()?->getUsername() ?? $marki->getUsername());
    }

    private function member(string $username, string $email, array $roles): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setPlainPassword('demo');
        $user->setRoles($roles);
        $user->verify();
        $this->entityManager->persist($user);

        return $user;
    }
}
