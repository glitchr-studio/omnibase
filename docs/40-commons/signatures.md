---
title: Signatures
order: 47
---

# Documents signed: `Base\Service\Signatures`

A document sent to be signed - a lease, a quote, a surety - for **any entity** of the application,
through [glitchr/omnisign](https://github.com/glitchr-studio/omnisign) (Yousign, DocuSign, a DocuSeal
instance of one's own): signed by the provider's e-mail or in the site's own page, followed until
it is over, and once completed the signed document and its evidence kept in the site's **private
storage**.

```php
use Omnisign\Model\{Document, Envelope as Request, Field, File, Signer};

$envelope = $signatures->send($quote, new Request(
    'Devis D-2026-042',
    [new Document('quote', new File($pdf, 'devis-D-2026-042.pdf'))],
    [new Signer('client', 'Camille Érable', 'camille@erable.example')],
    [new Field('client', 'quote', page: 1, x: 72, y: 640)],
    embedded: true,                                    // signed in the site's page, no e-mail from the provider
));
return $this->redirect($signatures->signingUrl($envelope, 'client', $returnUrl));

$signatures->refresh($envelope);                       // on the way back - the provider's webhook does it too
$signatures->signed($envelope);                        // the signed PDF, once completed
$signatures->evidence($envelope);                      // its audit trail or certificate
$signatures->of($quote);                               // the envelopes about the quote, newest first
```

## Only with the family

`glitchr/omnisign` is **suggested, not required**. Without it nothing of this exists in a site:

- `Base\Service\Signatures` is not registered;
- `Base\Entity\Signature\Envelope` is not an entity - no `signature_envelope` table, nothing in the
  site's schema, migrations or fixtures (its fields are those of the mapped superclass
  `AbstractEnvelope`, which has no table);
- `POST /signatures/{gateway}/webhook` answers 404.

With it: `composer require glitchr/omnisign omnisign/docuseal` (or `omnisign/yousign`,
`omnisign/docusign`; not on Packagist yet: the `vcs` repositories of `glitchr/omnibase`'s
`composer.json`), register `Omnisign\Bridge\Symfony\OmnisignBundle`, configure a gateway, then
`make:migration` and `doctrine:migrations:migrate` for the table:

```yaml
# config/packages/omnisign.yaml
omnisign:
    gateways:
        contracts:
            factory: docuseal
            options:
                url: '%env(DOCUSEAL_URL)%'                 # https://sign.example.org/api
                api_key: '%env(DOCUSEAL_API_KEY)%'
                webhook_secret: '%env(default::DOCUSEAL_WEBHOOK_SECRET)%'

# config/packages/base.yaml
base:
    signatures:
        gateway: contracts        # null: the only gateway configured
        storage: ~                # null: the uploads' storage (base.uploader.storage, var/storage/uploads)
```

## What an envelope keeps

| | |
|---|---|
| `subjectClass`, `subjectId` | the entity it is about - any one, by Doctrine's name of its class and its id |
| `gateway`, `reference` | the omnisign gateway and its reference there: what to ask it again by |
| `status` | `draft`, `sent`, `completed`, `declined`, `expired`, `canceled` |
| `envelope` | what it takes to ask the provider again: the signers and where each stands (`getSigners()`), the documents' keys and names, the provider's own references - not the documents' content |
| `signedFile`, `evidenceFile` | their paths in the private storage, once completed: `signatures/<year>/<random>/<name>` |

The files are read back through `signed()` and `evidence()`; a site serves them to whoever may see
them, with a signed link (`Base\Service\DownloadLinks`) or its own access check - the storage itself
is out of `public/`.

## Following it

- **`refresh()`** asks the provider where it stands (a page the signer comes back to, a command).
- **The provider's webhook**: `POST /signatures/{gateway}/webhook` (`base_signature_webhook`) - its
  signature checked by the gateway (`webhook_secret`, or the provider's own key), the envelope it
  names asked again. A callback whose signature does not hold is answered 400.
- **Events** (`Base\Event\SignatureEvent`), dispatched once each: `SIGNED` when a signer signed,
  then `COMPLETED`, `DECLINED`, `EXPIRED` or `CANCELED`; `getSubject()` gives the entity it is
  about. The application answers there: a quote accepted, a lease in force.

```php
#[AsEventListener(event: SignatureEvent::COMPLETED)]
public function onSigned(SignatureEvent $event): void
{
    if ($event->getSubject() instanceof Quote) { ... }
}
```

`remind()` asks the provider to remind who has not signed; `cancel()` withdraws it.

## Tests

`tests/Service/SignaturesTest.php`, in the harness (which installs `glitchr/omnisign` and
`omnisign/docuseal` and registers a DocuSeal gateway): a lease sent for a member, signed in the
page, followed to completion, its files kept and read back, its events, the webhook's signature -
DocuSeal answering as an instance of its open-source edition answered (`omnisign/docuseal`'s
recorded answers). What a provider does for real is said in each gateway's documentation.
