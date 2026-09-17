<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sellinnate\RagEngine\Exceptions\EncryptionException;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Ingestion\Ingestor;
use Sellinnate\RagEngine\Managers\KmsManager;
use Sellinnate\RagEngine\Pipeline\IngestionPipeline;
use Sellinnate\RagEngine\Security\AeadCipher;
use Sellinnate\RagEngine\Security\EnvelopeEncrypter;
use Sellinnate\RagEngine\Security\Kms\ArrayKeyStore;
use Sellinnate\RagEngine\Security\Kms\AtomicKeyStore;
use Sellinnate\RagEngine\Security\Kms\DatabaseKeyStore;
use Sellinnate\RagEngine\Security\Kms\LocalKms;

const TEST_MASTER_KEY = 'test-master-key-0123456789abcdef-XYZ';

function dbKeyStore(?string $masterKey = TEST_MASTER_KEY, ?string $connection = null): DatabaseKeyStore
{
    return new DatabaseKeyStore(app(ConnectionResolverInterface::class), new AeadCipher, $masterKey, $connection);
}

it('round-trips KEK material and stores it encrypted', function () {
    $store = dbKeyStore();
    $material = random_bytes(32);

    expect($store->has('tenant-1'))->toBeFalse()
        ->and($store->get('tenant-1'))->toBeNull();

    $store->put('tenant-1', $material);

    expect($store->has('tenant-1'))->toBeTrue()
        ->and($store->get('tenant-1'))->toBe($material);

    $row = DB::table('rag_kms_keys')->first();
    expect($row->key_hash)->toBe(hash('sha256', 'tenant-1'))
        ->and($row->material)->not->toContain(base64_encode($material))
        ->and(json_encode($row))->not->toContain('tenant-1');
});

it('overwrites on put (rotation) and hard-deletes on forget', function () {
    $store = dbKeyStore();
    $store->put('t', 'v1');
    $store->put('t', 'v2');

    expect($store->get('t'))->toBe('v2')
        ->and(DB::table('rag_kms_keys')->count())->toBe(1);

    $store->forget('t');

    expect(DB::table('rag_kms_keys')->count())->toBe(0)
        ->and($store->has('t'))->toBeFalse();
});

it('adds atomically without overwriting an existing key', function () {
    $store = dbKeyStore();

    expect($store->add('t', 'first'))->toBeTrue()
        ->and($store->add('t', 'second'))->toBeFalse()
        ->and($store->get('t'))->toBe('first');
});

it('fails closed without a (long enough) master key', function (?string $key) {
    dbKeyStore($key);
})->with([null, '', 'too-short'])->throws(EncryptionException::class, 'master key');

it('cannot read KEKs with a different master key', function () {
    dbKeyStore()->put('t', 'secret');

    dbKeyStore(str_repeat('x', 40))->get('t');
})->throws(EncryptionException::class);

it('detects a KEK row swapped onto another key id', function () {
    $store = dbKeyStore();
    $store->put('tenant-a', 'a-material');
    $store->put('tenant-b', 'b-material');

    $aRow = DB::table('rag_kms_keys')->where('key_hash', hash('sha256', 'tenant-a'))->value('material');
    DB::table('rag_kms_keys')->where('key_hash', hash('sha256', 'tenant-b'))->update(['material' => $aRow]);

    $store->get('tenant-b');
})->throws(EncryptionException::class, 'does not belong');

it('rejects a sealed record that is not a key envelope', function () {
    $cipher = new AeadCipher;
    DB::table('rag_kms_keys')->insert([
        'key_hash' => hash('sha256', 't'),
        'material' => $cipher->encrypt(hash('sha256', TEST_MASTER_KEY, true), 'not-json'),
    ]);

    dbKeyStore()->get('t');
})->throws(EncryptionException::class, 'corrupt');

it('shares keys across nodes and crypto-shreds for all of them', function () {
    $nodeA = new EnvelopeEncrypter(new LocalKms(dbKeyStore()), new AeadCipher);
    $kmsB = new LocalKms(dbKeyStore());
    $nodeB = new EnvelopeEncrypter($kmsB, new AeadCipher);

    $payload = $nodeA->encrypt('multi-node secret', 'tenant-x');
    expect($nodeB->decrypt($payload))->toBe('multi-node secret');

    // Rotation on B stays readable on A (all KEK versions are shared).
    $kmsB->rotateKey('tenant-x');
    $rotated = $nodeB->encrypt('after rotation', 'tenant-x');
    expect($nodeA->decrypt($rotated))->toBe('after rotation')
        ->and($nodeA->decrypt($payload))->toBe('multi-node secret');

    $kmsB->destroyKey('tenant-x');

    expect(fn () => $nodeA->decrypt($payload))->toThrow(EncryptionException::class);
});

