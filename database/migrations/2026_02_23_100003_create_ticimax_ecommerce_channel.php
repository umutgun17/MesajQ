<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Get the tenant_id from the existing ticimax-ticket channel
        $ticketChannel = DB::table('channels')
            ->where('type', 'ticimax-ticket')
            ->first();

        if (! $ticketChannel) {
            return;
        }

        // Check if ticimax-ecommerce already exists
        $exists = DB::table('channels')
            ->where('type', 'ticimax-ecommerce')
            ->where('tenant_id', $ticketChannel->tenant_id)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('channels')->insert([
            'tenant_id' => $ticketChannel->tenant_id,
            'type' => 'ticimax-ecommerce',
            'name' => 'Ticimax E-Ticaret',
            'category' => 'E-Ticaret',
            'is_active' => false,
            'credentials' => $ticketChannel->credentials, // Same WSDL + auth_code
            'settings' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('channels')
            ->where('type', 'ticimax-ecommerce')
            ->delete();
    }
};
