<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Console;

use Illuminate\Console\Command;
use Sellinnate\RagEngine\Console\Concerns\NormalizesInput;
use Sellinnate\RagEngine\Recovery\Reconciler;

/**
 * Vectors ↔ metadata reconciliation report (FR-DX-05, NFR-DR-02).
 */
final class ReconcileCommand extends Command
{
    use NormalizesInput;

    protected $signature = 'rag:reconcile {tenant : The tenant id} {--prune : Delete orphan embedding records}';

    protected $description = 'Report chunks missing vectors and orphan embeddings for a tenant';

    public function handle(Reconciler $reconciler): int
    {
        $tenant = $this->stringArgument('tenant');

        if ($this->option('prune')) {
            $this->info('Pruned '.$reconciler->pruneOrphans($tenant).' orphan embedding record(s).');
        }

        $report = $reconciler->reconcile($tenant);

        $this->table(['Issue', 'Count'], [
            ['Chunks missing embeddings', count($report['missing_embeddings'])],
            ['Orphan embeddings', count($report['orphan_embeddings'])],
        ]);

        if ($reconciler->isConsistent($tenant)) {
            $this->info('Corpus is consistent.');

            return self::SUCCESS;
        }

        $this->warn('Inconsistencies detected — re-index affected documents.');

        return self::FAILURE;
    }
}
