<?php
/**
 * musteri_footer.php
 * Müşteri portalı için temiz ve şık footer.
 */
?>
<footer class="bg-white py-5 mt-5 border-top">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-md-6 text-center text-md-start">
                <p class="mb-0 text-muted">
                    © <?php echo date('Y'); ?> <strong><?php echo htmlspecialchars($portal_data['firma_adi']); ?></strong>. 
                </p>
            </div>
            <div class="col-md-6 text-center text-md-end mt-3 mt-md-0">
                <span class="text-muted small">Powered by <strong class="text-dark">ibiR Cari Platform</strong></span>
            </div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>