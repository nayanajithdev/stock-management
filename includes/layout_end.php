<?php
/** @var string $currentPage */
$isAuthPage = in_array($currentPage, ['login', 'setup-owner'], true);
$appJsVersion = (string) (filemtime(__DIR__ . '/../assets/app.js') ?: 1);
?>
<?php if ($isAuthPage): ?>
        </section>
    </main>
<?php else: ?>
            </main>
        </div>
    </div>
<?php endif; ?>

    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
    <script src="<?php echo e(app_url('assets/app.js?v=' . $appJsVersion)); ?>"></script>
</body>
</html>
