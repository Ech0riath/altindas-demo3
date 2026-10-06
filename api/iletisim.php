<?php
declare(strict_types=1);
/**
 * İletişim ve keşif talebi uç noktası. Bağımlılık yok (PHPMailer yok), mail() kullanır.
 * Sırlar kodda YOK: config.php (public_html DIŞINDA) dosyasından okunur. Bkz. README.md.
 * NOT: Yerelde çalıştırılıp denenmedi (PHP kurulu değildi); sözdizimi gözle kontrol edildi.
 */

// ---- Varsayılanlar (config.php ile ezilir) ----
$ALICI           = 'info@altindasmuhendislik.com';
$GONDEREN        = 'noreply@altindasmuhendislik.com'; // alan adına ait bir adres olmalı (SPF/teslim edilebilirlik)
$TURNSTILE_SECRET = '';                               // boşsa Turnstile atlanır
$MIN_SURE        = 3;                                 // saniye
$SAAT_LIMIT      = 5;                                 // aynı IP, saatte en çok (zaman damgalı)
$SAAT_LIMIT_TSIZ = 2;                                 // zaman damgası yoksa (JS'siz) daha sıkı

$cfg = __DIR__ . '/../../config.php';
if (is_file($cfg)) {
    require $cfg; // yukarıdaki değişkenleri tanımlayabilir
}

// ---- Yardımcılar ----
$json_ister = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

function bitir(bool $ok, int $kod, string $form, bool $json): void
{
    if ($json) {
        http_response_code($kod);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok]);
        exit;
    }
    // Sabit yol (Referer'a güvenilmez): açık yönlendirme olmaz.
    $yol = $form === 'kesif' ? '/kesif-talebi/' : '/iletisim/';
    $durum = $ok ? 'ok' : 'hata';
    header('Location: ' . $yol . '?durum=' . $durum . '#durum-' . $durum, true, 303);
    exit;
}

function temiz(string $s, int $max): string
{
    $s = str_replace("\0", '', $s);
    $s = preg_replace('/[^\P{C}\n\t]/u', '', $s) ?? '';  // kontrol karakterlerini at (satır sonu hariç)
    $s = trim($s);
    return mb_substr($s, 0, $max, 'UTF-8');
}

function baslik_guvenli(string $s): string
{
    return trim(preg_replace('/[\r\n]+/', ' ', $s) ?? '');
}

// ---- Yöntem ve içerik türü ----
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
$ct = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
if (strpos($ct, 'application/x-www-form-urlencoded') !== 0 && strpos($ct, 'multipart/form-data') !== 0) {
    http_response_code(415);
    exit;
}

$form = (($_POST['form'] ?? '') === 'kesif') ? 'kesif' : 'iletisim';

// ---- Honeypot: doluysa sessizce "başarılı" göster, gönderme ----
if (($_POST['web_sitesi'] ?? '') !== '') {
    bitir(true, 200, $form, $json_ister);
}

// ---- Zaman damgası ----
$ts = (string)($_POST['ts'] ?? '');
$tsVar = ctype_digit($ts) && $ts !== '';
if ($tsVar) {
    $gecen = time() - (int)$ts;
    if ($gecen < $MIN_SURE || $gecen > 86400 * 2) {
        bitir(false, 422, $form, $json_ister);
    }
}

// ---- IP hız sınırı (dosya tabanlı) ----
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$dosya = sys_get_temp_dir() . '/altindas_iletisim_' . hash('sha256', $ip) . '.json';
$simdi = time();
$kayit = [];
$fh = @fopen($dosya, 'c+');
if ($fh !== false) {
    flock($fh, LOCK_EX);
    $ham = stream_get_contents($fh);
    $kayit = $ham ? (json_decode($ham, true) ?: []) : [];
    $kayit = array_values(array_filter($kayit, static fn($t) => is_int($t) && $t > $simdi - 3600));
    $limit = $tsVar ? $SAAT_LIMIT : $SAAT_LIMIT_TSIZ;
    if (count($kayit) >= $limit) {
        flock($fh, LOCK_UN);
        fclose($fh);
        bitir(false, 429, $form, $json_ister);
    }
    $kayit[] = $simdi;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($kayit));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

// ---- Turnstile (yalnız TURNSTILE_SECRET doluysa) ----
if ($TURNSTILE_SECRET !== '') {
    $tok = (string)($_POST['cf-turnstile-response'] ?? '');
    $ok = false;
    if ($tok !== '') {
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query(['secret' => $TURNSTILE_SECRET, 'response' => $tok, 'remoteip' => $ip]),
            'timeout' => 5,
        ]]);
        $yanit = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);
        $j = $yanit ? json_decode($yanit, true) : null;
        $ok = is_array($j) && ($j['success'] ?? false) === true;
    }
    if (!$ok) {
        bitir(false, 422, $form, $json_ister);
    }
}

