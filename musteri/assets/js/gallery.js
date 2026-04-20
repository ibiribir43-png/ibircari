// DOSYA ADI: assets/js/gallery.js 
// V4.20: Orijinal Tüm Mantık Korundu + Swipe (Kaydırma), Focus Mode ve Mobil Sepet Fixleri Eklendi!

document.addEventListener('DOMContentLoaded', () => {
    
    let masterImageList = []; 
    let viewableImageList = []; 
    let currentImageIndex = -1; 
    let isLoading = true;
    
    const isMobile = window.innerWidth < 768;
    let THUMB_WIDTH = isMobile ? 80 : 100;
    const OVERSCAN_COUNT = isMobile ? 3 : 5;
    let PRELOAD_COUNT = isMobile ? 2 : 5;
    let preloadCache = {};
    const MAX_VISIBLE_THUMBS = 12;
    const SCROLL_IDLE_MS = 2000;
    let scrollIdleTimer = null;

    window.addEventListener('resize', () => {
        const mobileNow = window.innerWidth < 768;
        if (mobileNow !== isMobile) location.reload();
    });

    // --- DOM ELEMENTLERİ ---
    let mainImage = document.getElementById('main-image');
    let viewerPlaceholder = document.getElementById('viewer-placeholder');
    let mainControls = document.getElementById('main-controls');
    let mainFilename = document.getElementById('main-filename');
    let btnMainSelect = document.getElementById('main-select-btn');
    let btnMainFav = document.getElementById('main-fav-btn');
    let btnMainNote = document.getElementById('main-note-btn');
    let btnLightboxPrev = document.getElementById('lightbox-prev');
    let btnLightboxNext = document.getElementById('lightbox-next');
    let noteEditor = document.getElementById('main-note-editor');
    let noteTextarea = document.getElementById('main-note-textarea');
    let btnNoteSave = document.getElementById('main-note-save-btn');
    let btnNoteCancel = document.getElementById('main-note-cancel-btn');
    let filmstripContainer = document.getElementById('virtual-scroll-container') || document.getElementById('filmstrip-container');
    let filmstripTrack = document.getElementById('filmstrip-track');
    let filmstripLoading = document.getElementById('filmstrip-loading');
    let filmstripCounter = document.getElementById('filmstrip-counter');
    let cartSidebar = document.getElementById('cart-sidebar');
    let cartItemsContainer = document.getElementById('cart-items-container');
    let cartEmptyMsg = document.getElementById('cart-empty-msg');
    let cartCount = document.getElementById('cart-count');
    let btnCompleteSelection = document.getElementById('complete-selection-btn');
    let confirmModal = document.getElementById('confirm-modal');
    let btnConfirmClose = document.getElementById('confirm-close-btn');
    let btnConfirmSubmit = document.getElementById('confirm-submit-btn');
    let confirmCountSelected = document.getElementById('confirm-count-selected');
    let confirmCountFavorited = document.getElementById('confirm-count-favorited');
    let confirmGeneralNote = document.getElementById('confirm-general-note');
    
    // YENİ: Mobil Focus Mode Butonları ve Sepet Toggle
    let focusSel = document.getElementById('f-sel');
    let focusFav = document.getElementById('f-fav');
    let cartToggleBtn = document.getElementById('cart-toggle-btn');
    let cartCloseBtn = document.querySelector('.cart-close');

    // ==========================================
    // 1. BAŞLATMA VE API ÇAĞRISI
    // ==========================================
    async function initializeGallery() {
        if (!filmstripLoading || !viewerPlaceholder) return;
        filmstripLoading.style.display = 'flex';
        viewerPlaceholder.style.display = 'flex';

        try {
            const response = await fetchWithCredentials('api_portal.php?action=get_images');
            const data = await response.json();

            if (data.status === 'error') throw new Error(data.message);
            if (!data.masterImageList || data.masterImageList.length === 0) throw new Error('Fotoğraf bulunamadı.');

            masterImageList = data.masterImageList;
            isLoading = false;
            filterViewableList('all');

            if (filmstripTrack) {
                filmstripTrack.style.width = `${viewableImageList.length * THUMB_WIDTH}px`;
                filmstripTrack.innerHTML = '';
            }

            renderVisibleThumbs();
            loadVisibleThumbImages();
            updateCart();

            if (viewableImageList.length > 0) {
                setCurrentImage(0);
            } else {
                clearMainImage();
            }

        } catch (error) {
            console.error('Başlatma hatası:', error);
            if (viewerPlaceholder) viewerPlaceholder.textContent = `Hata: ${error.message}`;
            isLoading = false;
        } finally {
            if (filmstripLoading) filmstripLoading.style.display = 'none';
        }
    }

    // ==========================================
    // 2. SANAL KAYDIRMA (VIRTUAL SCROLL - ORİJİNAL)
    // ==========================================
    let virtualScrollRAF;
    function onFilmstripScroll() {
        if (virtualScrollRAF) cancelAnimationFrame(virtualScrollRAF);
        virtualScrollRAF = requestAnimationFrame(renderVisibleThumbs);
        scheduleIdleImageLoad();
    }
    
    function scrollToViewableIndex(index, behavior = 'smooth') {
        if (!filmstripContainer || !viewableImageList) return;
        if (index < 0 || index >= viewableImageList.length) return;
        try {
            const targetScrollLeft = (index * THUMB_WIDTH) - (filmstripContainer.clientWidth / 2) + (THUMB_WIDTH / 2);
            filmstripContainer.scrollTo({ left: Math.max(0, targetScrollLeft), behavior: behavior });
        } catch (e) {}
    }

    function scheduleIdleImageLoad() {
        if (scrollIdleTimer) clearTimeout(scrollIdleTimer);
        scrollIdleTimer = setTimeout(() => { loadVisibleThumbImages(); }, SCROLL_IDLE_MS);
    }

    function loadVisibleThumbImages() {
        if (!filmstripTrack) return;
        const imgs = Array.from(filmstripTrack.querySelectorAll('img.thumb-image'));
        let loads = 0;
        const MAX_LOADS_PER_RUN = 10;
        for (const img of imgs) {
            if (loads >= MAX_LOADS_PER_RUN) break;
            if (img.dataset.src && (!img.getAttribute('data-loading'))) {
                img.setAttribute('data-loading', '1');
                const assign = () => {
                    img.src = img.dataset.src;
                    img.removeAttribute('data-src');
                    img.removeAttribute('data-loading');
                };
                if ('requestIdleCallback' in window) {
                    requestIdleCallback(assign, { timeout: 2000 });
                } else {
                    setTimeout(assign, 100);
                }
                loads++;
            }
        }
    }

    function renderVisibleThumbs() {
        if (!filmstripTrack || !viewableImageList) return;
        filmstripTrack.style.width = `${Math.max(0, viewableImageList.length * THUMB_WIDTH)}px`;
        if (!filmstripContainer || !filmstripTrack) return;

        const containerWidth = filmstripContainer.clientWidth;
        const scrollLeft = filmstripContainer.scrollLeft;

        let startIndex = Math.max(0, Math.floor(scrollLeft / THUMB_WIDTH) - OVERSCAN_COUNT);
        let endIndex = Math.min(viewableImageList.length - 1, Math.ceil((scrollLeft + containerWidth) / THUMB_WIDTH) + OVERSCAN_COUNT);

        const currentDOMNodes = new Map();
        filmstripTrack.querySelectorAll('.thumb-wrapper').forEach(node => {
            currentDOMNodes.set(node.dataset.path, node);
        });

        const fragment = document.createDocumentFragment();
        for (let i = startIndex; i <= endIndex; i++) {
            const image = viewableImageList[i];
            if (!image) continue;
            if (currentDOMNodes.has(image.path)) {
                currentDOMNodes.delete(image.path);
            } else {
                fragment.appendChild(createThumbNode(image, i));
            }
        }

        currentDOMNodes.forEach(node => {
            if (filmstripTrack) filmstripTrack.removeChild(node);
        });

        if (filmstripTrack) filmstripTrack.appendChild(fragment);
        scheduleIdleImageLoad();
    }

    function createThumbNode(image, idx) {
        const wrapper = document.createElement('div');
        wrapper.className = 'thumb-wrapper';
        wrapper.dataset.path = image.path;
        wrapper.dataset.index = String(idx);
        wrapper.style.position = 'absolute';
        wrapper.style.top = '0';
        wrapper.style.height = '100%';
        wrapper.style.left = `${idx * THUMB_WIDTH}px`;
        wrapper.style.width = `${THUMB_WIDTH}px`;
        wrapper.addEventListener('click', onThumbClick, { passive: true });

        const img = document.createElement('img');
        img.className = 'thumb-image';
        img.dataset.src = image.path;
        img.setAttribute('data-thumb-path', image.path);
        img.style.height = '100%';
        img.style.width = '100%';
        img.style.objectFit = 'cover';

        const center = currentImageIndex > -1 ? currentImageIndex : 0;
        if (Math.abs(idx - center) <= PRELOAD_COUNT + OVERSCAN_COUNT) {
            img.src = image.path;
        }
        img.alt = getFilename(image.path);
        img.loading = 'lazy';
        
        wrapper.appendChild(img);
        wrapper.appendChild(createThumbIcons(image));
        return wrapper;
    }

    function createThumbIcons(image) {
        const iconsDiv = document.createElement('div');
        iconsDiv.className = 'thumb-icons';
        if (image.note && image.note.trim() !== '') {
            iconsDiv.innerHTML += '<i class="fa-solid fa-comment-dots thumb-icon has-note"></i>';
        }
        if (image.selection_type === 2 || image.selection_type === 3) {
            iconsDiv.innerHTML += '<i class="fa-solid fa-heart thumb-icon is-favorited"></i>';
        }
        if (image.selection_type === 1 || image.selection_type === 3) {
            iconsDiv.innerHTML += '<i class="fa-solid fa-check-circle thumb-icon is-selected"></i>';
        }
        return iconsDiv;
    }

    function onThumbClick(e) {
        const index = parseInt(e.currentTarget.dataset.index, 10);
        setCurrentImage(index);
    }
    
    function unloadFarImages(centerIndex) {
        if (!Array.isArray(viewableImageList)) return;
        const low = Math.max(0, centerIndex - PRELOAD_COUNT - OVERSCAN_COUNT);
        const high = Math.min(viewableImageList.length - 1, centerIndex + PRELOAD_COUNT + OVERSCAN_COUNT);
        viewableImageList.forEach((img, idx) => {
            if (idx < low || idx > high) {
                const thumbNode = document.querySelector(`img[data-thumb-path="${img.path}"]`);
                if (thumbNode && thumbNode.dataset.src) {
                    thumbNode.removeAttribute('src');
                }
                if (preloadCache[img.path]) {
                    delete preloadCache[img.path];
                }
            }
        });
    }

    function setThumbSrcIfNear(path, index, centerIndex) {
        const thumbNode = document.querySelector(`img[data-thumb-path="${path}"]`);
        if (!thumbNode) return;
        const dist = Math.abs(index - centerIndex);
        if (dist <= PRELOAD_COUNT + OVERSCAN_COUNT) {
            if (!thumbNode.getAttribute('src')) {
                thumbNode.setAttribute('src', thumbNode.dataset.src);
            }
        } else {
            thumbNode.removeAttribute('src');
        }
    }

    // ==========================================
    // 3. EKRAN VE ARAYÜZ YÖNETİMİ
    // ==========================================
    function setCurrentImage(index) {
        if (index < 0 || index >= viewableImageList.length || !mainImage || !viewerPlaceholder) return;

        filmstripTrack?.querySelectorAll('.thumb-wrapper').forEach(w => w.classList.remove('is-active'));
        filmstripTrack?.querySelector(`[data-index="${index}"]`)?.classList.add('is-active');

        const image = viewableImageList[index];
        if (!image) return;
        
        currentImageIndex = masterImageList.findIndex(img => img.path === image.path);
        
        // YENİ: Resim değişirken hafif bir geçiş efekti (Opacity)
        mainImage.style.opacity = '0.5';
        setTimeout(() => {
            mainImage.src = image.path;
            mainImage.style.opacity = '1';
        }, 100);

        mainImage.style.display = 'block';
        viewerPlaceholder.style.display = 'none';
        
        if (mainControls) mainControls.style.display = 'flex';
        if (btnLightboxPrev) btnLightboxPrev.style.display = 'block';
        if (btnLightboxNext) btnLightboxNext.style.display = 'block';

        updateMainControls(image);
        updatePreloadCache(currentImageIndex);
        unloadFarImages(index);
        viewableImageList.forEach((img, i) => setThumbSrcIfNear(img.path, i, index));
        scrollToViewableIndex(index, 'smooth');
    }

    function updatePreloadCache(globalIndex) {
        preloadCache = {};
        for (let i = 1; i <= PRELOAD_COUNT; i++) {
            const n = globalIndex + i;
            const p = globalIndex - i;
            if (n < masterImageList.length) { preloadCache[masterImageList[n].path] = new Image(); preloadCache[masterImageList[n].path].src = masterImageList[n].path; }
            if (p >= 0) { preloadCache[masterImageList[p].path] = new Image(); preloadCache[masterImageList[p].path].src = masterImageList[p].path; }
        }
    }

    function updateMainControls(image) {
        if (!image) return;
        if (mainFilename) mainFilename.textContent = getFilename(image.path);
        
        const isSel = image.selection_type === 1 || image.selection_type === 3;
        const isFav = image.selection_type === 2 || image.selection_type === 3;
        const canNote = isSel || isFav;
        
        // Masaüstü Butonları Güncelleme
        if (btnMainSelect) {
            btnMainSelect.classList.toggle('active', isSel);
            btnMainSelect.querySelector('span').textContent = isSel ? 'Seçildi' : 'Seç';
        }
        
        if (btnMainFav) {
            btnMainFav.classList.toggle('active', isFav);
        }
        
        // YENİ: Mobil Focus Modu Şeffaf Butonları Güncelleme
        if (focusSel) focusSel.classList.toggle('active-sel', isSel);
        if (focusFav) focusFav.classList.toggle('active-fav', isFav);
        
        // Not Butonu
        const hasNote = image.note && image.note.trim() !== '';
        if (btnMainNote) {
            btnMainNote.classList.toggle('active', hasNote);
            btnMainNote.querySelector('span').textContent = hasNote ? 'Notu Düzenle' : 'Not Ekle';
            btnMainNote.disabled = !canNote;
        }

        if (noteEditor?.style.display === 'block') {
            noteTextarea.value = image.note || '';
        }
    }

    function navigateLightbox(direction) {
        if (currentImageIndex === -1 || isLoading) return;
        let nextIndex = currentImageIndex + direction;
        if (nextIndex < 0) nextIndex = masterImageList.length - 1;
        else if (nextIndex >= masterImageList.length) nextIndex = 0;
        
        const nextImage = masterImageList[nextIndex];
        const viewableIndex = viewableImageList.findIndex(img => img.path === nextImage.path);

        if (viewableIndex > -1) {
            setCurrentImage(viewableIndex);
        } else {
            filterViewableList('all');
            setTimeout(() => {
                const idx = viewableImageList.findIndex(img => img.path === nextImage.path);
                if (idx > -1) setCurrentImage(idx);
            }, 50);
        }
    }

    function filterViewableList(filterType) {
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.filter === filterType);
        });

        switch (filterType) {
            case 'selected': viewableImageList = masterImageList.filter(img => img.selection_type === 1 || img.selection_type === 3); break;
            case 'favorited': viewableImageList = masterImageList.filter(img => img.selection_type === 2 || img.selection_type === 3); break;
            case 'all': default: viewableImageList = [...masterImageList]; break;
        }

        if (filmstripTrack) {
            filmstripTrack.style.width = `${viewableImageList.length * THUMB_WIDTH}px`;
            filmstripTrack.innerHTML = '';
        }
        if (filmstripContainer) filmstripContainer.scrollLeft = 0; 
        renderVisibleThumbs();
        updateFilmstripCounter();
    }

    // ==========================================
    // 4. API İŞLEMLERİ (Toggle, Not, Gönderim)
    // ==========================================
    async function handleToggleAction(actionType) {
        if (currentImageIndex === -1) return;
        const image = masterImageList[currentImageIndex];
        const oldType = image.selection_type;
        const apiType = (actionType === 'select') ? 1 : 2;

        try {
            const res = await fetchWithCredentials('api_portal.php?action=toggle', {
                method: 'POST',
                body: JSON.stringify({ 
                    image_path: image.path, 
                    filename: image.filename,
                    type: apiType 
                })
            });
            const result = await res.json();
            if (result.status === 'success') {
                image.selection_type = calculateNewSelectionType(oldType, actionType);
                updateAllUI(image);
            } else {
                alert(result.message || 'Bir hata oluştu');
            }
        } catch (error) {
            alert('Bağlantı hatası oluştu.');
        }
    }

    function calculateNewSelectionType(oldType, action) {
        let isSelected = oldType === 1 || oldType === 3;
        let isFavorited = oldType === 2 || oldType === 3;
        if (action === 'select') isSelected = !isSelected; else isFavorited = !isFavorited;
        if (isSelected && isFavorited) return 3;
        if (isSelected) return 1;
        if (isFavorited) return 2;
        return 0;
    }

    async function handleSaveNote() {
        if (currentImageIndex === -1) return;
        const image = masterImageList[currentImageIndex];
        const newNote = noteTextarea.value.trim();
        const oldNote = image.note;

        image.note = newNote;
        updateAllUI(image);
        if (noteEditor) noteEditor.style.display = 'none';

        try {
            await fetchWithCredentials('api_portal.php?action=save_note', {
                method: 'POST',
                body: JSON.stringify({ filename: image.filename, note: newNote })
            });
        } catch (error) {
            image.note = oldNote;
            updateAllUI(image);
            alert('Hata: Not kaydedilemedi.');
        }
    }
    
    async function handleCompleteSelection() {
        const generalNote = confirmGeneralNote?.value.trim();
        if (btnConfirmSubmit) {
            btnConfirmSubmit.disabled = true;
            btnConfirmSubmit.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Gönderiliyor...';
        }
        try {
            if (generalNote) {
                await fetchWithCredentials('api_portal.php?action=save_general_note', {
                    method: 'POST',
                    body: JSON.stringify({ note: generalNote })
                });
            }
            window.location.href = 'dashboard.php?status=completed';
        } catch (error) {
            alert('Hata: Seçim tamamlanamadı.');
            if (btnConfirmSubmit) { btnConfirmSubmit.disabled = false; btnConfirmSubmit.innerHTML = 'Seçimleri Gönder ve Bitir'; }
        }
    }

    function updateAllUI(image) {
        if (currentImageIndex > -1 && masterImageList[currentImageIndex]?.path === image.path) updateMainControls(image);
        renderVisibleThumbs();
        updateCart();
    }

    // ==========================================
    // 5. SEPET YÖNETİMİ
    // ==========================================
    function updateCart() {
        const selectedItems = masterImageList.filter(img => img.selection_type === 1 || img.selection_type === 3);
        const favoritedCount = masterImageList.filter(img => img.selection_type === 2 || img.selection_type === 3).length;
        
        // Yeni: Mobil Butondaki Sepet Sayısı Rozeti
        const cartBadge = document.getElementById('cart-badge');
        if(cartBadge) cartBadge.textContent = selectedItems.length;

        if (cartCount) cartCount.textContent = `(${selectedItems.length})`;
        if (btnCompleteSelection) btnCompleteSelection.disabled = (selectedItems.length === 0);
        if (confirmCountSelected) confirmCountSelected.textContent = selectedItems.length;
        if (confirmCountFavorited) confirmCountFavorited.textContent = favoritedCount;

        if (selectedItems.length === 0) {
            if (cartItemsContainer) cartItemsContainer.innerHTML = '';
            if (cartEmptyMsg) cartEmptyMsg.style.display = 'flex'; // block yerine flex
        } else {
            if (cartEmptyMsg) cartEmptyMsg.style.display = 'none';
            if (cartItemsContainer) cartItemsContainer.innerHTML = '';
            selectedItems.forEach(image => cartItemsContainer.appendChild(createCartItemNode(image)));
        }
    }

    function createCartItemNode(image) {
        const item = document.createElement('div');
        item.className = 'cart-item';
        item.dataset.path = image.path;
        item.innerHTML = `<img src="${image.path}" class="cart-item-thumb"><div class="cart-item-info"><div class="cart-item-filename">${getFilename(image.path)}</div><div class="cart-item-note-preview">${image.note ? 'Not: ' + image.note.substring(0,20) : ''}</div></div>`;
        
        const actions = document.createElement('div');
        actions.className = 'cart-item-actions';
        
        const btnRemove = document.createElement('button');
        btnRemove.className = 'cart-action-btn btn-remove';
        btnRemove.innerHTML = `<i class="fa-solid fa-trash-can"></i>`;
        btnRemove.onclick = (e) => { 
            e.stopPropagation(); 
            currentImageIndex = masterImageList.findIndex(img => img.path === image.path); 
            handleToggleAction('select'); 
        };
        
        actions.appendChild(btnRemove);
        item.querySelector('.cart-item-info').appendChild(actions);
        item.onclick = () => {
            const idx = viewableImageList.findIndex(img => img.path === image.path);
            if (idx > -1) setCurrentImage(idx);
            
            // Mobilde tıklandığında sepeti otomatik kapatıp resmi göstersin
            if(window.innerWidth < 899 && cartSidebar) {
                cartSidebar.classList.remove('open');
            }
        };
        return item;
    } 

    function getFilename(path) { return path ? path.split('/').pop() : ''; }
    function updateFilmstripCounter() { if (filmstripCounter) filmstripCounter.textContent = `${viewableImageList.length} / ${masterImageList.length}`; }

    function clearMainImage() {
        if (mainImage) { mainImage.src = ''; mainImage.style.display = 'none'; }
        viewerPlaceholder.style.display = 'flex';
        if(mainControls) mainControls.style.display = 'none';
        if(btnLightboxPrev) btnLightboxPrev.style.display = 'none';
        if(btnLightboxNext) btnLightboxNext.style.display = 'none';
        currentImageIndex = -1;
    }

    async function fetchWithCredentials(url, options = {}) {
        const res = await fetch(url, {
            method: options.method || 'GET',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', ...options.headers },
            body: options.body
        });
        if (!res.ok) throw new Error('Sunucu hatası');
        return res;
    }

    // ==========================================
    // 6. EVENT LİSTENER'LAR VE SWIPE FİXİ
    // ==========================================
    
    if (filmstripContainer) {
        filmstripContainer.addEventListener('wheel', (ev) => {
            if (Math.abs(ev.deltaY) > Math.abs(ev.deltaX)) { 
                ev.preventDefault(); 
                // Daha pürüzsüz kaydırma için (Smooth scroll)
                filmstripContainer.scrollBy({
                    left: ev.deltaY > 0 ? 150 : -150, 
                    behavior: 'smooth' 
                });
            }
        }, { passive: false });
        filmstripContainer.addEventListener('scroll', onFilmstripScroll, { passive: true });
    }

    // Masaüstü Butonlar
    document.getElementById('filter-all').onclick = () => filterViewableList('all');
    document.getElementById('filter-selected').onclick = () => filterViewableList('selected');
    document.getElementById('filter-favorited').onclick = () => filterViewableList('favorited');
    if(btnMainSelect) btnMainSelect.onclick = () => handleToggleAction('select');
    if(btnMainFav) btnMainFav.onclick = () => handleToggleAction('favorite');
    if(btnMainNote) btnMainNote.onclick = () => { if (currentImageIndex === -1) return; noteTextarea.value = masterImageList[currentImageIndex].note || ''; noteEditor.style.display = 'block'; noteTextarea.focus(); };
    if(btnNoteSave) btnNoteSave.onclick = handleSaveNote;
    if(btnNoteCancel) btnNoteCancel.onclick = () => noteEditor.style.display = 'none';
    if(btnLightboxPrev) btnLightboxPrev.onclick = () => navigateLightbox(-1);
    if(btnLightboxNext) btnLightboxNext.onclick = () => navigateLightbox(1);
    if(btnCompleteSelection) btnCompleteSelection.onclick = () => confirmModal.style.display = 'flex';
    if(btnConfirmSubmit) btnConfirmSubmit.onclick = handleCompleteSelection;
    if(btnConfirmClose) btnConfirmClose.onclick = () => confirmModal.style.display = 'none';
    
    // YENİ FİX: Mobil Focus Mode Butonları
    if(focusSel) focusSel.onclick = () => handleToggleAction('select');
    if(focusFav) focusFav.onclick = () => handleToggleAction('favorite');

    // YENİ FİX: Mobil Sepet Açma/Kapama
    if(cartToggleBtn) {
        cartToggleBtn.addEventListener('click', () => {
            if(cartSidebar) cartSidebar.classList.add('open');
        });
    }
    if(cartCloseBtn) {
        cartCloseBtn.addEventListener('click', () => {
            if(cartSidebar) cartSidebar.classList.remove('open');
        });
    }

    // YENİ FİX: Mobilde Resim Üzerinde Sağa/Sola Kaydırma (Swipe)
    const viewerArea = document.querySelector('.viewer-area');
    let touchStartX = 0;
    
    if (viewerArea) {
        viewerArea.addEventListener('touchstart', (e) => {
            touchStartX = e.changedTouches[0].screenX;
        }, {passive: true});

        viewerArea.addEventListener('touchend', (e) => {
            const touchEndX = e.changedTouches[0].screenX;
            const diff = touchStartX - touchEndX;
            
            // 50px'den fazla kaydırıldıysa yönü tespit et
            if (Math.abs(diff) > 50) {
                if (diff > 0) {
                    navigateLightbox(1); // Sola kaydırıldı, Sonrakine geç
                } else {
                    navigateLightbox(-1); // Sağa kaydırıldı, Öncekine geç
                }
            }
        }, {passive: true});
    }

    // Klavye Yön Tuşları
    document.onkeydown = (e) => {
        if (isLoading || document.activeElement.tagName === 'TEXTAREA') return;
        if (e.key === 'ArrowRight') navigateLightbox(1);
        if (e.key === 'ArrowLeft') navigateLightbox(-1);
    };

    // Sistemi Başlat
    initializeGallery();
});