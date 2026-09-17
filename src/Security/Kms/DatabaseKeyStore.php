<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Security\Kms;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\ConnectionResolverInterface;
use JsonException;
use Sellinnate\RagEngine\Exceptions\EncryptionException;
use Sellinnate\RagEngine\Security\AeadCipher;
use SensitiveParameter;

/**
 * Database-backed KEK store for the local KMS driver (`kms.local.store =
 * database`). Suited to multi-node / ephemeral-disk hosting (Laravel Cloud,
 * Vapor, Kubernetes) where a file keystore would not be shared or would vanish.
 *
 * - KEK material is ALWAYS encrypted at rest with the master key
 *   (AES-256-GCM); a missing or short master key fails closed.
 * - Each row is bound to its key id inside the ciphertext, so swapping rows
 *   between tenants is detected instead of silently cross-wiring keys.
 * - Key ids are stored as SHA-256 hashes (no tenant identifiers in the table).
 * - {@see forget()} hard-deletes the row: crypto-shredding (FR-SEC-04).
 * - {@see add()} is an atomic insert-if-absent, safe under concurrent
 *   provisioning of the same tenant from several nodes.
 */
final class DatabaseKeyStore implements AtomicKeyStore
{
    public const MIN_MASTER_KEY_LENGTH = 32;

    private readonly string $masterKey;

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly AeadCipher $cipher,
        #[SensitiveParameter] ?string $masterKey,
        private readonly ?string $connection = null,
        private readonly string $table = 'rag_kms_keys',
    ) {
        if ($masterKey === null || strlen($masterKey) < self::MIN_MASTER_KEY_LENGTH) {
            throw new EncryptionException(sprintf(
                'The database KMS key store requires a master key of at least %d characters '
                .'(set RAG_KMS_MASTER_KEY, e.g. `php -r "echo base64_encode(random_bytes(32));"`). '
                .'KEK material is never stored unencrypted in the database.',
                self::MIN_MASTER_KEY_LENGTH,
            ));
        }

        // Derive a fixed 32-byte AEAD key from the master secret.
        $this->masterKey = hash('sha256', $masterKey, true);
    }

    public function has(string $keyId): bool
    {
        return $this->rows()->where('key_hash', $this->hash($keyId))->exists();
    }

    public function get(string $keyId): ?string
    {
        $material = $this->rows()->where('key_hash', $this->hash($keyId))->value('material');

        if (! is_string($material)) {
            return null;
        }

        return $this->open($keyId, $material);
    }

    public function put(string $keyId, string $material): void
    {
        if ($this->add($keyId, $material)) {
            return;
        }

        // Rotation: replace the material, keep the original creation time.
        $this->rows()
            ->where('key_hash', $this->hash($keyId))
            ->update(['material' => $this->seal($keyId, $material), 'updated_at' => now()]);
    }

    public function add(string $keyId, string $material): bool
    {
        $now = now();

        return $this->rows()->insertOrIgnore([
            'key_hash' => $this->hash($keyId),
            'material' => $this->seal($keyId, $material),
            'created_at' => $now,
            'updated_at' => $now,
        ]) > 0;
    }

    public function forget(string $keyId): void
    {
        // Hard delete — destroying the KEK is what crypto-shreds the data.
        $this->rows()->where('key_hash', $this->hash($keyId))->delete();
    }

    private function seal(string $keyId, #[SensitiveParameter] string $material): string
    {
        $envelope = json_encode(['k' => $this->hash($keyId), 'm' => base64_encode($material)], JSON_THROW_ON_ERROR);

        return $this->cipher->encrypt($this->masterKey, $envelope);
    }

    private function open(string $keyId, string $sealed): string
    {
        $envelope = $this->cipher->decrypt($this->masterKey, $sealed);

        try {
            $decoded = json_decode($envelope, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }

        $material = is_array($decoded) && is_string($decoded['m'] ?? null)
            ? base64_decode($decoded['m'], true)
            : false;

        if (! is_array($decoded) || ($decoded['k'] ?? null) !== $this->hash($keyId) || $material === false) {
            throw new EncryptionException("KEK record for [{$keyId}] is corrupt or does not belong to this key.");
        }

        return $material;
    }

    private function hash(string $keyId): string
    {
        return hash('sha256', $keyId);
    }

    private function rows(): Builder
    {
        return $this->db->connection($this->connection)->table($this->table);
    }
}
