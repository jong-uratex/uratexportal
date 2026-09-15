  <!-- Main Footer -->
  <footer class="main-footer text-sm">
    <div class="float-right d-none d-sm-inline">
      Shopify REST Admin API v2025-10 | Latency: 42ms
    </div>
    <strong>Copyright &copy; 2026 <a href="https://uratex.com.ph" target="_blank" style="color: #003399;">Uratex Philippines</a>.</strong> Shopify Portal.
  </footer>
</div>
<!-- ./wrapper -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.1/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script>
document.addEventListener('input', function (event) {
  const counterId = event.target.dataset.charCounter;
  const counter = counterId && document.getElementById(counterId);
  if (counter) {
    counter.textContent = Array.from(event.target.value).length;
  }
});
</script>
</body>
</html>
