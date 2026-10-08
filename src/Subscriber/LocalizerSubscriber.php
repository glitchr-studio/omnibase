<?php

namespace Base\Subscriber;

use App\Entity\User;
use Base\Entity\User as BaseUser;
use Base\Entity\User\Notification;
use Base\Routing\AdvancedRouterInterface;
use Base\Service\LocalizerInterface;

use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;
use Symfony\Component\Security\Http\SecurityEvents;

class LocalizerSubscriber implements EventSubscriberInterface
{
    public const __LANG_IDENTIFIER__ = "LANG";
    public const __TIMEZONE_IDENTIFIER__ = "TIMEZONE";

    /** The request attribute holding the language a page was shown in, for the response to remember. */
    public const DISPLAYED_LOCALE = "_base_displayed_locale";

    /** What Symfony's Request::create() says when it is told no language. */
    public const REQUEST_DEFAULT_LANGUAGE = "en-us,en;q=0.5";

    /**
     * @var LocalizerInterface
     */
    protected LocalizerInterface $localizer;

    /**
     * @var AdvancedRouterInterface
     */
    protected AdvancedRouterInterface $router;

    /**
     * @var TokenStorageInterface
     */
    protected $tokenStorage;

    public function __construct(LocalizerInterface $localizer, AdvancedRouterInterface $router, TokenStorageInterface $tokenStorage)
    {
        $this->localizer = $localizer;
        $this->router = $router;
        $this->tokenStorage = $tokenStorage;
    }

    public static function getSubscribedEvents(): array
    {
        /*
         * Must be set prior SecuritySubscriber and
         * after Symfony\Component\HttpKernel\EventListener\LocaleListener::setDefaultLocale()
         *
         * CLI: php bin/console debug:event kernel.request
         */
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 8],
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
            SecurityEvents::SWITCH_USER => 'onSwitchUser'
        ];
    }

    /** @var list<string>|null the languages this site speaks */
    private ?array $languages = null;

    /**
     * The languages this site speaks: the translator's (the default and its
     * fallbacks), and those its pages have an address in ("app_legal.de" -
     * a site may give a language its own addresses without a fallback).
     *
     * @return list<string>
     */
    private function languages(): array
    {
        if (null === $this->languages) {
            $languages = $this->localizer->getAvailableLocaleLangs();
            $generator = $this->router->getGenerator();
            if (method_exists($generator, 'getCompiledRoutes')) {
                foreach (array_keys($generator->getCompiledRoutes()) as $name) {
                    if (preg_match('/\.([a-z]{2})$/', (string) $name, $found)) {
                        $languages[] = $found[1];
                    }
                }
            }
            // The site's own first: Accept-Language's ties go to it.
            $this->languages = array_values(array_unique(array_filter($languages)));
        }

        return $this->languages;
    }

    /** A locale of this site's ("de", "fr_FR", "fr-FR"), normalized; null for any other. */
    private function available(?string $locale, bool $declared = false): ?string
    {
        if (!is_string($locale) || !preg_match('/^[a-zA-Z]{2}([-_][a-zA-Z]{2})?$/', $locale)) {
            return null;
        }
        // A route's own (_locale: de) is the site's by declaration.
        if (!$declared && !in_array(strtolower(substr($locale, 0, 2)), $this->languages(), true)) {
            return null;
        }

        return $this->localizer::normalizeLocale(strtolower(substr($locale, 0, 2)).substr($locale, 2));
    }

    /** The language the page was shown in, remembered for the next one (LANG). */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $locale = $request->attributes->get(self::DISPLAYED_LOCALE);
        if (!is_string($locale) || $request->cookies->get(self::__LANG_IDENTIFIER__) === $locale) {
            return;
        }

        $response = $event->getResponse();
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === self::__LANG_IDENTIFIER__) {
                return; // set by the page itself (a change of language)
            }
        }

        $response->headers->setCookie(Cookie::create(self::__LANG_IDENTIFIER__, $locale, 0, "/", null, $request->isSecure(), false, false, Cookie::SAMESITE_LAX));
    }

    public function onSwitchUser(SwitchUserEvent $event): void
    {
        if (!is_instanceof(User::class, BaseUser::class)) {
            return;
        }
        setcookie(self::__LANG_IDENTIFIER__, $event->getTargetUser()->getLocale(), 0, "/", $this->router->getDomain());
    }

    /**
     * The language of a page: its address (a route of one language,
     * "/en/..." or "/datenschutz"), else the visitor's own choice or the
     * language their last page was shown in (the LANG cookie, which the
     * response keeps up to date), else a signed-in member's, else - on a
     * first visit only - the browser's (Accept-Language), else the site's.
     *
     * The browser's language used to be read on every page from the
     * USER/INFO cookie, which the site's script writes after the first one:
     * a visitor who arrived on a French page, or chose French, was moved to
     * their browser's language from the second page on.
     */
    public function onKernelRequest(RequestEvent $event)
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $_locale = $this->router->match($request->getPathInfo())["_locale"] ?? null;
        $_locale = $this->available($_locale, true);
        if ($_locale !== null) {
            $this->localizer->markAsChanged();
        }

        $locale = $_locale ?? $this->available($request->cookies->get(self::__LANG_IDENTIFIER__));

        $user = $this->tokenStorage->getToken()?->getUser();
        if ($user instanceof BaseUser) {
            $locale ??= $this->available($user->getLocale());
        }

        // Symfony's Request::create() puts "en-us,en;q=0.5" on every request it makes
        // that says nothing (a test client's, a sub-request's): no word from a browser.
        if ($locale === null && $request->headers->has("Accept-Language") && self::REQUEST_DEFAULT_LANGUAGE !== $request->headers->get("Accept-Language")) {
            $preferred = $request->getPreferredLanguage($this->languages());
            $locale = $this->available($preferred);
        }

        // The site's own language - not the translator's current one, which in a
        // long-lived worker is still the previous request's.
        $locale ??= $this->localizer::getDefaultLocale() ?? $this->localizer->getLocale();

        // Normalize locale
        $locale = $this->localizer::normalizeLocale($locale);

        // Set new locale
        $this->localizer->setLocale($locale, $request);
        $this->localizer->markAsLate();
        $request->attributes->set(self::DISPLAYED_LOCALE, $locale);

        // And the router's: Symfony's LocaleListener handed it the request's
        // locale before this one decided it (priority 16 against 8), and
        // the links of a page without a language of its own (/blog) came
        // out in the default language, whatever the visitor's.
        $this->router->getContext()->setParameter('_locale', $locale);

        //
        // Set timezone
        //
        $this->localizer->setTimezone("UTC");

        if (is_instanceof(User::class, BaseUser::class)) {

            $timezone = User::getCookie("timezone") ?? "UTC";            
            if (!in_array($timezone, timezone_identifiers_list())) {

                $notification = new Notification("@localizer.timezone.invalid", [$timezone]);
                $notification->send("info");

                $timezone = "UTC";
            }

            $this->localizer->setTimezone($timezone);

            $country = User::getCookie("country");
            if ($country) {
                $this->localizer->setCountry($country);
            }
        }
    }
}
