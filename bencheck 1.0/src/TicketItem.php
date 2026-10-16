<?php

namespace GlpiPlugin\Bencheck;

use CommonDBTM;
use Plugin;
use Ticket;

class TicketItem extends CommonDBTM
{
    public static function getTable($classname = null)
    {
        return Checklist::T_TICKET;
    }

    public static function getTypeName($nb = 0)
    {
        return 'Checklist';
    }

    /** "fait/total", ou chaîne vide si le ticket n'a aucun item */
    public static function countLabel(int $tickets_id): string
    {
        $total = countElementsInTable(self::getTable(), ['tickets_id' => $tickets_id]);
        if (!$total) {
            return '';
        }
        $done = countElementsInTable(self::getTable(), ['tickets_id' => $tickets_id, 'is_done' => 1]);
        return "$done/$total";
    }

    /**
     * Section "Checklist" insérée dans le panneau de droite du ticket
     * (même structure repliable que les sections natives de GLPI).
     */
    public static function showInPanel(Ticket $ticket): void
    {
        $tid     = (int) $ticket->getID();
        $canedit = $ticket->canUpdateItem();
        $label   = self::countLabel($tid);

        if ($tid <= 0 || ($label === '' && !$canedit)) {
            return; // rien à montrer
        }

        $open = $label !== ''; // dépliée seulement s'il y a déjà des items
        $url  = Plugin::getWebDir('bencheck', true) . '/ajax/checklist.php';

        echo Checklist::sortableAssets();
        echo "<section class='accordion-item' aria-label='Checklist'>
                <div class='accordion-header' id='heading-bencheck'>
                  <button class='accordion-button" . ($open ? '' : ' collapsed') . "' type='button'
                          data-bs-toggle='collapse' data-bs-target='#bencheck-panel'
                          aria-expanded='" . ($open ? 'true' : 'false') . "' aria-controls='bencheck-panel'>
                    <i class='ti ti-checklist item-icon'></i>
                    <span class='item-title'>Checklist
                      <span id='bencheck-count' class='badge bg-blue-lt ms-1" . ($label === '' ? ' d-none' : '') . "'>"
                      . htmlspecialchars($label) . "</span>
                    </span>
                  </button>
                </div>
                <div id='bencheck-panel' class='accordion-collapse collapse" . ($open ? ' show' : '') . "'
                     aria-labelledby='heading-bencheck'>
                  <div class='accordion-body'>
                    <div id='bencheck-body'>" . self::renderBody($ticket) . "</div>
                  </div>
                </div>
              </section>";

        $js = <<<'JS'
<script>
(function () {
    const url = __URL__, tid = __TID__, canedit = __CANEDIT__;
    const panel = document.getElementById('bencheck-panel');
    const body  = document.getElementById('bencheck-body');
    const count = document.getElementById('bencheck-count');
    if (!panel || !body) { return; }
    const meta  = document.querySelector('meta[property="glpi:csrf_token"]');
    const token = meta ? meta.getAttribute('content') : '';

    function send(data) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': token,
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams(data)
        }).then(function (r) { return r.text(); }).then(function (t) {
            try { return JSON.parse(t); } catch (e) { return {ok: false, error: 'Réponse invalide du serveur'}; }
        });
    }

    // Met à jour le panneau avec le HTML renvoyé (sans recharger la page du ticket)
    function update(r) {
        if (!r.ok) { alert(r.error || 'Erreur'); return false; }
        if (typeof r.html === 'string') { body.innerHTML = r.html; initSortable(); }
        if (typeof r.count === 'string' && count) {
            count.textContent = r.count;
            count.classList.toggle('d-none', r.count === '');
        }
        return true;
    }
    function resync() { send({dcl_op: 'render', tickets_id: tid}).then(update); }

    function initSortable() {
        const list = document.getElementById('dcl-list');
        if (canedit && list && window.bcSortable) {
            window.bcSortable(list, '.dcl-row', '.dcl-handle', function (ids) {
                send({dcl_op: 'reorder', tickets_id: tid, ids: ids.join(',')})
                    .then(function (r) { if (!r.ok) { alert(r.error || 'Erreur'); resync(); } });
            });
        }
    }
    function addItem() {
        const input = document.getElementById('dcl-new');
        const label = input ? input.value.trim() : '';
        if (label) { send({dcl_op: 'add', tickets_id: tid, label: label}).then(update); }
    }

