jQuery(document).ready(function ($) {

    /* --------------------------------------
       YARDIMCI FONKSİYONLAR
    -------------------------------------- */

    function cleanName(name) {
        if (!name) return "";
        return name.replace(/\.png$/i, "").replace(/-/g, " ").trim();
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
            action: "get_drive_files",
            nonce: mockup_ajax.nonce,
            api_key: mockup_ajax.api_key,
            folder_id: mockup_ajax.mockup_folder_id
        }).done(response => {
            if (response.success) {

                mockups = response.data;

                // 🔥 MOCKUP'LARI ALFABETİK SIRALA
                mockups.sort((a, b) => a.name.localeCompare(b.name, 'tr'));

                const select = $("#frontend-mockup-select");
                select.empty().append(`<option value="">Ürün seçin</option>`);

                mockups.forEach(file => {
                    const cleanName = file.name
                        .replace(/\.png$/i, "")
                        .replace(/-/g, " ")
                        .trim();

                    // 🔥 Doğru: value = file.id
                    select.append(
                        `<option value="${file.id}">${cleanName}</option>`
                    );
                });
            }
        });
    }

    function loadCollections() {
        return $.post(mockup_ajax.ajax_url, {
            action: "get_drive_files",
            nonce: mockup_ajax.nonce,
            api_key: mockup_ajax.api_key,
            folder_id: mockup_ajax.koleksiyon_folder_id
        }).done(response => {
            if (response.success) {

                // 🔥 SADECE KLASÖRLER
                collections = response.data.filter(item =>
                    item.mimeType === "application/vnd.google-apps.folder"
                );

                // 🔥 ALFABETİK SIRALA
                collections.sort((a, b) => a.name.localeCompare(b.name, 'tr'));

                const select = $("#frontend-collection-select");
                select.empty().append(`<option value="">Koleksiyon seçin</option>`);

                // 🔥 SADECE KLASÖR ADLARINI EKLE
                collections.forEach(folder => {
                    select.append(
                        `<option value="${folder.id}">${folder.name}</option>`
                    );
                });
            }
        });
    }

    function loadDesigns(collectionId) {
        return $.post(mockup_ajax.ajax_url, {
            action: "get_drive_files",
            nonce: mockup_ajax.nonce,
            api_key: mockup_ajax.api_key,
            folder_id: collectionId
        }).done(response => {
            if (response.success) {
                designs = response.data || [];

                // 🔥 TASARIMLARI ALFABETİK SIRALA (önizleme seçiminden önce)
                designs.sort((a, b) => a.name.localeCompare(b.name, 'tr'));

                // Önizleme: özel "preview" dosyası varsa onu, yoksa ilk tasarımı göster
                const previewFile = designs.find(d => isPreviewFile(d.name));
                const firstDesign = designs.find(d => !isPreviewFile(d.name));
                const previewSource = previewFile || firstDesign;

                if (previewSource) {
                    setPreviewImage("#collection-preview-image", driveImage(previewSource.id), "#collection-placeholder");
                } else {
                    setPreviewImage("#collection-preview-image", "", "#collection-placeholder");
                }

                const select = $("#frontend-design-select");
                select.empty().append(`<option value="">Tasarım seçin</option>`);
                designs.forEach(des => {

                    // Önizleme dosyası tasarım listesinde görünmesin
                    if (isPreviewFile(des.name)) return;

                    select.append(`<option value="${des.id}">${cleanName(des.name)}</option>`);
                });
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
    $("#frontend-mockup-select").on("change", function () {
        const id = $(this).val();
        const file = mockups.find(m => m.id === id);
        const url = file ? driveImage(file.id) : "";
        setPreviewImage("#selected-mockup-thumbnail", url, "#mockup-placeholder");
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
        const url = file ? driveImage(file.id) : "";
        setPreviewImage("#selected-design-thumbnail", url, "#design-placeholder");
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

        $.post(mockup_ajax.ajax_url, {
            action: "generate_mockup",
            nonce: mockup_ajax.nonce,
            mockup_id: mockupId,
            design_id: designId,
            width_percent: preset.width,
            left_percent: preset.left,
            top_percent: preset.top,
            preset_code: preset.code.toLowerCase()
        }).done(response => {

            $btn.removeClass("loading").prop("disabled", false);
            $btn.find('.btn-text').text('Ürün Görseli Oluştur');

        if (response.success) {

            const finalURL = response.data.url;

            // 🔥 Placeholder yazısını gizle
            $("#frontend-preview-placeholder").hide();

            $("#frontend-preview-image")
                .attr("src", finalURL)
                .css("width", "60%")
                .show();

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

        const productType = extractProductTypeFromFilename(mockupFile.name);

        if (!productType) {
            alert("Bu dosyadan ürün tipi çıkarılamadı! (" + mockupFile.name + ")");
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
                image_url: previewImg,   // 🔥 BASE64 YOK — SADECE URL
                product_type: productType
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
