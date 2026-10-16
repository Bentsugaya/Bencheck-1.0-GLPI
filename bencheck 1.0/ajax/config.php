<?php
use GlpiPlugin\Bencheck\Checklist;

Session::checkLoginUser();
header('Content-Type: application/json; charset=utf-8');

if (!Session::haveRight('config', UPDATE)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Action non autorisée']);
    exit;
}

$op = $_POST['bc_op'] ?? '';

if ($op === 'reorder_items') {
    $cid = (int) ($_POST['checklists_id'] ?? 0);
    if ($cid > 0 && Checklist::saveOrder(Checklist::T_DEFAULT, ['checklists_id' => $cid], (string) ($_POST['ids'] ?? ''))) {
        echo json_encode(['ok' => true]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ordre invalide']);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Opération inconnue']);
