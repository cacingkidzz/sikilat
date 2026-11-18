<?php
declare(strict_types=1);

/**
 * SIKILAT - Penutup layout panel admin: footer, flash message, script.
 *
 * Variabel opsional:
 * - $pageScripts (array) daftar path aset JS tambahan, contoh: ['js/dashboard.js']
 */

if (!defined('SIKILAT_APP')) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

$pageScripts = $pageScripts ?? [];
$flashes = get_flashes();
$flashesJson = $flashes !== []
    ? json_encode($flashes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
    : '';
$appNameDisplay = $appNameDisplay ?? setting('nama_aplikasi', APP_NAME);
$schoolName = $schoolName ?? setting('nama_sekolah', '');
?>
            </div>
        </div>
    </main>

    <footer class="app-footer bg-white border-top">
        <div class="float-end d-none d-sm-inline small text-secondary">
            <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?>
        </div>
        <div class="small text-secondary">
            <strong class="text-body"><?= e($appNameDisplay) ?></strong>
            &middot; <?= e(APP_DESCRIPTION) ?>
            <?php if ($schoolName !== ''): ?>
                &middot; <?= e($schoolName) ?>
            <?php endif; ?>
            &middot; &copy; <?= date('Y') ?>
        </div>
    </footer>
</div><!-- /.app-wrapper -->

<?php if ($flashesJson !== ''): ?>
    <div id="flash-data" class="d-none" data-flash="<?= e($flashesJson) ?>" aria-hidden="true"></div>
<?php endif; ?>

<script src="<?= e(asset('plugins/jquery/jquery.min.js')) ?>"></script>
<script src="<?= e(asset('plugins/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('plugins/overlayscrollbars/overlayscrollbars.browser.es6.min.js')) ?>"></script>
<script src="<?= e(asset('plugins/adminlte/js/adminlte.min.js')) ?>"></script>
<script src="<?= e(asset('plugins/sweetalert2/sweetalert2.all.min.js')) ?>"></script>
<?php if ($useChartJs): ?>
    <script src="<?= e(asset('plugins/chartjs/chart.umd.min.js')) ?>"></script>
<?php endif; ?>
<?php if ($useDataTables): ?>
    <script src="<?= e(asset('plugins/datatables/js/dataTables.min.js')) ?>"></script>
    <script src="<?= e(asset('plugins/datatables/js/dataTables.bootstrap5.min.js')) ?>"></script>
<?php endif; ?>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach ($pageScripts as $scriptPath): ?>
    <script src="<?= e(asset((string) $scriptPath)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
