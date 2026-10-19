<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Zamanlanmış görevler — tüm tenant'lar için (tek cron: * * * * * artisan schedule:run)
|--------------------------------------------------------------------------
| Her görev tenant-bağımsızdır; tek-tenant varsayılanı olan komutlar aktif tenant
| listesi üzerinde döngüyle koşturulur.
*/

$log = fn (string $name) => storage_path("logs/{$name}.log");

// Kuyruk işçisi — her dakika taze proses, 55 sn boyunca kuyruğu dinler (webhook mesajları ~1-2 sn).
Schedule::command("queue:work --sleep=1 --max-time=55 --tries=3")
    ->everyMinute()->runInBackground()->withoutOverlapping(2)->appendOutputTo($log("queue-worker"));

// Faturalama: deneme bitişi, yenileme tahsilatı, hatırlatma (her gün 06:00 TR)
Schedule::command("billing:renew")->dailyAt("03:00")->withoutOverlapping(60)->appendOutputTo($log("billing"));

// Kullanıcı aktivitesi: 90 günden eski kayıtları sil
Schedule::command("activity:prune")->dailyAt("04:10")->withoutOverlapping(60);

// Tek-tenant varsayılanı olan komutları aktif tenant'lar üzerinde döndür.
$forEachTenant = function (string $command, array $args = []) {
    return function () use ($command, $args) {
        \App\Models\Tenant::where("is_active", true)->orderBy("id")->each(function ($t) use ($command, $args) {
            try { Artisan::call($command, ["--tenant" => $t->id] + $args); } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning("schedule.$command", ["tenant" => $t->id, "error" => $e->getMessage()]); }
        });
    };
};

// ── Gelen kutusu kanalları ──
Schedule::command("sms:poll-inbox")->everyThreeMinutes()->withoutOverlapping(5)->appendOutputTo($log("sms-inbox"));
Schedule::command("meta:sync-conversations --limit=25")->everyFifteenMinutes()->withoutOverlapping(10)->appendOutputTo($log("meta-sync"));
Schedule::command("trendyol:sync-questions --status=WAITING_FOR_ANSWER --limit=50 --pages=2")->everyFiveMinutes()->withoutOverlapping(5)->appendOutputTo($log("trendyol-questions"));
Schedule::command("trendyol:sync-questions --resync --status=ANSWERED --limit=50 --pages=2")->everyFifteenMinutes()->withoutOverlapping(10)->appendOutputTo($log("trendyol-questions"));
Schedule::command("hepsiburada:sync-questions --status=1 --limit=50 --pages=2")->everyFiveMinutes()->withoutOverlapping(5)->appendOutputTo($log("hepsiburada-questions"));
Schedule::command("hepsiburada:sync-questions --resync --status=2 --limit=50 --pages=2")->everyFifteenMinutes()->withoutOverlapping(10)->appendOutputTo($log("hepsiburada-questions"));
Schedule::command("email:sync")->everyThreeMinutes()->withoutOverlapping(10)->appendOutputTo($log("email-sync"));
Schedule::command("email:health-check")->twiceDaily(7, 19)->withoutOverlapping(10)->appendOutputTo($log("email-health"));
Schedule::call($forEachTenant("wa:sync-quick-replies", ["--refresh" => true]))->hourlyAt(15)->name("wa-quick-replies")->withoutOverlapping(30);

// ── AI ──
Schedule::command("embeddings:generate --target=training --limit=2000")->everyFifteenMinutes()->withoutOverlapping(10)->appendOutputTo($log("embeddings"));
Schedule::command("embeddings:generate --target=products --limit=100")->hourly()->withoutOverlapping(30)->appendOutputTo($log("embeddings"));

