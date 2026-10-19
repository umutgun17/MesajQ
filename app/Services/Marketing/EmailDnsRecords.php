<?php

namespace App\Services\Marketing;

/**
 * Tenant ayarlarından gönderim DNS kayıtlarını (SPF/DKIM/DMARC) üretir ve
 * canlı DNS'ten doğrular. Komutlar ve panel API'si ortak bu servisi kullanır.
 */
class EmailDnsRecords
{
    /**
     * Eklenmesi gereken DNS kayıtları.
     *
     * @return list<array{purpose:string,type:string,host:string,value:string}>
     */
    public static function records(array $s): array
    {
        $domain = trim((string) ($s['email_sending_domain'] ?? ''));
        $selector = trim((string) ($s['email_dkim_selector'] ?? 'yunbi'));
        $pub = (string) ($s['email_dkim_public'] ?? '');
        $ip = trim((string) ($s['email_sending_ip'] ?? ''));

        if ($domain === '') {
            return [];
        }

        return [
            ['purpose' => 'SPF', 'type' => 'TXT', 'host' => $domain,
                'value' => 'v=spf1' . ($ip !== '' ? " ip4:{$ip}" : '') . ' ~all'],
            ['purpose' => 'DKIM', 'type' => 'TXT', 'host' => "{$selector}._domainkey.{$domain}",
                'value' => 'v=DKIM1; h=sha256; k=rsa; p=' . $pub],
            ['purpose' => 'DMARC', 'type' => 'TXT', 'host' => "_dmarc.{$domain}",
                'value' => "v=DMARC1; p=none; rua=mailto:dmarc@{$domain}"],
        ];
    }

    /**
     * Kayıtları canlı DNS'ten doğrula.
     *
     * @return array{records:list<array{purpose:string,type:string,host:string,value:string,ok:bool}>,verified:bool}
     */
    public static function verify(array $s): array
    {
        $pub = preg_replace('/\s+/', '', (string) ($s['email_dkim_public'] ?? ''));
        $out = [];
        $allOk = true;

        foreach (self::records($s) as $rec) {
            $live = self::txt($rec['host']);
            $needle = match ($rec['purpose']) {
                'SPF' => 'v=spf1',
                'DKIM' => 'v=DKIM1',
                default => 'v=DMARC1',
            };
            $match = self::firstMatching($live, $needle);
            $ok = $match !== null;

            // DKIM'de açık anahtarın da eşleşmesini iste
            if ($ok && $rec['purpose'] === 'DKIM' && $pub !== '') {
                $ok = str_contains(preg_replace('/\s+/', '', $match), substr($pub, 0, 40));
            }

            $out[] = $rec + ['ok' => $ok];
            $allOk = $allOk && $ok;
        }

        return ['records' => $out, 'verified' => $out !== [] && $allOk];
    }

    /** @return list<string> */
    protected static function txt(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT) ?: [];

        return array_map(fn ($r) => (string) ($r['txt'] ?? ''), $records);
    }

    /** @param list<string> $records */
    protected static function firstMatching(array $records, string $needle): ?string
    {
        foreach ($records as $r) {
            if (stripos($r, $needle) !== false) {
                return $r;
            }
        }

        return null;
    }
}
