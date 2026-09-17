<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sellinnate\RagEngine\Audit\AuditLogger;
use Sellinnate\RagEngine\Models\AuditEntry;

/**
 * `audit.db_triggers` (RAG_AUDIT_DB_TRIGGERS) lets hosts that reject
 * CREATE TRIGGER skip the database WORM triggers, while the model-level
 * immutability guard stays in place.
 */
function freshAuditConnection(): void
{
    config()->set('database.connections.audit_fresh', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    DB::purge('audit_fresh');
    config()->set('database.default', 'audit_fresh');
    DB::setDefaultConnection('audit_fresh');
}

function runPackageMigration(string $direction = 'up'): void
{
    (include __DIR__.'/../../../database/migrations/create_rag_engine_tables.php.stub')->{$direction}();
}

/**
 * @return list<string>
 */
function auditTriggers(): array
{
    return DB::table('sqlite_master')->where('type', 'trigger')->orderBy('name')->pluck('name')->all();
}

it('installs the WORM triggers by default', function () {
    freshAuditConnection();
    runPackageMigration();

    expect(auditTriggers())->toBe(['rag_audit_entries_no_delete', 'rag_audit_entries_no_update']);

    app(AuditLogger::class)->log('test.action', 'target');

    expect(fn () => DB::table('rag_audit_entries')->update(['action' => 'tampered']))
        ->toThrow(Exception::class, 'immutable');
});

it('skips the triggers when disabled but keeps the application guard', function () {
    config()->set('rag-engine.audit.db_triggers', false);
    freshAuditConnection();
    runPackageMigration();

    expect(auditTriggers())->toBe([]);

    app(AuditLogger::class)->log('test.action', 'target');
    $entry = AuditEntry::query()->firstOrFail();

    expect(fn () => $entry->update(['action' => 'tampered']))->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $entry->delete())->toThrow(RuntimeException::class, 'immutable');
});

it('treats the string "false" from the environment as disabled', function () {
    config()->set('rag-engine.audit.db_triggers', 'false');
    freshAuditConnection();
    runPackageMigration();

    expect(auditTriggers())->toBe([]);
});

it('rolls the migration back cleanly, including the vector tables', function () {
    freshAuditConnection();
    runPackageMigration();
    runPackageMigration('down');

    expect(Schema::hasTable('rag_documents'))->toBeFalse()
        ->and(Schema::hasTable('rag_vectors'))->toBeFalse()
        ->and(Schema::hasTable('rag_vector_namespaces'))->toBeFalse()
        ->and(auditTriggers())->toBe([]);
});