// ---- Alanlar ve doğrulama ----
$ad      = temiz((string)($_POST['ad'] ?? ''), 80);
$firma   = temiz((string)($_POST['firma'] ?? ''), 120);
$telefon = temiz((string)($_POST['telefon'] ?? ''), 20);
$eposta  = temiz((string)($_POST['eposta'] ?? ''), 120);
$mesaj   = temiz((string)($_POST['mesaj'] ?? ''), 2000);
$tesis   = temiz((string)($_POST['tesis_turu'] ?? ''), 40);
$kvkk    = ($_POST['kvkk'] ?? '') === '1';

$izinliTesis = ['Fabrika', 'Trafo merkezi', 'OSB tesisi', 'Konut / site', 'Diğer'];
if ($tesis !== '' && !in_array($tesis, $izinliTesis, true)) {
    $tesis = '';
}
$izinliHizmet = ['Kompanzasyon', 'Trafo Bakımı', 'İşletme Sorumluluğu', 'Endüstriyel Otomasyon', 'Elektrik Taahhüt Proje', 'Kamera Sistemleri'];
$hizmetler = [];
if (isset($_POST['hizmet']) && is_array($_POST['hizmet'])) {
    foreach ($_POST['hizmet'] as $h) {
        if (is_string($h) && in_array($h, $izinliHizmet, true)) {
            $hizmetler[] = $h;
        }
    }
}

$telSade = preg_replace('/[\s\-()]/', '', $telefon) ?? '';
$gecerli = $ad !== ''
    && $kvkk
    && preg_match('/^(?:\+?90|0)?[2-5]\d{9}$/', $telSade) === 1
    && ($eposta === '' || filter_var($eposta, FILTER_VALIDATE_EMAIL) !== false)
    && ($form === 'kesif' || $mesaj !== '');
if (!$gecerli) {
    bitir(false, 422, $form, $json_ister);
}

// ---- E-posta ----
$konuEtiket = $form === 'kesif' ? 'Keşif talebi' : 'İletişim formu';
$konu = '=?UTF-8?B?' . base64_encode($konuEtiket . ': ' . baslik_guvenli($ad)) . '?=';

$govde  = $konuEtiket . "\n\n";
$govde .= "Ad soyad : $ad\n";
if ($firma !== '')  { $govde .= "Firma    : $firma\n"; }
$govde .= "Telefon  : $telefon\n";
if ($eposta !== '') { $govde .= "E-posta  : $eposta\n"; }
if ($tesis !== '')  { $govde .= "Tesis    : $tesis\n"; }
if ($hizmetler)     { $govde .= 'Hizmet   : ' . implode(', ', $hizmetler) . "\n"; }
if ($mesaj !== '')  { $govde .= "\nMesaj:\n$mesaj\n"; }
$govde .= "\nKVKK aydınlatma metni onaylandı: evet\n";
$govde .= 'Tarih: ' . date('Y-m-d H:i:s') . "\n";

$basliklar  = "MIME-Version: 1.0\r\n";
$basliklar .= "Content-Type: text/plain; charset=UTF-8\r\n";
$basliklar .= "Content-Transfer-Encoding: 8bit\r\n";
$basliklar .= 'From: ' . $GONDEREN . "\r\n";
if ($eposta !== '') {
    $basliklar .= 'Reply-To: ' . baslik_guvenli($eposta) . "\r\n";
}

$gitti = @mail($ALICI, $konu, $govde, $basliklar);

// Onay e-postası (yalnız geçerli e-posta verildiyse; en iyi çaba)
if ($gitti && $eposta !== '') {
    $onayKonu = '=?UTF-8?B?' . base64_encode('Talebiniz bize ulaştı: Altındaş Mühendislik & Elektrik') . '?=';
    $onay  = "Merhaba $ad,\n\n";
    $onay .= "Mesajınız bize ulaştı. En kısa sürede dönüş yapacağız.\n";
    $onay .= "Acil durumlarda +90 538 447 56 76 numarasından bize ulaşabilirsiniz.\n\n";
    $onay .= "Altındaş Mühendislik & Elektrik\n";
    $onayB  = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
    $onayB .= 'From: ' . $GONDEREN . "\r\n";
    @mail($eposta, $onayKonu, $onay, $onayB);
}

bitir($gitti, $gitti ? 200 : 500, $form, $json_ister);
