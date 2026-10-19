<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kampanyalar: segment hedefleme (liste alternatifi) + e-posta kanalı içeriği
        Schema::table('marketing_campaigns', function (Blueprint $table) {
            $table->unsignedBigInteger('segment_id')->nullable()->after('list_id');
            $table->string('subject', 255)->nullable()->after('coupon_code');
            $table->text('body_html')->nullable()->after('subject');
        });

        // Alıcılar: e-posta kampanyalarında telefon yok, email var
        DB::statement('alter table marketing_campaign_recipients alter column phone drop not null');
        Schema::table('marketing_campaign_recipients', function (Blueprint $table) {
            $table->string('email')->nullable()->after('phone');
        });
        DB::statement('create unique index mcr_campaign_email_uq on marketing_campaign_recipients (campaign_id, email) where email is not null');
        DB::statement('create index mcr_tenant_email_ix on marketing_campaign_recipients (tenant_id, email) where email is not null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists mcr_campaign_email_uq');
        DB::statement('drop index if exists mcr_tenant_email_ix');
        Schema::table('marketing_campaign_recipients', function (Blueprint $table) {
            $table->dropColumn('email');
        });
        Schema::table('marketing_campaigns', function (Blueprint $table) {
            $table->dropColumn(['segment_id', 'subject', 'body_html']);
        });
    }
};