    panel.addEventListener('change', function (e) {
        const cb = e.target.closest('.dcl-check');
        if (!cb) { return; }
        send({dcl_op: 'toggle', id: cb.dataset.id, is_done: cb.checked ? 1 : 0})
            .then(function (r) { if (!update(r)) { cb.checked = !cb.checked; } });
    });
    panel.addEventListener('click', function (e) {
        const del = e.target.closest('.dcl-del');
        if (del) {
            e.preventDefault();
            send({dcl_op: 'delete', id: del.dataset.id}).then(update);
            return;
        }
        if (e.target.closest('#dcl-apply')) {
            const sel = document.getElementById('dcl-checklist');
            if (sel && sel.value) { send({dcl_op: 'apply', tickets_id: tid, checklists_id: sel.value}).then(update); }
            return;
        }
        if (e.target.closest('#dcl-add')) { addItem(); }
    });
    // Le panneau est dans le formulaire du ticket : Entrée ne doit pas l'envoyer
    panel.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.id === 'dcl-new') { e.preventDefault(); addItem(); }
    });

    initSortable();
})();
</script>
JS;
        echo str_replace(
            ['__URL__', '__TID__', '__CANEDIT__'],
            [json_encode($url), (string) $tid, $canedit ? 'true' : 'false'],
            $js
        );
    }

    /** Contenu du panneau (sélecteur de checklist, items, ajout) — aussi renvoyé par l'AJAX */
    public static function renderBody(Ticket $ticket): string
    {
        global $DB;

        $canedit = $ticket->canUpdateItem();
        $tid     = (int) $ticket->getID();
        $types   = Ticket::getTypes();
        $h       = static fn($s) => htmlspecialchars((string) $s);

        $lists = [];
        foreach ($DB->request(['FROM' => Checklist::T_LISTS, 'ORDER' => ['rank ASC', 'id ASC']]) as $l) {
            $lists[(int) $l['id']] = $l;
        }

        $out = '';

        // --- Choix manuel d'une checklist
        // data-track-changes="" : évite l'alerte "modifications non enregistrées" du formulaire du ticket
        if ($canedit) {
            if ($lists) {
                $out .= "<div class='input-group mb-3'>
                           <select id='dcl-checklist' class='form-select' data-track-changes=''>
                             <option value=''>— Choisir une checklist —</option>";
                foreach ($lists as $id => $l) {
                    $suffix = ((int) $l['ticket_type'] && isset($types[(int) $l['ticket_type']]))
                        ? ' (' . $types[(int) $l['ticket_type']] . ')' : '';
                    $out .= "<option value='$id'>" . $h($l['name'] . $suffix) . "</option>";
                }
                $out .= "  </select>
                           <button type='button' id='dcl-apply' class='btn btn-primary'>Appliquer</button>
                         </div>";
            } else {
                $out .= "<p class='text-muted'>Aucune checklist définie. Un administrateur peut en créer dans
                         Configuration &gt; Plugins &gt; BenCheck.</p>";
            }
        }

        // --- Items du ticket
        $out .= "<div id='dcl-list'>";
        $count = 0;
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['tickets_id' => $tid],
            'ORDER' => ['rank ASC', 'id ASC'],
        ]) as $row) {
            $count++;
            $checked  = $row['is_done'] ? 'checked' : '';
            $disabled = $canedit ? '' : 'disabled';
            $style    = $row['is_done'] ? " style='text-decoration:line-through'" : '';

            $meta = '';
            if ((int) $row['checklists_id'] && isset($lists[(int) $row['checklists_id']])) {
                $meta .= "<span class='badge bg-secondary-lt me-1'>" . $h($lists[(int) $row['checklists_id']]['name']) . "</span>";
            }
            if ($row['is_done'] && $row['date_done']) {
                $meta .= $h(\getUserName($row['users_id_done'])) . ' - ' . \Html::convDateTime($row['date_done']);
            }

            $out .= "<div class='dcl-row d-flex align-items-start mb-2' data-id='{$row['id']}'>";
            if ($canedit) {
                $out .= "<span class='bc-handle dcl-handle me-2 text-muted' title='Glisser pour réorganiser'>
                           <i class='ti ti-grip-vertical'></i></span>";
            }
            $out .= "<input type='checkbox' class='form-check-input dcl-check me-2 mt-1 flex-shrink-0'
                            data-id='{$row['id']}' data-track-changes='' $checked $disabled>
                     <div class='flex-grow-1' style='min-width:0'><span$style>" . $h($row['label']) . "</span>"
                   . ($meta !== '' ? "<div class='small text-muted'>$meta</div>" : '') . "</div>";
            if ($canedit) {
                $out .= "<a href='#' class='dcl-del ms-2 text-danger flex-shrink-0' data-id='{$row['id']}' title='Supprimer'>
                           <i class='ti ti-trash'></i></a>";
            }
            $out .= "</div>";
        }
        $out .= "</div>";

        if (!$count) {
            $out .= "<p class='text-muted mb-2'>Aucun item pour ce ticket."
                  . ($canedit && $lists ? " Choisissez une checklist ou ajoutez un item libre." : '') . "</p>";
        }

        if ($canedit) {
            $out .= "<div class='input-group mt-3'>
                       <input type='text' id='dcl-new' class='form-control' placeholder='Nouvel item libre' data-track-changes=''>
                       <button type='button' id='dcl-add' class='btn btn-outline-primary'>Ajouter</button>
                     </div>";
        }

        return $out;
    }
}
