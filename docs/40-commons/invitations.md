---
title: Invitations
order: 43
---

# Invitations

Nobody signs up, someone is invited: a random token (48 hexadecimal
characters) is sent by e-mail and never stored - only its SHA-256 is kept. Its
link, before it runs out, creates the account (the link proved the address)
or attaches the existing one.

- `Base\Entity\User\Invitation`: a mapped superclass (e-mail, name,
  `tokenHash`, `createdAt`, `expiresAt`, `acceptedAt`, `invitedBy`). Each bundle
  keeps its own table and says what an invitation opens.
- `Base\Service\Invitations`: `token()`, `hash()`, `expiry($days)`, `save()`,
  `findOpen($class, $token)`, `markAccepted()`, `createUser()`, `existingUser()`.

```php
#[ORM\Entity]
#[ORM\Table(name: 'office_invitation')]
class Invitation extends \Base\Entity\User\Invitation
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] private Practice $practice;
    public function getId(): ?int { return $this->id; }
}

$token = Invitations::token();
$invitations->save(new Invitation($email, $name, Invitations::hash($token), Invitations::expiry(14)));
// mail the link with $token; on the link:
$invitation = $invitations->findOpen(Invitation::class, $token) ?? throw new GoneHttpException();
```

`omnibase/estate` extends both (its `Invitation` keeps `estate_invitation`,
its `Invitations` opens an associate, a tenant, a contractor or a member).
