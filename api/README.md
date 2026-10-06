# /api/iletisim.php

İletişim ve keşif talebi formlarının uç noktası. Yalnız POST; `mail()` kullanır (bağımlılık yok). Gönderim sonrası forma `?durum=ok|hata` ile döner (JSON isteğinde `{"ok":true|false}`).

## Sırlar: config.php (public_html DIŞINDA)

`iletisim.php` dosyası `__DIR__ . '/../../config.php'` yolunu okur. Sunucuda `public_html/api/iletisim.php` ise bu yol `public_html/../config.php`, yani **public_html'in bir üstü** (web'den erişilemez). Dosya yoksa varsayılanlarla çalışır.

```php
<?php
// config.php: depoya GİRMEZ, rsync --exclude 'config*' ile korunur
$ALICI            = 'info@altindasmuhendislik.com';
$GONDEREN         = 'noreply@altindasmuhendislik.com'; // alan adına ait bir posta kutusu olmalı
$TURNSTILE_SECRET = '';                                // boş = Turnstile kapalı
// $MIN_SURE = 3; $SAAT_LIMIT = 5; $SAAT_LIMIT_TSIZ = 2;
```

## Turnstile'ı açma

1. Cloudflare panelinde Turnstile site oluştur (alan adı: altindasmuhendislik.com); site anahtarı ve gizli anahtarı al.
2. Gizli anahtarı `config.php` içindeki `$TURNSTILE_SECRET` değerine yaz.
3. Site anahtarını derleme ortamına `PUBLIC_TURNSTILE_SITEKEY` olarak ver, siteyi yeniden derle (formlar `cf-turnstile` kutusunu ve betiğini ancak bu değişken doluysa basar).
4. Tek başına birini açmak formu bozar: ikisi birlikte açılmalı. KVKK/çerez metninde Cloudflare'in anılması gerekebilir.

## Korumalar

Yalnız POST ve form içerik türü · honeypot (`web_sitesi`) · zaman damgası ≥ 3 sn (JS'siz gönderimde damga boştur, daha sıkı IP limiti uygulanır) · alan uzunluk sınırları · e-posta `filter_var` · telefon regex · IP başına saatlik limit (`sys_get_temp_dir()` altında dosya) · başlık enjeksiyonuna karşı satır sonu temizliği · yönlendirme sabit yola.

## Notlar

- Sunucuda PHP `mail()` kapalıysa gönderim başarısız olur ve kullanıcı telefon bilgisini gören hata mesajını görür. Teslim edilebilirlik için `$GONDEREN` alan adına ait olmalı; SMTP gerekirse ayrı karar (PHPMailer bilerek kullanılmadı).
- Yerelde PHP ile denenmedi.
