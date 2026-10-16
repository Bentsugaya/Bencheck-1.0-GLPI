<?php
use GlpiPlugin\Bencheck\Checklist;

Session::checkRight('config', UPDATE);
global $DB;

$T_LISTS = Checklist::T_LISTS;
$T_ITEMS = Checklist::T_DEFAULT;
// URL explicite : en GLPI 11 PHP_SELF vaut /index.php, ce qui casserait les formulaires
$self    = Plugin::getWebDir('bencheck', true) . '/front/config.php';
$ajax    = Plugin::getWebDir('bencheck', true) . '/ajax/config.php';

function bc_h($s)
{
    return htmlspecialchars((string) $s);
}

// Formulaires GLPI (pour l'application automatique)
$auto = [0 => 'Manuel uniquement', -1 => 'Tout formulaire'];
foreach ($DB->request([
    'SELECT' => ['id', 'name'],
    'FROM'   => 'glpi_forms_forms',
    'WHERE'  => ['is_deleted' => 0],
    'ORDER'  => 'name ASC',
]) as $f) {
    $auto[(int) $f['id']] = 'Formulaire : ' . $f['name'];
}
$types = [0 => 'Tous les types'] + Ticket::getTypes();

$read_type = function () use ($types) {
    $t = (int) ($_POST['ticket_type'] ?? 0);
    return isset($types[$t]) ? $t : 0;
};
$read_auto = function () use ($auto) {
    $a = (int) ($_POST['auto_forms_id'] ?? 0);
    return isset($auto[$a]) ? $a : 0;
};

// ------------------------------------------------------------------ actions
if (isset($_POST['bc_add_checklist']) && trim($_POST['name'] ?? '') !== '') {
    $DB->insert($T_LISTS, [
        'name'          => mb_substr(trim($_POST['name']), 0, 255),
        'ticket_type'   => $read_type(),
        'auto_forms_id' => $read_auto(),
        'rank'          => countElementsInTable($T_LISTS),
    ]);
    Html::redirect($self);
}
if (isset($_POST['bc_save_checklist']) && trim($_POST['name'] ?? '') !== '') {
    $DB->update($T_LISTS, [
        'name'          => mb_substr(trim($_POST['name']), 0, 255),
        'ticket_type'   => $read_type(),
        'auto_forms_id' => $read_auto(),
    ], ['id' => (int) $_POST['id']]);
    Html::redirect($self);
}
if (isset($_POST['bc_delete_checklist'])) {
    $id = (int) $_POST['id'];
    $DB->delete($T_ITEMS, ['checklists_id' => $id]);
    $DB->delete($T_LISTS, ['id' => $id]);
    Html::redirect($self);
}
if (isset($_POST['bc_add_item']) && trim($_POST['label'] ?? '') !== '') {
    $cid = (int) $_POST['checklists_id'];
    if (countElementsInTable($T_LISTS, ['id' => $cid])) {
        $DB->insert($T_ITEMS, [
            'checklists_id' => $cid,
            'label'         => mb_substr(trim($_POST['label']), 0, 255),
            'rank'          => countElementsInTable($T_ITEMS, ['checklists_id' => $cid]),
        ]);
    }
    Html::redirect($self);
}
if (isset($_POST['bc_delete_item'])) {
    $DB->delete($T_ITEMS, ['id' => (int) $_POST['id']]);
    Html::redirect($self);
}

// ------------------------------------------------------------------ affichage
Html::header('BenCheck', $self, 'config', 'plugins');
echo Checklist::sortableAssets();

echo "<div class='m-3'>";
echo "<p class='text-muted'>Une checklist est un ensemble d'items. Dans un ticket, l'utilisateur choisit
      manuellement la checklist à appliquer (onglet « Checklist »). L'application automatique est optionnelle
      et ne concerne que les tickets créés depuis un formulaire.</p>";

