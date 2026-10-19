<?php

namespace App\Support;

/**
 * SSRF koruması için ortak URL doğrulayıcı.
 *
 * Kiracı-kontrollü URL'ler (shop_url, feed url, push endpoint, wsdl_url…) sunucudan
 * fetch edilmeden önce buradan geçmeli. http(s) şeması zorunlu; host'un çözülen TÜM
 * A/AAAA kayıtları private/reserved/link-local ise reddedilir (cloud metadata 169.254.169.254,
 * localhost, iç ağ engellenir). IPv6 dahil. Not: tam DNS-rebinding (TOCTOU) korumasi için
 * çağıran taraf redirect'i kapatmalı — bkz. isPublicHttpUrl kullanımları.
 */
class SafeUrl
{
    public static function isPublicHttpUrl(?string $url): bool
    {
        $url = (string) $url;
        $parts = parse_url($url);
        if (! $parts) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = $parts['host'] ?? '';
        if ($host === '') {
            return false;
        }

        $ips = self::resolveHost($host);
        if (empty($ips)) {
            // Çözülemeyen host = güvenli tarafta kal, engelle
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                // private / reserved / link-local
                return false;
            }
        }

        return true;
    }

    /** Host'a ait tüm IPv4 + IPv6 adreslerini döndürür (host zaten IP ise onu). */
    private static function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (! empty($r['ip'])) {
                    $ips[] = $r['ip'];
                }
                if (! empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }

        // dns_get_record başarısızsa IPv4 fallback
        if (empty($ips)) {
            $v4 = gethostbyname($host);
            if ($v4 !== $host && filter_var($v4, FILTER_VALIDATE_IP)) {
                $ips[] = $v4;
            }
        }

        return $ips;
    }
}
