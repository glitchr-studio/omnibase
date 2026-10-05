---
title: Errors and refusals
order: 60
---

# Errors and refusals

An application hands its errors to omnibase:

```yaml
# config/packages/framework.yaml
framework:
    error_controller: Base\Controller\ErrorController::Main
```

- **In debug** (dev, and the test environment): Symfony's debug page
  (`ErrorController::Rescue()`), under the error's own status.
- **Otherwise**: `exception.html.twig`, or `@Admin/error.html.twig` for a path
  under `/admin` when omnibase/admin is installed; the kernel gives the
  response the error's status. `exception.html.twig` extends the site's
  `layout3.html.twig`, or its `layout1.html.twig` when it has no layout3
  (until 2026-10-06 a site without layout3 could not render the page: every
  unknown address answered 500 in prod).

| What happened | Status |
|---|---|
| The signed-in user may not do this (`AccessDeniedException`, `#[IsGranted]`, a voter) | 403 |
| A visitor who is not signed in asks for a protected page | 302 to the sign-in page |
| A payload that does not validate (`#[MapRequestPayload]`, `#[MapQueryString]`) | 422 |
| No such page, no such record | 404 |
| Anything that is not an HTTP error | 500 |

A test asserts the real status: `assertResponseStatusCodeSame(403)` for a
refusal, 422 for a validation failure.

(The debug page used to answer 404 whatever the error, so in dev and in the
tests every refusal and every validation failure of a signed-in user read as
"not found". Production was right. Tests that expected 404 for a refusal
expect 403 now.)

## The 404 that are meant

Two refusals are answered 404 on purpose, so as not to tell that the place
exists:

- **While access is restricted** (the `base.settings.access_restriction.*`
  settings: a site not open yet, or under maintenance), the back office and
  the profiler answer 404 to whoever is not granted `BACKEND`
  (`Base\Subscriber\SecuritySubscriber`).
- **The profiler outside debug** answers 404 (`Base\Subscriber\ProfilerSubscriber`).

An application that wants a record to stay unknown to those who may not see
it (a private order, another customer's file) throws
`createNotFoundException()` itself rather than denying access: that is its
choice, record by record, not something omnibase does for it.
