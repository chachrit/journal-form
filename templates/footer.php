</main>
<?php foreach ($scripts ?? [] as $script): ?>
<script src="<?= e(url($script)) ?>?v=2"></script>
<?php endforeach; ?>
</body>
</html>
