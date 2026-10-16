<?php

function plugin_bencheck_exec($sql)
{
    global $DB;
    return $DB->doQuery($sql);
}

function plugin_bencheck_install()
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    $lists = 'glpi_plugin_bencheck_checklists';
    $defs  = 'glpi_plugin_bencheck_defaultitems';
    $tick  = 'glpi_plugin_bencheck_ticketitems';

    // Migration depuis l'ancien plugin "defaultchecklist" (conserve les données)
    foreach (['defaultitems', 'ticketitems'] as $t) {
        $old = "glpi_plugin_defaultchecklist_{$t}";
        $new = "glpi_plugin_bencheck_{$t}";
        if ($DB->tableExists($old) && !$DB->tableExists($new)) {
            plugin_bencheck_exec("RENAME TABLE `{$old}` TO `{$new}`");
        }
    }

    $fresh = !$DB->tableExists($defs);

    // --- Checklists nommées (v3) : auto_forms_id = 0 manuel uniquement, -1 tout formulaire, >0 un formulaire
    if (!$DB->tableExists($lists)) {
        plugin_bencheck_exec("CREATE TABLE `{$lists}` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `ticket_type` tinyint NOT NULL DEFAULT 0,
            `auto_forms_id` int NOT NULL DEFAULT 0,
            `rank` int NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    // --- Items modèles
    if ($fresh) {
        plugin_bencheck_exec("CREATE TABLE `{$defs}` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `checklists_id` int {$sign} NOT NULL DEFAULT 0,
            `label` varchar(255) NOT NULL,
            `ticket_type` tinyint NOT NULL DEFAULT 0,
            `forms_id` int {$sign} NOT NULL DEFAULT 0,
            `rank` int NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `checklists_id` (`checklists_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    } else {
        // Mises à jour depuis les versions précédentes (colonnes ajoutées au fil des versions)
        if (!$DB->fieldExists($defs, 'ticket_type')) {
            plugin_bencheck_exec("ALTER TABLE `{$defs}` ADD `ticket_type` tinyint NOT NULL DEFAULT 0 AFTER `label`");
        }
        if (!$DB->fieldExists($defs, 'forms_id')) {
            plugin_bencheck_exec("ALTER TABLE `{$defs}` ADD `forms_id` int {$sign} NOT NULL DEFAULT 0 AFTER `ticket_type`");
        }
        if (!$DB->fieldExists($defs, 'checklists_id')) {
            plugin_bencheck_exec("ALTER TABLE `{$defs}` ADD `checklists_id` int {$sign} NOT NULL DEFAULT 0 AFTER `id`,
                ADD KEY `checklists_id` (`checklists_id`)");
        }
    }

    // --- Items des tickets
    if (!$DB->tableExists($tick)) {
        plugin_bencheck_exec("CREATE TABLE `{$tick}` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `tickets_id` int {$sign} NOT NULL DEFAULT 0,
            `checklists_id` int {$sign} NOT NULL DEFAULT 0,
            `label` varchar(255) NOT NULL,
            `is_done` tinyint NOT NULL DEFAULT 0,
            `users_id_done` int {$sign} NOT NULL DEFAULT 0,
            `date_done` timestamp NULL DEFAULT NULL,
            `rank` int NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `tickets_id` (`tickets_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    } elseif (!$DB->fieldExists($tick, 'checklists_id')) {
        plugin_bencheck_exec("ALTER TABLE `{$tick}` ADD `checklists_id` int {$sign} NOT NULL DEFAULT 0 AFTER `tickets_id`");
    }

    if ($fresh) {
        // Installation neuve : une checklist d'exemple, à choisir manuellement dans les tickets
        $DB->insert($lists, ['name' => 'Checklist par défaut', 'ticket_type' => 0, 'auto_forms_id' => 0, 'rank' => 0]);
        $cid = $DB->insertId();
        foreach (['Prise en compte du ticket', 'Diagnostic effectué', 'Solution appliquée', 'Utilisateur informé'] as $i => $label) {
            $DB->insert($defs, ['checklists_id' => $cid, 'label' => $label, 'rank' => $i]);
        }
    } else {
        // Mise à jour : les anciens items "orphelins" sont regroupés en checklists nommées.
        // Un ancien groupe (formulaire, type) devient une checklist appliquée automatiquement
        // à ces tickets, ce qui conserve le comportement précédent.
        $groups = [];
        foreach ($DB->request([
            'SELECT'   => ['forms_id', 'ticket_type'],
            'DISTINCT' => true,
            'FROM'     => $defs,
            'WHERE'    => ['checklists_id' => 0],
        ]) as $g) {
            $groups[] = ['forms_id' => (int) $g['forms_id'], 'ticket_type' => (int) $g['ticket_type']];
        }
        $n = count($groups);
        foreach ($groups as $i => $g) {
            $DB->insert($lists, [
                'name'          => 'Checklist par défaut' . ($n > 1 ? ' ' . ($i + 1) : ''),
                'ticket_type'   => $g['ticket_type'],
                'auto_forms_id' => $g['forms_id'] === 0 ? -1 : $g['forms_id'],
                'rank'          => countElementsInTable($lists),
            ]);
            $cid = $DB->insertId();
            $DB->update($defs, ['checklists_id' => $cid], [
                'checklists_id' => 0,
                'forms_id'      => $g['forms_id'],
                'ticket_type'   => $g['ticket_type'],
            ]);
        }
    }

    return true;
}

function plugin_bencheck_uninstall()
{
    foreach (['checklists', 'defaultitems', 'ticketitems'] as $t) {
        plugin_bencheck_exec("DROP TABLE IF EXISTS `glpi_plugin_bencheck_{$t}`");
    }
    return true;
}

/**
 * Hook : GLPI vient de lier un élément créé à des réponses de formulaire.
 * Si c'est un ticket, on applique les checklists configurées en "application
 * automatique" pour ce formulaire. Les autres tickets restent sans checklist
 * (choix manuel dans l'onglet du ticket).
 */
function plugin_bencheck_form_item_linked(CommonDBTM $link)
{
    global $DB;

    try {
        $itemtype   = $link->fields['itemtype'] ?? ($link->input['itemtype'] ?? '');
        $tickets_id = (int) ($link->fields['items_id'] ?? ($link->input['items_id'] ?? 0));
        $answers_id = (int) ($link->fields['forms_answerssets_id'] ?? ($link->input['forms_answerssets_id'] ?? 0));

        if ($itemtype !== 'Ticket' || $tickets_id <= 0 || $answers_id <= 0) {
            return;
        }

        $answers = $DB->request([
            'SELECT' => ['forms_forms_id'],
            'FROM'   => 'glpi_forms_answerssets',
            'WHERE'  => ['id' => $answers_id],
        ])->current();
        if (!$answers) {
            return;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return;
        }

        \GlpiPlugin\Bencheck\Checklist::applyAutomatic(
            $tickets_id,
            (int) $answers['forms_forms_id'],
            (int) $ticket->fields['type']
        );
    } catch (\Throwable $e) {
        // Ne jamais bloquer la soumission du formulaire à cause de la checklist
        trigger_error('BenCheck: ' . $e->getMessage(), E_USER_WARNING);
    }
}

/**
 * Hook pre_itil_info_section : affiche la checklist dans le panneau de droite du ticket.
 * Appelé avec ['item' => objet, 'options' => paramètres du formulaire].
 */
function plugin_bencheck_show_ticket_checklist($params)
{
    $item = $params['item'] ?? null;
    if (!($item instanceof Ticket) || $item->isNewItem()) {
        return; // ni Change/Problem, ni création d'un nouveau ticket
    }
    if (Session::getCurrentInterface() !== 'central') {
        return; // pas d'affichage aux demandeurs de l'interface simplifiée
    }
    \GlpiPlugin\Bencheck\TicketItem::showInPanel($item);
}
