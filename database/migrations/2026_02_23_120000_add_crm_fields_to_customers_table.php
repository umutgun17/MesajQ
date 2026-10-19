<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('channel_id')->nullable()->after('tenant_id')
                ->constrained('channels')->nullOnDelete();
            $table->string('platform_customer_id')->nullable()->after('platform_type');
            $table->string('surname')->nullable()->after('name');
            $table->tinyInteger('gender')->nullable()->after('avatar');
            $table->date('birth_date')->nullable()->after('gender');
            $table->string('city')->nullable()->after('birth_date');
            $table->string('district')->nullable()->after('city');
            $table->text('address')->nullable()->after('district');
            $table->string('member_type')->nullable()->after('address');
            $table->boolean('is_active')->default(true)->after('member_type');
            $table->boolean('mail_consent')->default(false)->after('is_active');
            $table->boolean('sms_consent')->default(false)->after('mail_consent');
            $table->integer('total_orders')->default(0)->after('sms_consent');
            $table->decimal('total_spent', 12, 2)->default(0)->after('total_orders');
            $table->decimal('loyalty_points', 10, 2)->default(0)->after('total_spent');
            $table->timestamp('platform_created_at')->nullable()->after('loyalty_points');
            $table->timestamp('last_login_at')->nullable()->after('platform_created_at');

            $table->unique(['tenant_id', 'channel_id', 'platform_customer_id'], 'customers_tenant_channel_platform_unique');
            $table->index('city');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_tenant_channel_platform_unique');
            $table->dropIndex(['city']);
            $table->dropForeign(['channel_id']);

            $table->dropColumn([
                'channel_id', 'platform_customer_id', 'surname', 'gender',
                'birth_date', 'city', 'district', 'address', 'member_type',
                'is_active', 'mail_consent', 'sms_consent', 'total_orders',
                'total_spent', 'loyalty_points', 'platform_created_at', 'last_login_at',
            ]);
        });
    }
};
