<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Security\Kms;

use Sellinnate\RagEngine\Contracts\KeyManagement;
use Sellinnate\RagEngine\Data\GeneratedDataKey;
use Sellinnate\RagEngine\Exceptions\EncryptionException;
use Sellinnate\RagEngine\Security\AeadCipher;

/**
 * Local KMS driver for dev/test (FR-SEC-02 local driver, NFR-TE-01).
 *
 * KEK material lives in a {@see KeyStore}. To keep rotation non-destructive
 * (FR-SEC-05) the driver keeps every KEK version and unwraps by trying the
 * newest first. {@see destroyKey()} wipes all versions, implementing
 * crypto-shredding (FR-SEC-04): any DEK wrapped by that KEK becomes
 * permanently un-unwrappable.
 */
final class LocalKms implements KeyManagement
{
    public function __construct(
        private readonly KeyStore $store,
        private readonly AeadCipher $cipher = new AeadCipher,
    ) {}

    public function createKey(string $keyId): void
    {
        if ($this->store->has($keyId)) {
            return;
        }

        $material = $this->encodeVersions([random_bytes(32)]);

        // Shared stores create atomically so concurrent nodes can't clobber
        // each other's freshly generated KEK.
        if ($this->store instanceof AtomicKeyStore) {
            $this->store->add($keyId, $material);

            return;
        }

        $this->store->put($keyId, $material);
    }

    public function generateDataKey(string $keyId): GeneratedDataKey
    {
        $this->createKey($keyId);

        $dek = random_bytes(32);
        $kek = $this->currentKek($keyId);

        return new GeneratedDataKey(
            plaintext: $dek,
            wrapped: $this->cipher->encrypt($kek, $dek),
            keyId: $keyId,
        );
    }

    public function unwrapDataKey(string $keyId, string $wrappedKey): string
    {
        // Existence is decided by reading the material itself (a store's get()
        // may be a locking read that sees keys other nodes just committed).
        $raw = $this->store->get($keyId);

        if ($raw === null) {
            throw new EncryptionException(
                "KEK [{$keyId}] does not exist or was crypto-shredded; the data key cannot be unwrapped."
            );
        }

        foreach (array_reverse($this->decodeVersions($raw)) as $kek) {
            try {
                return $this->cipher->decrypt($kek, $wrappedKey);
            } catch (EncryptionException) {
                // Try an older KEK version.
            }
        }

        throw new EncryptionException("Unable to unwrap the data key with KEK [{$keyId}].");
    }

    public function wrapDataKey(string $keyId, string $plaintext): string
    {
        $this->createKey($keyId);

        return $this->cipher->encrypt($this->currentKek($keyId), $plaintext);
    }

    public function rotateKey(string $keyId): void
    {
        // Read the material (not has()) so a stale "missing" can never replace
        // the existing versions with a single new one.
        $raw = $this->store->get($keyId);
        $versions = $raw === null ? [] : $this->decodeVersions($raw);
        $versions[] = random_bytes(32);

        $this->store->put($keyId, $this->encodeVersions($versions));
    }

    public function destroyKey(string $keyId): void
    {
        $this->store->forget($keyId);
    }

    public function keyExists(string $keyId): bool
    {
        return $this->store->has($keyId);
    }

    public function name(): string
    {
        return 'local';
    }

    /**
     * KEK versions as stored: oldest-first, newest appended last.
     *
     * @return list<string>
     */
    private function storedVersions(string $keyId): array
    {
        $raw = $this->store->get($keyId);

        if ($raw === null) {
            throw new EncryptionException("KEK [{$keyId}] not found.");
        }

        return $this->decodeVersions($raw);
    }

    /**
     * @return list<string> Oldest first.
     */
    private function decodeVersions(string $raw): array
    {
        /** @var list<string> $decoded */
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        return array_map(
            fn (string $b64): string => (string) base64_decode($b64, true),
            $decoded,
        );
    }

    private function currentKek(string $keyId): string
    {
        $versions = $this->storedVersions($keyId);

        if ($versions === []) {
            throw new EncryptionException("KEK [{$keyId}] has no versions.");
        }

        return $versions[count($versions) - 1];
    }

    /**
     * @param  list<string>  $versions  Oldest first.
     */
    private function encodeVersions(array $versions): string
    {
        return json_encode(
            array_map(fn (string $kek): string => base64_encode($kek), $versions),
            JSON_THROW_ON_ERROR,
        );
    }
}
