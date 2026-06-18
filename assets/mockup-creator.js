jQuery(document).ready(function($) {
    let currentPreviewUrl = '';
    let currentMockupName = '';
    let currentDesignName = '';
    let driveFiles = {
        mockups: [],
        collections: [],
        designs: []
    };
    
    let presets = {};
    let editingPresetId = null;
    
    // Sayfa yüklendiğinde preset'leri yükle
    loadPresets();
    
    // Drive bağlantı testi (backend üzerinden)
    $('#test-drive-connection').on('click', function() {

        $('#drive-status').html('<p style="color:blue">🔄 Bağlantı test ediliyor...</p>');

        $.ajax({
            url: mockup_ajax.ajax_url,
            method: "POST",
            data: {
                action: "test_drive_connection_manual",
                nonce: mockup_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#drive-status').html(`
                        <p style="color:green">✅ ${response.data.message}</p>
                        <p>📁 ${response.data.file_count} dosya bulundu</p>
                    `);
                } else {
                    $('#drive-status').html(`<p style="color:red">❌ ${response.data}</p>`);
                }
            },
            error: function() {
                $('#drive-status').html('<p style="color:red">❌ Test hatası</p>');
            }
        });
    });
    
    // Dosyaları yükle
    $('#connect-drive').on('click', function() {
        const apiKey = $('#drive-api-key').val();
        const mockupFolderId = $('#mockup-folder-id').val();
        const koleksiyonFolderId = $('#koleksiyon-folder-id').val();
        
        if (!apiKey || !mockupFolderId) {
            alert('Lütfen API anahtarı ve Mockup klasör ID girin');
            return;
        }
        
        $('#drive-status').html('<p style="color:blue">🔄 Dosyalar yükleniyor...</p>');
        
        // Mockup dosyalarını getir
        loadMockupFiles(apiKey, mockupFolderId);
        
        // Koleksiyon klasörleri getir (eğer varsa)
        if (koleksiyonFolderId) {
            loadCollectionFolders(apiKey, koleksiyonFolderId);
        }
    });
    
    // Mockup dosyalarını yükle
    function loadMockupFiles(apiKey, folderId) {
        $.ajax({
            url: mockup_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'get_drive_files',
                nonce: mockup_ajax.nonce,
                api_key: apiKey,
                folder_id: folderId
            },
            success: function(response) {
                if (response.success) {
                    driveFiles.mockups = response.data.filter(file => 
                        file.mimeType.startsWith('image/')
                    );
                    
                    loadFilesToDropdown(driveFiles.mockups, 'mockup');
                    
                    $('#drive-status').html(
                        `<p style="color:green">✅ ${driveFiles.mockups.length} mockup yüklendi!</p>`
                    );
                    
                    // İlk mockup'u otomatik seç ve göster
                    if (driveFiles.mockups.length > 0) {
                        $('#mockup-select').val(driveFiles.mockups[0].id).trigger('change');
                    }
                    
                    // Navigasyon butonlarını güncelle
                    updateNavigationButtons('mockup');
                } else {
                    $('#drive-status').html(`<p style="color:red">❌ ${response.data}</p>`);
                }
            },
            error: function() {
                $('#drive-status').html('<p style="color:red">❌ Mockup yükleme hatası</p>');
            }
        });
    }
    
    // Koleksiyon klasörlerini yükle
    function loadCollectionFolders(apiKey, folderId) {
        $.ajax({
            url: mockup_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'get_drive_files',
                nonce: mockup_ajax.nonce,
                api_key: apiKey,
                folder_id: folderId
            },
            success: function(response) {
                if (response.success) {
                    // Sadece klasörleri filtrele
                    driveFiles.collections = response.data.filter(file => 
                        file.mimeType === 'application/vnd.google-apps.folder'
                    );
                    
                    loadCollectionsToDropdown(driveFiles.collections);
                    
                    $('#drive-status').html(
                        $('#drive-status').html() + `<p style="color:green">✅ ${driveFiles.collections.length} koleksiyon yüklendi!</p>`
                    );
                    
                    // Navigasyon butonlarını güncelle
                    updateNavigationButtons('collection');
                }
            },
            error: function() {
                console.log('Koleksiyon yükleme hatası');
            }
        });
    }
    
    // Koleksiyon seçildiğinde tasarımları yükle
    function loadDesignsFromCollection(apiKey, folderId) {
        $.ajax({
            url: mockup_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'get_drive_files',
                nonce: mockup_ajax.nonce,
                api_key: apiKey,
                folder_id: folderId
            },
            success: function(response) {
                if (response.success) {
                    driveFiles.designs = response.data.filter(file => 
                        file.mimeType.startsWith('image/')
                    );
                    
                    loadFilesToDropdown(driveFiles.designs, 'design');
                    
                    // Navigasyon butonlarını güncelle
                    updateNavigationButtons('design');
                }
            }
        });
    }
    
    // Dosyaları dropdown'a yükle
    function loadFilesToDropdown(files, type) {
        const selectElement = $(`#${type}-select`);
        selectElement.empty().append('<option value="">Seçin</option>');
        
        files.forEach(file => {
            const displayName = file.name.length > 30 ? 
                file.name.substring(0, 30) + '...' : file.name;
            
            const imageUrl = `https://drive.google.com/uc?export=view&id=${file.id}`;
                    
            // --- YENİ THUMBNAIL DESTEKLİ OPTION ---
            selectElement.append(
                `<option value="${file.id}" 
                        data-url="${imageUrl}" 
                        data-name="${file.name}"
                        data-thumb="${imageUrl}">
                    ${displayName}
                </option>`
            );
        });

        // --- THUMBNAIL DÖNÜŞTÜRÜCÜ EKLENDİ ---
        convertSelectToThumbnail(selectElement);
    }

    function convertSelectToThumbnail(selectElement) {
        // Select’i dışarı sarmala
        const wrapper = $('<div class="thumb-select-wrapper"></div>');
        selectElement.after(wrapper);
        wrapper.append(selectElement);

        // Thumbnail kutusu
        const thumbBox = $('<div class="thumb-preview-box"></div>');
        wrapper.append(thumbBox);

        // Değiştikçe thumbnail'i göster
        selectElement.on('change', function () {
            const selected = $(this).find('option:selected');
            const thumb = selected.data('thumb');

            if (thumb) {
                thumbBox.html(`<img src="${thumb}" class="thumb-preview-img">`);
            } else {
                thumbBox.html('');
            }
        });

        // İlk seçimi tetikle (varsa görseli göstersin)
        setTimeout(() => selectElement.trigger('change'), 150);
    }
    
    // Koleksiyonları dropdown'a yükle
    function loadCollectionsToDropdown(collections) {
        const selectElement = $('#collection-select');
        selectElement.empty().append('<option value="">Koleksiyon seçin</option>');
        
        collections.forEach(folder => {
            selectElement.append(
                `<option value="${folder.id}">
                     ${folder.name}
                 </option>`
            );
        });
    }
    
    // Mockup seçildiğinde ön izleme göster
    $('#mockup-select').on('change', function() {
        const selectedOption = $(this).find('option:selected');
        const imageUrl = selectedOption.data('url');
        currentMockupName = selectedOption.data('name') || '';
        
        if (imageUrl && selectedOption.val() !== '') {
            $('#preview-placeholder').hide();
            $('#preview-image').attr('src', imageUrl).show();
            $('.output-controls').show();
            
            // Ayarları kaydet
            saveSettings();
            
            // Eğer tasarım da seçilmişse, mockup oluştur
            const designId = $('#design-select').val();
            if (designId && designId !== '') {
                generateMockupPreview(selectedOption.val(), designId);
            }
        } else {
            $('#preview-placeholder').show();
            $('#preview-image').hide();
            $('.output-controls').hide();
        }
        
        // Navigasyon butonlarını güncelle
        updateNavigationButtons('mockup');
    });
    
    // Koleksiyon seçildiğinde tasarımları yükle
    $('#collection-select').on('change', function() {
        const collectionId = $(this).val();
        const apiKey = $('#drive-api-key').val();
        
        if (collectionId && collectionId !== '' && apiKey) {
            loadDesignsFromCollection(apiKey, collectionId);
            saveSettings();
        }
        
        // Navigasyon butonlarını güncelle
        updateNavigationButtons('collection');
    });
    
    // Tasarım seçildiğinde
    $('#design-select').on('change', function() {
        const selectedOption = $(this).find('option:selected');
        const designUrl = selectedOption.data('url');
        const mockupId = $('#mockup-select').val();
        const designId = $(this).val();
        currentDesignName = selectedOption.data('name') || '';
        
        if (mockupId && designId && designId !== '') {
            generateMockupPreview(mockupId, designId);
        }
        saveSettings();
        
        // Navigasyon butonlarını güncelle
        updateNavigationButtons('design');
    });
    
    // Slider değerlerini güncelle
    $('.slider').on('input', function() {
        const id = $(this).attr('id').replace('-slider', '-value');
        $('#' + id).text($(this).val());
        saveSettings();
        
        // Eğer hem mockup hem tasarım seçilmişse, ön izlemeyi güncelle
        const mockupId = $('#mockup-select').val();
        const designId = $('#design-select').val();
        if (mockupId && designId) {
            generateMockupPreview(mockupId, designId);
        }
    });
    
    // PRESET İŞLEMLERİ
    
    // Preset'leri yükle
    function loadPresets() {
        $.ajax({
            url: mockup_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'get_presets',
                nonce: mockup_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    presets = response.data;
                    updatePresetDropdown();
                }
            }
        });
    }
    
    // Preset dropdown'ını güncelle
    function updatePresetDropdown() {
        const selectElement = $('#preset-select');
        selectElement.empty().append('<option value="">Preset seçin</option>');
        
        $.each(presets, function(id, preset) {
            selectElement.append(
                `<option value="${id}">${preset.name}</option>`
            );
        });
    }
    
    // Preset uygula
    $('#apply-preset').on('click', function() {
        const presetId = $('#preset-select').val();
        
        if (!presetId) {
            alert('Lütfen bir preset seçin');
            return;
        }
        
        if (presets[presetId]) {
            const preset = presets[presetId];
            $('#width-slider').val(preset.width).trigger('input');
            $('#left-slider').val(preset.left).trigger('input');
            $('#top-slider').val(preset.top).trigger('input');
            
            $('#drive-status').html(`<p style="color:green">✅ "${preset.name}" preset uygulandı!</p>`);
        }
    });
    
    // Yeni preset ekle
    $('#add-preset').on('click', function() {
        const currentWidth = $('#width-slider').val();
        const currentLeft = $('#left-slider').val();
        const currentTop = $('#top-slider').val();
        
        // Form slider'larını güncelle
        $('#form-width-slider').val(currentWidth).trigger('input');
        $('#form-left-slider').val(currentLeft).trigger('input');
        $('#form-top-slider').val(currentTop).trigger('input');
        
        // Formu sıfırla
        $('#preset-code').val(''); 
        $('#preset-name').val('');
        $('#form-title').text('Yeni Preset Ekle');
        editingPresetId = null;
        
        // Modal'ı aç
        showPresetForm();
    });
    
    // Preset yönetimi
    $('#manage-presets').on('click', function() {
        loadPresetList();
        $('#preset-modal').show();
    });

    // Modal'ı kapat
    $('.close-modal').on('click', function() {
        $('#preset-modal').hide();
        hidePresetForm();
    });

    // Preset listesini yükle
    function loadPresetList() {
        $.ajax({
            url: mockup_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'get_presets',
                nonce: mockup_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    presets = response.data;
                    renderPresetList();
                }
            }
        });
    }

    // Preset listesini render et
    function renderPresetList() {
        let html = `
            <table class="preset-table">
                <thead>
                    <tr>
                        <th>Kod</th>
                        <th>Preset Adı</th>
                        <th>Genişlik</th>
                        <th>Soldan</th>
                        <th>Üstten</th>
                        <th>İşlemler</th>
                    </tr>
                </thead>
                <tbody>`;
            
        $.each(presets, function(id, preset) {
            html += `
                <tr>
                    <td>${preset.code || '-'}</td>
                    <td>${preset.name}</td>
                    <td>${preset.width}%</td>
                    <td>${preset.left}%</td>
                    <td>${preset.top}%</td>
                    <td>
                        <button class="button edit-preset" data-id="${id}">Düzenle</button>
                        <button class="button delete-preset" data-id="${id}">Sil</button>
                    </td>
                </tr>
            `;
        });

        html += `
                </tbody>
            </table>
        `;

        $('#preset-list').html(html);

        /** Event listener'lar burada olmalı */
        $('.edit-preset').on('click', function() {
            const presetId = $(this).data('id');
            editPreset(presetId);
        });

        $('.delete-preset').on('click', function() {
            const presetId = $(this).data('id');
            deletePreset(presetId);
        });
    }
    
    // Preset düzenle
    function editPreset(presetId) {
        const preset = presets[presetId];
        
        if (preset) {
            $('#preset-code').val(preset.code || '');
            $('#preset-name').val(preset.name);
            $('#form-width-slider').val(preset.width).trigger('input');
            $('#form-left-slider').val(preset.left).trigger('input');
            $('#form-top-slider').val(preset.top).trigger('input');
            
            $('#form-title').text('Preset Düzenle');
            editingPresetId = presetId;
            
            showPresetForm();
        }
    }
    
    // Preset sil
    function deletePreset(presetId) {
        if (confirm('Bu preset\'i silmek istediğinizden emin misiniz?')) {
            $.ajax({
                url: mockup_ajax.ajax_url,
                method: 'POST',
                data: {
                    action: 'delete_preset',
                    nonce: mockup_ajax.nonce,
                    preset_id: presetId
                },
                success: function(response) {
                    if (response.success) {
                        loadPresetList();
                        loadPresets(); // Dropdown'ı güncelle
                        $('#drive-status').html('<p style="color:green">✅ Preset silindi!</p>');
                    } else {
                        alert('Silme hatası: ' + response.data);
                    }
                }
            });
        }
    }
    
    // Form slider'larını güncelle
    $('.slider').on('input', function() {
        const id = $(this).attr('id').replace('-slider', '-value');
        $('#' + id).text($(this).val());
    });
    
    // Preset formunu kaydet
    $('#save-preset-form').on('click', function() {
        const presetCode = $('#preset-code').val().trim();
        const presetName = $('#preset-name').val().trim();
        const width = $('#form-width-slider').val();
        const left = $('#form-left-slider').val();
        const top = $('#form-top-slider').val();
        
        if (!presetName) {
            alert('Lütfen preset adı girin');
            return;
        }
        
        const action = editingPresetId ? 'update_preset' : 'save_preset';
        const data = {
            action: action,
            nonce: mockup_ajax.nonce,
            preset_code: presetCode,
            preset_name: presetName,
            width: width,
            left: left,
            top: top
        };
        
        if (editingPresetId) {
            data.preset_id = editingPresetId;
        }
        
        $.ajax({
            url: mockup_ajax.ajax_url,
            method: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    loadPresetList();
                    loadPresets(); // Dropdown'ı güncelle
                    hidePresetForm();
                    $('#drive-status').html('<p style="color:green">✅ Preset kaydedildi!</p>');
                } else {
                    alert('Kaydetme hatası: ' + response.data);
                }
            }
        });
    });
    
    // Form iptal
    $('#cancel-preset-form').on('click', function() {
        hidePresetForm();
    });
    
    // Form göster
    function showPresetForm() {
        $('#preset-list').hide();
        $('#preset-form').show();
    }
    
    // Form gizle
    function hidePresetForm() {
        $('#preset-form').hide();
        $('#preset-list').show();
        editingPresetId = null;
    }
    
    // Navigasyon butonları için fonksiyonlar
    $('.nav-btn').on('click', function() {
        const target = $(this).data('target');
        const isPrev = $(this).hasClass('prev');
        navigateDropdown(target, isPrev);
    });

    function navigateDropdown(target, isPrev) {
        const selectElement = $(`#${target}-select`);
        const options = selectElement.find('option:not([value=""])');
        
        if (options.length === 0) {
            return;
        }
        
        let currentIndex = selectElement.prop('selectedIndex');
        if (currentIndex === 0 || currentIndex === -1) {
            // Eğer hiç seçili değilse, ilk veya son öğeyi seç
            currentIndex = isPrev ? options.length - 1 : 0;
        } else {
            // Seçili öğenin index'ini bul (ilk boş option'ı atla)
            currentIndex = currentIndex - 1;
            
            if (isPrev) {
                currentIndex = currentIndex <= 0 ? options.length - 1 : currentIndex - 1;
            } else {
                currentIndex = currentIndex >= options.length - 1 ? 0 : currentIndex + 1;
            }
        }
        
        const newOption = options.eq(currentIndex);
        selectElement.val(newOption.val()).trigger('change');
    }

    // Navigasyon butonlarını güncelle
    function updateNavigationButtons(target) {
        const selectElement = $(`#${target}-select`);
        const options = selectElement.find('option:not([value=""])');
        const currentIndex = selectElement.prop('selectedIndex') - 1;
        
        const prevButton = $(`.nav-btn.prev[data-target="${target}"]`);
        const nextButton = $(`.nav-btn.next[data-target="${target}"]`);
        
        if (options.length > 1) {
            prevButton.prop('disabled', false).css('opacity', '1');
            nextButton.prop('disabled', false).css('opacity', '1');
        } else {
            prevButton.prop('disabled', true).css('opacity', '0.5');
            nextButton.prop('disabled', true).css('opacity', '0.5');
        }
    }
    
    // Mockup ön izlemesi oluştur
    function generateMockupPreview(mockupId, designId) {
        const width = $('#width-slider').val();
        const left = $('#left-slider').val();
        const top = $('#top-slider').val();

        const presetId = $('#preset-select').val();
        const presetCode = presetId ? presets[presetId].code : '';

        $('#drive-status').html('<p style="color:blue">🔄 Mockup oluşturuluyor...</p>');

        $.ajax({
            url: mockup_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'generate_mockup',
                nonce: mockup_ajax.nonce,
                mockup_id: mockupId,
                design_id: designId,
                width_percent: width,
                left_percent: left,
                top_percent: top,
                preset_code: presetCode.toLowerCase() // 🔥 Kritik
            },
            success: function(response) {
                if (response.success) {
                    $('#preview-placeholder').hide();
                    
                    // Ön izleme görselini göster - cache önlemek için timestamp ekle
                    const previewImage = $('#preview-image');
                    const imageUrl = response.data.url + '?t=' + new Date().getTime();
                    
                    previewImage.attr('src', imageUrl).show();
                    
                    // Global değişkenleri güncelle
                    currentPreviewUrl = response.data.url;
                    currentMockupName = response.data.mockup_name || currentMockupName;
                    currentDesignName = response.data.design_name || currentDesignName;
                    
                    // Görsel yüklendiğinde kontrol et
                    previewImage.on('load', function() {
                        $('.output-controls').show();
                        $('#drive-status').html('<p style="color:green">✅ Mockup oluşturuldu!</p>');
                    }).on('error', function() {
                        $('#drive-status').html('<p style="color:red">❌ Ön izleme yüklenemedi</p>');
                    });
                    
                    // İndirme linkini güncelle
                    $('#download-image').off('click').on('click', function() {
                        window.open(response.data.url, '_blank');
                    });
                    
                } else {
                    $('#drive-status').html(`<p style="color:red">❌ ${response.data}</p>`);
                }
            },
            error: function(xhr, status, error) {
                $('#drive-status').html('<p style="color:red">❌ Mockup oluşturma hatası: ' + error + '</p>');
            }
        });
    }
    
    // Ortam'a kaydet
    $('#save-to-media').on('click', function() {
        if (!currentPreviewUrl) {
            alert('Önce bir mockup oluşturun');
            return;
        }
        
        $('#drive-status').html('<p style="color:blue">🔄 Ortam kütüphanesine kaydediliyor...</p>');
        
        $.ajax({
            url: mockup_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'save_to_media',
                nonce: mockup_ajax.nonce,
                file_url: currentPreviewUrl,
                mockup_name: currentMockupName,
                design_name: currentDesignName,
                preset_code: $('#preset-select').val() || ''
            },
            success: function(response) {
                if (response.success) {
                    $('#drive-status').html(
                        `<p style="color:green">✅ ${response.data.message}</p>
                         <p><a href="${response.data.edit_url}" target="_blank">Görseli düzenle</a></p>`
                    );
                } else {
                    $('#drive-status').html(`<p style="color:red">❌ ${response.data}</p>`);
                }
            },
            error: function() {
                $('#drive-status').html('<p style="color:red">❌ Kaydetme hatası</p>');
            }
        });
    });
    
    // Resmi panoya kopyala
    $('#copy-image').on('click', function() {
        if (!currentPreviewUrl) {
            alert('Önce bir mockup oluşturun');
            return;
        }
        
        // Basit çözüm - linki kopyala
        const tempInput = document.createElement('input');
        tempInput.value = currentPreviewUrl;
        document.body.appendChild(tempInput);
        tempInput.select();
        document.execCommand('copy');
        document.body.removeChild(tempInput);
        
        $('#drive-status').html('<p style="color:green">✅ Resim linki panoya kopyalandı!</p>');
    });
    
    // Tek oluştur butonu
    $('#generate-single').on('click', function() {
        const mockupId = $('#mockup-select').val();
        const designId = $('#design-select').val();
        
        if (!mockupId || !designId) {
            alert('Lütfen mockup ve tasarım seçin');
            return;
        }
        
        generateMockupPreview(mockupId, designId);
    });
    
    // Yenile butonu
    $('#refresh-files').on('click', function() {
        const apiKey = $('#drive-api-key').val();
        const mockupFolderId = $('#mockup-folder-id').val();
        const koleksiyonFolderId = $('#koleksiyon-folder-id').val();
        
        if (apiKey && mockupFolderId) {
            loadMockupFiles(apiKey, mockupFolderId);
            if (koleksiyonFolderId) {
                loadCollectionFolders(apiKey, koleksiyonFolderId);
            }
        }
    });
    
    // Ayarları localStorage'a kaydet
    function saveSettings() {
        const settings = {
            apiKey: $('#drive-api-key').val(),
            mockupFolderId: $('#mockup-folder-id').val(),
            koleksiyonFolderId: $('#koleksiyon-folder-id').val(),
            selectedMockup: $('#mockup-select').val(),
            selectedCollection: $('#collection-select').val(),
            selectedDesign: $('#design-select').val(),
            width: $('#width-slider').val(),
            left: $('#left-slider').val(),
            top: $('#top-slider').val()
        };
        
        localStorage.setItem('mockupCreatorSettings', JSON.stringify(settings));
    }
    
    // Ayarları yükle
    function loadSettings() {
        const saved = localStorage.getItem('mockupCreatorSettings');
        if (saved) {
            const settings = JSON.parse(saved);
            
            $('#drive-api-key').val(settings.apiKey || '');
            $('#mockup-folder-id').val(settings.mockupFolderId || '');
            $('#koleksiyon-folder-id').val(settings.koleksiyonFolderId || '');
            $('#width-slider').val(settings.width || 33).trigger('input');
            $('#left-slider').val(settings.left || 34).trigger('input');
            $('#top-slider').val(settings.top || 27).trigger('input');
            
            // Eğer API key ve folder ID varsa, otomatik yükle
            if (settings.apiKey && settings.mockupFolderId) {
                setTimeout(() => {
                    loadMockupFiles(settings.apiKey, settings.mockupFolderId);
                    if (settings.koleksiyonFolderId) {
                        loadCollectionFolders(settings.apiKey, settings.koleksiyonFolderId);
                    }
                }, 1000);
            }
        }
    }
    
    // Ayarları WordPress'e kaydet
    $('#save-settings').on('click', function () {
        const apiKey = $('#drive-api-key').val();
        const mockupFolderId = $('#mockup-folder-id').val();
        const koleksiyonFolderId = $('#koleksiyon-folder-id').val();

        $('#drive-status').html('<p style="color:blue">🔄 Ayarlar kaydediliyor...</p>');

        $.ajax({
            url: mockup_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'save_settings',   // 🔥 Doğru action adı
                nonce: mockup_ajax.nonce,
                api_key: apiKey,
                mockup_folder: mockupFolderId,
                koleksiyon_folder: koleksiyonFolderId
            },
            success: function (response) {
                if (response.success) {
                    $('#drive-status').html(
                        '<p style="color:green">✅ Ayarlar kaydedildi!</p>'
                    );
                } else {
                    $('#drive-status').html(
                        `<p style="color:red">❌ Ayarlar kaydedilemedi: ${response.data}</p>`
                    );
                }
            },
            error: function () {
                $('#drive-status').html('<p style="color:red">❌ Kaydetme hatası</p>');
            }
        });
    });

    // YENİ: Otomasyon kontrolleri
