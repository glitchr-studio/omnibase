<?php

namespace Base\Form\Extension;

use Base\Enum\SpamScore;
use Base\Routing\AdvancedRouterInterface;
use Base\Service\FormGuard;
use Base\Service\Model\SpamProtectionInterface;
use Base\Service\SpamCheckerInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The option `guard` of a root form: Base\Service\FormGuard's checks, in
 * their order (docs/20-architecture/guard.md).
 *
 *     $this->createForm(ContactType::class, $model, ['guard' => true]);
 *     $this->createForm(ContactType::class, $model, ['guard' => ['action' => 'contact', 'min_delay' => 5]]);
 *
 * It adds a trap (guard_website), the stamp of the time the form was shown
 * (guard_opened) and - with glitchr/omniguard and a captcha configured - the
 * captcha (guard_captcha); after the submission it refuses a trap filled, a
 * form sent too fast, a sender the lists know. The captcha is checked by its
 * own constraint, on its field.
 *
 * Where glitchr/ux-google already guards the form (its option
 * captcha_protection: google.recaptcha.enable), no second captcha is added:
 * the trap, the time, the lists and the classifier stay.
 *
 * `spam_protection` - the classifier step, on by default as it always was
 * outside the back office: a form whose data implement
 * SpamProtectionInterface is scored (SpamChecker), its callback told, and
 * blatant spam refused. It runs once the form is otherwise valid: nothing is
 * sent to the classifier for a form already refused.
 */
class FormTypeGuardExtension extends AbstractTypeExtension
{
    public function __construct(
        protected readonly FormGuard $guard,
        protected readonly SpamCheckerInterface $spamChecker,
        protected readonly AdvancedRouterInterface $router,
        protected readonly ?TranslatorInterface $translator = null,
        protected readonly ?RequestStack $requests = null,
        protected readonly bool $spamByDefault = true,
    ) {
    }

    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'guard' => false,
            'spam_protection' => fn (Options $options) => $this->spamByDefault && !$this->router->isAdmin(),
        ]);
        $resolver->setAllowedTypes('guard', ['bool', 'array']);
        $resolver->setNormalizer('guard', static function (Options $options, $value) {
            if (false === $value) {
                return false;
            }

            return (\is_array($value) ? $value : []) + [
                'trap' => true,           // the field robots fill
                'min_delay' => null,      // seconds; null: base.guard.min_delay
                'challenge' => true,      // the captcha: true for base.guard.challenge, a gateway's name, false for none
                'action' => null,         // what the form is for, signed into the captcha's token: the form's name by default
                'reputation' => true,     // ask the lists of base.guard.reputation
                'email' => 'email',       // the field (or the data's property) holding the sender's e-mail
                'name' => 'name',         // the one holding their name
            ];
        });
        $resolver->setInfo('guard', 'Guard this form: a trap, the time it takes, the lists, the captcha (Base\Service\FormGuard). true, or an array: trap, min_delay, challenge, action, reputation, email, name.');
        $resolver->setInfo('spam_protection', 'Score the data (SpamProtectionInterface) with the classifier behind SpamChecker, and refuse blatant spam.');
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (!$builder->getForm()->isRoot()) {
            return;
        }

        if (false !== $guard = $options['guard']) {
            $this->guard($builder, $guard, $options);
        }

        if ($options['spam_protection'] && class_implements_interface($options['data_class'] ?? null, SpamProtectionInterface::class)) {
            // After the validation (priority 0): only a form that holds is classified.
            $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
                $form = $event->getForm();
                $data = $event->getData();
                if (!$data instanceof SpamProtectionInterface || \count($form->getErrors(true)) > 0) {
                    return;
                }
                if (SpamScore::__toInt()[SpamScore::BLATANT_SPAM] === $this->spamChecker->check($data)) {
                    $form->addError(new FormError($this->message(FormGuard::SPAM)));
                }
            }, -10);
        }
    }

    /** @param array<string, mixed> $guard */
    protected function guard(FormBuilderInterface $builder, array $guard, array $options): void
    {
        if ($guard['trap'] && !$builder->has(FormGuard::TRAP_FIELD)) {
            // Off-screen for people (and for screen readers), filled by robots.
            $builder->add(FormGuard::TRAP_FIELD, TextType::class, [
                'mapped' => false, 'required' => false, 'label' => false,
                'row_attr' => ['class' => 'base-trap', 'aria-hidden' => 'true', 'style' => 'position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden'],
                'attr' => ['tabindex' => '-1', 'autocomplete' => 'off'],
            ]);
        }
        $builder->add(FormGuard::STAMP_FIELD, HiddenType::class, ['mapped' => false, 'data' => $this->guard->stamp()]);

        $gateway = false === $guard['challenge'] ? null : (\is_string($guard['challenge']) ? $guard['challenge'] : $this->guard->challengeGateway());
        $google = (bool) ($options['captcha_protection'] ?? false);
        if (null !== $gateway && !$google && class_exists(\Omniguard\Bridge\Symfony\Form\ChallengeType::class)) {
            // Checked by the guard itself (below), not by the field's constraint: in the guard's order,
            // and whatever the form's validation groups - a constraint of the Default group is not
            // asked by a form validated in "new" alone (the sign-up).
            $builder->add(FormGuard::CHALLENGE_FIELD, \Omniguard\Bridge\Symfony\Form\ChallengeType::class, [
                'gateway' => $gateway,
                'action' => $guard['action'] ?? $this->action($builder->getName()),
                'constraints' => [],
            ]);
        }

        // Before the validation: a robot caught here does not reach the captcha's provider.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use ($guard): void {
            $form = $event->getForm();
            $request = $this->requests?->getCurrentRequest();
            $found = $this->guard->inspect($form, $request, $guard['min_delay'], (string) $guard['email'], (string) $guard['name'], (bool) $guard['reputation']);
            if (null === $found && $form->has(FormGuard::CHALLENGE_FIELD)) {
                $field = $form->get(FormGuard::CHALLENGE_FIELD);
                $options = $field->getConfig()->getOptions();
                if (null !== $answer = $this->guard->challenge($options['gateway'], $field->getData(), $request, $options['action'])) {
                    $field->addError(new FormError($this->translator?->trans(FormGuard::CHALLENGE_MESSAGES[$answer], [], 'validators') ?? FormGuard::CHALLENGE_MESSAGES[$answer], FormGuard::CHALLENGE_MESSAGES[$answer], [], null, $answer));
                }

                return;
            }
            if (null === $found) {
                return;
            }
            [$reason, $field] = $found;
            $target = null !== $field && $form->has($field) ? $form->get($field) : $form;
            $target->addError(new FormError($this->message($reason), null, [], null, $reason));
        }, 10);
    }

    /** An action a captcha accepts: letters, digits, underscores. */
    protected function action(string $name): ?string
    {
        $action = trim((string) preg_replace('/[^A-Za-z0-9_]+/', '_', $name), '_');

        return '' === $action ? null : strtolower($action);
    }

    protected function message(string $reason): string
    {
        $key = '@forms.guard.'.$reason;
        $message = $this->translator?->trans($key);

        return null !== $message && $message !== $key && '' !== $message ? $message : $key;
    }
}
