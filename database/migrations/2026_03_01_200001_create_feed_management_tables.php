<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Feed Profiles
        Schema::create('feed_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('template')->default('custom'); // google_shopping, meta_catalog, custom
            $table->string('output_format')->default('rss'); // rss, atom
            $table->string('xml_root_tag')->default('rss');
            $table->string('xml_item_tag')->default('item');
            $table->string('xml_namespace')->nullable();
            $table->string('xml_namespace_prefix')->nullable();
            $table->string('variant_mode')->default('variant'); // variant, product
            $table->string('currency')->default('TRY');
            $table->string('storefront_base_url')->nullable();
            $table->boolean('exclude_out_of_stock')->default(false);
            $table->boolean('exclude_inactive')->default(true);
            $table->boolean('is_active')->default(true);
            $table->jsonb('settings')->nullable();
            $table->timestamp('last_generated_at')->nullable();
            $table->unsignedInteger('product_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        // 2. Feed Sources (external XML/CSV)
        Schema::create('feed_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type'); // xml_url, xml_file, csv_url, csv_file
            $table->text('url')->nullable();
            $table->string('file_path')->nullable();
            $table->string('join_field_internal')->default('sku'); // sku, barcode, platform_product_id
            $table->string('join_field_external');
            $table->string('item_xpath')->default('//item');
            $table->jsonb('parsed_fields')->nullable(); // [{name, xpath, sample_values}]
            $table->jsonb('cached_data')->nullable(); // {join_key -> {field: value}}
            $table->timestamp('last_fetched_at')->nullable();
            $table->string('last_fetch_status')->nullable();
            $table->text('last_fetch_error')->nullable();
            $table->unsignedSmallInteger('fetch_interval_hours')->default(24);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 3. Feed Mappings (XML tag -> source field)
        Schema::create('feed_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feed_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('xml_tag'); // g:title, g:price, etc.
            $table->string('source_type'); // product, variant, ai_content, external, static, combined
            $table->string('source_field')->nullable();
            $table->foreignId('source_id')->nullable()->constrained('feed_sources')->nullOnDelete();
            $table->text('static_value')->nullable();
            $table->text('combine_pattern')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('include_prefix')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['feed_profile_id', 'sort_order']);
        });

        // 4. Feed Transformations (per-mapping transform pipeline)
        Schema::create('feed_transformations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feed_mapping_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // find_replace, prefix, suffix, uppercase, lowercase, titlecase, truncate, default_value, number_format, regex_replace, stock_to_availability
            $table->jsonb('config')->default('{}');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['feed_mapping_id', 'sort_order']);
        });

        // 5. Feed AI Contents (replaces product_feed_overrides for AI)
        Schema::create('feed_ai_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->jsonb('extra_fields')->nullable(); // gender, age_group, google_category, etc.
            $table->text('prompt_used')->nullable();
            $table->string('model_used')->nullable();
            $table->boolean('is_excluded')->default(false);
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'product_id', 'feed_profile_id'], 'feed_ai_contents_unique');
        });

        // Data migration: product_feed_overrides -> feed_ai_contents
        if (Schema::hasTable('product_feed_overrides')) {
            DB::statement("
                INSERT INTO feed_ai_contents (tenant_id, product_id, feed_profile_id, title, description, extra_fields, is_excluded, generated_at, created_at, updated_at)
                SELECT
                    tenant_id,
                    product_id,
                    NULL,
                    ai_title,
                    ai_description,
                    jsonb_build_object(
                        'gender', gender,
                        'age_group', age_group,
                        'google_category', google_category,
                        'material', material,
                        'custom_label_0', custom_label_0,
                        'custom_label_1', custom_label_1
                    ),
                    is_excluded,
                    ai_optimized_at,
                    created_at,
                    updated_at
                FROM product_feed_overrides
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_ai_contents');
        Schema::dropIfExists('feed_transformations');
        Schema::dropIfExists('feed_mappings');
        Schema::dropIfExists('feed_sources');
        Schema::dropIfExists('feed_profiles');
    }
};
