<?php
use GlpiPlugin\Bencheck\Checklist;
use GlpiPlugin\Bencheck\TicketItem;

Session::checkLoginUser();
header('Content-Type: application/json; charset=utf-8');

global $DB;
$table  = Checklist::T_TICKET;
$action = $_POST['dcl_op'] ?? ''; // ne pas utiliser 'action' : réservé à l'inventaire GLPI
$ticket = new Ticket();

function dcl_fail($msg, $code = 403)
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// Détermine le ticket concerné
if (in_array($action, ['add', 'reorder', 'apply', 'render'], true)) {
    $tickets_id = (int) ($_POST['tickets_id'] ?? 0);
} else {
    $row = $DB->request(['FROM' => $table, 'WHERE' => ['id' => (int) ($_POST['id'] ?? 0)]])->current();
    if (!$row) {
        dcl_fail('Item introuvable', 404);
    }
    $tickets_id = (int) $row['tickets_id'];
}

if (!$ticket->getFromDB($tickets_id) || !$ticket->canUpdateItem()) {
    dcl_fail('Action non autorisée');
}

$extra = [];

switch ($action) {
    case 'toggle':
        $done = (int) ($_POST['is_done'] ?? 0) === 1;
        $DB->update($table, [
            'is_done'       => $done ? 1 : 0,
            'users_id_done' => $done ? Session::getLoginUserID() : 0,
            'date_done'     => $done ? $_SESSION['glpi_currenttime'] : null,
        ], ['id' => $row['id']]);
        break;

    case 'add':
        $label = trim((string) ($_POST['label'] ?? ''));
        if ($label === '') {
            dcl_fail('Libellé vide', 400);
        }
        $DB->insert($table, [
            'tickets_id' => $tickets_id,
            'label'      => mb_substr($label, 0, 255),
            'rank'       => 100000 + countElementsInTable($table, ['tickets_id' => $tickets_id]),
        ]);
        break;

    case 'delete':
        $DB->delete($table, ['id' => $row['id']]);
        break;

    case 'reorder':
        if (!Checklist::saveOrder($table, ['tickets_id' => $tickets_id], (string) ($_POST['ids'] ?? ''))) {
            dcl_fail('Ordre invalide', 400);
        }
        break;

    case 'apply':
        $cid = (int) ($_POST['checklists_id'] ?? 0);
        if (!countElementsInTable(Checklist::T_LISTS, ['id' => $cid])) {
            dcl_fail('Checklist introuvable', 404);
        }
        $added = Checklist::applyToTicket($tickets_id, $cid);
        if ($added < 0) {
            dcl_fail('Cette checklist est déjà appliquée à ce ticket', 409);
        }
        if ($added === 0) {
            dcl_fail('Cette checklist ne contient aucun item', 400);
        }
        $extra['added'] = $added;
        break;

    case 'render':
        break;

    default:
        dcl_fail('Opération inconnue', 400);
}

$response = ['ok' => true] + $extra;
if ($action !== 'reorder') {
    // Le panneau est mis à jour sans recharger la page (évite de perdre les modifications en cours du ticket)
    $response['html']  = TicketItem::renderBody($ticket);
    $response['count'] = TicketItem::countLabel($tickets_id);
}
echo json_encode($response);
