document.addEventListener('DOMContentLoaded', function() {
    const dropZone = document.getElementById('videoDropZone');
    const fileInput = document.getElementById('videoInput');

    if(dropZone && fileInput) {
        dropZone.addEventListener('click', () => {
            fileInput.click();
        });

        fileInput.addEventListener('change', function() {
            handleFiles(this.files);
        });

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => {
                dropZone.style.borderColor = '#0d6efd';
                dropZone.style.backgroundColor = '#f8f9fa';
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => {
                dropZone.style.borderColor = '#ccc';
                dropZone.style.backgroundColor = 'transparent';
            }, false);
        });

        dropZone.addEventListener('drop', function(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            
            const dataTransfer = new DataTransfer();
            if(files.length > 0) {
                dataTransfer.items.add(files[0]);
                fileInput.files = dataTransfer.files;
            }
            
            handleFiles(files);
        }, false);
    }
});

function handleFiles(files) {
    if (files.length > 0) {
        const file = files[0];
        if (file.type === 'video/mp4' || file.type === 'video/quicktime' || file.name.toLowerCase().endsWith('.mov')) {
            const preview = document.getElementById('videoPreview');
            if(preview) {
                preview.innerHTML = `
                    <div class="alert alert-info mb-0 d-flex justify-content-between align-items-center">
                        <div>
                            <i class="fas fa-video me-2"></i> 
                            <strong>${file.name}</strong> 
                            <span class="text-muted ms-2">(${(file.size / (1024 * 1024)).toFixed(2)} MB)</span>
                        </div>
                        <i class="fas fa-check text-success"></i>
                    </div>
                `;
            }
        } else {
            document.getElementById('videoInput').value = '';
            document.getElementById('videoPreview').innerHTML = '';
            Swal.fire('Hata', 'Lütfen geçerli bir video dosyası seçin (MP4 veya MOV).', 'error');
        }
    }
}

// Parçalı Yükleme (Chunked Upload) Mantığı
async function uploadVideo() {
    const fileInput = document.getElementById('videoInput');
    const musteriIdInput = document.getElementById('musteri_id');
    const baslikInput = document.getElementById('videoBaslik');
    
    if (!fileInput || fileInput.files.length === 0) {
        Swal.fire('Uyarı', 'Lütfen yüklemek için bir video seçin.', 'warning');
        return;
    }

    if (!musteriIdInput || !musteriIdInput.value) {
        Swal.fire('Hata', 'Müşteri kimliği (ID) bulunamadı. Sayfayı yenileyip tekrar deneyin.', 'error');
        return;
    }

    const file = fileInput.files[0];
    const musteriId = musteriIdInput.value;
    const baslik = baslikInput && baslikInput.value ? baslikInput.value : 'Video Klip';

    const maxSize = 500 * 1024 * 1024; // 500 MB limit
    if (file.size > maxSize) {
        Swal.fire('Hata', 'Dosya boyutu 500MB\'dan küçük olmalıdır.', 'error');
        return;
    }

    // Yükleme Arayüzü Ayarları
    const progressContainer = document.getElementById('videoProgressContainer');
    const progressBar = document.getElementById('videoProgressBar');
    const uploadBtn = document.querySelector('button[onclick="uploadVideo()"]');

    if(progressContainer) progressContainer.classList.remove('d-none');
    if(uploadBtn) {
        uploadBtn.disabled = true;
        uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Yükleniyor...';
    }

    // Parça ayarları
    const chunkSize = 5 * 1024 * 1024; // Her parça 5 MB
    const totalChunks = Math.ceil(file.size / chunkSize);
    const fileIdentifier = Date.now() + '_' + file.name.replace(/[^a-zA-Z0-9.]/g, ''); // Benzersiz ID
    let currentChunk = 0;

    // Tek bir parçayı yükleyen asenkron fonksiyon
    const uploadNextChunk = async () => {
        const start = currentChunk * chunkSize;
        const end = Math.min(start + chunkSize, file.size);
        const chunk = file.slice(start, end);

        const formData = new FormData();
        formData.append('video_chunk', chunk);
        formData.append('chunk_index', currentChunk);
        formData.append('total_chunks', totalChunks);
        formData.append('file_identifier', fileIdentifier);
        formData.append('file_name', file.name);
        
        // Sadece son parçada veritabanı kayıt bilgilerini gönderiyoruz
        if (currentChunk === totalChunks - 1) {
            formData.append('musteri_id', musteriId);
            formData.append('baslik', baslik);
        }

        try {
            const response = await fetch('api_videoklipupload.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                currentChunk++;
                
                // Progress Bar Güncellemesi
                const percentComplete = Math.round((currentChunk / totalChunks) * 100);
                if(progressBar) {
                    progressBar.style.width = percentComplete + '%';
                    progressBar.innerText = percentComplete + '%';
                }

                if (currentChunk < totalChunks) {
                    // Diğer parçayı yüklemeye devam et
                    uploadNextChunk();
                } else {
                    // Tüm parçalar bitti
                    if(uploadBtn) {
                        uploadBtn.disabled = false;
                        uploadBtn.innerHTML = 'Yükle';
                    }
                    Swal.fire({
                        icon: 'success',
                        title: 'Başarılı!',
                        text: 'Video başarıyla yüklendi.',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                }
            } else {
                throw new Error(result.message || 'Parça yüklenirken hata oluştu.');
            }
        } catch (error) {
            console.error("Upload Error:", error);
            if(uploadBtn) {
                uploadBtn.disabled = false;
                uploadBtn.innerHTML = 'Yükle';
            }
            if(progressContainer) progressContainer.classList.add('d-none');
            Swal.fire('Hata', error.message || 'Yükleme işlemi sırasında ağ hatası oluştu.', 'error');
        }
    };

    // İlk parçayı yüklemeye başla
    uploadNextChunk();
}