$('#test-drive-now').on('click', function() {
    const button = $(this);
    const originalText = button.text();
    
    button.prop('disabled', true).text('🔍 Test Ediliyor...');
    
    $.ajax({
        url: mockup_ajax.ajax_url,
        method: 'POST',
        data: {
            action: 'test_drive_connection_manual',
            nonce: mockup_ajax.nonce
        },
        success: function(response) {
            if (response.success) {
                $('#drive-status').html(
                    `<p style="color:green">✅ ${response.data.message}</p>
                     <p>📁 ${response.data.file_count} dosya bulundu</p>`
                );
                
                // Sayfayı yenile
                setTimeout(() => {
                    location.reload();
                }, 2000);
            } else {
                $('#drive-status').html(`<p style="color:red">❌ ${response.data}</p>`);
            }
        },
        error: function() {
            $('#drive-status').html('<p style="color:red">❌ Test hatası</p>');
        },
        complete: function() {
            button.prop('disabled', false).text(originalText);
        }
    });
});

// YENİ: Logları göster/gizle
$('#view-drive-logs').on('click', function() {
    const logsContainer = $('#drive-logs');
    const button = $(this);
    
    if (logsContainer.is(':visible')) {
        logsContainer.hide();
        button.text('📋 Logları Görüntüle');
    } else {
        logsContainer.show();
        button.text('📋 Logları Gizle');
    }
});
    
    // Sayfa yüklendiğinde ayarları yükle
    loadSettings();
});

function convertSelectToThumbnail(selectElement) {
    selectElement.off('change.thumbnail').on('change.thumbnail', function () {
        const opt = $(this).find('option:selected');
        if (!opt.length) return;

        const thumb = opt.data('thumb');

        // Thumbnail önizlemesi için küçük bir alan eklemeyi tercih edebiliriz:
        let previewId = selectElement.attr('id') + '-thumb-preview';

        if ($('#' + previewId).length === 0) {
            selectElement.after(`<img id="${previewId}" class="mockup-option-thumb" style="margin-top:5px; display:block;">`);
        }

        $('#' + previewId).attr('src', thumb);
    });

    // İlk render
    selectElement.trigger('change.thumbnail');
}