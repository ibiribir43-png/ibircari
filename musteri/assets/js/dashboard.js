

function oynatVideo(url) {
    var v = document.getElementById('videoPlayer');
    var m = document.getElementById('videoModal');
    v.src = url;
    new bootstrap.Modal(m).show();
    v.play();
    m.addEventListener('hidden.bs.modal', function() { v.pause(); v.src = ''; }, { once: true });
}

function albumAc() {
    new bootstrap.Modal(document.getElementById('albumModal')).show();
}

function kapatAlbumAcRevize() {
    var am = bootstrap.Modal.getInstance(document.getElementById('albumModal'));
    if (am) am.hide();
    setTimeout(function() {
        new bootstrap.Modal(document.getElementById('revizeModal')).show();
    }, 350);
}

document.addEventListener('DOMContentLoaded', function() {
    var albumCarousel = document.getElementById('albumCarousel');
    if (albumCarousel) {
        albumCarousel.addEventListener('slid.bs.carousel', function (e) {
            var totalItems = document.querySelectorAll('#albumCarousel .carousel-item').length;
            // Eğer ulaşılan slayt sonuncu ise (indexler 0'dan başlar)
            if (e.to === totalItems - 1) {
                var actionBtns = document.getElementById('albumActionButtons');
                var uyariMetni = document.getElementById('albumUyariMetni');
                
                if(actionBtns) {
                    actionBtns.style.opacity = '1';
                    actionBtns.style.pointerEvents = 'auto';
                }
                if(uyariMetni) {
                    uyariMetni.style.display = 'none';
                }
            }
        });
    }
});