foreach ($DB->request(['FROM' => $T_LISTS, 'ORDER' => ['rank ASC', 'id ASC']]) as $l) {
    $lid = (int) $l['id'];

    echo "<div class='card mb-3'><div class='card-header d-flex gap-2 align-items-start'>";

    // paramètres de la checklist
    echo "<form method='post' action='" . bc_h($self) . "' class='row g-2 flex-grow-1'>
            <input type='hidden' name='id' value='$lid'>
            <div class='col-md-4'><input type='text' name='name' class='form-control' value='" . bc_h($l['name']) . "' required></div>
            <div class='col-md-3'><select name='ticket_type' class='form-select' title='Type de ticket (application automatique)'>";
    foreach ($types as $k => $v) {
        echo "<option value='$k'" . ((int) $l['ticket_type'] === $k ? ' selected' : '') . ">" . bc_h($v) . "</option>";
    }
    echo "  </select></div>
            <div class='col-md-3'><select name='auto_forms_id' class='form-select' title='Application automatique'>";
    foreach ($auto as $k => $v) {
        echo "<option value='$k'" . ((int) $l['auto_forms_id'] === $k ? ' selected' : '') . ">" . bc_h($v) . "</option>";
    }
    if (!isset($auto[(int) $l['auto_forms_id']])) {
        echo "<option value='" . (int) $l['auto_forms_id'] . "' selected>Formulaire supprimé (#" . (int) $l['auto_forms_id'] . ")</option>";
    }
    echo "  </select></div>
            <div class='col-md-2'><button class='btn btn-primary w-100' name='bc_save_checklist' value='1'>Enregistrer</button></div>";
    Html::closeForm();

    // suppression de la checklist
    echo "<form method='post' action='" . bc_h($self) . "' onsubmit=\"return confirm('Supprimer cette checklist et ses items ?');\">
            <input type='hidden' name='id' value='$lid'>
            <button class='btn btn-outline-danger' name='bc_delete_checklist' value='1' title='Supprimer la checklist'>
              <i class='ti ti-trash'></i></button>";
    Html::closeForm();
    echo "</div><div class='card-body'>";

    // items (glisser-déposer pour réorganiser)
    echo "<table class='table table-sm mb-2'><tbody class='bc-sortable' data-checklist='$lid'>";
    $n = 0;
    foreach ($DB->request(['FROM' => $T_ITEMS, 'WHERE' => ['checklists_id' => $lid], 'ORDER' => ['rank ASC', 'id ASC']]) as $it) {
        $n++;
        echo "<tr class='bc-row' data-id='{$it['id']}'>
                <td style='width:30px'><span class='bc-handle text-muted' title='Glisser pour réorganiser'>
                  <i class='ti ti-grip-vertical'></i></span></td>
                <td>" . bc_h($it['label']) . "</td>
                <td class='text-end'>
                  <form method='post' action='" . bc_h($self) . "' class='d-inline'>
                    <input type='hidden' name='id' value='{$it['id']}'>
                    <button class='btn btn-sm btn-outline-danger' name='bc_delete_item' value='1'>" . __('Delete') . "</button>";
        Html::closeForm();
        echo "  </td></tr>";
    }
    if (!$n) {
        echo "<tr><td colspan='3' class='text-muted'>Aucun item.</td></tr>";
    }
    echo "</tbody></table>";

    // ajout d'un item
    echo "<form method='post' action='" . bc_h($self) . "' class='input-group'>
            <input type='hidden' name='checklists_id' value='$lid'>
            <input type='text' name='label' class='form-control' placeholder='Nouvel item' required>
            <button class='btn btn-outline-primary' name='bc_add_item' value='1'>" . __('Add') . "</button>";
    Html::closeForm();

    echo "</div></div>";
}

// nouvelle checklist
echo "<div class='card'><div class='card-header'><h4 class='mb-0'>Nouvelle checklist</h4></div><div class='card-body'>
      <form method='post' action='" . bc_h($self) . "' class='row g-2'>
        <div class='col-md-4'><input type='text' name='name' class='form-control' placeholder='Nom de la checklist' required></div>
        <div class='col-md-3'><select name='ticket_type' class='form-select'>";
foreach ($types as $k => $v) {
    echo "<option value='$k'>" . bc_h($v) . "</option>";
}
echo "  </select></div>
        <div class='col-md-3'><select name='auto_forms_id' class='form-select'>";
foreach ($auto as $k => $v) {
    echo "<option value='$k'>" . bc_h($v) . "</option>";
}
echo "  </select></div>
        <div class='col-md-2'><button class='btn btn-primary w-100' name='bc_add_checklist' value='1'>" . __('Add') . "</button></div>";
Html::closeForm();
echo "</div></div></div>";

$js = <<<'JS'
<script>
(function () {
    const url = __URL__;
    const meta = document.querySelector('meta[property="glpi:csrf_token"]');
    const token = meta ? meta.getAttribute('content') : '';
    document.querySelectorAll('.bc-sortable').forEach(function (tbody) {
        window.bcSortable(tbody, '.bc-row', '.bc-handle', function (ids) {
            fetch(url, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Glpi-Csrf-Token': token,
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({bc_op: 'reorder_items', checklists_id: tbody.dataset.checklist, ids: ids.join(',')})
            }).then(function (r) { return r.json(); }).then(function (r) {
                if (!r.ok) { alert(r.error || 'Erreur'); window.location.reload(); }
            }).catch(function () { alert('Erreur réseau'); window.location.reload(); });
        });
    });
})();
</script>
JS;
echo str_replace('__URL__', json_encode($ajax), $js);

Html::footer();
