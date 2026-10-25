<?php foreach ($values as $key => $value): ?>
<?php if (!in_array($key, $omit ?? [], true) && is_scalar($value) && !($key === 'agent' && $value === '')): ?>
<input type="hidden" name="<?= esc($key) ?>" value="<?= esc((string) $value) ?>">
<?php endif ?>
<?php endforeach ?>
