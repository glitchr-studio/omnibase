<?php

namespace Base\Controller;

use Base\Routing\AdvancedRouterInterface;
use Base\Service\ReferrerInterface;
use Base\Service\LocalizerInterface;
use Base\Service\TranslatorInterface;
use Base\Subscriber\LocalizerSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

class LocalizerController extends AbstractController
{
    /**
     * @var LocalizerInterface
     */
    protected LocalizerInterface $localizer;

    protected AdvancedRouterInterface $router;
    protected ReferrerInterface $referrer;
    protected TranslatorInterface $translator;
    protected EntityManagerInterface $entityManager;

    public function __construct(LocalizerInterface $localizer, EntityManagerInterface $entityManager, AdvancedRouterInterface $router, ReferrerInterface $referrer, TranslatorInterface $translator)
    {
        $this->localizer = $localizer;
        $this->router = $router;
        $this->referrer = $referrer;
        $this->translator = $translator;
        $this->entityManager = $entityManager;
    }

    /**
     * The visitor's choice of language: remembered (LANG, and a member's own
     * locale), then the page they came from, in that language when it has an
     * address of its own. Without one ("/locale"), the choice is forgotten.
     */
    #[Route("/locale/{_locale}", name: "_switch_locale")]
    public function Locale(Request $request, ReferrerInterface $referrer, ?string $_locale = null)
    {
        $response = $this->switchLocale($request, $referrer, $_locale);
        if ($_locale === null) {
            $response->headers->clearCookie(LocalizerSubscriber::__LANG_IDENTIFIER__, "/");
        }

        return $response;
    }

    private function switchLocale(Request $request, ReferrerInterface $referrer, ?string $_locale): Response
    {
        $availableLocales = array_merge($this->localizer->getAvailableLocaleLangs(), $this->localizer->getAvailableLocales());
        if (!in_array($_locale, $availableLocales)) {
            return $this->redirect($this->router->getUrlIndex());
        }

        $locale = $this->localizer->getLocale($_locale);
        if (!$this->isGranted("IS_IMPERSONATOR")) {
            $this->getUser()?->setLocale($locale);
            $this->entityManager->flush();
        }

        $this->addFlash("info", $this->translator->trans("@controllers.locale_changeto.action", [$this->localizer->getLocaleLangName($_locale)]));

        // Back to the page the visitor came from, at its address in that language if it has one.
        $response = null;
        $from = $request->headers->get("referer");
        $host = $from ? parse_url($from, PHP_URL_HOST) : null;
        $path = $from && (!$host || $host === $request->getHost()) ? (parse_url($from, PHP_URL_PATH) ?: "/") : null;
        $match = $path !== null ? $this->router->getRouteMatch($path) : null;
        $referrerName = $match["_canonical_route"] ?? $match["_route"] ?? null;
        if ($referrerName && !str_starts_with($referrerName, "_switch_locale")) {
            foreach ($this->localizer->getAvailableLocaleLangs() as $available) {
                $referrerName = str_ends_with($referrerName, "." . $available) ? substr($referrerName, 0, -strlen("." . $available)) : $referrerName;
            }
            $referrerParameters = array_filter($match, fn($a) => !str_starts_with($a, "_"), ARRAY_FILTER_USE_KEY);
            $lang = "." . $this->localizer->getLocaleLang($_locale);
            try {
                $response = $this->redirect($this->router->generate($referrerName . $lang, $referrerParameters));
            } catch (RouteNotFoundException $e) {
                $response = $this->redirect($this->router->generate($referrerName, $referrerParameters));
            }
        }
        $response ??= $this->redirect($this->router->getUrlIndex());

        // The choice, for every page that has no language of its own in its address.
        $response->headers->setCookie(Cookie::create(LocalizerSubscriber::__LANG_IDENTIFIER__, $locale, 0, "/", null, $request->isSecure(), false, false, Cookie::SAMESITE_LAX));

        return $response;
    }

    #[Route("/timezone/{_timezone}", name: "_switch_timezone")]
    public function Timezone(Request $request, ReferrerInterface $referrer, ?string $_locale = null)
    {
        if ($_locale === null) {
            setcookie(LocalizerSubscriber::__LANG_IDENTIFIER__, null);
        }

        $referrer->setUrl($_SERVER["HTTP_REFERER"] ?? null);
        $referrerName = $this->router->getRouteName(strval($referrer));
        $referrerParameters = array_filter($this->router->match(strval($referrer)), fn($a) => !str_starts_with($a, "_"), ARRAY_FILTER_USE_KEY);
        $referrer->setUrl(null);

        $availableLocales = array_merge($this->localizer->getAvailableLocaleLangs(), $this->localizer->getAvailableLocales());
        if (in_array($_locale, $availableLocales)) {
            setcookie('_locale', $_locale);
            if (!$this->isGranted("IS_IMPERSONATOR")) {
                $this->getUser()?->setLocale($this->localizer->getLocale($_locale));
                $this->entityManager->flush();
            }

            $this->addFlash("info", $this->translator->trans("@controllers.locale_changeto.action", [$this->localizer->getLocaleLangName($_locale)]));

            if (!str_starts_with($referrerName, "_switch_timezone")) {
                $referrer->setUrl(null);
                $lang = $_locale ? "." . $this->localizer->getLocaleLang($_locale) : "";

                try {
                    return $this->redirect($this->router->generate($referrerName . $lang, $referrerParameters));
                } catch (RouteNotFoundException $e) {
                    return $this->redirect($this->router->generate($referrerName, $referrerParameters));
                }
            }
        }

        return $this->redirect($this->router->getUrlIndex());
    }
}
