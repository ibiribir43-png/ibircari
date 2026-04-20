<?php
// Değişkenleri güvenli hale getirelim
$h_id = isset($sms['id']) ? $sms['id'] : '';
$h_tip = isset($sms['tip']) ? $sms['tip'] : '';
$h_baslik = isset($sms['baslik']) ? $sms['baslik'] : '';
$h_deger = isset($sms['deger']) ? $sms['deger'] : '';
$h_fiyat = isset($sms['fiyat']) ? $sms['fiyat'] : '';
?>

<!-- Modal Başlangıcı -->
<div class="modal fade" id="modalEkDuzenle<?= $h_id ?>" tabindex="-1" aria-labelledby="modalEkDuzenleLabel<?= $h_id ?>" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content shadow-lg border-0">
            <form method="POST" action="paketler.php">
                <!-- İşlem Türü ve ID'ler -->
                <input type="hidden" name="ek_hizmet_duzenle" value="1">
                <input type="hidden" name="hizmet_id" value="<?= $h_id ?>">
                <input type="hidden" name="tip" value="<?= $h_tip ?>">
                
                <div class="modal-header bg-light border-bottom-0">
                    <h5 class="modal-title fw-bold" id="modalEkDuzenleLabel<?= $h_id ?>">
                        <i class="fas fa-edit text-primary me-2"></i>Düzenle
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                
                <div class="modal-body text-start">
                    <div class="mb-3">
                        <label class="form-label fw-bold text-muted small">Vitrin Başlığı</label>
                        <input type="text" name="baslik" class="form-control" value="<?= htmlspecialchars($h_baslik, ENT_QUOTES) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-muted small">Verilecek Değer (Adet veya MB)</label>
                        <input type="number" name="deger" class="form-control" value="<?= htmlspecialchars($h_deger, ENT_QUOTES) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-muted small">Fiyat (₺)</label>
                        <input type="number" step="0.01" name="fiyat" class="form-control" value="<?= htmlspecialchars($h_fiyat, ENT_QUOTES) ?>" required>
                    </div>
                </div>
                
                <div class="modal-footer border-top-0 bg-light">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save me-1"></i> Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>