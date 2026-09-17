<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Security\Kms;

/**
 * A {@see KeyStore} that can create a key atomically. Shared stores (e.g. the
 * database store on a multi-node deployment) implement it so two nodes
 * provisioning the same tenant at once can never overwrite each other's KEK —
 * which would leave DEKs wrapped by the losing KEK permanently unreadable.
 */
interface AtomicKeyStore extends KeyStore
{
    /**
     * Store the material only if no key exists under this id.
     *
     * @return bool True when this call created the key, false when it existed.
     */
    public function add(string $keyId, string $material): bool;
}
