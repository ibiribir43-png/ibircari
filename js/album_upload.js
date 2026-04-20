document.addEventListener('DOMContentLoaded', () => {
    const albumBtn = document.getElementById('albumUpBtn');
    const albumInp = document.getElementById('albumInp');
    const pDiv = document.getElementById('albumPDiv');
    const pBar = document.getElementById('albumPBar');

    // Dosya seçildiğinde butonu aktif et
    albumInp.addEventListener('change', () => {
        albumBtn.disabled = albumInp.files.length === 0;
        document.getElementById('albumInfo').innerText = albumInp.files.length + " albüm hazır.";
    });

    // Yükleme butonu tıklandığında fonksiyonu çağır
    albumBtn.addEventListener('click', async () => {
        const files = albumInp.files;
        if(files.length === 0) return alert("Albüm dosyası seçilmedi!");

        const m_id = document.getElementById('album_m_id').value;
        albumBtn.disabled = true;
        pDiv.style.display = 'block';

        for(let i = 0; i < files.length; i++) {
            const formData = new FormData();
            formData.append('m_id', m_id);
            formData.append('albums[]', files[i]);

            try {
                await new Promise((resolve, reject) => {
                    const xhr = new XMLHttpRequest();
                    xhr.upload.onprogress = (e) => { 
                        if(e.lengthComputable) pBar.style.width = Math.round((e.loaded / e.total) * 100) + '%';
                    };
                    xhr.onload = () => { 
                        try {
                            const res = JSON.parse(xhr.responseText);
                            if(res.status === 'success') resolve();
                            else reject(res.message || "Sunucu hatası");
                        } catch(e) { reject("Sunucu JSON hatası"); }
                    };
                    xhr.onerror = () => reject("Ağ hatası");
                    xhr.open("POST", "api_haziralbümupload.php");
                    xhr.send(formData);
                });
            } catch(err) {
                alert(`Hata: ${err}`);
                albumBtn.disabled = false;
                return;
            }
        }

        pBar.innerText = "Albüm Yüklendi! Sayfa yenileniyor...";
        setTimeout(() => location.reload(), 1200);
    });
});