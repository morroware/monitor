<?php if (!defined('CFC_BOOTSTRAPPED')) { http_response_code(500); exit; } ?>
</main>
<footer class="site-footer">
    <span><?= h(cfc_config('general.site_name', 'The Castle Fun Center')) ?> · Monitor v<?= h(CFC_VERSION) ?></span>
    <span class="muted small">Server time: <?= h(date('Y-m-d H:i:s T')) ?></span>
</footer>
<div id="toast-container"></div>
</body>
</html>
