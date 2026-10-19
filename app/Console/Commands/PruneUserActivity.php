<?php

namespace App\Console\Commands;

use App\Services\UserActivityRecorder;
use Illuminate\Console\Command;

class PruneUserActivity extends Command
{
    protected $signature = 'activity:prune';

    protected $description = 'Panel kullanıcı aktivite kayıtlarını (giriş + sayfa görüntüleme) saklama süresi sonunda siler';

    public function handle(): int
    {
        $n = UserActivityRecorder::prune();
        $this->info("Silinen: {$n} (saklama " . UserActivityRecorder::RETENTION_DAYS . ' gün)');

        return self::SUCCESS;
    }
}
