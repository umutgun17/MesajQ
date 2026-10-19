<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'product_reviews' => ['parent_table' => 'products', 'fk' => 'product_id'],
        'product_review_summaries' => ['parent_table' => 'products', 'fk' => 'product_id'],
        'product_variants' => ['parent_table' => 'products', 'fk' => 'product_id'],
        'order_items' => ['parent_table' => 'orders', 'fk' => 'order_id'],
        'messages' => ['parent_table' => 'conversations', 'fk' => 'conversation_id'],
        'comments' => ['parent_table' => 'conversations', 'fk' => 'conversation_id'],
    ];

    public function up(): void
    {
        // Step 1: Add tenant_id column to all child tables
        foreach (array_keys($this->tables) as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('tenant_id')->nullable()->index()->after('id');
                $blueprint->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            });
        }

        // comment_replies is special: parent is comments, which chains to conversations
        Schema::table('comment_replies', function (Blueprint $blueprint) {
            $blueprint->unsignedBigInteger('tenant_id')->nullable()->index()->after('id');
            $blueprint->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });

        // Step 2: Backfill tenant_id from parent tables
        foreach ($this->tables as $table => $config) {
            DB::statement("
                UPDATE {$table}
                SET tenant_id = parent.tenant_id
                FROM {$config['parent_table']} AS parent
                WHERE {$table}.{$config['fk']} = parent.id
                  AND {$table}.tenant_id IS NULL
            ");
        }

        // comment_replies: chain through comments → conversations
        DB::statement("
            UPDATE comment_replies
            SET tenant_id = c.tenant_id
            FROM comments cm
            JOIN conversations c ON cm.conversation_id = c.id
            WHERE comment_replies.comment_id = cm.id
              AND comment_replies.tenant_id IS NULL
        ");
    }

    public function down(): void
    {
        $allTables = array_merge(array_keys($this->tables), ['comment_replies']);

        foreach ($allTables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropForeign([$table === 'comment_replies' ? 'comment_replies_tenant_id_foreign' : "{$table}_tenant_id_foreign"]);
                $blueprint->dropColumn('tenant_id');
            });
        }
    }
};
