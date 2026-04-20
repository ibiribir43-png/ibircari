// Admin panel JS
document.addEventListener('DOMContentLoaded', function () {
    // Basit onay kutusu örneği
    const deleteLinks = document.querySelectorAll('a[href*="?sil="]');
    deleteLinks.forEach(link => {
        link.addEventListener('click', function (e) {
            if (!confirm('Silmek istediğine emin misin?')) {
                e.preventDefault();
            }
        });
    });

    console.log('Admin panel JS yüklendi.');
});