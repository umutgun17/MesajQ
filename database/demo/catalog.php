<?php

/**
 * Demo tenant (Test Firma) kozmetik kataloğu.
 * Yalnızca `demo:seed` komutu tarafından okunur — canlı veriyle ilgisi yoktur.
 *
 * img alanları public/demo/test-firma/products/pNNN.jpg dosya numaralarıdır.
 */
return [
    // ── CİLT BAKIMI · Serum ────────────────────────────────────────────────
    ['sku' => 'LM-SR-01', 'name' => 'Luméa C+ Aydınlatıcı Vitamin C Serumu 30 ml', 'cat' => 'Serum', 'path' => 'Cilt Bakımı > Serum', 'price' => 749.90, 'disc' => 599.90, 'stock' => 148, 'img' => [1, 8],
     'desc' => '%15 saf L-Askorbik Asit ve E vitamini içeren aydınlatıcı serum. Cilt tonunu eşitler, leke görünümünü azaltır, canlı ve parlak bir görünüm kazandırır. Sabah kullanımı için idealdir.',
     'specs' => ['Hacim' => '30 ml', 'Cilt Tipi' => 'Tüm cilt tipleri', 'Ana Etken' => 'Vitamin C (%15)', 'Kullanım' => 'Sabah', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '30 ml'], ['Hacim' => '50 ml']]],

    ['sku' => 'LM-SR-02', 'name' => 'Luméa HA Yoğun Nemlendirici Hyaluronik Asit Serumu 30 ml', 'cat' => 'Serum', 'path' => 'Cilt Bakımı > Serum', 'price' => 649.90, 'disc' => 519.90, 'stock' => 212, 'img' => [3, 6],
     'desc' => 'Üç farklı moleküler ağırlıkta hyaluronik asit ile cildin derin katmanlarına kadar nem taşır. Kuruluk hissini anında giderir, ince çizgilerin görünümünü azaltır.',
     'specs' => ['Hacim' => '30 ml', 'Cilt Tipi' => 'Kuru & normal', 'Ana Etken' => 'Hyaluronik Asit', 'Kullanım' => 'Sabah & akşam', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '30 ml'], ['Hacim' => '50 ml']]],

    ['sku' => 'LM-SR-03', 'name' => 'Luméa Retinol %0.3 Yenileyici Gece Serumu 30 ml', 'cat' => 'Serum', 'path' => 'Cilt Bakımı > Serum', 'price' => 899.90, 'disc' => 719.90, 'stock' => 64, 'img' => [5, 4],
     'desc' => 'Kapsüllenmiş retinol teknolojisiyle hassasiyet yaratmadan cilt yenilenmesini destekler. İnce çizgiler ve gözenek görünümünü azaltır. Yalnızca gece kullanılır.',
     'specs' => ['Hacim' => '30 ml', 'Cilt Tipi' => 'Olgun & karma', 'Ana Etken' => 'Retinol %0.3', 'Kullanım' => 'Gece', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '30 ml']]],

    ['sku' => 'LM-SR-04', 'name' => 'Luméa Niasinamid %10 + Çinko Gözenek Serumu 30 ml', 'cat' => 'Serum', 'path' => 'Cilt Bakımı > Serum', 'price' => 599.90, 'disc' => 479.90, 'stock' => 305, 'img' => [2, 11],
     'desc' => 'Sebum dengesini düzenler, gözenek görünümünü küçültür ve kızarıklığı azaltır. Yağlı ve karma ciltler için günlük bakım adımı.',
     'specs' => ['Hacim' => '30 ml', 'Cilt Tipi' => 'Yağlı & karma', 'Ana Etken' => 'Niasinamid %10', 'Kullanım' => 'Sabah & akşam', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '30 ml']]],

    ['sku' => 'LM-SR-05', 'name' => 'Luméa Argan & Marula Besleyici Yüz Yağı 30 ml', 'cat' => 'Serum', 'path' => 'Cilt Bakımı > Serum', 'price' => 799.90, 'disc' => 639.90, 'stock' => 87, 'img' => [9, 10],
     'desc' => 'Soğuk sıkım argan ve marula yağlarıyla cilt bariyerini besler. Gece bakımının son adımı olarak birkaç damla yeterlidir.',
     'specs' => ['Hacim' => '30 ml', 'Cilt Tipi' => 'Kuru & hassas', 'Ana Etken' => 'Argan + Marula yağı', 'Kullanım' => 'Gece', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '30 ml']]],

    ['sku' => 'LM-SR-06', 'name' => 'Luméa Peptit Kompleks Sıkılaştırıcı Serum 30 ml', 'cat' => 'Serum', 'path' => 'Cilt Bakımı > Serum', 'price' => 1049.90, 'disc' => 849.90, 'stock' => 41, 'img' => [7, 17],
     'desc' => 'Beş farklı peptit kompleksiyle cildin sıkılık ve elastikiyet görünümünü destekler. Yoğun bakım rutinlerinde retinol ile dönüşümlü kullanılabilir.',
     'specs' => ['Hacim' => '30 ml', 'Cilt Tipi' => 'Olgun cilt', 'Ana Etken' => 'Matrixyl + Argireline', 'Kullanım' => 'Sabah & akşam', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '30 ml']]],

    // ── CİLT BAKIMI · Nemlendirici ─────────────────────────────────────────
    ['sku' => 'LM-NM-01', 'name' => 'Luméa Aqua Boost 72 Saat Nemlendirici Krem 50 ml', 'cat' => 'Nemlendirici', 'path' => 'Cilt Bakımı > Nemlendirici', 'price' => 699.90, 'disc' => 559.90, 'stock' => 264, 'img' => [25, 33],
     'desc' => '72 saate kadar nem desteği sağlayan hafif dokulu jel-krem. Makyaj öncesi bakım adımı olarak da kullanılabilir.',
     'specs' => ['Hacim' => '50 ml', 'Cilt Tipi' => 'Tüm cilt tipleri', 'Ana Etken' => 'Hyaluronik Asit + Gliserin', 'Doku' => 'Jel-krem', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '50 ml'], ['Hacim' => '100 ml']]],

    ['sku' => 'LM-NM-02', 'name' => 'Luméa Ceramide Bariyer Onarıcı Krem 50 ml', 'cat' => 'Nemlendirici', 'path' => 'Cilt Bakımı > Nemlendirici', 'price' => 849.90, 'disc' => 679.90, 'stock' => 176, 'img' => [26, 42],
     'desc' => 'Seramid 1-3-6 II kompleksi ile hasarlı cilt bariyerini onarır. Hassas ve tahriş olmuş ciltlerde yatıştırıcı etki sağlar.',
     'specs' => ['Hacim' => '50 ml', 'Cilt Tipi' => 'Hassas & kuru', 'Ana Etken' => 'Seramid kompleksi', 'Doku' => 'Zengin krem', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '50 ml']]],

    ['sku' => 'LM-NM-03', 'name' => 'Luméa Gece Yenileyici Yoğun Bakım Kremi 50 ml', 'cat' => 'Nemlendirici', 'path' => 'Cilt Bakımı > Nemlendirici', 'price' => 949.90, 'disc' => 759.90, 'stock' => 92, 'img' => [30, 38],
     'desc' => 'Uyku sırasında cildin yenilenme döngüsünü destekleyen zengin gece kremi. Sabah dinlenmiş ve dolgun bir cilt görünümü bırakır.',
     'specs' => ['Hacim' => '50 ml', 'Cilt Tipi' => 'Normal & kuru', 'Ana Etken' => 'Shea yağı + Panthenol', 'Kullanım' => 'Gece', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '50 ml']]],

    ['sku' => 'LM-NM-04', 'name' => 'Luméa Matlaştırıcı Nemlendirici Jel Krem 50 ml', 'cat' => 'Nemlendirici', 'path' => 'Cilt Bakımı > Nemlendirici', 'price' => 629.90, 'disc' => 499.90, 'stock' => 0, 'img' => [29, 34],
     'desc' => 'Yağlı ciltler için parlama karşıtı, gözenek tıkamayan nemlendirici. Gün boyu mat bir görünüm sağlar.',
     'specs' => ['Hacim' => '50 ml', 'Cilt Tipi' => 'Yağlı', 'Ana Etken' => 'Niasinamid + Çinko PCA', 'Doku' => 'Jel', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '50 ml']]],

    ['sku' => 'LM-NM-05', 'name' => 'Luméa Yaşlanma Karşıtı Kolajen Kremi 50 ml', 'cat' => 'Nemlendirici', 'path' => 'Cilt Bakımı > Nemlendirici', 'price' => 1199.90, 'disc' => 959.90, 'stock' => 58, 'img' => [44, 45],
     'desc' => 'Hidrolize kolajen ve bitkisel kök hücre özleriyle dolgunluk hissi veren yoğun bakım kremi.',
     'specs' => ['Hacim' => '50 ml', 'Cilt Tipi' => 'Olgun cilt', 'Ana Etken' => 'Hidrolize kolajen', 'Doku' => 'Zengin krem', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '50 ml']]],

    // ── CİLT BAKIMI · Temizleyici ──────────────────────────────────────────
    ['sku' => 'LM-TM-01', 'name' => 'Luméa Nazik Köpük Yüz Temizleme Jeli 200 ml', 'cat' => 'Temizleyici', 'path' => 'Cilt Bakımı > Temizleyici', 'price' => 399.90, 'disc' => 319.90, 'stock' => 412, 'img' => [67, 71],
     'desc' => 'pH dengeli, sülfatsız formülüyle cildi kurutmadan temizler. Günlük sabah-akşam kullanımı için uygundur.',
     'specs' => ['Hacim' => '200 ml', 'Cilt Tipi' => 'Tüm cilt tipleri', 'Ana Etken' => 'Aloe vera + Panthenol', 'pH' => '5.5', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '200 ml'], ['Hacim' => '400 ml']]],

    ['sku' => 'LM-TM-02', 'name' => 'Luméa Micellar Makyaj Temizleme Suyu 400 ml', 'cat' => 'Temizleyici', 'path' => 'Cilt Bakımı > Temizleyici', 'price' => 349.90, 'disc' => 279.90, 'stock' => 338, 'img' => [72, 73],
     'desc' => 'Durulama gerektirmeden makyajı ve gün içi kirliliği temizler. Göz çevresinde de güvenle kullanılabilir.',
     'specs' => ['Hacim' => '400 ml', 'Cilt Tipi' => 'Tüm cilt tipleri', 'Ana Etken' => 'Micellar su', 'Kullanım' => 'Durulama gerektirmez', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '400 ml']]],

    ['sku' => 'LM-TM-03', 'name' => 'Luméa AHA-BHA Arındırıcı Temizleme Jeli 150 ml', 'cat' => 'Temizleyici', 'path' => 'Cilt Bakımı > Temizleyici', 'price' => 449.90, 'disc' => 359.90, 'stock' => 121, 'img' => [74, 78],
     'desc' => 'Salisilik asit ve glikolik asit içerir; siyah nokta ve gözenek görünümünü azaltır. Haftada 3-4 kez kullanımı önerilir.',
     'specs' => ['Hacim' => '150 ml', 'Cilt Tipi' => 'Yağlı & akneye eğilimli', 'Ana Etken' => 'AHA %5 + BHA %2', 'Kullanım' => 'Haftada 3-4 kez', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '150 ml']]],

    // ── CİLT BAKIMI · Tonik & Esans ────────────────────────────────────────
    ['sku' => 'LM-TN-01', 'name' => 'Luméa Gül Suyu Dengeleyici Tonik 200 ml', 'cat' => 'Tonik', 'path' => 'Cilt Bakımı > Tonik & Esans', 'price' => 379.90, 'disc' => 299.90, 'stock' => 287, 'img' => [24, 80],
     'desc' => 'Isparta gülünden elde edilen gül suyu ile cildi yatıştırır ve pH dengesini yeniden kurar. Alkolsüz formül.',
     'specs' => ['Hacim' => '200 ml', 'Cilt Tipi' => 'Tüm cilt tipleri', 'Ana Etken' => 'Gül suyu', 'Alkol' => 'İçermez', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '200 ml']]],

    ['sku' => 'LM-TN-02', 'name' => 'Luméa Glow Essence Aydınlatıcı Esans 150 ml', 'cat' => 'Tonik', 'path' => 'Cilt Bakımı > Tonik & Esans', 'price' => 529.90, 'disc' => 419.90, 'stock' => 96, 'img' => [79, 88],
     'desc' => 'Fermente pirinç özü ve niasinamid ile cilt tonunu eşitleyen hafif esans. Serum öncesi kullanılır.',
     'specs' => ['Hacim' => '150 ml', 'Cilt Tipi' => 'Tüm cilt tipleri', 'Ana Etken' => 'Fermente pirinç özü', 'Kullanım' => 'Tonik sonrası', 'Menşei' => 'Güney Kore'],
     'variants' => [['Hacim' => '150 ml']]],

    // ── CİLT BAKIMI · Göz ──────────────────────────────────────────────────
    ['sku' => 'LM-GZ-01', 'name' => 'Luméa Kafein Göz Çevresi Bakım Kremi 15 ml', 'cat' => 'Göz Bakımı', 'path' => 'Cilt Bakımı > Göz Bakımı', 'price' => 549.90, 'disc' => 439.90, 'stock' => 194, 'img' => [47, 49],
     'desc' => 'Kafein ve yeşil çay özüyle göz altı şişlik ve morluk görünümünü azaltır. Metal aplikatörlü ambalaj serinlik hissi verir.',
     'specs' => ['Hacim' => '15 ml', 'Bölge' => 'Göz çevresi', 'Ana Etken' => 'Kafein %5', 'Kullanım' => 'Sabah & akşam', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '15 ml']]],

    ['sku' => 'LM-GZ-02', 'name' => 'Luméa Peptit Göz Serumu 15 ml', 'cat' => 'Göz Bakımı', 'path' => 'Cilt Bakımı > Göz Bakımı', 'price' => 649.90, 'disc' => 519.90, 'stock' => 73, 'img' => [22, 48],
     'desc' => 'Göz çevresindeki ince çizgi görünümüne yönelik peptit yoğun serum. Hafif dokusu makyaj altında birikme yapmaz.',
     'specs' => ['Hacim' => '15 ml', 'Bölge' => 'Göz çevresi', 'Ana Etken' => 'Peptit kompleksi', 'Doku' => 'Serum', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '15 ml']]],

    // ── CİLT BAKIMI · Maske & Peeling ──────────────────────────────────────
    ['sku' => 'LM-MS-01', 'name' => 'Luméa Kil Maskesi Gözenek Arındırıcı 100 ml', 'cat' => 'Maske', 'path' => 'Cilt Bakımı > Maske & Peeling', 'price' => 429.90, 'disc' => 339.90, 'stock' => 231, 'img' => [27, 28],
     'desc' => 'Kaolin ve bentonit kili ile fazla sebumu emer, gözenekleri arındırır. 10 dakika bekletip durulayın.',
     'specs' => ['Hacim' => '100 ml', 'Cilt Tipi' => 'Yağlı & karma', 'Ana Etken' => 'Kaolin + Bentonit', 'Süre' => '10 dakika', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '100 ml']]],

    ['sku' => 'LM-MS-02', 'name' => 'Luméa Yoğun Nem Uyku Maskesi 75 ml', 'cat' => 'Maske', 'path' => 'Cilt Bakımı > Maske & Peeling', 'price' => 599.90, 'disc' => 479.90, 'stock' => 108, 'img' => [31, 32],
     'desc' => 'Gece boyunca cilt üzerinde kalan nem kilidi maskesi. Sabah yumuşak ve dolgun bir cilt bırakır.',
     'specs' => ['Hacim' => '75 ml', 'Cilt Tipi' => 'Kuru & yorgun cilt', 'Ana Etken' => 'Skualan + Üre', 'Kullanım' => 'Haftada 2-3 gece', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '75 ml']]],

    ['sku' => 'LM-MS-03', 'name' => 'Luméa Enzim Peeling Pürüzsüzleştirici 75 ml', 'cat' => 'Peeling', 'path' => 'Cilt Bakımı > Maske & Peeling', 'price' => 499.90, 'disc' => 399.90, 'stock' => 143, 'img' => [35, 36],
     'desc' => 'Papaya ve ananas enzimleriyle mekanik aşındırma olmadan ölü deriden arındırır. Hassas ciltlerde de kullanılabilir.',
     'specs' => ['Hacim' => '75 ml', 'Cilt Tipi' => 'Tüm cilt tipleri', 'Ana Etken' => 'Papain + Bromelain', 'Kullanım' => 'Haftada 1-2 kez', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '75 ml']]],

    // ── CİLT BAKIMI · Güneş ────────────────────────────────────────────────
    ['sku' => 'LM-GN-01', 'name' => 'Luméa SPF 50+ Yüz Güneş Kremi Renksiz 50 ml', 'cat' => 'Güneş Koruyucu', 'path' => 'Cilt Bakımı > Güneş Koruyucu', 'price' => 649.90, 'disc' => 519.90, 'stock' => 356, 'img' => [82, 83],
     'desc' => 'Beyaz iz bırakmayan hafif dokulu yüksek korumalı güneş kremi. Makyaj altında rahatlıkla kullanılır.',
     'specs' => ['Hacim' => '50 ml', 'Koruma' => 'SPF 50+ / PA++++', 'Cilt Tipi' => 'Tüm cilt tipleri', 'Doku' => 'Hafif fluid', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '50 ml']]],

    ['sku' => 'LM-GN-02', 'name' => 'Luméa SPF 30 Vücut Güneş Spreyi 200 ml', 'cat' => 'Güneş Koruyucu', 'path' => 'Cilt Bakımı > Güneş Koruyucu', 'price' => 549.90, 'disc' => 439.90, 'stock' => 187, 'img' => [83, 82],
     'desc' => 'Kolay yayılan sprey formda vücut güneş koruyucusu. Suya dayanıklı formül.',
     'specs' => ['Hacim' => '200 ml', 'Koruma' => 'SPF 30', 'Form' => 'Sprey', 'Özellik' => 'Suya dayanıklı', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '200 ml']]],

    // ── MAKYAJ · Dudak ─────────────────────────────────────────────────────
    ['sku' => 'LM-MK-01', 'name' => 'Luméa Velvet Matte Ruj', 'cat' => 'Ruj', 'path' => 'Makyaj > Dudak', 'price' => 349.90, 'disc' => 279.90, 'stock' => 520, 'img' => [50, 53],
     'desc' => 'Kadifemsi mat bitişli, gün boyu kalıcı ruj. Dudakları kurutmayan nemlendirici formül.',
     'specs' => ['Bitiş' => 'Mat', 'Kalıcılık' => '8 saat', 'Net Miktar' => '3.5 g', 'Menşei' => 'İtalya'],
     'variants' => [['Renk' => '01 Nude Rose'], ['Renk' => '02 Dusty Pink'], ['Renk' => '03 Classic Red'], ['Renk' => '04 Terracotta'], ['Renk' => '05 Berry'], ['Renk' => '06 Plum']]],

    ['sku' => 'LM-MK-02', 'name' => 'Luméa Glossy Shine Dudak Parlatıcısı', 'cat' => 'Ruj', 'path' => 'Makyaj > Dudak', 'price' => 279.90, 'disc' => 219.90, 'stock' => 398, 'img' => [64, 55],
     'desc' => 'Yapışkan hissi bırakmayan, ayna etkisi veren dudak parlatıcısı. Hyaluronik asit içerir.',
     'specs' => ['Bitiş' => 'Parlak', 'Net Miktar' => '5 ml', 'Ana Etken' => 'Hyaluronik Asit', 'Menşei' => 'İtalya'],
     'variants' => [['Renk' => 'Şeffaf'], ['Renk' => 'Peach'], ['Renk' => 'Rosé'], ['Renk' => 'Cherry']]],

    ['sku' => 'LM-MK-03', 'name' => 'Luméa Nemlendirici Dudak Balmı SPF 15', 'cat' => 'Ruj', 'path' => 'Makyaj > Dudak', 'price' => 189.90, 'disc' => 149.90, 'stock' => 610, 'img' => [51, 52],
     'desc' => 'Shea yağı ve E vitamini içeren, SPF 15 korumalı günlük dudak bakım balmı.',
     'specs' => ['Koruma' => 'SPF 15', 'Net Miktar' => '4.8 g', 'Ana Etken' => 'Shea yağı', 'Menşei' => 'Türkiye'],
     'variants' => [['Renk' => 'Renksiz'], ['Renk' => 'Hafif Pembe']]],

    // ── MAKYAJ · Göz ───────────────────────────────────────────────────────
    ['sku' => 'LM-MK-04', 'name' => 'Luméa Volume Pro Hacim Veren Maskara', 'cat' => 'Maskara', 'path' => 'Makyaj > Göz', 'price' => 399.90, 'disc' => 319.90, 'stock' => 275, 'img' => [60, 65],
     'desc' => 'Konik fırçasıyla kirpikleri tek tek ayırarak hacim ve uzunluk kazandırır. Topaklanma yapmaz.',
     'specs' => ['Etki' => 'Hacim + uzunluk', 'Net Miktar' => '10 ml', 'Su Geçirmez' => 'Hayır', 'Menşei' => 'İtalya'],
     'variants' => [['Renk' => 'Siyah'], ['Renk' => 'Kahve']]],

    ['sku' => 'LM-MK-05', 'name' => 'Luméa Nude Dreams 12\'li Far Paleti', 'cat' => 'Far', 'path' => 'Makyaj > Göz', 'price' => 749.90, 'disc' => 599.90, 'stock' => 132, 'img' => [63, 58],
     'desc' => 'Mat ve simli bitişleri bir arada sunan 12 tonluk nude far paleti. Yüksek pigmentli, kolay harmanlanan formül.',
     'specs' => ['Ton Sayısı' => '12', 'Bitiş' => 'Mat & simli', 'Net Miktar' => '14.4 g', 'Menşei' => 'İtalya'],
     'variants' => [['Ton' => 'Nude Dreams'], ['Ton' => 'Rose Gold']]],

    ['sku' => 'LM-MK-06', 'name' => 'Luméa Precision Eyeliner Kalem', 'cat' => 'Eyeliner', 'path' => 'Makyaj > Göz', 'price' => 249.90, 'disc' => 199.90, 'stock' => 344, 'img' => [59, 66],
     'desc' => '0.4 mm ince uçlu keçe eyeliner. Yoğun siyah pigment, akmayan ve smudge yapmayan formül.',
     'specs' => ['Uç Kalınlığı' => '0.4 mm', 'Kalıcılık' => '12 saat', 'Menşei' => 'İtalya'],
     'variants' => [['Renk' => 'Siyah'], ['Renk' => 'Kahve']]],

    // ── MAKYAJ · Yüz ───────────────────────────────────────────────────────
    ['sku' => 'LM-MK-07', 'name' => 'Luméa Second Skin Fondöten 30 ml', 'cat' => 'Fondöten', 'path' => 'Makyaj > Yüz', 'price' => 699.90, 'disc' => 559.90, 'stock' => 208, 'img' => [57, 61],
     'desc' => 'Doğal bitişli, orta kapatıcılığa sahip fondöten. Cilt üzerinde ikinci bir katman hissi bırakmaz.',
     'specs' => ['Kapatıcılık' => 'Orta', 'Bitiş' => 'Doğal', 'Hacim' => '30 ml', 'Menşei' => 'İtalya'],
     'variants' => [['Ton' => '10 Porcelain'], ['Ton' => '20 Ivory'], ['Ton' => '30 Beige'], ['Ton' => '40 Sand'], ['Ton' => '50 Honey'], ['Ton' => '60 Amber'], ['Ton' => '70 Caramel'], ['Ton' => '80 Espresso']]],

    ['sku' => 'LM-MK-08', 'name' => 'Luméa Soft Focus Sabitleyici Pudra', 'cat' => 'Pudra', 'path' => 'Makyaj > Yüz', 'price' => 449.90, 'disc' => 359.90, 'stock' => 167, 'img' => [57, 62],
     'desc' => 'İnce dokusuyla makyajı sabitler, parlamayı önler. Fotoğrafta beyaz iz bırakmaz.',
     'specs' => ['Bitiş' => 'Mat', 'Net Miktar' => '9 g', 'Menşei' => 'İtalya'],
     'variants' => [['Ton' => 'Şeffaf'], ['Ton' => 'Açık'], ['Ton' => 'Orta']]],

    ['sku' => 'LM-MK-09', 'name' => 'Luméa Peach Glow Allık', 'cat' => 'Allık', 'path' => 'Makyaj > Yüz', 'price' => 379.90, 'disc' => 299.90, 'stock' => 0, 'img' => [56, 54],
     'desc' => 'Yanaklara doğal bir canlılık veren pudra allık. Harmanlanması kolay, yüksek pigmentli formül.',
     'specs' => ['Bitiş' => 'Saten', 'Net Miktar' => '6 g', 'Menşei' => 'İtalya'],
     'variants' => [['Renk' => 'Peach'], ['Renk' => 'Rosé'], ['Renk' => 'Coral']]],

    ['sku' => 'LM-MK-10', 'name' => 'Luméa 12\'li Profesyonel Makyaj Fırça Seti', 'cat' => 'Aksesuar', 'path' => 'Makyaj > Aksesuar', 'price' => 899.90, 'disc' => 719.90, 'stock' => 89, 'img' => [62, 65],
     'desc' => 'Yüz ve göz makyajı için 12 parçalık sentetik kıllı fırça seti. Deri görünümlü saklama kılıfı hediyeli.',
     'specs' => ['Parça' => '12', 'Kıl Tipi' => 'Sentetik (vegan)', 'Ekstra' => 'Saklama kılıfı', 'Menşei' => 'Çin'],
     'variants' => [['Renk' => 'Siyah'], ['Renk' => 'Rose Gold']]],

    // ── SAÇ BAKIMI ─────────────────────────────────────────────────────────
    ['sku' => 'LM-SC-01', 'name' => 'Luméa Onarıcı Keratin Şampuanı 400 ml', 'cat' => 'Şampuan', 'path' => 'Saç Bakımı > Şampuan', 'price' => 379.90, 'disc' => 299.90, 'stock' => 445, 'img' => [68, 69],
     'desc' => 'Hidrolize keratin ile yıpranmış saç tellerini onarır. Sülfat ve tuz içermez.',
     'specs' => ['Hacim' => '400 ml', 'Saç Tipi' => 'Yıpranmış & boyalı', 'Ana Etken' => 'Hidrolize keratin', 'Sülfat' => 'İçermez', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '400 ml'], ['Hacim' => '750 ml']]],

    ['sku' => 'LM-SC-02', 'name' => 'Luméa Dökülme Karşıtı Biotin Şampuanı 400 ml', 'cat' => 'Şampuan', 'path' => 'Saç Bakımı > Şampuan', 'price' => 429.90, 'disc' => 339.90, 'stock' => 301, 'img' => [70, 75],
     'desc' => 'Biotin, kafein ve saw palmetto özü ile saç köklerini güçlendirir. Düzenli kullanımda dolgunluk hissi sağlar.',
     'specs' => ['Hacim' => '400 ml', 'Saç Tipi' => 'İnce telli & dökülen', 'Ana Etken' => 'Biotin + Kafein', 'Sülfat' => 'İçermez', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '400 ml']]],

    ['sku' => 'LM-SC-03', 'name' => 'Luméa Yoğun Bakım Saç Maskesi 300 ml', 'cat' => 'Saç Maskesi', 'path' => 'Saç Bakımı > Saç Kremi & Maske', 'price' => 499.90, 'disc' => 399.90, 'stock' => 226, 'img' => [76, 39],
     'desc' => 'Haftalık kullanım için yoğun onarıcı maske. Argan yağı ve panthenol ile saçı yumuşatır.',
     'specs' => ['Hacim' => '300 ml', 'Saç Tipi' => 'Kuru & yıpranmış', 'Ana Etken' => 'Argan yağı', 'Süre' => '10 dakika', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '300 ml']]],

    ['sku' => 'LM-SC-04', 'name' => 'Luméa Argan Saç Serumu Isı Koruyucu 100 ml', 'cat' => 'Saç Serumu', 'path' => 'Saç Bakımı > Saç Serumu & Yağı', 'price' => 449.90, 'disc' => 359.90, 'stock' => 178, 'img' => [14, 16],
     'desc' => '230°C\'ye kadar ısı koruması sağlayan, ağırlık yapmayan saç serumu. Elektriklenmeyi önler.',
     'specs' => ['Hacim' => '100 ml', 'Isı Koruması' => '230°C', 'Ana Etken' => 'Argan yağı', 'Kullanım' => 'Nemli saça', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '100 ml']]],

    ['sku' => 'LM-SC-05', 'name' => 'Luméa Renk Koruyucu Saç Kremi 300 ml', 'cat' => 'Saç Kremi', 'path' => 'Saç Bakımı > Saç Kremi & Maske', 'price' => 349.90, 'disc' => 279.90, 'stock' => 264, 'img' => [77, 81],
     'desc' => 'Boyalı saçlarda renk solmasını geciktiren günlük saç kremi. UV filtreli formül.',
     'specs' => ['Hacim' => '300 ml', 'Saç Tipi' => 'Boyalı', 'Özellik' => 'UV filtreli', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '300 ml']]],

    // ── VÜCUT BAKIMI ───────────────────────────────────────────────────────
    ['sku' => 'LM-VC-01', 'name' => 'Luméa Shea Butter Yoğun Vücut Losyonu 400 ml', 'cat' => 'Vücut Losyonu', 'path' => 'Vücut Bakımı > Vücut Losyonu', 'price' => 429.90, 'disc' => 339.90, 'stock' => 372, 'img' => [37, 40],
     'desc' => '%10 shea yağı içeren yoğun vücut losyonu. 24 saat nem desteği sağlar, hızlı emilir.',
     'specs' => ['Hacim' => '400 ml', 'Ana Etken' => 'Shea yağı %10', 'Cilt Tipi' => 'Kuru cilt', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '400 ml']]],

    ['sku' => 'LM-VC-02', 'name' => 'Luméa Kahve Çekirdeği Vücut Peelingi 250 g', 'cat' => 'Vücut Peelingi', 'path' => 'Vücut Bakımı > Vücut Losyonu', 'price' => 379.90, 'disc' => 299.90, 'stock' => 156, 'img' => [46, 20],
     'desc' => 'Öğütülmüş kahve çekirdeği ve hindistan cevizi yağıyla cildi pürüzsüzleştirir. Duşta ıslak cilde uygulanır.',
     'specs' => ['Net Miktar' => '250 g', 'Ana Etken' => 'Kahve + Hindistan cevizi yağı', 'Kullanım' => 'Haftada 2 kez', 'Menşei' => 'Türkiye'],
     'variants' => [['Net Miktar' => '250 g']]],

    ['sku' => 'LM-VC-03', 'name' => 'Luméa Nemlendirici Duş Jeli 500 ml', 'cat' => 'Duş Jeli', 'path' => 'Vücut Bakımı > Duş & Banyo', 'price' => 279.90, 'disc' => 219.90, 'stock' => 508, 'img' => [68, 76],
     'desc' => 'Cildi kurutmayan, bol köpüren nemlendirici duş jeli. Aile boyu ambalaj.',
     'specs' => ['Hacim' => '500 ml', 'Ana Etken' => 'Gliserin + Aloe', 'pH' => '5.5', 'Menşei' => 'Türkiye'],
     'variants' => [['Koku' => 'Lavanta'], ['Koku' => 'Yeşil Çay'], ['Koku' => 'Hindistan Cevizi']]],

    ['sku' => 'LM-VC-04', 'name' => 'Luméa Yoğun Bakım El Kremi 75 ml', 'cat' => 'El Kremi', 'path' => 'Vücut Bakımı > El & Ayak', 'price' => 199.90, 'disc' => 159.90, 'stock' => 684, 'img' => [41, 43],
     'desc' => 'Çatlamış ve kuru eller için yoğun bakım kremi. Yapışkan hissi bırakmadan hızla emilir.',
     'specs' => ['Hacim' => '75 ml', 'Ana Etken' => 'Üre %5 + Shea', 'Cilt Tipi' => 'Çok kuru', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '75 ml']]],

    ['sku' => 'LM-VC-05', 'name' => 'Luméa Ayak Bakım Kremi Çatlak Onarıcı 100 ml', 'cat' => 'Ayak Kremi', 'path' => 'Vücut Bakımı > El & Ayak', 'price' => 249.90, 'disc' => 199.90, 'stock' => 219, 'img' => [21, 23],
     'desc' => 'Topuk çatlaklarına yönelik %10 üre içeren yoğun onarıcı krem.',
     'specs' => ['Hacim' => '100 ml', 'Ana Etken' => 'Üre %10', 'Kullanım' => 'Günde 1-2 kez', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '100 ml']]],

    // ── PARFÜM ─────────────────────────────────────────────────────────────
    ['sku' => 'LM-PR-01', 'name' => 'Luméa Blanc EDP Kadın Parfüm 50 ml', 'cat' => 'Kadın Parfüm', 'path' => 'Parfüm > Kadın', 'price' => 1499.90, 'disc' => 1199.90, 'stock' => 76, 'img' => [84, 86],
     'desc' => 'Beyaz çiçekler, bergamot ve misk notalarından oluşan zarif çiçeksi imza koku. 8 saate kadar kalıcılık.',
     'specs' => ['Hacim' => '50 ml', 'Konsantrasyon' => 'EDP', 'Koku Ailesi' => 'Çiçeksi', 'Kalıcılık' => '8 saat', 'Menşei' => 'Fransa'],
     'variants' => [['Hacim' => '50 ml'], ['Hacim' => '100 ml']]],

    ['sku' => 'LM-PR-02', 'name' => 'Luméa Noir EDP Erkek Parfüm 100 ml', 'cat' => 'Erkek Parfüm', 'path' => 'Parfüm > Erkek', 'price' => 1699.90, 'disc' => 1359.90, 'stock' => 63, 'img' => [85, 87],
     'desc' => 'Odunsu ve baharatlı notalarla derinlik kazanan yoğun erkek parfümü. Akşam kullanımı için idealdir.',
     'specs' => ['Hacim' => '100 ml', 'Konsantrasyon' => 'EDP', 'Koku Ailesi' => 'Odunsu-baharatlı', 'Kalıcılık' => '10 saat', 'Menşei' => 'Fransa'],
     'variants' => [['Hacim' => '100 ml']]],

    ['sku' => 'LM-PR-03', 'name' => 'Luméa Roll-On Parfüm Deodorant 50 ml', 'cat' => 'Deodorant', 'path' => 'Parfüm > Deodorant', 'price' => 249.90, 'disc' => 199.90, 'stock' => 421, 'img' => [89, 90],
     'desc' => 'Alüminyum tuzu içermeyen, 48 saat koku koruması sağlayan roll-on deodorant.',
     'specs' => ['Hacim' => '50 ml', 'Koruma' => '48 saat', 'Alüminyum' => 'İçermez', 'Menşei' => 'Türkiye'],
     'variants' => [['Koku' => 'Blanc'], ['Koku' => 'Noir']]],

    // ── ERKEK BAKIM ────────────────────────────────────────────────────────
    ['sku' => 'LM-ER-01', 'name' => 'Luméa Men Tıraş Sonrası Yatıştırıcı Balm 100 ml', 'cat' => 'Tıraş', 'path' => 'Erkek Bakım > Tıraş', 'price' => 399.90, 'disc' => 319.90, 'stock' => 197, 'img' => [93, 91],
     'desc' => 'Alkolsüz formülüyle tıraş sonrası yanma ve kızarıklığı yatıştırır. Aloe vera ve allantoin içerir.',
     'specs' => ['Hacim' => '100 ml', 'Alkol' => 'İçermez', 'Ana Etken' => 'Aloe vera + Allantoin', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '100 ml']]],

    ['sku' => 'LM-ER-02', 'name' => 'Luméa Men Sakal Bakım Yağı 30 ml', 'cat' => 'Sakal Bakımı', 'path' => 'Erkek Bakım > Sakal', 'price' => 349.90, 'disc' => 279.90, 'stock' => 134, 'img' => [92, 13],
     'desc' => 'Jojoba ve argan yağı karışımıyla sakalı yumuşatır, alttaki cildi nemlendirir. Kaşıntıyı azaltır.',
     'specs' => ['Hacim' => '30 ml', 'Ana Etken' => 'Jojoba + Argan', 'Koku' => 'Sedir & vetiver', 'Menşei' => 'Türkiye'],
     'variants' => [['Hacim' => '30 ml']]],

    // ── OJE ────────────────────────────────────────────────────────────────
    ['sku' => 'LM-OJ-01', 'name' => 'Luméa Uzun Süre Kalıcı Oje', 'cat' => 'Oje', 'path' => 'Makyaj > Tırnak', 'price' => 179.90, 'disc' => 139.90, 'stock' => 592, 'img' => [94, 96],
     'desc' => '7 güne kadar kalıcılık sunan jel etkili oje. 10-free formül, hızlı kuruma.',
     'specs' => ['Hacim' => '10 ml', 'Kalıcılık' => '7 gün', 'Formül' => '10-free', 'Menşei' => 'İtalya'],
     'variants' => [['Renk' => 'Nude'], ['Renk' => 'Kırmızı'], ['Renk' => 'Bordo'], ['Renk' => 'Lacivert'], ['Renk' => 'Şeffaf']]],

    // ── SETLER ─────────────────────────────────────────────────────────────
    ['sku' => 'LM-ST-01', 'name' => 'Luméa Glow Ritüeli Cilt Bakım Seti (4 Ürün)', 'cat' => 'Set', 'path' => 'Setler & Hediye', 'price' => 2199.90, 'disc' => 1649.90, 'stock' => 48, 'img' => [15, 12],
     'desc' => 'Temizleme jeli, tonik, C vitamini serumu ve nemlendirici kremden oluşan 4 adımlık aydınlatıcı bakım rutini. Özel kutusunda.',
     'specs' => ['İçerik' => '4 ürün', 'Cilt Tipi' => 'Tüm cilt tipleri', 'Ambalaj' => 'Hediye kutusu', 'Menşei' => 'Türkiye'],
     'variants' => [['Set' => 'Standart']]],

    ['sku' => 'LM-ST-02', 'name' => 'Luméa Hediye Kutusu — Parfüm & Vücut Bakım İkilisi', 'cat' => 'Set', 'path' => 'Setler & Hediye', 'price' => 1899.90, 'disc' => 1499.90, 'stock' => 37, 'img' => [18, 19],
     'desc' => 'Blanc EDP 50 ml ve Shea Butter vücut losyonundan oluşan hediye seti. Kurdeleli özel kutu ve hediye notu seçeneğiyle.',
     'specs' => ['İçerik' => '2 ürün', 'Ambalaj' => 'Kurdeleli hediye kutusu', 'Ekstra' => 'Hediye notu', 'Menşei' => 'Türkiye'],
     'variants' => [['Set' => 'Blanc'], ['Set' => 'Noir']]],
];
