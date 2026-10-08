<?php

namespace Base\Entity\Signature;

use Doctrine\ORM\Mapping as ORM;

/*
 * A document sent to be signed, through glitchr/omnisign (Base\Service\Signatures):
 * what AbstractEnvelope keeps. An entity - a table, signature_envelope - only
 * when glitchr/omnisign is installed: a site without the family has no such
 * table, and its schema, its migrations and its fixtures know nothing of it.
 */
if (class_exists(\Omnisign\Registry::class)) {
    #[ORM\Entity]
    #[ORM\Table(name: 'signature_envelope')]
    #[ORM\Index(name: 'signature_envelope_subject', columns: ['subjectClass', 'subjectId'])]
    #[ORM\Index(name: 'signature_envelope_reference', columns: ['reference'])]
    class Envelope extends AbstractEnvelope
    {
    }
} else {
    class Envelope extends AbstractEnvelope
    {
    }
}
