    </div>
    
    <?php $settings = getSettings(); $primaryColor = $settings['primary_color'] ?? '#0d6efd'; ?>
    <footer class="text-center py-3 mt-3" style="background-color: <?php echo $primaryColor; ?>; border-top: 1px solid <?php echo $primaryColor; ?>; margin-left: 260px;">
        <p class="mb-0 text-white small">© <?php echo date('Y'); ?> <?php echo $settings['dorm_name'] ?? t('app_name'); ?> | Developed by Jirawut Developer</p>
    </footer>
    <style>
        footer {
            margin-left: var(--sidebar-width, 260px);
            transition: margin-left 0.3s ease;
        }
        @media (max-width: 768px) {
            footer {
                margin-left: 0 !important;
                padding-top: 0.75rem !important;
                padding-bottom: 0.75rem !important;
                font-size: 0.75rem !important;
            }
        }
    </style>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</body>
</html>