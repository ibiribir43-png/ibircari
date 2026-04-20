let currentImageIndex = 0;
let selectedImages = new Set();

const currentImageElement = document.getElementById('currentImage');
const currentIndexElement = document.getElementById('currentIndex');
const selectButton = document.getElementById('selectButton');
const selectionCountElement = document.getElementById('selectionCount');

function updateImage() {
    currentImageElement.src = imageList[currentImageIndex];
    currentIndexElement.textContent = currentImageIndex + 1;

    // Seçili ise buton ve resim vurgusu
    if (selectedImages.has(currentImageIndex)) {
        selectButton.classList.add('selected');
        currentImageElement.classList.add('selected-image');
    } else {
        selectButton.classList.remove('selected');
        currentImageElement.classList.remove('selected-image');
    }
}

function nextImage() {
    if (currentImageIndex < imageList.length - 1) {
        currentImageIndex++;
        updateImage();
    }
}

function prevImage() {
    if (currentImageIndex > 0) {
        currentImageIndex--;
        updateImage();
    }
}

// Seçilen resimlerin adlarını güncelle
function updateSelectedNames() {
    const selectedList = Array.from(selectedImages).map(index => imageList[index].split('/').pop());
    document.getElementById('selectedNames').innerHTML = selectedList.join(', ');
}

// Resim seçme işlemi
selectButton.addEventListener('click', () => {
    if (selectedImages.has(currentImageIndex)) {
        selectedImages.delete(currentImageIndex);
    } else if (selectedImages.size < 60) {
        selectedImages.add(currentImageIndex);
    } else {
        alert('Maksimum 60 resim seçebilirsiniz!');
        return;
    }
    
    selectionCountElement.textContent = selectedImages.size;
    updateImage();
    updateSelectedNames();
});

// Klavye kontrolleri
document.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowRight') {
        nextImage();
    } else if (e.key === 'ArrowLeft') {
        prevImage();
    } else if (e.key === ' ') { // Boşluk tuşu
        selectButton.click();
    }
});

// Dokunmatik kaydırma için
let touchStartX = 0;
currentImageElement.addEventListener('touchstart', (e) => {
    touchStartX = e.touches[0].clientX;
});

currentImageElement.addEventListener('touchend', (e) => {
    const touchEndX = e.changedTouches[0].clientX;
    const diff = touchStartX - touchEndX;
    
    if (Math.abs(diff) > 50) { // En az 50px kaydırma
        if (diff > 0) {
            nextImage();
        } else {
            prevImage();
        }
    }
});

// İşlemi tamamlama
document.getElementById('completeButton').addEventListener('click', () => {
    if (selectedImages.size === 0) {
        alert('Lütfen en az bir resim seçiniz!');
        return;
    }
    
    const selectedList = Array.from(selectedImages).map(index => imageList[index]);
    document.getElementById('selectedList').innerHTML = selectedList
        .map(path => `<div>${path.split('/').pop()}</div>`)
        .join('');
    
    document.getElementById('selectionModal').style.display = 'block';
});

// Modal onaylama işlemi için yeni kod
console.log('viewer.js güncel');
document.getElementById('confirmSelection').addEventListener('click', async () => {
    const selectedList = Array.from(selectedImages).map(index => imageList[index]);
    if (selectedList.length === 0) {
        alert('Seçim yok!');
        return;
    }
    try {
        await fetch('save_selections.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ selections: selectedList }),
            credentials: 'same-origin'
        });
        alert('Seçimleriniz kaydedildi!');
        document.getElementById('selectionModal').style.display = 'none';
        window.location.href = 'index.php';
    } catch (error) {
        alert('HATA: ' + error.message);
    }
});

// Modal kapatma işlemi
document.getElementById('cancelSelection').addEventListener('click', () => {
    document.getElementById('selectionModal').style.display = 'none';
});

document.getElementById('prevButton').addEventListener('click', prevImage);
document.getElementById('nextButton').addEventListener('click', nextImage);

// Başlangıç görüntüsünü yükle
updateImage();
updateSelectedNames();