<?php
declare(strict_types=1);

/**
 * SIKILAT - Footer layout khusus halaman siswa.
 *
 * Variabel opsional:
 * - $pageScripts (array) path JS tambahan relatif terhadap assets/
 */

if (!defined('SIKILAT_APP')) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

$pageScripts = $pageScripts ?? [];
$appNameDisplay = $appNameDisplay ?? setting('nama_aplikasi', APP_NAME);
$schoolName = $schoolName ?? setting('nama_sekolah', '');
?>
    </div>
</main>

<footer class="siswa-footer">
    <div class="container<?= !empty($studentWide) ? '-fluid px-3 px-lg-4' : '' ?>">
        <div class="siswa-footer-inner">
            <div>
                <strong><?= e($appNameDisplay) ?></strong>
                <span class="text-secondary">&middot; <?= e(APP_DESCRIPTION) ?></span>
                <?php if ($schoolName !== ''): ?>
                    <span class="text-secondary d-none d-sm-inline">&middot; <?= e($schoolName) ?></span>
                <?php endif; ?>
            </div>
            <div class="small text-secondary">
                v<?= e(APP_VERSION) ?> &middot; &copy; <?= e((string) date('Y')) ?>
            </div>
        </div>
    </div>
</footer>

<script src="<?= e(asset('plugins/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('plugins/sweetalert2/sweetalert2.all.min.js')) ?>"></script>
<script src="<?= e(asset('js/siswa.js')) ?>"></script>
<?php foreach ($pageScripts as $scriptPath): ?>
    <script src="<?= e(asset((string) $scriptPath)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
