<?php

namespace App\Services;

use App\Models\Channel;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NetgsmService
{
    protected string $usercode = '';
    protected string $password = '';
    protected string $baseUrl = 'https://api.netgsm.com.tr';

    public static function fromChannel(Channel $channel): self
    {
        $instance = new self();
        $creds = $channel->credentials ?? [];
        $instance->usercode = $creds['netgsm_usercode'] ?? '';
        $instance->password = $creds['netgsm_password'] ?? '';

        return $instance;
    }

    public function isAvailable(): bool
    {
        return ! empty($this->usercode) && ! empty($this->password);
    }

    /**
     * Tek SMS gönder (NetGSM REST get). Yalnızca CANLI bağlamda çağrılmalı —
     * dry-run kontrolü çağıran katmandadır (ReturnNotifier).
     *
     * @return array{success:bool, code:?string, error:?string}
     */
    public function sendSms(string $gsmno, string $message, string $msgheader, ?string $encoding = null): array
    {
        $gsm = preg_replace('/\D+/', '', $gsmno);
        if (strlen($gsm) < 10) {
            return ['success' => false, 'code' => null, 'error' => 'Geçersiz telefon numarası.'];
        }

        // Türkçe karakter (ç ğ ı ş İ vb.) GET endpoint'inde ASCII'ye düşüyor. encoding='tr'
        // verilirse REST v2 (TR) ile gönderilir → tam Türkçe. Transport hatasında GET'e düşer.
        if ($encoding === 'tr') {
            $rest = $this->sendViaRestTr($gsm, $message, $msgheader);
            if ($rest !== null) {
                return $rest;
            }
        }

        try {
            $resp = Http::timeout(20)->asForm()->get("{$this->baseUrl}/sms/send/get", [
                'usercode' => $this->usercode,
                'password' => $this->password,
                'gsmno' => $gsm,
                'message' => $message,
                'msgheader' => $msgheader,
            ]);

            $body = trim($resp->body());
            // NetGSM: "00 <jobid>" / "01" / "02" başarı; aksi hata kodu
            $code = explode(' ', $body)[0] ?? '';
            $ok = $resp->successful() && in_array($code, ['00', '01', '02'], true);

            if (! $ok) {
                Log::warning('[NetGSM][SMS] gönderim hatası', ['gsm' => $gsm, 'body' => $body]);
            }

            return ['success' => $ok, 'code' => $code ?: null, 'error' => $ok ? null : ('NetGSM kodu: ' . $body)];
        } catch (\Throwable $e) {
            Log::error('[NetGSM][SMS] bağlantı hatası', ['err' => $e->getMessage()]);

            return ['success' => false, 'code' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * REST v2 (TR encoding) ile Türkçe karakter destekli gönderim.
     * Başarı/NetGSM-hatası → array döner; transport (bağlantı) hatası → null
     * (çağıran GET'e düşer, SMS hiç gitmemekten iyidir → ASCII gider).
     *
     * @return array{success:bool, code:?string, error:?string}|null
     */
    protected function sendViaRestTr(string $gsm, string $message, string $msgheader): ?array
    {
        try {
            $resp = Http::timeout(20)
                ->withBasicAuth($this->usercode, $this->password)
                ->acceptJson()
                ->post("{$this->baseUrl}/sms/rest/v2/send", [
                    'msgheader' => $msgheader,
                    'encoding' => 'TR',
                    'messages' => [['msg' => $message, 'no' => $gsm]],
                ]);

            $json = $resp->json() ?? [];
            $code = (string) ($json['code'] ?? '');
            $ok = $resp->successful() && in_array($code, ['00', '01', '02'], true);

            if (! $ok) {
                Log::warning('[NetGSM][REST-TR] gönderim hatası', ['gsm' => $gsm, 'body' => $resp->body()]);

                return ['success' => false, 'code' => $code ?: null, 'error' => 'NetGSM REST kodu: ' . $resp->body()];
            }

            return ['success' => true, 'code' => $json['jobid'] ?? $code, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('[NetGSM][REST-TR] bağlantı hatası, GET fallback', ['err' => $e->getMessage()]);

            return null; // transport hatası → çağıran GET (ASCII) ile dener
        }
    }

    /**
     * CDR (Call Detail Records) çek
     *
     * @param string $startDate  Format: ddMMyyyyHHmm
     * @param string $endDate    Format: ddMMyyyyHHmm
     */
    /**
     * NetGSM GELEN SMS kutusu (REST v2). Müşteri, kendisine gönderdiğimiz
     * bilgilendirmeye cevap yazdığında mesaj burada birikir — panelde
     * görünmesi için `sms:poll-inbox` komutu bunu dakikalarla tarar.
     *
     * ⚠️ Gönderici başlığımız harf tabanlı ("TONNY BLACK") olduğu için müşteri
     * o başlığa CEVAP YAZAMAZ; yanıtın kutuya düşmesi için SMS metnine yanıt
     * numarası (NetGSM hattı, usercode = 0850 numarası) eklenir.
     *
     * @param  string  $start  ddMMyyyyHHmm
     * @param  string  $stop   ddMMyyyyHHmm
     * @return array<int, array{id:string, gonderen:string, mesaj:string, kayittar:string, operator:?string}>|null
     */
    public function getInbox(string $start, string $stop): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        try {
            $resp = Http::timeout(25)
                ->withBasicAuth($this->usercode, $this->password)
                ->get("{$this->baseUrl}/sms/rest/v2/inbox", [
                    'startdate' => $start,
                    'stopdate' => $stop,
                ]);

            // DİKKAT: NetGSM "mesaj yok" durumunu HTTP 406 + code 40 ile döner
            // (failed() true olur). Önce gövdedeki kodu oku, sonra hata de.
            $code = (string) $resp->json('code', '');

            if ($code === '00') {
                return array_values((array) $resp->json('messages', []));
            }

            if ($code === '40') {
                return []; // "Incoming SMS not found" — kutuda yeni mesaj yok
            }

            Log::warning('[NetGSM] inbox okunamadı', [
                'status' => $resp->status(),
                'code' => $code ?: null,
                'body' => mb_substr((string) $resp->body(), 0, 200),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('[NetGSM] inbox exception', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /** Hesabın NetGSM hattı (usercode = 0850 numarası) — SMS'e "yanıt numarası" olarak yazılır. */
    public function lineNumber(): string
    {
        $digits = preg_replace('/\D+/', '', $this->usercode);

        return strlen($digits) === 10 ? '0' . $digits : $digits;
    }

    public function getCdr(string $startDate, string $stopDate): ?array
    {
        try {
            $response = Http::timeout(30)->post("{$this->baseUrl}/netsantral/report", [
                'usercode' => $this->usercode,
                'password' => $this->password,
                'startdate' => $startDate,
                'stopdate' => $stopDate,
            ]);

            if (! $response->successful()) {
                Log::warning('NetGSM CDR API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();

            // Error response: {"code": 32, "description": "..."}
            if (is_array($data) && isset($data['code'])) {
                Log::warning('NetGSM CDR API returned error', [
                    'code' => $data['code'],
                    'description' => $data['description'] ?? '',
                ]);
                return null;
            }

            // Response: array of { uniqueid, values: [{ date, destination, source, duration, direction, line, directory, commonID }] }
            return $data ?? [];
        } catch (\Exception $e) {
            Log::error('NetGSM CDR API exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Ses kaydı dosyasını indir
     */
    public function downloadRecording(string $url, string $savePath): bool
    {
        try {
            $response = Http::timeout(60)->get($url);

            if ($response->successful()) {
                $dir = dirname($savePath);
                if (! is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($savePath, $response->body());
                return true;
            }

            Log::warning('NetGSM recording download failed', [
                'url' => $url,
                'status' => $response->status(),
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error('NetGSM recording download exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * CDR API response'unu flat record listesine dönüştür
     *
     * API format: [{ uniqueid, values: [record, ...] }, ...]
     * Output: [record, record, ...]
     */
    protected function flattenCdrResponse(array $rawResponse): array
    {
        $records = [];
        foreach ($rawResponse as $entry) {
            foreach ($entry['values'] ?? [] as $record) {
                $records[] = $record;
            }
        }
        return $records;
    }

    /**
     * CDR kayıtlarını commonID'ye göre grupla ve tek çağrı kaydına dönüştür
     */
    public function groupCdrRecords(array $rawResponse): array
    {
        $records = $this->flattenCdrResponse($rawResponse);

        $grouped = [];
        foreach ($records as $record) {
            $commonId = $record['commonID'] ?? null;
            if (! $commonId) {
                continue;
            }
            $grouped[$commonId][] = $record;
        }

        $calls = [];
        foreach ($grouped as $commonId => $group) {
            $call = $this->parseCallGroup($commonId, $group);
            if ($call) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    /**
     * Aynı commonID'ye sahip kayıtları tek çağrı verisine dönüştür
     */
    protected function parseCallGroup(string $commonId, array $records): ?array
    {
        // IVR/Puanlama kayıtlarını ayır — bunlar çağrının parçası, ayrı çağrı değil
        $ivrKeywords = ['Puanlama', 'interactive', 'karsilama', 'announcement', 'mesaidisi', 'oglearasi', 'ivr'];
        $operatorRecords = [];
        foreach ($records as $record) {
            $dest = $record['destination'] ?? '';
            $isIvr = false;
            foreach ($ivrKeywords as $kw) {
                if (str_contains($dest, $kw)) {
                    $isIvr = true;
                    break;
                }
            }
            if (! $isIvr) {
                $operatorRecords[] = $record;
            }
        }

        // Tüm kayıtlar IVR ise yine de kaydet (IVR-only çağrı)
        $ivrOnly = empty($operatorRecords);
        if ($ivrOnly) {
            $operatorRecords = $records;
        }

        // En uzun süreli kaydı bul (gerçek konuşma süresi)
        // NetGSM bazen direction=1 vermeden queue-operator üzerinde duration>0 ile cevaplanmış çağrı gösterir
        $bestRecord = null;
        $maxDuration = 0;
        $answeredRecord = null; // direction=1 olan kayıt (varsa)

        foreach ($operatorRecords as $record) {
            $dur = (int) ($record['duration'] ?? 0);

            if (($record['direction'] ?? 0) == 1) {
                // direction=1 kaydını tut, en uzun sürelisini tercih et
                if (! $answeredRecord || $dur > (int) ($answeredRecord['duration'] ?? 0)) {
                    $answeredRecord = $record;
                }
            }

            if ($dur > $maxDuration) {
                $maxDuration = $dur;
                $bestRecord = $record;
            }
        }

        // Ana kaydı belirle: direction=1 varsa onu tercih et, yoksa en uzun süreli kayıt
        $mainRecord = $answeredRecord ?? $bestRecord ?? $operatorRecords[0];
        $caller = $mainRecord['source'] ?? '';
        $called = $mainRecord['destination'] ?? '';

        // Süre: direction=1 varsa onun süresini al, yoksa max duration (bekleme süresi)
        // direction=2 duration'ı kuyruk bekleme/çalma süresidir, konuşma süresi DEĞİLDİR
        $duration = $answeredRecord
            ? (int) ($answeredRecord['duration'] ?? 0)
            : $maxDuration;

        // Dahili numarasını ayıkla (format: "106-8504807557")
        // Öncelik: cevaplanmış kayıt > en uzun süreli kayıt > herhangi biri
        $extension = null;
        $extSources = array_filter([$answeredRecord, $bestRecord]);
        foreach ($extSources as $src) {
            if ($src && preg_match('/^(\d{3})-/', $src['destination'] ?? '', $m)) {
                $extension = $m[1];
                break;
            }
        }
        if (! $extension) {
            foreach ($operatorRecords as $r) {
                if (preg_match('/^(\d{3})-/', $r['destination'] ?? '', $m)) {
                    $extension = $m[1];
                    break;
                }
            }
        }

        // Yön belirleme: source 10 haneden kısa ise dahili (outbound)
        $direction = 'inbound';
        if (strlen($caller) <= 4) {
            $direction = 'outbound';
        }

        // Durum belirleme:
        // - ivrOnly → ivr (sadece IVR/otomat ile etkileşim, operatöre ulaşmamış)
        // - direction=1 olan kayıt varsa + dahili var → answered
        // - direction=1 var ama dahili yok → ivr (IVR cevaplanmış ama insan değil)
        // - duration > 0 ama direction=1 yok → voicemail (kuyrukta beklemiş)
        // - duration 0 → missed (hiç bağlanmamış)
        $status = 'missed';

        if ($ivrOnly) {
            // Tüm kayıtlar IVR — operatöre hiç ulaşmamış
            $status = $maxDuration > 0 ? 'ivr' : 'missed';
        } elseif ($answeredRecord) {
            // direction=1 kaydı var — ama IVR mi yoksa gerçek operatör mü?
            $dest = $answeredRecord['destination'] ?? '';
            $isIvrDest = false;
            foreach ($ivrKeywords as $kw) {
                if (str_contains($dest, $kw)) {
                    $isIvrDest = true;
                    break;
                }
            }

            if ($isIvrDest || ! $extension) {
                $status = 'ivr';
            } else {
                $status = 'answered';
            }
        } elseif ($maxDuration > 0) {
            $status = 'voicemail';
        }

        // Tarih: en erken operatör kaydı (çağrı başlangıcı)
        $dates = array_map(fn ($r) => $r['date'] ?? '', $operatorRecords);
        sort($dates);
        $startedAt = $this->parseDate($dates[0] ?? '');

        // Çalma süresi: ilk kayıt ile cevaplanma arasındaki fark
        $ringDuration = 0;
        $connectedRecord = $answeredRecord;
        if ($connectedRecord && $startedAt) {
            $answeredAt = $this->parseDate($connectedRecord['date'] ?? '');
            if ($answeredAt && $startedAt) {
                $ringDuration = max(0, $answeredAt->diffInSeconds($startedAt));
            }
        }

        // Ses kaydı URL: cevaplanmış/en uzun süreli kayıtta, yoksa tüm kayıtlardan
        $recordingUrl = null;
        $recSources = array_filter([$answeredRecord, $bestRecord]);
        foreach ($recSources as $src) {
            if ($src && ! empty($src['recording'])) {
                $recordingUrl = $src['recording'];
                break;
            }
        }
        if (! $recordingUrl) {
            foreach ($operatorRecords as $r) {
                if (! empty($r['recording'])) {
                    $recordingUrl = $r['recording'];
                    break;
                }
            }
        }

        return [
            'common_id' => $commonId,
            'caller' => $caller,
            'called' => $called,
            'extension' => $extension,
            'direction' => $direction,
            'status' => $status,
            'started_at' => $startedAt,
            'duration' => $duration,
            'ring_duration' => $ringDuration,
            'recording_url' => $recordingUrl,
        ];
    }

    /**
     * NetGSM tarih formatını parse et: "13.03.2026 12:49:07"
     * NetGSM Türkiye saati (Europe/Istanbul, UTC+3) döndürür, DB'ye UTC olarak kaydedilir.
     */
    protected function parseDate(string $dateStr): ?Carbon
    {
        if (empty($dateStr)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('d.m.Y H:i:s', $dateStr, 'Europe/Istanbul')->utc();
        } catch (\Exception) {
            try {
                return Carbon::parse($dateStr, 'Europe/Istanbul')->utc();
            } catch (\Exception) {
                return null;
            }
        }
    }
}
