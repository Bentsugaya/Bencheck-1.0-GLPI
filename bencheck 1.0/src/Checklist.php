<?php

namespace GlpiPlugin\Bencheck;

/**
 * Logique commune : application d'une checklist à un ticket + JS de glisser-déposer.
 */
final class Checklist
{
    public const T_LISTS   = 'glpi_plugin_bencheck_checklists';
    public const T_DEFAULT = 'glpi_plugin_bencheck_defaultitems';
    public const T_TICKET  = 'glpi_plugin_bencheck_ticketitems';

    /**
     * Copie les items d'une checklist dans un ticket (à la suite des items existants).
     *
     * @return int nombre d'items ajoutés, ou -1 si la checklist est déjà appliquée au ticket
     */
    public static function applyToTicket(int $tickets_id, int $checklists_id): int
    {
        global $DB;

        if (countElementsInTable(self::T_TICKET, ['tickets_id' => $tickets_id, 'checklists_id' => $checklists_id])) {
            return -1;
        }

        $last = $DB->request([
            'SELECT' => ['rank'],
            'FROM'   => self::T_TICKET,
            'WHERE'  => ['tickets_id' => $tickets_id],
            'ORDER'  => 'rank DESC',
            'LIMIT'  => 1,
        ])->current();
        $rank = $last ? ((int) $last['rank']) + 1 : 0;

        $added = 0;
        foreach ($DB->request([
            'FROM'  => self::T_DEFAULT,
            'WHERE' => ['checklists_id' => $checklists_id],
            'ORDER' => ['rank ASC', 'id ASC'],
        ]) as $row) {
            $DB->insert(self::T_TICKET, [
                'tickets_id'    => $tickets_id,
                'checklists_id' => $checklists_id,
                'label'         => $row['label'],
                'rank'          => $rank++,
            ]);
            $added++;
        }
        return $added;
    }

    /**
     * Ticket issu d'un formulaire : applique les checklists configurées en
     * "application automatique" pour ce formulaire (ou pour tout formulaire)
     * et compatibles avec le type du ticket.
     */
    public static function applyAutomatic(int $tickets_id, int $forms_id, int $ticket_type): void
    {
        global $DB;

        foreach ($DB->request([
            'FROM'  => self::T_LISTS,
            'WHERE' => [
                ['OR' => [['auto_forms_id' => -1], ['auto_forms_id' => $forms_id]]],
                ['OR' => [['ticket_type' => 0], ['ticket_type' => $ticket_type]]],
            ],
            'ORDER' => ['rank ASC', 'id ASC'],
        ]) as $list) {
            self::applyToTicket($tickets_id, (int) $list['id']);
        }
    }

    /**
     * Applique un ordre (liste d'ids) en écrivant `rank` pour les lignes qui
     * appartiennent bien au périmètre donné (ex: ['tickets_id' => 12]).
     */
    public static function saveOrder(string $table, array $scope, string $ids_csv): bool
    {
        global $DB;

        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $ids_csv)))));
        if (!$ids) {
            return false;
        }
        $valid = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => $scope + ['id' => $ids]]) as $r) {
            $valid[(int) $r['id']] = true;
        }
        $pos = 0;
        foreach ($ids as $id) {
            if (isset($valid[$id])) {
                $DB->update($table, ['rank' => $pos++], ['id' => $id]);
            }
        }
        return true;
    }

    /**
     * Petit script de glisser-déposer (HTML5 natif, sans dépendance).
     * Définit window.bcSortable(container, rowSelector, handleSelector, onDrop(ids)).
     */
    public static function sortableAssets(): string
    {
        return <<<'HTML'
<style>
.bc-handle { cursor: grab; }
.bc-dragging { opacity: .4; }
</style>
<script>
if (!window.bcSortable) {
  window.bcSortable = function (container, rowSel, handleSel, onDrop) {
    let dragging = null, before = '';
    const order = function () {
      return Array.from(container.querySelectorAll(rowSel)).map(function (r) { return r.dataset.id; });
    };
    container.querySelectorAll(rowSel).forEach(function (row) {
      const handle = row.querySelector(handleSel);
      if (!handle) { return; }
      handle.addEventListener('mousedown', function () { row.draggable = true; });
      handle.addEventListener('mouseup', function () { row.draggable = false; });
      row.addEventListener('dragstart', function (e) {
        dragging = row;
        before = order().join(',');
        row.classList.add('bc-dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', row.dataset.id || '');
      });
      row.addEventListener('dragend', function () {
        row.classList.remove('bc-dragging');
        row.draggable = false;
        dragging = null;
        const ids = order();
        if (ids.join(',') !== before) { onDrop(ids); }
      });
    });
    container.addEventListener('dragover', function (e) {
      if (!dragging) { return; }
      e.preventDefault();
      let after = null, best = -Infinity;
      container.querySelectorAll(rowSel + ':not(.bc-dragging)').forEach(function (r) {
        const box = r.getBoundingClientRect();
        const offset = e.clientY - box.top - box.height / 2;
        if (offset < 0 && offset > best) { best = offset; after = r; }
      });
      if (after) { container.insertBefore(dragging, after); } else { container.appendChild(dragging); }
    });
    container.addEventListener('drop', function (e) { e.preventDefault(); });
  };
}
</script>
HTML;
    }
}
