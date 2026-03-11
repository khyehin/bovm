<?php
// public/include/date_range.php
if (!function_exists('h')) {
  function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

$date_from = $date_from ?? ($_GET['date_from'] ?? '');
$date_to   = $date_to   ?? ($_GET['date_to']   ?? '');
$date_all  = $date_all  ?? ($_GET['date_all']  ?? ''); // ✅ NEW

$date_from = trim((string)$date_from);
$date_to   = trim((string)$date_to);
$date_all  = trim((string)$date_all);

$display_text = '';
if ($date_all === '1') {
  $display_text = 'All';
} elseif ($date_from !== '' && $date_to !== '') {
  $display_text = $date_from.' to '.$date_to;
} elseif ($date_from !== '') {
  $display_text = $date_from; // ✅ 单日
} elseif ($date_to !== '') {
  $display_text = $date_to;
}
?>

<div class="form-group" data-drp>
  <label class="field-label"><?= h(t('common.date_range')) ?></label>

  <div class="date-filter-wrapper">
    <input
      type="text"
      class="form-control drp-display-input"
      placeholder="<?= h(t('common.select_date_range')) ?>"
      readonly
      autocomplete="off"
      value="<?= h($display_text) ?>"
      data-drp-display
    >

    <input type="hidden" name="date_from" value="<?= h($date_from) ?>" data-drp-from>
    <input type="hidden" name="date_to"   value="<?= h($date_to) ?>"   data-drp-to>

    <!-- ✅ NEW：All 的状态 -->
    <input type="hidden" name="date_all" value="<?= h($date_all) ?>" data-drp-all>

    <div class="drp-container" data-drp-container style="display:none;">
      <div class="drp-wrapper">
        <div class="drp-header">
          <button type="button" class="drp-nav" data-dir="-1">&lt;</button>
          <div class="drp-month-label" data-drp-month></div>
          <button type="button" class="drp-nav" data-dir="1">&gt;</button>
        </div>

        <div class="drp-week-row">
          <div><?= h(t('common.mo')) ?></div>
          <div><?= h(t('common.tu')) ?></div>
          <div><?= h(t('common.we')) ?></div>
          <div><?= h(t('common.th')) ?></div>
          <div><?= h(t('common.fr')) ?></div>
          <div><?= h(t('common.sa')) ?></div>
          <div><?= h(t('common.su')) ?></div>
        </div>

        <div class="drp-grid" data-drp-grid></div>
      </div>

      <div class="drp-quick-bar">
        <button type="button" class="drp-quick-item" data-range="today"><?= h(t('common.today')) ?></button>
        <button type="button" class="drp-quick-item" data-range="yesterday"><?= h(t('common.yesterday')) ?></button>
        <button type="button" class="drp-quick-item" data-range="this_week"><?= h(t('common.this_week')) ?></button>
        <button type="button" class="drp-quick-item" data-range="last_week"><?= h(t('common.last_week')) ?></button>
        <button type="button" class="drp-quick-item" data-range="this_month"><?= h(t('common.this_month')) ?></button>
        <button type="button" class="drp-quick-item" data-range="last_month"><?= h(t('common.last_month')) ?></button>
        <button type="button" class="drp-quick-item" data-range="this_year"><?= h(t('common.this_year')) ?></button>
        <button type="button" class="drp-quick-item" data-range="last_year"><?= h(t('common.last_year')) ?></button>
        <button type="button" class="drp-quick-item" data-range="all"><?= h(t('common.all')) ?></button>
      </div>

    </div>
  </div>
</div>

<?php
if (!defined('VM_DATERANGE_CSS_INCLUDED')) {
  define('VM_DATERANGE_CSS_INCLUDED', true);
  ?><link rel="stylesheet" href="<?= h(url('assets/css/date_range.css')) ?>"><?php
}
if (!defined('VM_DATERANGE_JS_INCLUDED')) {
  define('VM_DATERANGE_JS_INCLUDED', true);
  ?><script src="<?= h(url('assets/js/date_range.js')) ?>"></script><?php
}
?>
