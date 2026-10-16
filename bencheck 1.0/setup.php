<?php
define('PLUGIN_BENCHECK_VERSION', '3.1.0');

function plugin_init_bencheck()
{
    global $PLUGIN_HOOKS;

    $plugin = new Plugin();
    if ($plugin->isInstalled('bencheck') && $plugin->isActivated('bencheck')) {
        // Checklist affichée directement dans le panneau du ticket (pas d'onglet).
        // Pour la placer en bas du panneau : Hooks::POST_ITIL_INFO_SECTION
        $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::PRE_ITIL_INFO_SECTION]['bencheck'] = 'plugin_bencheck_show_ticket_checklist';
        // Ticket créé depuis un formulaire : GLPI enregistre ensuite la liaison
        // "réponses du formulaire -> ticket" ; on s'y accroche pour copier la checklist
        $PLUGIN_HOOKS['item_add']['bencheck'] = [
            \Glpi\Form\Destination\AnswersSet_FormDestinationItem::class => 'plugin_bencheck_form_item_linked'
        ];
        // Page de configuration
        $PLUGIN_HOOKS['config_page']['bencheck'] = 'front/config.php';
    }
}

function plugin_version_bencheck()
{
    return [
        'name'         => 'BenCheck',
        'version'      => PLUGIN_BENCHECK_VERSION,
        'author'       => 'Ben',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => ['min' => '11.0.0', 'max' => '11.0.99'],
            'php'  => ['min' => '8.2'],
        ],
    ];
}

function plugin_bencheck_check_prerequisites()
{
    return true;
}

function plugin_bencheck_check_config($verbose = false)
{
    return true;
}
