<?php
// public/admin/customers/categories.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_admin();
require_perm('CUSTOMER.E');

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

/* ============================
   AJAX handlers
   ============================ */
if (isset($_POST['ajax'])) {
  header('Content-Type: application/json; charset=utf-8');
  try {

    /* ---------- create ---------- */
    if ($_POST['ajax'] === 'create') {
      $name = trim($_POST['name'] ?? '');
      if ($name === '') throw new RuntimeException('Name required');

      $max = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM customer_categories")->fetchColumn();
      $pdo->prepare("
        INSERT INTO customer_categories (name, sort_order)
        VALUES (?,?)
      ")->execute([$name, $max + 1]);

      echo json_encode(['ok'=>1]);
      exit;
    }

    /* ---------- rename ---------- */
    if ($_POST['ajax'] === 'rename') {
      $id   = (int)($_POST['id'] ?? 0);
      $name = trim($_POST['name'] ?? '');
      if ($id<=0 || $name==='') throw new RuntimeException('Invalid input');

      $pdo->prepare("UPDATE customer_categories SET name=? WHERE id=?")
          ->execute([$name,$id]);

      echo json_encode(['ok'=>1]);
      exit;
    }

    /* ---------- reorder (FIXED) ---------- */
    if ($_POST['ajax'] === 'reorder') {
      $order = $_POST['order'] ?? [];

      // ✅ accept order[]=1&order[]=2 OR order="1,2,3"
      if (is_string($order)) {
        $order = array_values(array_filter(
          array_map('trim', explode(',', $order)),
          fn($x)=>$x!==''
        ));
      }

      if (!is_array($order) || !$order) {
        throw new RuntimeException('Invalid order');
      }

      $pdo->beginTransaction();
      $i = 1;
      $st = $pdo->prepare("UPDATE customer_categories SET sort_order=? WHERE id=?");
      foreach ($order as $id) {
        $id = (int)$id;
        if ($id <= 0) continue;
        $st->execute([$i++, $id]);
      }
      $pdo->commit();

      echo json_encode(['ok'=>1]);
      exit;
    }

    /* ---------- delete ---------- */
    if ($_POST['ajax'] === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id<=0) throw new RuntimeException('Invalid id');

      // block delete if used
      $st = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE category_id=?");
      $st->execute([$id]);
      if ((int)$st->fetchColumn() > 0) {
        throw new RuntimeException('Category is in use');
      }

      $pdo->prepare("DELETE FROM customer_categories WHERE id=?")->execute([$id]);
      echo json_encode(['ok'=>1]);
      exit;
    }

    throw new RuntimeException('Unknown action');

  } catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>0,'err'=>$e->getMessage()]);
    exit;
  }
}

/* ============================
   Load categories
   ============================ */
$categories = $pdo->query("
  SELECT id, name, sort_order
  FROM customer_categories
  ORDER BY sort_order ASC, id ASC
")->fetchAll() ?: [];

$page_title = 'Customer Categories';
include __DIR__ . '/../include/header.php';
?>

<style>
.cat-wrap{ max-width:720px; }
.cat-row{
  display:flex; align-items:center; gap:10px;
  padding:10px 12px;
  border:1px solid #e5e7eb;
  border-radius:12px;
  background:#fff;
}
.cat-row + .cat-row{ margin-top:8px; }

.cat-handle{
  cursor:grab;
  font-size:18px;
  color:#9ca3af;
  user-select:none;
}
.cat-name{ flex:1; }
.cat-name input{
  width:100%;
  border:1px solid #e5e7eb;
  border-radius:10px;
  padding:6px 10px;
  font-size:13px;
}
.cat-actions{
  display:flex; gap:6px;
}
.cat-actions button{
  border:1px solid #e5e7eb;
  background:#fff;
  border-radius:999px;
  width:32px; height:32px;
  cursor:pointer;
}
.cat-actions button:hover{ background:#f3f4f6; }

.add-row{
  margin-top:14px;
  display:flex; gap:8px;
}
.add-row input{ flex:1; }
.small{ font-size:11px; color:#6b7280; }
</style>

<div class="admin-main">
  <div class="admin-main-inner">

    <div class="admin-card cat-wrap">
      <div class="admin-card-header">
        <div>
          <div class="form-page-eyebrow">Master data</div>
          <h1 class="page-title">Customer Categories</h1>
        </div>
        <div>
          <a href="<?= h(url('admin/customers/list.php')) ?>" class="btn btn-light">← Back</a>
        </div>
      </div>

      <div id="catList">
        <?php foreach ($categories as $c): ?>
          <div class="cat-row" data-id="<?= (int)$c['id'] ?>">
            <div class="cat-handle">≡</div>
            <div class="cat-name">
              <input type="text" value="<?= h($c['name']) ?>" class="js-name">
            </div>
            <div class="cat-actions">
              <button class="js-del" title="Delete">✕</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="add-row">
        <input type="text" id="newName" class="form-control" placeholder="New category name">
        <button id="addBtn" class="btn btn-primary">Add</button>
      </div>

      <div class="small">
        Drag to reorder · Click name to rename · Delete only if unused
      </div>
    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
const post = async (data)=>{
  const fd = new FormData();
  Object.keys(data).forEach(k=>{
    const v = data[k];
    if (Array.isArray(v)) {
      v.forEach(item => fd.append(k + '[]', item));
    } else {
      fd.append(k, v);
    }
  });
  const res = await fetch(location.href,{method:'POST',body:fd});
  const j = await res.json();
  if(!j.ok) throw new Error(j.err||'Error');
};

/* rename */
document.querySelectorAll('.js-name').forEach(inp=>{
  let old = inp.value;
  inp.addEventListener('blur', async ()=>{
    if(inp.value===old) return;
    try{
      await post({ajax:'rename',id:inp.closest('.cat-row').dataset.id,name:inp.value});
      old = inp.value;
    }catch(e){
      alert(e.message);
      inp.value = old;
    }
  });
});

/* delete */
document.querySelectorAll('.js-del').forEach(btn=>{
  btn.onclick = async ()=>{
    if(!confirm('Delete this category?')) return;
    try{
      await post({ajax:'delete',id:btn.closest('.cat-row').dataset.id});
      btn.closest('.cat-row').remove();
    }catch(e){ alert(e.message); }
  };
});

/* add */
document.getElementById('addBtn').onclick = async ()=>{
  const name = document.getElementById('newName').value.trim();
  if(!name) return;
  try{
    await post({ajax:'create',name});
    location.reload();
  }catch(e){ alert(e.message); }
};

/* reorder */
new Sortable(document.getElementById('catList'),{
  handle:'.cat-handle',
  animation:150,
  onEnd: async ()=>{
    const order=[...document.querySelectorAll('.cat-row')].map(r=>r.dataset.id);
    try{ await post({ajax:'reorder',order}); }catch(e){ alert(e.message); }
  }
});
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>
