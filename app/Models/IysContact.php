<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * İYS aktarım kuyruğu — sipariş verilerinden türetilmiş tekil kişi kayıtları.
 * Bir satır = bir kişi; telefon varsa ARAMA+MESAJ, e-posta varsa EPOSTA izni
 * aynı işlem adımında Netgsm İYS API'sine iletilir.
 */
class IysContact extends Model
{
    protected $fillable = [
        'tenant_id', 'phone', 'email', 'name', 'first_order_at', 'first_order_no',
        'order_source', 'status', 'types_result', 'attempts', 'last_attempt_at',
        'sent_at', 'last_error',
    ];

    protected $casts = [
        'types_result' => 'array',
        'first_order_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    /** Bu kişi için gönderilmesi planlanan İYS izin tipleri. */
    public function plannedTypes(): array
    {
        $types = [];
        if ($this->phone) {
            $types[] = 'ARAMA';
            $types[] = 'MESAJ';
        }
        if ($this->email) {
            $types[] = 'EPOSTA';
        }

        return $types;
    }

    /** Henüz başarıyla iletilmemiş tipler (retry'da yalnız eksikler gider). */
    public function missingTypes(): array
    {
        $done = collect($this->types_result ?? [])
            ->filter(fn ($r) => ($r['ok'] ?? false) === true)
            ->keys()->all();

        return array_values(array_diff($this->plannedTypes(), $done));
    }
}
