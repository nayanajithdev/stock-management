<?php
/** @var string $currentPage */
$isAuthPage = in_array($currentPage, ['login', 'setup-owner'], true);
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
    <script src="<?php echo e(app_url('assets/app.js')); ?>"></script>
</body>
</html>
