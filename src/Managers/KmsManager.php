<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Managers;

use Aws\Kms\KmsClient;
use Illuminate\Database\ConnectionResolverInterface;
use Sellinnate\RagEngine\Contracts\KeyManagement;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Security\AeadCipher;
use Sellinnate\RagEngine\Security\Kms\ArrayKeyStore;
use Sellinnate\RagEngine\Security\Kms\AwsKms;
use Sellinnate\RagEngine\Security\Kms\DatabaseKeyStore;
use Sellinnate\RagEngine\Security\Kms\FileKeyStore;
use Sellinnate\RagEngine\Security\Kms\KeyStore;
use Sellinnate\RagEngine\Security\Kms\LocalKms;

/**
 * Resolves KMS drivers (FR-SEC-02, decision 6.8).
 *
 * Cloud drivers (AWS/GCP/Azure/Vault) register through {@see extend()} so the
 * core ships without those SDKs as hard dependencies (NFR-ES-04).
 *
 * @extends DriverManager<KeyManagement>
 */
final class KmsManager extends DriverManager
{
    protected function configSection(): string
    {
        return 'kms';
    }

    public function getDefaultDriver(): string
    {
        return (string) $this->app->make('config')->get('rag-engine.defaults.kms', 'local');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createLocalDriver(array $config): KeyManagement
    {
        $cipher = new AeadCipher((string) $this->app->make('config')->get('rag-engine.security.cipher', 'aes-256-gcm'));

        return new LocalKms($this->createKeyStore($config, $cipher), $cipher);
    }

    /**
     * The local driver's KEK store: `array` (in-memory), `file` (single node)
     * or `database` (shared, multi-node). Unknown values fail closed.
     *
     * @param  array<string, mixed>  $config
     */
    private function createKeyStore(array $config, AeadCipher $cipher): KeyStore
    {
        $store = $config['store'] ?? 'array';
        $masterKey = isset($config['master_key']) && is_string($config['master_key']) && $config['master_key'] !== ''
            ? $config['master_key']
            : null;

        return match ($store) {
            'array', '' => new ArrayKeyStore,
            'file' => isset($config['keystore']) && is_string($config['keystore']) && $config['keystore'] !== ''
                ? new FileKeyStore($config['keystore'], $cipher, $masterKey)
                : throw new RagException('The file KMS key store requires rag-engine.kms.local.keystore (RAG_KMS_KEYSTORE).'),
            'database' => new DatabaseKeyStore(
                $this->app->make(ConnectionResolverInterface::class),
                $cipher,
                $masterKey,
                connection: isset($config['connection']) && is_string($config['connection']) && $config['connection'] !== '' ? $config['connection'] : null,
                table: (string) $this->app->make('config')->get('rag-engine.tables.kms_keys', 'rag_kms_keys'),
            ),
            default => throw new RagException(sprintf(
                'Unsupported local KMS key store [%s]; use array, file or database.',
                is_scalar($store) ? (string) $store : get_debug_type($store),
            )),
        };
    }

    /**
     * AWS KMS driver. Requires `aws/aws-sdk-php`. Credentials resolve via the
     * standard AWS provider chain unless `key`/`secret` are given in config.
     *
     * @param  array<string, mixed>  $config
     */
    protected function createAwsDriver(array $config): KeyManagement
    {
        if (! class_exists(KmsClient::class)) {
            throw new RagException('The AWS KMS driver requires aws/aws-sdk-php (composer require aws/aws-sdk-php).');
        }

        $args = [
            'region' => (string) ($config['region'] ?? 'us-east-1'),
            'version' => (string) ($config['version'] ?? 'latest'),
        ];

        if (! empty($config['key']) && ! empty($config['secret'])) {
            $args['credentials'] = ['key' => (string) $config['key'], 'secret' => (string) $config['secret']];
        }

        return new AwsKms(
            new KmsClient($args),
            aliasPrefix: (string) ($config['alias_prefix'] ?? 'alias/rag-'),
            deletionWindowDays: (int) ($config['deletion_window_days'] ?? 7),
        );
    }
}
