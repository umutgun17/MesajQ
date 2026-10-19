<?php

namespace App\Console\Commands;

use App\Models\AiTrainingData;
use App\Models\Product;
use App\Services\EmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateEmbeddings extends Command
{
    protected $signature = 'embeddings:generate
        {--target=training : Target table (training or products)}
        {--limit=0 : Max records to process (0 = all)}
        {--batch-size=100 : Batch size for API calls}';

    protected $description = 'Generate embeddings for training data or products using OpenAI';

    public function handle(EmbeddingService $embeddingService): int
    {
        ini_set('memory_limit', '512M');

        if (! $embeddingService->isAvailable()) {
            $this->error('OpenAI API key is not configured.');

            return self::FAILURE;
        }

        $target = $this->option('target');
        $limit = (int) $this->option('limit');
        $batchSize = (int) $this->option('batch-size');

        if (! in_array($target, ['training', 'products'])) {
            $this->error('Invalid target. Use "training" or "products".');

            return self::FAILURE;
        }

        $this->info("Generating embeddings for: {$target}");

        if ($target === 'training') {
            return $this->processTrainingData($embeddingService, $limit, $batchSize);
        }

        return $this->processProducts($embeddingService, $limit, $batchSize);
    }

    protected function processTrainingData(EmbeddingService $embeddingService, int $limit, int $batchSize): int
    {
        $total = AiTrainingData::withoutGlobalScopes()
            ->whereNull('embedding')
            ->where('is_active', true)
            ->count();

        $target = $limit > 0 ? min($limit, $total) : $total;
        $this->info("Records to process: {$target}");

        if ($target === 0) {
            $this->info('No records need embedding.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($target);
        $bar->start();

        $processed = 0;
        $errors = 0;
        $lastId = 0;

        while ($processed + $errors < $target) {
            $take = min($batchSize, $target - $processed - $errors);

            $records = AiTrainingData::withoutGlobalScopes()
                ->whereNull('embedding')
                ->where('is_active', true)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($take)
                ->get();

            if ($records->isEmpty()) {
                break;
            }

            $lastId = $records->last()->id;

            $texts = $records->map(fn ($r) => $r->buildEmbeddingText())->toArray();
            $embeddings = $embeddingService->embedBatch($texts);

            foreach ($records as $i => $record) {
                if (! empty($embeddings[$i])) {
                    DB::table('ai_training_data')
                        ->where('id', $record->id)
                        ->update(['embedding' => json_encode($embeddings[$i])]);
                    $processed++;
                } else {
                    $errors++;
                }
                $bar->advance();
            }

            // Rate limit: 200ms between batches
            usleep(200_000);
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. Processed: {$processed}, Errors: {$errors}");

        return self::SUCCESS;
    }

    protected function processProducts(EmbeddingService $embeddingService, int $limit, int $batchSize): int
    {
        $total = Product::withoutGlobalScopes()
            ->whereNull('embedding')
            ->where('is_active', true)
            ->count();

        $target = $limit > 0 ? min($limit, $total) : $total;
        $this->info("Records to process: {$target}");

        if ($target === 0) {
            $this->info('No records need embedding.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($target);
        $bar->start();

        $processed = 0;
        $errors = 0;
        $lastId = 0;

        while ($processed + $errors < $target) {
            $take = min($batchSize, $target - $processed - $errors);

            $records = Product::withoutGlobalScopes()
                ->with([
                    'reviewSummary' => fn ($q) => $q->withoutGlobalScopes(),
                    'variants' => fn ($q) => $q->withoutGlobalScopes()->where('is_active', true),
                ])
                ->whereNull('embedding')
                ->where('is_active', true)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($take)
                ->get();

            if ($records->isEmpty()) {
                break;
            }

            $lastId = $records->last()->id;

            $texts = $records->map(fn ($p) => $p->buildEmbeddingText())->toArray();

            $embeddings = $embeddingService->embedBatch($texts);

            foreach ($records as $i => $record) {
                if (! empty($embeddings[$i])) {
                    DB::table('products')
                        ->where('id', $record->id)
                        ->update(['embedding' => json_encode($embeddings[$i])]);
                    $processed++;
                } else {
                    $errors++;
                }
                $bar->advance();
            }

            // Free memory from eager-loaded relations
            unset($records, $texts, $embeddings);

            // Rate limit: 200ms between batches
            usleep(200_000);
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. Processed: {$processed}, Errors: {$errors}");

        return self::SUCCESS;
    }
}
