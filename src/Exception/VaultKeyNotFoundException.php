<?php

namespace Base\Exception;

/**
 * A field secured by #[Vault] is about to be stored and the vault has no key
 * pair: the value is refused rather than written in clear.
 */
class VaultKeyNotFoundException extends \RuntimeException
{
    public function __construct(public readonly string $vault, public readonly string $path, ?string $reason = null, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf(
            'The "%s" vault has no key pair (%s)%s: a secured value would be stored in clear, so it is refused. '
            .'Generate the pair with "php bin/console secrets:generate-keys --env=%s" (on a production server, give the decryption key as the file or as SYMFONY_DECRYPTION_SECRET), '
            .'or accept clear text with "base.vault.allow_plaintext: true".',
            $vault,
            $path,
            $reason ? ' - '.$reason : '',
            $vault
        ), 0, $previous);
    }
}
