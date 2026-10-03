---
title: Comments
order: 46
---

# Comments

`Base\Entity\Thread\Comment` is what a visitor leaves under a thread (a blog
post, a sequence, a video) - or, with no thread, in the site's visitors' book:
a name, an e-mail (never shown), a site, a text, a reply to another comment
(one level), the account when signed in. Table `threadComment`.

- States (`Base\Enum\CommentState`): `pending`, `approved`, `spam`, `trash`.
- Akismet: the form's model (`Base\Form\Model\CommentModel`) implements
  `SpamProtectionInterface`; the score decides the state (`toComment($thread,
  $autoApprove)`).
- Robots: `Base\Form\Type\CommentType` adds a trap field (`url`) and the time the
  form was opened (`opened`); `Base\Service\CommentGuard::check($form,
  $request)` answers `trapped` (thank it, save nothing), `too_fast`, `flood`
  or null (`base.comments.min_delay`, `base.comments.flood_interval`).
- Reading: `Base\Repository\Thread\CommentRepository::findVisible($thread)`,
  `countVisible()`, `findPending()`, `countPending()`.

```php
$model = CommentModel::forUser($user);
$form = $this->createForm(CommentType::class, $model, ['signed_in' => null !== $user]);
$form->handleRequest($request);
if ($form->isSubmitted() && CommentGuard::TRAPPED === $guard->check($form, $request)) { return $this->redirect($back); }
if ($form->isSubmitted() && $form->isValid()) {
    $comment = $model->toComment($thread, autoApprove: true)->setIp($request->getClientIp());
    $em->persist($comment); $em->flush();
}
```

A bundle that needs more on its comments extends `Comment` (JOINED) with a
`#[DiscriminatorEntry]` of its own and passes its class to `toComment()`.
`omnibase/blog` is the reference user (moderation screen, notifications,
Akismet reports).
