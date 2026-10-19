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
Schedule::command("ticimax:sync-tickets --limit=50 --pages=2")->everyFiveMinutes()->withoutOverlapping(5)->appendOutputTo($log("ticimax-tickets"));
Schedule::command("ticimax:sync-tickets --resync --limit=50 --pages=2")->everyFifteenMinutes()->withoutOverlapping(10)->appendOutputTo($log("ticimax-tickets"));
Schedule::command("email:sync")->everyThreeMinutes()->withoutOverlapping(10)->appendOutputTo($log("email-sync"));
Schedule::command("email:health-check")->twiceDaily(7, 19)->withoutOverlapping(10)->appendOutputTo($log("email-health"));
Schedule::command("call:sync --minutes=10")->everyFiveMinutes()->withoutOverlapping(5)->appendOutputTo($log("call-sync"));
Schedule::call($forEachTenant("wa:sync-quick-replies", ["--refresh" => true]))->hourlyAt(15)->name("wa-quick-replies")->withoutOverlapping(30);

// ── AI ──
Schedule::command("embeddings:generate --target=training --limit=2000")->everyFifteenMinutes()->withoutOverlapping(10)->appendOutputTo($log("embeddings"));
Schedule::command("embeddings:generate --target=products --limit=100")->hourly()->withoutOverlapping(30)->appendOutputTo($log("embeddings"));

// ── Mağaza bağlamı (sipariş/müşteri/ürün) — konuşma yanında sipariş bilgisi, AI cevapları, web chat ──
Schedule::command("crm:sync-orders-live --mode=new")->everyMinute()->withoutOverlapping(5)->appendOutputTo($log("crm-orders"));
Schedule::command("crm:sync-orders-live --mode=changes")->everyTwoMinutes()->withoutOverlapping(5)->appendOutputTo($log("crm-orders"));
Schedule::command("crm:sync-orders-live --mode=status")->hourlyAt(7)->withoutOverlapping(30)->appendOutputTo($log("crm-orders"));
Schedule::command("crm:sync-orders-live --mode=safety")->dailyAt("04:00")->withoutOverlapping(60)->appendOutputTo($log("crm-orders"));
Schedule::command("crm:refresh-open-orders")->everyFifteenMinutes()->withoutOverlapping(14)->appendOutputTo($log("crm-orders"));
Schedule::command("crm:sync-stock --minutes=10")->everyFiveMinutes()->withoutOverlapping(5)->appendOutputTo($log("crm-stock"));
Schedule::command("crm:sync-members --limit=100")->dailyAt("03:30")->withoutOverlapping(120)->appendOutputTo($log("crm-members"));
Schedule::command("crm:sync-products --limit=100")->dailyAt("03:00")->withoutOverlapping(120)->appendOutputTo($log("crm-products"));
Schedule::command("ikas:sync --what=orders")->everyFifteenMinutes()->withoutOverlapping(10)->appendOutputTo($log("ikas-sync"));
Schedule::command("ikas:sync --what=customers")->hourly()->withoutOverlapping(30)->appendOutputTo($log("ikas-sync"));
Schedule::command("search:verify-images")->dailyAt("03:45")->withoutOverlapping(60)->appendOutputTo($log("search-images"));
