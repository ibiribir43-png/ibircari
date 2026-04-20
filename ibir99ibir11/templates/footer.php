</main> <!-- End Content Area -->
    
    <footer class="bg-white text-center py-3 mt-auto border-top text-muted small">
        &copy; <?= date('Y'); ?> ibiR Core CRM. Tüm Hakları Saklıdır.
    </footer>

</div> <!-- End Main Wrapper -->

<!-- JS -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Mobil Sidebar Toggle
    document.getElementById('sidebarToggle')?.addEventListener('click', function() {
        document.querySelector('.sidebar').classList.toggle('d-none');
    });
</script>
<script src="../assets/js/admin.js"></script>
</body>
</html>