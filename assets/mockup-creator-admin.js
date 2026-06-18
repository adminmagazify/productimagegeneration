jQuery(document).ready(function($) {
    // Drive bağlantı testi
    $('#test-drive-connection').on('click', function() {
        const apiKey = $('#drive-api-key').val();
        const folderId = $('#mockup-folder-id').val();
        
        if (!apiKey) {
            alert('Lütfen API anahtarını girin');
            return;
        }
        
        if (!folderId) {
            alert('Lütfen Mockup Klasör ID girin');
            return;
        }
        
        $('#drive-status').html('<p style="color:blue">🔄 Drive bağlantısı test ediliyor...</p>');
        
        const testUrl = `https://www.googleapis.com/drive/v3/files?q='${folderId}'+in+parents&key=${apiKey}&fields=files(id,name,mimeType)`;
        
        $.ajax({
            url: testUrl,
            method: 'GET',
            timeout: 10000,
            success: function(response) {
                if (response.files && response.files.length > 0) {
                    const imageCount = response.files.filter(file => 
                        file.mimeType.startsWith('image/')
                    ).length;
                    
                    $('#drive-status').html(
                        `<p style="color:green">✅ Bağlantı başarılı!</p>
                         <p>📁 ${response.files.length} dosya bulundu (${imageCount} resim)</p>`
                    );
                } else {
                    $('#drive-status').html('<p style="color:orange">⚠ Bağlantı başarılı ama klasör boş.</p>');
                }
            },
            error: function(xhr) {
                let errorMsg = 'Bilinmeyen hata';
                if (xhr.responseJSON && xhr.responseJSON.error) {
                    errorMsg = xhr.responseJSON.error.message;
                }
                $('#drive-status').html(`<p style="color:red">❌ Hata: ${errorMsg}</p>`);
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
                    $('#drive-status').html(
                        `<p style="color:green">✅ ${response.data.length} mockup yüklendi!</p>`
                    );
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
                    $('#drive-status').html(
                        $('#drive-status').html() + `<p style="color:green">✅ ${response.data.length} koleksiyon yüklendi!</p>`
                    );
                }
            },
            error: function() {
                console.log('Koleksiyon yükleme hatası');
            }
        });
    }
    
    // Ayarları WordPress'e kaydet
    $('#save-settings').on('click', function() {
        const apiKey = $('#drive-api-key').val();
        const mockupFolderId = $('#mockup-folder-id').val();
        const koleksiyonFolderId = $('#koleksiyon-folder-id').val();
        
        $('#drive-status').html('<p style="color:blue">🔄 Ayarlar kaydediliyor...</p>');
        
        $.ajax({
            url: mockup_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'save_mockup_settings',
                nonce: mockup_ajax.nonce,
                api_key: apiKey,
                mockup_folder: mockupFolderId,
                koleksiyon_folder: koleksiyonFolderId
            },
            success: function(response) {
                if (response.success) {
                    $('#drive-status').html('<p style="color:green">✅ Ayarlar kaydedildi!</p>');
                } else {
                    $('#drive-status').html('<p style="color:red">❌ Ayarlar kaydedilemedi</p>');
                }
            },
            error: function() {
                $('#drive-status').html('<p style="color:red">❌ Kaydetme hatası</p>');
            }
        });
    });
});