<?php
// modallar.php
// Bu dosya dashboard.php içinden çağırılmaktadır. Değişkenler oradan miras alınır.
?>

<?php if ($sozlesme): ?>
<div class="modal fade" id="sozlesmeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">Hizmet Sözleşmesi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="text-center mb-3">
                    <span class="badge bg-light text-dark border">Sözleşme No: <?= htmlspecialchars($sozlesme['sozlesme_no']); ?></span>
                </div>
                <div style="line-height:1.9;color:#444;font-size:0.9rem;">
                    <?= nl2br(htmlspecialchars((string)$sozlesme['sozlesme_maddeleri'])); ?>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Kapat</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="videoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content bg-black border-0">
            <div class="modal-header border-0 pb-0 justify-content-end">
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2">
                <div class="ratio ratio-16x9">
                    <video id="videoPlayer" controls class="rounded" preload="none" style="background:#000;"></video>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($albumler)): ?>
<div class="modal fade" id="albumModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content bg-black border-0">
            <div class="modal-header border-0 pb-0 justify-content-between px-4">
                <span class="text-white fw-bold"><i class="fas fa-images me-2" style="color:var(--gold-light,#f0d080);"></i>Albüm İnceleme</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body p-0 position-relative">
                <div id="albumCarousel" class="carousel slide" data-bs-ride="false">
                    <div class="carousel-inner">
                    <?php $i=0; foreach($albumler as $a): ?>
                        <div class="carousel-item <?= $i===0?'active':''; ?>">
                            <div class="d-flex align-items-center justify-content-center" style="height:82vh;background:#0a0a0a;">
                                <img src="<?= htmlspecialchars($url_albumler . $a['name'], ENT_QUOTES); ?>"
                                     style="max-height:100%;max-width:100%;object-fit:contain;"
                                     alt="Sayfa <?= $i+1; ?>" loading="lazy">
                            </div>
                            <div class="carousel-caption" style="bottom:70px;">
                                <span class="badge bg-dark bg-opacity-75">Sayfa <?= $i+1; ?> / <?= count($albumler); ?></span>
                            </div>
                        </div>
                    <?php $i++; endforeach; ?>
                    </div>
                    <?php if (count($albumler) > 1): ?>
                    <button class="carousel-control-prev" type="button" data-bs-target="#albumCarousel" data-bs-slide="prev">
                        <span class="carousel-control-prev-icon" style="width:3rem;height:3rem;"></span>
                    </button>
                    <button class="carousel-control-next" type="button" data-bs-target="#albumCarousel" data-bs-slide="next">
                        <span class="carousel-control-next-icon" style="width:3rem;height:3rem;"></span>
                    </button>
                    <?php endif; ?>
                </div>

                <?php if ($wf == 4): ?>
                <?php $coklu_sayfa = count($albumler) > 1; ?>
                
                <div id="albumUyariMetni" style="position:absolute; bottom:25px; left:0; right:0; text-align:center; z-index:20; <?= !$coklu_sayfa ? 'display:none;' : '' ?>">
                    <span style="background: rgba(0,0,0,0.8); color: #f0d080; padding: 10px 20px; border-radius: 50px; font-size: 0.85rem; font-weight: 600; border: 1px solid rgba(240,208,128,0.3);">
                        <i class="fas fa-arrow-right me-2"></i>İşlem yapabilmek için lütfen tüm sayfaları inceleyin.
                    </span>
                </div>

                <div id="albumActionButtons" style="position:absolute; bottom:16px; left:0; right:0; text-align:center; z-index:20; opacity: <?= $coklu_sayfa ? '0' : '1' ?>; pointer-events: <?= $coklu_sayfa ? 'none' : 'auto' ?>; transition: opacity 0.4s ease;">
                    <form method="POST" style="display:inline-block; margin-right:8px;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token); ?>">
                        <?php if (!empty($kapaklar)): ?>
                        <select name="secilen_kapak" class="form-select form-select-sm d-inline-block mb-2" style="width:auto;min-width:220px;background:rgba(255,255,255,0.9);">
                            <option value="">Kapak Modeli (Opsiyonel)</option>
                            <?php foreach ($kapaklar as $k): ?>
                            <option value="<?= (int)$k['id']; ?>"><?= htmlspecialchars(isset($k['kapak_adi']) ? $k['kapak_adi'] : 'Model '.$k['id']); ?></option>
                            <?php endforeach; ?>
                        </select><br>
                        <?php endif; ?>
                        <button type="submit" name="musteri_album_onay" style="background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;border-radius:50px;padding:12px 32px;font-weight:700;cursor:pointer;box-shadow:0 4px 15px rgba(22,163,74,.5);">
                            <i class="fas fa-check-circle me-2"></i>ALBÜMÜ ONAYLIYORUM
                        </button>
                    </form>
                    <button onclick="kapatAlbumAcRevize()" style="background:linear-gradient(135deg,#d97706,#b45309);color:#fff;border:none;border-radius:50px;padding:12px 24px;font-weight:700;cursor:pointer;">
                        <i class="fas fa-redo me-1"></i>Revize
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($wf == 4): ?>
<div class="modal fade" id="revizeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fas fa-redo me-2 text-warning"></i>Revize Talebi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token); ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ne değiştirilmesini istiyorsunuz?</label>
                        <textarea name="revize_notu" class="form-control" rows="4" placeholder="Detaylı açıklayın..." required></textarea>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Vazgeç</button>
                        <button type="submit" name="musteri_revize" class="btn btn-warning rounded-pill px-4 fw-bold">
                            <i class="fas fa-paper-plane me-1"></i>Gönder
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>