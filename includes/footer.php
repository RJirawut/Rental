    </div>
    
    <?php $settings = getSettings(); $primaryColor = $settings['primary_color'] ?? '#0d6efd'; ?>
    <footer class="text-center py-3 mt-3" style="background-color: <?php echo $primaryColor; ?>; border-top: 1px solid <?php echo $primaryColor; ?>; margin-left: 260px;">
        <p class="mb-0 text-white small">© <?php echo date('Y'); ?> <?php echo $settings['dorm_name'] ?? t('app_name'); ?> | Developed by Jirawut Developer</p>
    </footer>
    <style>
        @media (max-width: 768px) {
            footer {
                margin-left: 0 !important;
            }
        }
    </style>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Sidebar toggle for mobile
        document.getElementById('sidebarToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });
    </script>
</body>
</html>