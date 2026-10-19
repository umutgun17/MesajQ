<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateEmbeddingIndexes extends Command
{
    protected $signature = 'embeddings:create-indexes';

    protected $description = 'Create HNSW indexes on embedding columns for fast semantic search';

    public function handle(): int
    {
        $trainingCount = DB::table('ai_training_data')->whereNotNull('embedding')->count();
        $productCount = DB::table('products')->whereNotNull('embedding')->count();

        $this->info("Training data with embeddings: {$trainingCount}");
        $this->info("Products with embeddings: {$productCount}");

        if ($trainingCount < 100 && $productCount < 100) {
            $this->warn('Too few embeddings. Run embeddings:generate first.');

            return self::FAILURE;
        }

        DB::statement("SET maintenance_work_mem = '512MB'");

        if ($trainingCount >= 100) {
            $this->info('Creating HNSW index on ai_training_data (m=24, ef_construction=200)...');

            DB::statement('DROP INDEX IF EXISTS idx_training_embedding');
            DB::statement('
                CREATE INDEX idx_training_embedding ON ai_training_data
                USING hnsw (embedding vector_cosine_ops) WITH (m = 24, ef_construction = 200)
            ');

            $this->info('Training data HNSW index created.');
        }

        if ($productCount >= 30) {
            $this->info('Creating HNSW index on products (m=24, ef_construction=200)...');

            DB::statement('DROP INDEX IF EXISTS idx_products_embedding');
            DB::statement('
                CREATE INDEX idx_products_embedding ON products
                USING hnsw (embedding vector_cosine_ops) WITH (m = 24, ef_construction = 200)
            ');

            $this->info('Products HNSW index created.');
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
