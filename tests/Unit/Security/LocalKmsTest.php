<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Exceptions\EncryptionException;
use Sellinnate\RagEngine\Security\AeadCipher;
use Sellinnate\RagEngine\Security\Kms\ArrayKeyStore;
use Sellinnate\RagEngine\Security\Kms\KeyStore;
use Sellinnate\RagEngine\Security\Kms\LocalKms;

beforeEach(function () {
    $this->kms = new LocalKms(new ArrayKeyStore, new AeadCipher);
});

it('generates and unwraps a data key', function () {
    $dataKey = $this->kms->generateDataKey('tenant-1');

    expect($dataKey->keyId)->toBe('tenant-1')
        ->and(strlen($dataKey->plaintext))->toBe(32)
        ->and($this->kms->unwrapDataKey('tenant-1', $dataKey->wrapped))->toBe($dataKey->plaintext);
});

it('auto-creates the KEK on first data key generation', function () {
    expect($this->kms->keyExists('new'))->toBeFalse();

    $this->kms->generateDataKey('new');

    expect($this->kms->keyExists('new'))->toBeTrue();
});

it('crypto-shreds: destroyed key cannot unwrap previously wrapped DEKs', function () {
    $dataKey = $this->kms->generateDataKey('tenant-2');

    $this->kms->destroyKey('tenant-2');

    expect($this->kms->keyExists('tenant-2'))->toBeFalse();

    $this->kms->unwrapDataKey('tenant-2', $dataKey->wrapped);
})->throws(EncryptionException::class);

it('keeps old DEKs unwrappable after rotation (non-destructive, FR-SEC-05)', function () {
    $beforeRotation = $this->kms->generateDataKey('tenant-3');

    $this->kms->rotateKey('tenant-3');

    $afterRotation = $this->kms->generateDataKey('tenant-3');

    // Both the pre- and post-rotation DEKs still unwrap.
    expect($this->kms->unwrapDataKey('tenant-3', $beforeRotation->wrapped))->toBe($beforeRotation->plaintext)
        ->and($this->kms->unwrapDataKey('tenant-3', $afterRotation->wrapped))->toBe($afterRotation->plaintext);
});

it('wraps new DEKs with the NEWEST KEK version, not an older one (M2)', function () {
    $store = new ArrayKeyStore;
    $kms = new LocalKms($store, new AeadCipher);

    $beforeRotation = $kms->generateDataKey('tenant-r');
    $kms->rotateKey('tenant-r');
    $afterRotation = $kms->generateDataKey('tenant-r');

    // Rebuild a KMS whose keystore retains ONLY the newest KEK version.
    $versions = json_decode((string) $store->get('tenant-r'), true);
    expect($versions)->toHaveCount(2);

    $newestOnly = new ArrayKeyStore;
    $newestOnly->put('tenant-r', json_encode([end($versions)]));
    $isolated = new LocalKms($newestOnly, new AeadCipher);

    // The post-rotation DEK must unwrap under the newest KEK alone...
    expect($isolated->unwrapDataKey('tenant-r', $afterRotation->wrapped))->toBe($afterRotation->plaintext);

    // ...while the pre-rotation DEK (wrapped by the old KEK) must NOT.
    expect(fn () => $isolated->unwrapDataKey('tenant-r', $beforeRotation->wrapped))
        ->toThrow(EncryptionException::class);
});

it('isolates keys across tenants', function () {
    $a = $this->kms->generateDataKey('tenant-a');
    $b = $this->kms->generateDataKey('tenant-b');

    // A DEK wrapped under tenant-a must not unwrap under tenant-b.
    expect(fn () => $this->kms->unwrapDataKey('tenant-b', $a->wrapped))
        ->toThrow(EncryptionException::class)
        ->and($this->kms->unwrapDataKey('tenant-b', $b->wrapped))->toBe($b->plaintext);
});

it('throws when unwrapping under a never-created key', function () {
    $this->kms->unwrapDataKey('ghost', base64_encode(random_bytes(60)));
})->throws(EncryptionException::class);

it('reports its name', function () {
    expect($this->kms->name())->toBe('local');
});

it('createKey is idempotent and does not rotate existing material', function () {
    $this->kms->createKey('k');
    $dek = $this->kms->generateDataKey('k');
    $this->kms->createKey('k'); // no-op

    expect($this->kms->unwrapDataKey('k', $dek->wrapped))->toBe($dek->plaintext);
});

it('decides existence from the key material, not a possibly stale has()', function () {
    // A shared store whose has() reads an old snapshot (MySQL REPEATABLE READ)
    // while get() is a locking read that sees the committed key.
    $inner = new ArrayKeyStore;
    $stale = new class($inner) implements KeyStore
    {
        public function __construct(private readonly ArrayKeyStore $inner) {}

        public function has(string $keyId): bool
        {
            return false;
        }

        public function get(string $keyId): ?string
        {
            return $this->inner->get($keyId);
        }

        public function put(string $keyId, string $material): void
        {
            $this->inner->put($keyId, $material);
        }

        public function forget(string $keyId): void
        {
            $this->inner->forget($keyId);
        }
    };

    $writer = new LocalKms($inner, new AeadCipher);
    $dek = $writer->generateDataKey('tenant-x');

    $reader = new LocalKms($stale, new AeadCipher);
    expect($reader->unwrapDataKey('tenant-x', $dek->wrapped))->toBe($dek->plaintext);

    // Rotation through the stale view keeps every existing version.
    $reader->rotateKey('tenant-x');
    expect($writer->unwrapDataKey('tenant-x', $dek->wrapped))->toBe($dek->plaintext);
});
