jQuery(document).ready(function ($) {

    /* --------------------------------------
       YARDIMCI FONKSİYONLAR
    -------------------------------------- */

    function cleanName(name) {
        if (!name) return "";
        return name.replace(/\.[^.]+$/i, "").replace(/-/g, " ").trim();
    }

    function driveImage(id) {
        // Drive görselleri için "thumbnail" endpoint'i kullanılır:
        // tarayıcıda gömmeye uygundur, API anahtarı gerektirmez ve
        // googleapis alt=media adresindeki "automated queries" engeline takılmaz.
        return `https://drive.google.com/thumbnail?id=${id}&sz=w1000`;
    }

    /* Koleksiyon önizleme dosyasını esnek tanı: "0preview.png", "preview.png", "0-preview.png" vb. */
    function isPreviewFile(name) {
        if (!name) return false;
        const base = name.replace(/\.[^.]+$/, "").toLowerCase();
        return base.replace(/[^a-z]/g, "").indexOf("preview") !== -1;
    }

    // R2 proxy URL'ine boyut ekler (önizlemeler tam PNG yerine küçültülmüş gelsin → hız).
    // CDN/public URL kullanılıyorsa dokunmaz.
    function r2Thumb(url, w) {
        if (!url) return "";
        if (url.indexOf("pig_r2_img") === -1) return url;
        return url + (url.indexOf("?") >= 0 ? "&" : "?") + "w=" + w;
    }

    function setPreviewImage(selector, url, placeholder) {
        const img = $(selector);
        const text = $(placeholder);

        if (!url) {
            img.hide();
            text.show();
            return;
        }
        img.attr("src", url).show();
        text.hide();
    }

    function buttonFeedback(btn) {
        const $btn = $(btn);
        $btn.addClass("clicked");
        setTimeout(() => $btn.removeClass("clicked"), 300);
    }

    /* --------------------------------------
       PNG ADINDAN ÜRÜN TİPİ ÇIKARTMA
       Örn: "Tshirt-Standart-Siyah-MSC090.png"
       → "tshirt-standart"
    -------------------------------------- */
    function extractProductTypeFromFilename(name) {
        if (!name) return "";

        // "Tshirt Standart Yesil" → "Tshirt-Standart-Yesil"
        name = name.replace(/\.png$/i, "").trim();

        const parts = name.split(/[-\s]+/);

        if (parts.length < 2) {
            console.warn("⚠ Ürün tipi çıkarılamadı, ad:", name);
            return "";
        }

        return (parts[0] + "-" + parts[1]).toLowerCase();
    }


    /* --------------------------------------
       GLOBAL VARIABLES
    -------------------------------------- */
    let mockups = [];
    let collections = [];
    let designs = {};
    let presets = {};
    let lastBackUrl = "";   // son üretilen arka görselin URL'i (ürün galerisi için)
    let typeToTitle = {};   // product_type → profil başlığı (R2 varyant eşleştirmesi için)

    /* --------------------------------------
       RESİMLİ ÖZEL DROPDOWN
       Native <select> gizlenir ama çalışmaya devam eder (val/change/nav korunur).
       getImg(value) -> o seçeneğin thumbnail URL'i (yoksa "").
    -------------------------------------- */
    function createImageDropdown(selectId, getImg) {
        const $select = $('#' + selectId);
        if (!$select.length || $select.data('pigdd')) return null;

        $select.addClass('pig-dd-native');
        const $wrap = $('<div class="pig-dd"></div>');
        const $sel  = $('<div class="pig-dd-selected"><img class="pig-dd-thumb" alt=""><span class="pig-dd-label"></span><span class="pig-dd-caret">▾</span></div>');
        const $list = $('<div class="pig-dd-list"></div>');
        $wrap.append($sel, $list);
        $select.after($wrap);
        $select.data('pigdd', true);

        // Proxy URL'ine küçük boyut parametresi ekle (tam PNG yerine ~90px thumbnail)
        function thumb(u) { return u ? (u + (u.indexOf('?') >= 0 ? '&' : '?') + 'w=90') : ''; }

        function syncSelected() {
            const val  = $select.val();
            const text = $select.find('option:selected').text();
            const img  = val ? (getImg(val) || '') : '';
            $sel.find('.pig-dd-label').text(text);
            const $t = $sel.find('.pig-dd-thumb');
            if (img) { $t.attr('src', thumb(img)).show(); } else { $t.removeAttr('src').hide(); }
        }

        function render() {
            $list.empty();
            $select.find('option').each(function () {
                const val  = $(this).val();
                const text = $(this).text();
                const img  = val ? (getImg(val) || '') : '';
                const $item = $('<div class="pig-dd-item"></div>');
                const $img  = $('<img class="pig-dd-thumb" alt="" loading="lazy">');
                // src HENÜZ atanmaz; dropdown açılınca yüklenir (sayfa açılışı hızlı kalsın)
                if (img) { $img.attr('data-src', thumb(img)); } else { $img.css('visibility', 'hidden'); }
                $item.append($img, $('<span class="pig-dd-label"></span>').text(text));
                $item.on('click', function () {
                    $select.val(val).trigger('change');
                    $wrap.removeClass('open');
                });
                $list.append($item);
            });
            syncSelected();
        }

        // Liste görselleri yalnızca dropdown AÇILINCA yüklenir; lazy ile de
        // listede sadece görünür olanlar çekilir (kaydırınca gerisi gelir).
        function loadListImages() {
            $list.find('img.pig-dd-thumb[data-src]').each(function () {
                this.src = this.getAttribute('data-src');
                this.removeAttribute('data-src');
            });
        }

        $sel.on('click', function (e) {
            e.stopPropagation();
            $('.pig-dd').not($wrap).removeClass('open');
            $wrap.toggleClass('open');
            if ($wrap.hasClass('open')) { loadListImages(); }
        });
        $(document).on('click', function () { $wrap.removeClass('open'); });
        $select.on('change', syncSelected);

        return { render: render, sync: syncSelected };
    }

    // Thumbnail SADECE Ürün dropdown'ında. Koleksiyon/Tasarım normal (resimsiz) kalır → hız.
    const ddMockup     = createImageDropdown('frontend-mockup-select', v => { const f = mockups.find(m => m.id === v); return f ? f.url : ''; });
    const ddCollection = null;
    const ddDesign     = null;

    /* --------------------------------------
       KATEGORİ → ÜRÜN FİLTRESİ
       Kategori panel profillerinden gelir; seçilince o kategorideki
       profillerin product_type'larına sahip mockup'lar listelenir.
    -------------------------------------- */
    function getProfiles() {
        return (typeof mockup_profiles !== "undefined" && mockup_profiles.profiles) ? mockup_profiles.profiles : {};
    }

    function renderProductOptions(list) {
        const select = $("#frontend-mockup-select");
        select.empty().append(`<option value="">Ürün seçin</option>`);
        (list || []).forEach(file => {
            select.append(`<option value="${file.id}">${cleanName(file.name)}</option>`);
        });
        if (ddMockup) ddMockup.render();
    }

    // Ürün tipini normalize eder: uzantı at + Türkçe karakter foldingı + tişört/tshirt eşitleme.
    // Ürün adını normalize eder: uzantı at + Türkçe folding + tişört/tshirt eşitleme
    // + tüm ayraçları (tire/alt çizgi/boşluk) TEK BOŞLUĞA indir → "Hoodie-Basic" == "Hoodie Basic".
    function _normType(s) {
        return String(s || "").toLowerCase()
            .replace(/\.(png|jpe?g|webp)$/i, "")
            .replace(/ç/g, "c").replace(/ğ/g, "g").replace(/ı/g, "i").replace(/İ/g, "i")
            .replace(/ö/g, "o").replace(/ş/g, "s").replace(/ü/g, "u")
            .replace(/tişört|tisort/g, "tshirt")
            .replace(/[-_\s]+/g, " ")
            // Kalite eşanlamlıları: R2 iç adı "Standart"/"Premium" ↔ panel başlığı "Basic"/"Oversize Premium"
            .replace(/\bstandart\b/g, "basic")
            .replace(/\boversize\b/g, "")
            .replace(/\s+/g, " ")
            .trim();
    }

    // Seçili profilin R2 renk varyantlarını döner. R2 adları "Profil Adı + Renk" biçiminde
    // ("Hoodie Basic Erkek Beyaz"), o yüzden profilin BAŞLIĞIYLA (title) önek eşleşmesi yapılır.
    // Cinsiyet/kalite başlıkta olduğu için Kadın/Erkek, Basic/Premium doğru ayrılır.
    function mockupsForCategory(productType) {
        if (!productType) return mockups;
        const title = typeToTitle[productType] || productType;
        const t = _normType(title);
        return mockups.filter(m => {
            const n = _normType(m.name);
            return n === t || n.indexOf(t + " ") === 0;
        });
    }

    // Dropdown ÜRÜN PROFİLİ adlarıyla dolar (value = profilin product_type'ı).
    function initCategoryDropdown() {
        const profiles = getProfiles();
        const items = [];
        typeToTitle = {};
        Object.keys(profiles).forEach(k => {
            const p = profiles[k] || {};
            const title = p.profile_title || p.product_type || '';
            const pt = p.product_type || '';
            if (title && pt) { items.push({ title: title, pt: pt }); typeToTitle[pt] = title; }
        });
        items.sort((a, b) => a.title.localeCompare(b.title, 'tr'));
        const $select = $("#frontend-category-select");
        const current = $select.val();
        $select.empty().append(`<option value="">Ürün profili seçin</option>`);
        items.forEach(it => $select.append(`<option value="${it.pt}">${it.title}</option>`));
        if (current) $select.val(current);
    }

    /* --------------------------------------
    İLK YÜKLEMEDE DEFAULT GÖRSELLERİ GÖSTER
    -------------------------------------- */
    if (typeof mockup_defaults !== "undefined") {

        // Ürün placeholder
        if (mockup_defaults.product) {
            $("#selected-mockup-thumbnail")
                .attr("src", mockup_defaults.product)
                .show();
        }

        // Koleksiyon placeholder
        if (mockup_defaults.collection) {
            $("#collection-preview-image")
                .attr("src", mockup_defaults.collection)
                .show();
            $("#collection-placeholder").hide();
        }

        // Tasarım placeholder
        if (mockup_defaults.design) {
            $("#selected-design-thumbnail")
                .attr("src", mockup_defaults.design)
                .show();
        }

        // Boyut placeholder
        if (mockup_defaults.size) {
            $("#preset-preview-image")
                .attr("src", mockup_defaults.size)
                .show();
        }
    }

    /* --------------------------------------
       MOCKUP VE DİĞER VERİLERİ YÜKLE
    -------------------------------------- */

    function loadMockups() {
        return $.post(mockup_ajax.ajax_url, {
            action: "get_r2_mockups",
            nonce: mockup_ajax.nonce
        }).done(response => {
            if (response.success) {

                mockups = response.data || [];

                // Alfabetik sırala
                mockups.sort((a, b) => a.name.localeCompare(b.name, 'tr'));

                // Kategori dropdown'unu profillerden doldur, ürünleri seçili kategoriye göre listele
                initCategoryDropdown();
                renderProductOptions(mockupsForCategory($("#frontend-category-select").val()));
            } else {
                console.error("R2 ürün listesi alınamadı:", response.data);
            }
        });
    }

    function loadCollections() {
        return $.post(mockup_ajax.ajax_url, {
            action: "get_r2_collections",
            nonce: mockup_ajax.nonce
        }).done(response => {
            if (response.success) {

                collections = response.data || [];

                // Alfabetik sırala
                collections.sort((a, b) => a.name.localeCompare(b.name, 'tr'));

                const select = $("#frontend-collection-select");
                select.empty().append(`<option value="">Koleksiyon seçin</option>`);

                collections.forEach(col => {
                    select.append(
                        `<option value="${col.id}">${col.name}</option>`
                    );
                });
                if (ddCollection) ddCollection.render();
            } else {
                console.error("R2 koleksiyon listesi alınamadı:", response.data);
            }
        });
    }

    function loadDesigns(collectionId) {
        return $.post(mockup_ajax.ajax_url, {
            action: "get_r2_designs",
            nonce: mockup_ajax.nonce,
            collection_id: collectionId
        }).done(response => {
            if (response.success) {
                designs = response.data.designs || [];

                // Önizleme: server "preview" dosyasını verir, yoksa ilk tasarım
                const previewUrl = response.data.preview_url ||
                    (designs[0] ? designs[0].url : "");
                setPreviewImage("#collection-preview-image", r2Thumb(previewUrl, 500), "#collection-placeholder");

                const select = $("#frontend-design-select");
                select.empty().append(`<option value="">Tasarım seçin</option>`);
                designs.forEach(des => {
                    select.append(`<option value="${des.id}">${cleanName(des.name)}</option>`);
                });
                if (ddDesign) ddDesign.render();

                // Arka tasarım dropdown'ı da aynı tasarımlarla dolsun
                const backSelect = $("#frontend-back-design-select");
                backSelect.empty().append(`<option value="">Arka tasarım seçin (opsiyonel)</option>`);
                designs.forEach(des => {
                    backSelect.append(`<option value="${des.id}">${cleanName(des.name)}</option>`);
                });
            } else {
                console.error("R2 tasarım listesi alınamadı:", response.data);
            }
        });
    }

    function loadPresets() {
        return $.post(mockup_ajax.ajax_url, {
            action: "get_presets_with_images",
            nonce: mockup_ajax.nonce
        }).done(response => {
            if (response.success) {
                presets = response.data;
                const select = $("#frontend-preset-select");
                select.empty().append(`<option value="">Boyut seçin</option>`);
                Object.keys(presets).forEach(pKey => {
                    select.append(`<option value="${pKey}">${presets[pKey].name}</option>`);
                });
            }
        });
    }

    /* --------------------------------------
       SEÇİMLER
    -------------------------------------- */
    // Kategori seçilince ürünleri o kategoriye göre filtrele
    $("#frontend-category-select").on("change", function () {
        const cat = $(this).val();
        renderProductOptions(mockupsForCategory(cat));
        $("#frontend-mockup-select").val("").trigger("change");
    });

    $("#frontend-mockup-select").on("change", function () {
        const id = $(this).val();
        const file = mockups.find(m => m.id === id);
        const url = file ? file.url : "";
        setPreviewImage("#selected-mockup-thumbnail", r2Thumb(url, 500), "#mockup-placeholder");
    });

    $("#frontend-collection-select").on("change", function () {
        const colId = $(this).val();

        // 1) Görseli gizle
        $("#collection-preview-image").hide();

        // 2) Placeholder öğesini göster (metin yok)
        $("#collection-placeholder")
            .show()
            .text(""); // metni tamamen boş bırakıyoruz

        // 3) Koleksiyon tasarımlarını yükle
        if (colId) {
            loadDesigns(colId).then(() => {
                // 0preview.png loadDesigns içinde gelince
                // setPreviewImage() otomatik olarak görseli gösteriyor.
            });
        }
    });

    $("#frontend-design-select").on("change", function () {
        const id = $(this).val();
        const file = designs.find(d => d.id === id);
        const url = file ? file.url : "";
        setPreviewImage("#selected-design-thumbnail", r2Thumb(url, 500), "#design-placeholder");
    });

    $("#frontend-back-design-select").on("change", function () {
        const id = $(this).val();
        const file = designs.find(d => d.id === id);
        const url = file ? file.url : "";
        setPreviewImage("#selected-back-design-thumbnail", r2Thumb(url, 500), "#back-design-placeholder");
    });

    $("#frontend-preset-select").on("change", function () {
        $("#preset-preview-image")
            .attr("src", mockup_defaults.size)
            .show();
        $("#preset-placeholder").hide();
    });

    /* --------------------------------------
       MOCKUP ÜRET
    -------------------------------------- */

    $("#frontend-generate").on("click", function () {
        const $btn = $(this);

        const mockupId = $("#frontend-mockup-select").val();
        const designId = $("#frontend-design-select").val();
        const presetId = $("#frontend-preset-select").val();

        if (!mockupId || !designId || !presetId) {
            alert("Lütfen tüm seçimleri yapın.");
            return;
        }

        buttonFeedback(this);

        $btn.addClass("loading").prop("disabled", true);
        $btn.find('.btn-text').text('Ürün Görseli Hazırlanıyor...');

        const preset = presets[presetId];
        const mockupFile = mockups.find(m => m.id === mockupId);
        const designFile = designs.find(d => d.id === designId);

        $.post(mockup_ajax.ajax_url, {
            action: "generate_mockup_r2",
            nonce: mockup_ajax.nonce,
            mockup_key: mockupId,
            design_key: designId,
            back_design_key: $("#frontend-back-design-select").val() || "",
            mockup_name: mockupFile ? mockupFile.name : "mockup",
            design_name: designFile ? designFile.name : "design",
            width_percent: preset.width,
            left_percent: preset.left,
            top_percent: preset.top,
            preset_code: preset.code ? preset.code.toLowerCase() : ""
        }).done(response => {

            $btn.removeClass("loading").prop("disabled", false);
            $btn.find('.btn-text').text('Ürün Görseli Oluştur');

        if (response.success) {

            const finalURL = response.data.url;

            // 🔥 Placeholder yazısını gizle
            $("#frontend-preview-placeholder").hide();

            $("#frontend-preview-image")
                .attr("src", finalURL)
                .show();

            // Arka görsel (varsa) göster + ürün galerisi için sakla
            lastBackUrl = response.data.back_url || "";
            if (lastBackUrl) {
                $("#frontend-preview-image-back")
                    .attr("src", lastBackUrl)
                    .show();
            } else {
                $("#frontend-preview-image-back").hide();
            }

            $(".frontend-output-controls").show();

                /* İNDİR */
                $("#frontend-download").off().on("click", function () {
                    fetch(finalURL)
                        .then(r => r.blob())
                        .then(blob => {
                            const a = document.createElement("a");
                            a.href = URL.createObjectURL(blob);

                            // 🔥 Dosya adını direkt URL'den al (presets dahil)
                            let fileName = finalURL.split("/").pop().split("?")[0];

                            a.download = fileName;
                            a.click();
                        });
                });

                /* LİNK KOPYALA */
                $("#frontend-copy-link").off().on("click", function () {
                    navigator.clipboard.writeText(finalURL);
                    alert("Link kopyalandı!");
                });

            } else {
                alert("Hata: " + response.data);
            }
        });
    });


    /* --------------------------------------
        ÜRÜN OLUŞTUR (WOO ÜRÜNÜ)
    -------------------------------------- */

    $("#frontend-create-product").on("click", function () {

        const $btn = $(this);

        const previewImg = $("#frontend-preview-image").attr("src");
        if (!previewImg) {
            alert("Önce ürün görseli oluşturmalısın.");
            return;
        }

        // === MOCKUP ID'YI AL ===
        const mockupId = $("#frontend-mockup-select").val();
        const mockupFile = mockups.find(m => m.id === mockupId);

        if (!mockupFile) {
            alert("Mockup dosya bilgisi alınamadı!");
            return;
        }

        // Ürün tipi = seçili ÜRÜN PROFİLİ (dropdown value = profilin product_type'ı).
        // Cinsiyet/kalite profilde zaten kodlu; mockup adından çıkarmaya/gender eklemeye gerek yok.
        const productType = $("#frontend-category-select").val();

        if (!productType) {
            alert("Lütfen önce bir ürün profili seçin!");
            return;
        }

        // --- ANİMASYONU BAŞLAT ---
        $btn.prop("disabled", true)
            .addClass("loading")
            .text("Ürün Oluşturuluyor...");

        $.ajax({
            url: mockup_ajax.ajax_url,
            type: "POST",
            data: {
                action: "mockup_create_wc_product",
                nonce: mockup_ajax.nonce,
                image_url: previewImg,        // ön görsel (ana)
                back_image_url: lastBackUrl,  // arka görsel (galeri) — varsa
                product_type: productType,
                design_name: (function () {   // ürün adına eklenecek tasarım adı
                    const d = designs.find(x => x.id === $("#frontend-design-select").val());
                    return d ? d.name : "";
                })()
            },
            success: function (response) {

                $btn.prop("disabled", false)
                    .removeClass("loading")
                    .text("Ürün Oluştur");

                if (response.success) {

                    const modalHtml = `
                        <div id="mc-modal" style="
                            position: fixed; top: 0; left: 0;
                            width: 100%; height: 100%;
                            background: rgba(0,0,0,0.6);
                            display: flex; align-items: center;
                            justify-content: center; z-index: 99999;">
                            
                            <div style="
                                background: #fff; padding: 25px;
                                border-radius: 12px; width: 400px;
                                text-align: center;">
                                
                                <h3>Ürün Oluşturuldu 🎉</h3>
                                <p style="word-break: break-all;">${response.data.url}</p>

                                <a href="${response.data.url}" target="_blank"
                                    style="display:inline-block;margin-top:15px;
                                    padding:10px 20px;background:#2a7ae4;color:#fff;
                                    border-radius:6px;text-decoration:none;">
                                    Ürüne Git
                                </a>

                                <button id="mc-close" style="
                                    margin-top:15px;padding:8px 18px;
                                    border:none;background:#ccc;
                                    border-radius:6px;cursor:pointer;">
                                    Kapat
                                </button>

                            </div>
                        </div>
                    `;

                    $("body").append(modalHtml);
                    $("#mc-close").on("click", () => $("#mc-modal").remove());

                } else {
                    alert("HATA: " + response.data);
                }
            },
            error: function () {
                $btn.prop("disabled", false)
                    .removeClass("loading")
                    .text("Ürün Oluştur");

                alert("Beklenmeyen bir hata oluştu.");
            }
        });

    });


    /* --------------------------------------
       BAŞLANGIÇ VERİLERİNİ YÜKLE
    -------------------------------------- */
    loadMockups();
    loadCollections();
    loadPresets();

    // --- İlk yüklemede placeholder gösterilsin ---
    setPreviewImage(
        "#selected-mockup-thumbnail",
        $("#selected-mockup-thumbnail").attr("src"),
        "#mockup-placeholder"
    );

    /* --------------------------------------
    DROPDOWN NEXT / PREV BUTONLARI
    -------------------------------------- */
    $(".frontend-nav-btn").on("click", function () {
        const target = $(this).data("target"); 
        const direction = $(this).hasClass("next") ? 1 : -1;

        const select = $(`#frontend-${target}-select`);
        const total = select.find("option").length;

        if (total <= 1) return; // boşsa işlem yok

        let index = select.prop("selectedIndex");

        index += direction;

        if (index < 1) index = total - 1;     // başa dön
        if (index >= total) index = 1;        // sona dön

        select.prop("selectedIndex", index).trigger("change");
    });

});