it('creates keys through the atomic add() so a racing node never replaces a KEK', function () {
    $kmsA = new LocalKms(dbKeyStore());
    $wrapped = $kmsA->generateDataKey('tenant-race');

    // Node B lost the race: its has() check ran before A's insert landed.
    $racingStore = new class(dbKeyStore()) implements AtomicKeyStore
    {
        public function __construct(private readonly DatabaseKeyStore $inner) {}

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
            throw new LogicException('createKey must not use put() on an atomic store');
        }

        public function add(string $keyId, string $material): bool
        {
            return $this->inner->add($keyId, $material);
        }

        public function forget(string $keyId): void
        {
            $this->inner->forget($keyId);
        }
    };

    (new LocalKms($racingStore))->createKey('tenant-race');

    expect($kmsA->unwrapDataKey('tenant-race', $wrapped->wrapped))->toBe($wrapped->plaintext);
});

it('resolves the database store from config, on the configured connection', function () {
    config()->set('database.connections.kms_db', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('rag-engine.kms.local.store', 'database');
    config()->set('rag-engine.kms.local.master_key', TEST_MASTER_KEY);
    config()->set('rag-engine.kms.local.connection', 'kms_db');

    (include __DIR__.'/../../../database/migrations/create_rag_kms_keys_table.php.stub')->up();
    expect(Schema::connection('kms_db')->hasTable('rag_kms_keys'))->toBeTrue();

    $kms = app(KmsManager::class)->forgetDrivers()->driver('local');
    $kms->createKey('tenant-cfg');

    expect(DB::connection('kms_db')->table('rag_kms_keys')->count())->toBe(1)
        ->and(DB::table('rag_kms_keys')->count())->toBe(0);

    (include __DIR__.'/../../../database/migrations/create_rag_kms_keys_table.php.stub')->down();
    expect(Schema::connection('kms_db')->hasTable('rag_kms_keys'))->toBeFalse();
});

it('fails closed when store=database has no master key', function () {
    config()->set('rag-engine.kms.local.store', 'database');
    config()->set('rag-engine.kms.local.master_key', null);

    app(KmsManager::class)->forgetDrivers()->driver('local');
})->throws(EncryptionException::class, 'RAG_KMS_MASTER_KEY');

it('fails closed on an unknown or incomplete key store config', function (string $store, ?string $keystore, string $message) {
    config()->set('rag-engine.kms.local.store', $store);
    config()->set('rag-engine.kms.local.keystore', $keystore);

    expect(fn () => app(KmsManager::class)->forgetDrivers()->driver('local'))->toThrow(RagException::class, $message);
})->with([
    ['redis', '/tmp/x', 'Unsupported local KMS key store [redis]'],
    ['file', null, 'RAG_KMS_KEYSTORE'],
]);

it('keeps the in-memory store as the default', function () {
    $kms = app(KmsManager::class)->forgetDrivers()->driver('local');

    $store = (new ReflectionProperty(LocalKms::class, 'store'))->getValue($kms);

    expect($store)->toBeInstanceOf(ArrayKeyStore::class);
});

it('runs the full ingest/search pipeline on the database key store', function () {
    config()->set('rag-engine.kms.local.store', 'database');
    config()->set('rag-engine.kms.local.master_key', TEST_MASTER_KEY);
    app(KmsManager::class)->forgetDrivers();
    foreach ([EnvelopeEncrypter::class, 'rag-engine'] as $abstract) {
        app()->forgetInstance($abstract);
    }
    Rag::clearResolvedInstances();

    $document = app(Ingestor::class)->ingest(Rag::source()->text('Database-held keys protect this text.'));
    app(IngestionPipeline::class)->process($document);

    expect(DB::table('rag_kms_keys')->count())->toBe(1)
        ->and(Rag::search('database keys protect')->first()?->content)->toContain('Database-held keys');
});
