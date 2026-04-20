<?php namespace ProcessWire;

/**
 * Start
 *
 * Personal quick-access dashboard for ProcessWire admin.
 * Visual drag-and-drop link editor built into module config.
 * Adds a standalone page under Setup and a widget on the admin home page.
 *
 * Bundled helper: PagePicker — modal page-tree picker for the URL field.
 *
 * @author  Maxim Semenov <maxim@smnv.org>
 * @link    https://github.com/mxmsmnv/Start
 */
class Start extends Process implements Module, ConfigurableModule {

  public static function getModuleInfo(): array {
    return [
      'title'      => 'Start',
      'summary'    => 'Personal quick-access dashboard with visual link editor.',
      'version'    => 110,
      'author'     => 'Maxim Semenov',
      'href'       => 'https://github.com/mxmsmnv/Start',
      'icon'       => 'bookmark',
      'requires'   => 'ProcessWire>=3.0.0',
      'page'       => [
        'name'   => 'start',
        'parent' => 'setup',
        'title'  => 'Start',
      ],
      'permission' => 'start-dashboard',
      'permissions' => [
        'start-dashboard' => 'Access the Start dashboard',
      ],
      'autoload'   => true,
      'singular'   => true,
    ];
  }

  // -------------------------------------------------------------------------
  // Uninstall
  // -------------------------------------------------------------------------

  public function ___uninstall(): void {
    parent::___uninstall();
  }

  // -------------------------------------------------------------------------
  // Config defaults
  // -------------------------------------------------------------------------

  public static function getDefaultData(): array {
    return [
      'links_json' => '',
      'cols'       => 3,
      'language'   => 'en',
    ];
  }

  public function __construct() {
    parent::__construct();
    foreach (self::getDefaultData() as $k => $v) {
      $this->$k = $v;
    }
  }

  // -------------------------------------------------------------------------
  // Autoload hooks
  // -------------------------------------------------------------------------

  public function init(): void {
    $this->addHookAfter('ProcessHome::execute', $this, 'hookHomeWidget');
    $this->addHookBefore('ProcessHome::execute', $this, 'hookHomeHeadline');
    // Remove breadcrumbs on Start pages — they add no value here
    $this->addHookAfter('Page::render', $this, 'hookRemoveBreadcrumbs');
  }

  public function hookRemoveBreadcrumbs(HookEvent $event): void {
    $page = $this->wire('page');
    if (!$page || !in_array($page->name, ['start'])) return;
    // Fuel breadcrumbs with empty array so PW renders nothing
    $this->wire('breadcrumbs')->removeAll();
  }

  public function hookHomeHeadline(HookEvent $event): void {
    /** @var ProcessHome $process */
    $process = $event->object;
    $process->headline(self::t('start'));
    $process->browserTitle(self::t('start'));
  }

  public function hookHomeWidget(HookEvent $event): void {
    $links = $this->parseLinks();
    if (empty($links)) return;
    $event->return = $this->renderWidget($links) . $event->return;
  }

  // -------------------------------------------------------------------------
  // Process page — main view + PagePicker AJAX endpoint
  // -------------------------------------------------------------------------

  public function ___execute(): string {
    // PagePicker AJAX endpoint — handled before any HTML output
    if ($this->wire('input')->get('action') === 'pages') {
      $picker = new StartPagePicker($this->wire('config')->urls->admin . 'setup/start/');
      return $picker->ajax();
    }

    $links    = $this->parseLinks();
    $cols     = max(2, min(6, (int) $this->cols));
    $adminUrl = $this->wire('config')->urls->admin;

    $this->headline(self::t('start'));
    // Load Font Awesome
    $faUrl = $this->wire('config')->urls->{'Start'} . 'fontawesome/css/all.min.css';
    $this->wire('config')->styles->add($faUrl);

    $out = $this->renderStyles();

    // ── Toolbar: view-mode toggle only ────────────────────────────────────
    $out .= '<div class="st-page-actions">';
    $out .= '<div class="st-view-toggle" role="group" aria-label="' . self::t('view_mode') . '">';
    $out .= '<button id="st-btn-list" class="st-active" onclick="stSetView(\'list\')" title="' . self::t('list_view') . '" aria-pressed="true">';
    $out .= '<span uk-icon="icon:list;ratio:0.85"></span>';
    $out .= '</button>';
    $out .= '<button id="st-btn-icon" onclick="stSetView(\'icon\')" title="' . self::t('icon_view') . '" aria-pressed="false">';
    $out .= '<span uk-icon="icon:grid;ratio:0.85"></span>';
    $out .= '</button>';
    $out .= '</div>';
    $out .= '</div>';

    if (empty($links)) {
      $out .= '<div class="uk-alert uk-alert-primary" uk-alert>';
      $out .= '<p>' . sprintf(
        self::t('no_links'),
        '<a href="' . $adminUrl . 'setup/start/edit/">',
        '</a>'
      ) . '</p>';
      $out .= '</div>';
      return $out;
    }

    $out .= '<div id="st-main" class="st-groups-wrap" style="--st-cols:' . $cols . ';--st-icon-cols:' . $cols . '">';
    $out .= $this->renderGroups($links, $cols);
    $out .= '</div>';
    // Footer with Edit Links — matches the footer pattern from other PW modules
    $out .= '<div class="st-footer">';
    $out .= '<a href="' . $adminUrl . 'setup/start/edit/" class="st-footer-edit">' . self::t('edit_links') . '</a>';
    $out .= '</div>';

    $out .= <<<JS
<script>
(function(){
  var STORAGE_KEY = 'st_view_mode';
  function stSetView(mode) {
    var main = document.getElementById('st-main');
    if (!main) return;
    if (mode === 'icon') {
      main.classList.add('st-icon-mode');
    } else {
      main.classList.remove('st-icon-mode');
    }
    var btnList = document.getElementById('st-btn-list');
    var btnIcon = document.getElementById('st-btn-icon');
    if (btnList) { btnList.classList.toggle('st-active', mode === 'list'); btnList.setAttribute('aria-pressed', mode === 'list'); }
    if (btnIcon) { btnIcon.classList.toggle('st-active', mode === 'icon'); btnIcon.setAttribute('aria-pressed', mode === 'icon'); }
    try { localStorage.setItem(STORAGE_KEY, mode); } catch(e) {}
  }
  window.stSetView = stSetView;
  try {
    var saved = localStorage.getItem(STORAGE_KEY);
    if (saved === 'icon') stSetView('icon');
  } catch(e) {}
  if (window.UIkit) UIkit.update();
})();
</script>
JS;

    return $out;
  }

  // -------------------------------------------------------------------------
  // Edit sub-page — visual link editor accessible from /setup/start/edit/
  // -------------------------------------------------------------------------

  public function ___executeEdit(): string {
    $action = $this->wire('input')->get('action');

    // PagePicker AJAX endpoint
    if ($action === 'pages') {
      $picker = new StartPagePicker($this->wire('config')->urls->admin . 'setup/start/edit/');
      return $picker->ajax();
    }

    // Installed Process modules AJAX — for Example button
    if ($action === 'modules') {
      if (!$this->wire('user')->isLoggedin()) {
        $this->wire('config')->ajax = true;
        header('Content-Type: application/json', true, 403);
        return json_encode(['error' => 'Forbidden']);
      }
      $this->wire('config')->ajax = true;
      header('Content-Type: application/json');
      $adminUrl = $this->wire('config')->urls->admin;

      // Get only installed Process modules that have their own admin page
      $skip = ['Start', 'ProcessHome', 'ProcessLogin', 'ProcessProfile',
                'ProcessForgotPassword', 'ProcessPageSearch', 'ProcessPageAdd',
                'ProcessPageSort', 'ProcessPageEditLink', 'ProcessPageEditImageSelect',
                'ProcessRecentPages', 'ProcessPageTrash', 'ProcessPageList',
                'ProcessPageView', 'ProcessPageEdit'];

      $items = [];
      foreach ($this->wire('modules')->findByPrefix('Process') as $info) {
        $name = $info->className ?? (string)$info;
        if (in_array($name, $skip)) continue;

        // Only modules that declare a 'page' with a parent
        $moduleInfo = $this->wire('modules')->getModuleInfoVerbose($name, ['verbose' => false]);
        if (empty($moduleInfo['page']['name'])) continue;

        // Find the actual page
        $pageName   = $moduleInfo['page']['name'];
        $parentName = $moduleInfo['page']['parent'] ?? 'setup';
        $page = $this->wire('pages')->get("name=$pageName, template=admin, include=all");
        if (!$page->id) continue;

        $label = (string) $page->get('title|name');
        // Get icon from module info — PW uses FontAwesome names without "fa-" prefix
        $rawIcon = (string) $moduleInfo['icon'];
        $icon = 'fa-' . (str_starts_with($rawIcon, 'fa-') ? substr($rawIcon, 3) : $rawIcon);
        // Deduplicate by url
        $url = $page->url;
        if (!array_filter($items, function($i) use ($url){ return $i['url'] === $url; })) {
          $items[] = ['label' => $label, 'url' => $url, 'name' => $pageName, 'icon' => $icon];
        }
      }

      // Sort alphabetically
      usort($items, function($a, $b) { return strcmp($a['label'], $b['label']); });

      return json_encode(['adminUrl' => $adminUrl, 'items' => $items]);
    }

    $adminUrl = $this->wire('config')->urls->admin;
    // Load Font Awesome
    $faUrl = $this->wire('config')->urls->{'Start'} . 'fontawesome/css/all.min.css';
    $this->wire('config')->styles->add($faUrl);
    $this->headline(self::t('edit_links'));
    $this->browserTitle(self::t('edit_links_title'));

    // ── Handle POST save ────────────────────────────────────────────────
    if ($this->wire('input')->requestMethod('POST')) {
      $linksJson = (string) $this->wire('input')->post('links_json');
      $cols      = max(2, min(6, (int) $this->wire('input')->post('cols')));

      // Validate JSON
      $decoded = json_decode($linksJson, true);
      if (!is_array($decoded)) $linksJson = '';

      // Save to module config
      $this->wire('modules')->saveConfig('Start', [
        'links_json' => $linksJson,
        'cols'       => $cols,
      ]);

      $this->message(self::t('links_saved'));
      $this->wire('session')->redirect($adminUrl . 'setup/start/');
      return '';
    }

    // ── Render editor ────────────────────────────────────────────────────
    $data     = $this->wire('modules')->getModuleConfigData('Start');
    $json     = (string) ($data['links_json'] ?? '');
    $cols     = max(2, min(6, (int) ($data['cols'] ?? 3)));
    $pickerUrl = $adminUrl . 'setup/start/edit/';

    $out  = $this->renderStyles();

    // Form — Back, Example, Clear all are in the footer next to Save
    $out .= '<form method="post" action="' . $adminUrl . 'setup/start/edit/">';
    $out .= $this->wire('session')->CSRF->renderInput();
    $out .= '<input type="hidden" name="links_json" id="start_links_json" value="' . htmlspecialchars($json, ENT_QUOTES, 'UTF-8') . '">';
    $out .= '<input type="hidden" name="cols" id="start_cols" value="' . $cols . '">';
    $out .= self::buildEditorHTML($json, $cols, $pickerUrl);
    $out .= self::buildEditorJS($json, $cols, $pickerUrl, $adminUrl);
    // Footer: Back | Example | Clear all | Save
    $out .= '<div class="st-edit-footer uk-margin-top">';
    $out .= '<a href="' . $adminUrl . 'setup/start/" class="uk-button uk-button-default">' . self::t('back') . '</a>';
    $out .= '<button type="button" class="uk-button uk-button-default" onclick="stLoadExample()">' . self::t('example') . '</button>';
    $out .= '<button type="button" class="uk-button uk-button-secondary" onclick="stClearAll()">' . self::t('clear_all') . '</button>';
    $out .= '<button type="submit" class="uk-button uk-button-primary">' . self::t('save') . '</button>';
    $out .= '</div>';
    $out .= '</form>';

    $out .= '<script>if(window.UIkit)UIkit.update();</script>';
    return $out;
  }

  // -------------------------------------------------------------------------
  // Rendering
  // -------------------------------------------------------------------------

  protected function renderWidget(array $links): string {
    $adminUrl = $this->wire('config')->urls->admin;
    $cols     = max(2, min(6, (int) $this->cols));
    $out  = $this->renderStyles();
    $out .= '<div class="st-widget uk-card uk-card-default uk-card-body uk-margin-bottom" style="--st-cols:' . $cols . '">';
    $out .= '<div class="st-widget-header uk-flex uk-flex-middle uk-flex-between uk-margin-small-bottom">';
    $out .= '<span class="st-widget-title uk-text-bold uk-text-small">';
    $out .= '<span uk-icon="icon:bolt;ratio:0.85" class="uk-margin-small-right"></span>';
    $out .= self::t('start');
    $out .= '</span>';
    $out .= '<a href="' . $adminUrl . 'setup/start/" class="uk-link-muted uk-text-small">';
    $out .= self::t('view_all') . ' <span uk-icon="icon:arrow-right;ratio:0.75"></span>';
    $out .= '</a>';
    $out .= '</div>';
    $out .= $this->renderGroups($links, $cols);
    $out .= '</div>';
    return $out;
  }

  protected function renderGroups(array $links, int $cols): string {
    $out = '<div class="st-groups">';
    foreach ($links as $group) {
      $out .= '<div class="st-group">';
      if (!empty($group['label'])) {
        $out .= '<div class="uk-text-uppercase uk-text-muted uk-text-small st-group-label">';
        $out .= $this->wire('sanitizer')->entities($group['label']);
        $out .= '</div>';
      }
      $out .= '<div class="st-grid">';
      foreach ($group['items'] as $item) {
        $url    = $this->wire('sanitizer')->url($item['url'], ['allowRelative' => true]);
        if (!$url) continue;
        $label  = $this->wire('sanitizer')->entities($item['label']);
        $iconName = ltrim($item['icon'] ?? 'link');
        $target = !empty($item['external']) ? ' target="_blank" rel="noopener"' : '';
        $out .= '<a href="' . $url . '" class="st-item uk-card uk-card-default"' . $target . '>';
        $out .= '<span class="st-item-icon">' . $this->faIcon($iconName) . '</span>';
        $out .= '<span class="st-item-label">' . $label . '</span>';
        $out .= '</a>';
      }
      $out .= '</div>';
      $out .= '</div>';
    }
    $out .= '</div>';
    $out .= '<script>if(window.UIkit)UIkit.update();</script>';
    return $out;
  }

  /**
   * Render a Font Awesome icon tag.
   * Detects brand icons (fab) vs solid (fas) automatically.
   * $name can be a full FA class like "fa-github" or just "github".
   */
  protected function faIcon(string $name, string $extraClass = ''): string {
    static $brands = null;
    if ($brands === null) {
      $brands = array_flip(array_filter(
        file(__DIR__ . '/fontawesome/brands.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
      ));
    }
    $name = ltrim($name, ' ');
    if (strpos($name, 'fa-') !== 0) $name = 'fa-' . $name;
    $prefix = isset($brands[$name]) ? 'fab' : 'fas';
    $cls = trim($prefix . ' fa-fw ' . $name . ($extraClass ? ' ' . $extraClass : ''));
    return '<i class="' . $this->wire('sanitizer')->entities($cls) . '" aria-hidden="true"></i>';
  }

  private bool $stylesRendered = false;

  protected function renderStyles(): string {
    if ($this->stylesRendered) return '';
    $this->stylesRendered = true;
    return <<<HTML
<style>
/* Start module — layout only, no duplication of UIkit tokens */

/* ── Toolbar ── */
.st-page-actions{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;gap:.5rem}
.st-view-toggle{display:flex;gap:4px}
.st-view-toggle button{background:transparent;border:1px solid var(--pw-border-color);color:var(--pw-muted-color);width:30px;height:30px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background .15s,color .15s,border-color .15s}
.st-view-toggle button:hover,.st-view-toggle button.st-active{background:var(--pw-blocks-background);color:var(--pw-text-color);border-color:var(--pw-muted-color)}
.st-view-toggle button.st-active{color:var(--pw-main-color);border-color:var(--pw-main-color)}

/* ── Groups ── */
.st-groups{display:flex;flex-direction:column;gap:1.25rem}
.st-group-label{letter-spacing:.06em;margin-bottom:.5rem}

/* ══ LIST MODE (default) ════════════════════════════════════════════════════ */
.st-grid{display:grid;grid-template-columns:repeat(var(--st-cols,3),1fr);gap:.4rem}
.st-item{display:flex;align-items:center;gap:.5rem;padding:.45rem .7rem;
  text-decoration:none!important;color:var(--pw-text-color);font-size:.85rem;
  transition:background .15s,border-color .15s,color .15s;min-width:0}
.st-item:hover{color:var(--pw-main-color)}
.st-item-icon{flex-shrink:0;color:var(--pw-muted-color);transition:color .15s;font-size:14px}
.st-item:hover .st-item-icon{color:var(--pw-main-color)}
.st-item-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* ══ ICON MODE ══════════════════════════════════════════════════════════════ */
.st-icon-mode .st-grid{grid-template-columns:repeat(var(--st-icon-cols,6),1fr);gap:.6rem}
.st-icon-mode .st-item{
  flex-direction:column;align-items:center;justify-content:center;
  gap:.55rem;padding:1.1rem .5rem .9rem;
  text-align:center;min-width:0;min-height:80px;
  transition:border-color .15s,color .15s}
.st-icon-mode .st-item-icon{
  flex-shrink:0;width:44px;height:44px;
  display:flex;align-items:center;justify-content:center;
  background:var(--pw-main-background);border-radius:12px;
  color:var(--pw-text-color);transition:background .15s,color .15s}
.st-icon-mode .st-item-icon{font-size:22px}
.st-icon-mode .st-item:hover .st-item-icon{background:var(--pw-main-color);color:#fff}
.st-icon-mode .st-item-label{
  font-size:.75rem;overflow:hidden;text-overflow:ellipsis;
  white-space:nowrap;width:100%;color:var(--pw-text-color)}
.st-icon-mode .st-item:hover .st-item-label{color:var(--pw-main-color)}

/* ── Widget (admin home) always list mode ── */
.st-widget .st-grid{grid-template-columns:repeat(var(--st-cols,3),1fr)}
.st-widget .st-item{flex-direction:row;padding:.45rem .7rem;min-height:unset;transform:none!important;box-shadow:none!important}
.st-widget .st-item-icon{width:unset;height:unset;background:none!important;border-radius:0}
.st-widget .st-item-icon{font-size:13px}
.st-widget-header{margin-bottom:.75rem}
.st-widget-title{display:inline-flex;align-items:center;gap:.3rem}

/* ── Footer ── */
.st-footer{display:flex;justify-content:flex-end;align-items:center;margin-top:16px;padding-top:12px;border-top:1px solid var(--pw-border-color)}
.st-edit-footer{display:flex;align-items:center;gap:8px;padding-top:16px;border-top:1px solid var(--pw-border-color);flex-wrap:wrap}
.st-edit-footer .uk-button-primary{margin-left:auto}
.st-footer-edit{font-size:11px;color:var(--pw-muted-color);text-decoration:none}
.st-footer-edit:hover{color:var(--pw-main-color)}

/* ── Responsive ── */
@media(max-width:800px){
  .st-grid{grid-template-columns:repeat(2,1fr)!important}
  .st-icon-mode .st-grid{grid-template-columns:repeat(4,1fr)!important}
}
@media(max-width:520px){
  .st-grid{grid-template-columns:1fr!important}
  .st-icon-mode .st-grid{grid-template-columns:repeat(3,1fr)!important}
}
</style>
HTML;
  }


  // -------------------------------------------------------------------------
  // Translations
  // -------------------------------------------------------------------------

  const TRANSLATIONS = [
    'en' => [
      'start'           => 'Start',
      'edit_links'      => 'Edit Links',
      'edit_links_title'=> 'Start — Edit Links',
      'view_mode'       => 'View mode',
      'list_view'       => 'List view',
      'icon_view'       => 'Icon view',
      'view_all'        => 'View all',
      'no_links'        => 'No links yet. %sConfigure links%s in module settings.',
      'links_saved'     => 'Links saved.',
      'back'            => 'Back',
      'example'         => 'Example',
      'clear_all'       => 'Clear all',
      'save'            => 'Save',
      'language_label'  => 'Admin language',
      'language_desc'   => 'Language used for the Start dashboard interface.',
      'add_link'        => '+ Add link',
      'add_group'       => 'Add group',
      'remove'          => 'Remove',
      'remove_group'    => 'Remove group',
      'browse_page'     => 'Browse pages',
      'select_page'     => 'Select a page',
      'select_icon'     => 'Select icon',
      'columns'         => 'Columns',
      'search_pages'    => 'Search pages…',
      'loading'         => 'Loading…',
    ],
    'ru' => [
      'start'           => 'Старт',
      'edit_links'      => 'Редактировать ссылки',
      'edit_links_title'=> 'Старт — Редактирование ссылок',
      'view_mode'       => 'Вид',
      'list_view'       => 'Список',
      'icon_view'       => 'Иконки',
      'view_all'        => 'Все ссылки',
      'no_links'        => 'Ссылок нет. %sДобавьте ссылки%s в настройках модуля.',
      'links_saved'     => 'Ссылки сохранены.',
      'back'            => 'Назад',
      'example'         => 'Пример',
      'clear_all'       => 'Очистить',
      'save'            => 'Сохранить',
      'language_label'  => 'Язык интерфейса',
      'language_desc'   => 'Язык панели Start.',
      'add_link'        => '+ Добавить ссылку',
      'add_group'       => 'Добавить группу',
      'remove'          => 'Удалить',
      'remove_group'    => 'Удалить группу',
      'browse_page'     => 'Выбрать страницу',
      'select_page'     => 'Выберите страницу',
      'select_icon'     => 'Выбрать иконку',
      'columns'         => 'Колонки',
      'search_pages'    => 'Поиск страниц…',
      'loading'         => 'Загрузка…',
    ],
    'de' => [
      'start'           => 'Start',
      'edit_links'      => 'Links bearbeiten',
      'edit_links_title'=> 'Start — Links bearbeiten',
      'view_mode'       => 'Ansicht',
      'list_view'       => 'Listenansicht',
      'icon_view'       => 'Symbolansicht',
      'view_all'        => 'Alle anzeigen',
      'no_links'        => 'Noch keine Links. %sLinks konfigurieren%s in den Moduleinstellungen.',
      'links_saved'     => 'Links gespeichert.',
      'back'            => 'Zurück',
      'example'         => 'Beispiel',
      'clear_all'       => 'Alles löschen',
      'save'            => 'Speichern',
      'language_label'  => 'Verwaltungssprache',
      'language_desc'   => 'Sprache des Start-Dashboards.',
      'add_link'        => '+ Link hinzufügen',
      'add_group'       => 'Gruppe hinzufügen',
      'remove'          => 'Entfernen',
      'remove_group'    => 'Gruppe entfernen',
      'browse_page'     => 'Seiten durchsuchen',
      'select_page'     => 'Seite auswählen',
      'select_icon'     => 'Symbol auswählen',
      'columns'         => 'Spalten',
      'search_pages'    => 'Seiten suchen…',
      'loading'         => 'Laden…',
    ],
    'fr' => [
      'start'           => 'Accueil',
      'edit_links'      => 'Modifier les liens',
      'edit_links_title'=> 'Accueil — Modifier les liens',
      'view_mode'       => 'Vue',
      'list_view'       => 'Vue liste',
      'icon_view'       => 'Vue icônes',
      'view_all'        => 'Voir tout',
      'no_links'        => 'Aucun lien. %sConfigurer les liens%s dans les paramètres.',
      'links_saved'     => 'Liens enregistrés.',
      'back'            => 'Retour',
      'example'         => 'Exemple',
      'clear_all'       => 'Tout effacer',
      'save'            => 'Enregistrer',
      'language_label'  => "Langue d'administration",
      'language_desc'   => 'Langue utilisée pour le tableau de bord Start.',
      'add_link'        => '+ Ajouter un lien',
      'add_group'       => 'Ajouter un groupe',
      'remove'          => 'Supprimer',
      'remove_group'    => 'Supprimer le groupe',
      'browse_page'     => 'Parcourir les pages',
      'select_page'     => 'Sélectionner une page',
      'select_icon'     => 'Choisir une icône',
      'columns'         => 'Colonnes',
      'search_pages'    => 'Rechercher des pages…',
      'loading'         => 'Chargement…',
    ],
    'es' => [
      'start'           => 'Inicio',
      'edit_links'      => 'Editar enlaces',
      'edit_links_title'=> 'Inicio — Editar enlaces',
      'view_mode'       => 'Vista',
      'list_view'       => 'Vista lista',
      'icon_view'       => 'Vista iconos',
      'view_all'        => 'Ver todo',
      'no_links'        => 'Sin enlaces. %sConfigurar enlaces%s en la configuración.',
      'links_saved'     => 'Enlaces guardados.',
      'back'            => 'Volver',
      'example'         => 'Ejemplo',
      'clear_all'       => 'Borrar todo',
      'save'            => 'Guardar',
      'language_label'  => 'Idioma de administración',
      'language_desc'   => 'Idioma del panel de control Start.',
      'add_link'        => '+ Añadir enlace',
      'add_group'       => 'Añadir grupo',
      'remove'          => 'Eliminar',
      'remove_group'    => 'Eliminar grupo',
      'browse_page'     => 'Explorar páginas',
      'select_page'     => 'Seleccionar una página',
      'select_icon'     => 'Seleccionar icono',
      'columns'         => 'Columnas',
      'search_pages'    => 'Buscar páginas…',
      'loading'         => 'Cargando…',
    ],
    'pl' => [
      'start'           => 'Start',
      'edit_links'      => 'Edytuj linki',
      'edit_links_title'=> 'Start — Edytuj linki',
      'view_mode'       => 'Widok',
      'list_view'       => 'Widok listy',
      'icon_view'       => 'Widok ikon',
      'view_all'        => 'Zobacz wszystko',
      'no_links'        => 'Brak linków. %sSkonfiguruj linki%s w ustawieniach modułu.',
      'links_saved'     => 'Linki zapisane.',
      'back'            => 'Wstecz',
      'example'         => 'Przykład',
      'clear_all'       => 'Wyczyść wszystko',
      'save'            => 'Zapisz',
      'language_label'  => 'Język administracji',
      'language_desc'   => 'Język używany w panelu Start.',
      'add_link'        => '+ Dodaj link',
      'add_group'       => 'Dodaj grupę',
      'remove'          => 'Usuń',
      'remove_group'    => 'Usuń grupę',
      'browse_page'     => 'Przeglądaj strony',
      'select_page'     => 'Wybierz stronę',
      'select_icon'     => 'Wybierz ikonę',
      'columns'         => 'Kolumny',
      'search_pages'    => 'Szukaj stron…',
      'loading'         => 'Ładowanie…',
    ],
    'uk' => [
      'start'           => 'Старт',
      'edit_links'      => 'Редагувати посилання',
      'edit_links_title'=> 'Старт — Редагування посилань',
      'view_mode'       => 'Вигляд',
      'list_view'       => 'Список',
      'icon_view'       => 'Іконки',
      'view_all'        => 'Усі посилання',
      'no_links'        => 'Посилань немає. %sДодайте посилання%s у налаштуваннях модуля.',
      'links_saved'     => 'Посилання збережено.',
      'back'            => 'Назад',
      'example'         => 'Приклад',
      'clear_all'       => 'Очистити',
      'save'            => 'Зберегти',
      'language_label'  => 'Мова інтерфейсу',
      'language_desc'   => 'Мова панелі Start.',
      'add_link'        => '+ Додати посилання',
      'add_group'       => 'Додати групу',
      'remove'          => 'Видалити',
      'remove_group'    => 'Видалити групу',
      'browse_page'     => 'Переглянути сторінки',
      'select_page'     => 'Оберіть сторінку',
      'select_icon'     => 'Обрати іконку',
      'columns'         => 'Колонки',
      'search_pages'    => 'Пошук сторінок…',
      'loading'         => 'Завантаження…',
    ],
    'it' => [
      'start'           => 'Inizio',
      'edit_links'      => 'Modifica link',
      'edit_links_title'=> 'Inizio — Modifica link',
      'view_mode'       => 'Vista',
      'list_view'       => 'Vista elenco',
      'icon_view'       => 'Vista icone',
      'view_all'        => 'Vedi tutto',
      'no_links'        => 'Nessun link. %sConfigura i link%s nelle impostazioni.',
      'links_saved'     => 'Link salvati.',
      'back'            => 'Indietro',
      'example'         => 'Esempio',
      'clear_all'       => 'Cancella tutto',
      'save'            => 'Salva',
      'language_label'  => 'Lingua di amministrazione',
      'language_desc'   => 'Lingua utilizzata per il pannello Start.',
      'add_link'        => '+ Aggiungi link',
      'add_group'       => 'Aggiungi gruppo',
      'remove'          => 'Rimuovi',
      'remove_group'    => 'Rimuovi gruppo',
      'browse_page'     => 'Sfoglia pagine',
      'select_page'     => 'Seleziona una pagina',
      'select_icon'     => 'Seleziona icona',
      'columns'         => 'Colonne',
      'search_pages'    => 'Cerca pagine…',
      'loading'         => 'Caricamento…',
    ],
    'nl' => [
      'start'           => 'Start',
      'edit_links'      => 'Links bewerken',
      'edit_links_title'=> 'Start — Links bewerken',
      'view_mode'       => 'Weergave',
      'list_view'       => 'Lijstweergave',
      'icon_view'       => 'Icoonsweergave',
      'view_all'        => 'Alles bekijken',
      'no_links'        => 'Geen links. %sLinks configureren%s in de instellingen.',
      'links_saved'     => 'Links opgeslagen.',
      'back'            => 'Terug',
      'example'         => 'Voorbeeld',
      'clear_all'       => 'Alles wissen',
      'save'            => 'Opslaan',
      'language_label'  => 'Beheertaal',
      'language_desc'   => 'Taal voor het Start-dashboard.',
      'add_link'        => '+ Link toevoegen',
      'add_group'       => 'Groep toevoegen',
      'remove'          => 'Verwijderen',
      'remove_group'    => 'Groep verwijderen',
      'browse_page'     => "Pagina's bladeren",
      'select_page'     => 'Selecteer een pagina',
      'select_icon'     => 'Pictogram selecteren',
      'columns'         => 'Kolommen',
      'search_pages'    => "Pagina's zoeken…",
      'loading'         => 'Laden…',
    ],
    'pt' => [
      'start'           => 'Início',
      'edit_links'      => 'Editar links',
      'edit_links_title'=> 'Início — Editar links',
      'view_mode'       => 'Vista',
      'list_view'       => 'Vista em lista',
      'icon_view'       => 'Vista em ícones',
      'view_all'        => 'Ver tudo',
      'no_links'        => 'Sem links. %sConfigurar links%s nas definições.',
      'links_saved'     => 'Links guardados.',
      'back'            => 'Voltar',
      'example'         => 'Exemplo',
      'clear_all'       => 'Limpar tudo',
      'save'            => 'Guardar',
      'language_label'  => 'Idioma de administração',
      'language_desc'   => 'Idioma do painel Start.',
      'add_link'        => '+ Adicionar link',
      'add_group'       => 'Adicionar grupo',
      'remove'          => 'Remover',
      'remove_group'    => 'Remover grupo',
      'browse_page'     => 'Navegar páginas',
      'select_page'     => 'Selecionar uma página',
      'select_icon'     => 'Selecionar ícone',
      'columns'         => 'Colunas',
      'search_pages'    => 'Pesquisar páginas…',
      'loading'         => 'A carregar…',
    ],
    'zh' => [
      'start'           => '开始',
      'edit_links'      => '编辑链接',
      'edit_links_title'=> '开始 — 编辑链接',
      'view_mode'       => '视图',
      'list_view'       => '列表视图',
      'icon_view'       => '图标视图',
      'view_all'        => '查看全部',
      'no_links'        => '暂无链接。%s配置链接%s请前往模块设置。',
      'links_saved'     => '链接已保存。',
      'back'            => '返回',
      'example'         => '示例',
      'clear_all'       => '清除全部',
      'save'            => '保存',
      'language_label'  => '管理语言',
      'language_desc'   => '用于 Start 仪表盘的语言。',
      'add_link'        => '+ 添加链接',
      'add_group'       => '添加分组',
      'remove'          => '删除',
      'remove_group'    => '删除分组',
      'browse_page'     => '浏览页面',
      'select_page'     => '选择页面',
      'select_icon'     => '选择图标',
      'columns'         => '列数',
      'search_pages'    => '搜索页面…',
      'loading'         => '加载中…',
    ],
    'ja' => [
      'start'           => 'スタート',
      'edit_links'      => 'リンクを編集',
      'edit_links_title'=> 'スタート — リンクを編集',
      'view_mode'       => '表示',
      'list_view'       => 'リスト表示',
      'icon_view'       => 'アイコン表示',
      'view_all'        => 'すべて表示',
      'no_links'        => 'リンクがありません。%sリンクを設定%sしてください。',
      'links_saved'     => 'リンクを保存しました。',
      'back'            => '戻る',
      'example'         => '例',
      'clear_all'       => 'すべてクリア',
      'save'            => '保存',
      'language_label'  => '管理言語',
      'language_desc'   => 'Startダッシュボードの言語。',
      'add_link'        => '+ リンクを追加',
      'add_group'       => 'グループを追加',
      'remove'          => '削除',
      'remove_group'    => 'グループを削除',
      'browse_page'     => 'ページを参照',
      'select_page'     => 'ページを選択',
      'select_icon'     => 'アイコンを選択',
      'columns'         => '列数',
      'search_pages'    => 'ページを検索…',
      'loading'         => '読み込み中…',
    ],
  ];

  /**
   * Return a translated UI string for the current configured language.
   * Falls back to English if the key or language is not found.
   */
  public static function t(string $key): string {
    $lang = 'en';
    try {
      $cfg = wire('modules')->getModuleConfigData('Start');
      if (!empty($cfg['language'])) $lang = $cfg['language'];
    } catch (\Throwable $e) {}
    if (!isset(self::TRANSLATIONS[$lang])) $lang = 'en';
    return self::TRANSLATIONS[$lang][$key] ?? self::TRANSLATIONS['en'][$key] ?? $key;
  }

  // -------------------------------------------------------------------------
  // JSON parser
  // -------------------------------------------------------------------------

  protected function parseLinks(): array {
    $raw = trim((string) $this->links_json);
    if (!$raw) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) return [];

    $groups = [];
    foreach ($data as $group) {
      if (empty($group['items']) || !is_array($group['items'])) continue;
      $items = [];
      foreach ($group['items'] as $item) {
        if (empty($item['url']) || empty($item['label'])) continue;
        $items[] = [
          'label'    => (string) $item['label'],
          'url'      => (string) $item['url'],
          'icon'     => (string) ($item['icon'] ?? 'link'),
          'external' => !empty($item['external']),
        ];
      }
      if (empty($items)) continue;
      $groups[] = [
        'label' => (string) ($group['label'] ?? ''),
        'items' => $items,
      ];
    }
    return $groups;
  }

  // -------------------------------------------------------------------------
  // Module config — visual editor
  // -------------------------------------------------------------------------

  public function getModuleConfigInputfields(array $data): InputfieldWrapper {
    $data    = array_merge(self::getDefaultData(), $data);
    $modules = wire('modules');
    $wrapper = new InputfieldWrapper();

    /** @var InputfieldSelect $f */
    $f = $modules->get('InputfieldSelect');
    $f->attr('name', 'language');
    $f->label       = self::t('language_label');
    $f->description = self::t('language_desc');
    $options = [
      'en' => 'English',    'de' => 'Deutsch',    'fr' => 'Français',
      'es' => 'Español',    'it' => 'Italiano',   'nl' => 'Nederlands',
      'pt' => 'Português',  'pl' => 'Polski',      'ru' => 'Русский',
      'uk' => 'Українська', 'zh' => '中文',         'ja' => '日本語',
    ];
    foreach ($options as $code => $label) $f->addOption($code, $label);
    $f->attr('value', $data['language'] ?? 'en');
    $wrapper->add($f);

    // Redirect to visual editor for the rest of the config
    /** @var InputfieldMarkup $fm */
    $fm = $modules->get('InputfieldMarkup');
    $fm->label = self::t('edit_links');
    $fm->value = '<p><a href="' . wire('config')->urls->admin . 'setup/start/edit/" class="uk-button uk-button-default">'
               . self::t('edit_links') . ' →</a></p>';
    $wrapper->add($fm);

    return $wrapper;
  }

  protected static function buildEditorHTML(string $json, int $cols, string $pickerUrl = ''): string {
    $jsonAttr  = htmlspecialchars($json ?: '[]', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $colsInt   = (int) max(2, min(6, $cols));
    if (!$pickerUrl) {
      $pickerUrl = wire('config')->urls->admin . 'setup/start/edit/';
    }
    $pickerUrl    = htmlspecialchars($pickerUrl, ENT_QUOTES, 'UTF-8');
    $adminUrlAttr = htmlspecialchars(wire('config')->urls->admin, ENT_QUOTES, 'UTF-8');
    $i18nJson     = json_encode([
      'add_link'    => self::t('add_link'),
      'add_group'   => self::t('add_group'),
      'remove'      => self::t('remove'),
      'remove_group'=> self::t('remove_group'),
      'browse_page' => self::t('browse_page'),
      'select_page' => self::t('select_page'),
      'select_icon' => self::t('select_icon'),
      'columns'     => self::t('columns'),
      'example'     => self::t('example'),
      'search_pages'=> self::t('search_pages'),
      'loading'     => self::t('loading'),
    ], JSON_UNESCAPED_UNICODE);
    $tColumns     = self::t('columns');
    $tSelectIcon  = self::t('select_icon');
    $tAddGroup    = self::t('add_group');

    return <<<HTML
<style>
/* Start editor — only layout UIkit cannot provide */
.st-grp{margin-bottom:6px}
.st-grp-hdr{display:flex;align-items:center;gap:8px;padding:6px 10px;background:var(--pw-inputs-background);border:1px solid var(--pw-border-color);border-bottom:none}
.st-grp-handle,.st-row-handle{cursor:grab;color:var(--pw-muted-color);user-select:none;flex-shrink:0;font-size:15px;line-height:1}
.st-grp-handle:active,.st-row-handle:active{cursor:grabbing}
.st-grp-name{flex:1;min-width:0}
.st-items{border:1px solid var(--pw-border-color);padding:6px 8px;background:var(--pw-blocks-background)}
/* Фиксированные ширины через calc — все строки выравниваются одинаково */
.st-items-grid{display:flex;flex-direction:column;gap:4px}
.st-row{display:flex;align-items:center;gap:5px}
.st-row-handle{flex-shrink:0;width:20px}
.st-inp-label{width:28%;flex-shrink:0;min-width:0;box-sizing:border-box}
.st-inp-url{flex:1;min-width:0}
.st-inp-icon{flex-shrink:0}
.st-row-actions{display:flex;align-items:center;gap:4px;flex-shrink:0}
.st-inp-ext{flex-shrink:0}
/* Mobile */
@media(max-width:600px){
  .st-inp-label{width:35%}
}
.st-prev-grid{display:grid;gap:4px}
.st-prev-item{display:flex;align-items:center;gap:6px;padding:4px 8px;overflow:hidden}
.st-prev-item>span:last-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.st-prev-item [uk-icon]{color:var(--pw-muted-color);flex-shrink:0}
/* Icon-style action buttons (browse + delete) */
.st-icon-btn{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;flex-shrink:0;background:none;border:1px solid var(--pw-border-color);border-radius:50%;cursor:pointer;color:var(--pw-muted-color);transition:border-color .15s,color .15s}
.st-icon-btn:hover{border-color:var(--pw-muted-color);color:var(--pw-text-color)}
.st-icon-btn-danger{color:var(--pw-muted-color)}
.st-icon-btn-danger:hover{border-color:var(--pw-error-inline-text-color);color:var(--pw-error-inline-text-color)}
/* Add link / Add group dashed buttons */
.st-add-btn{display:block;width:100%;padding:6px 10px;background:none;border:1px dashed var(--pw-border-color);color:var(--pw-muted-color);cursor:pointer;font-family:inherit;font-size:13px;text-align:center;transition:border-color .15s,color .15s}
.st-add-btn:hover{border-color:var(--pw-main-color);color:var(--pw-main-color)}
.st-editor-toolbar{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
@media(max-width:480px){.st-editor-toolbar{flex-direction:column;align-items:stretch}
  .st-editor-toolbar .uk-button-group{display:flex}
  .st-editor-toolbar .uk-button-group .uk-button{flex:1}}
/* Icon picker button */
.st-icon-pick-btn{display:inline-flex;align-items:center;gap:6px;padding:0 10px;height:32px;flex-shrink:0;cursor:pointer;border:1px solid var(--pw-border-color);background:var(--pw-blocks-background);color:var(--pw-text-color);font-size:12px;transition:border-color .15s}
.st-icon-pick-btn:hover{border-color:var(--pw-muted-color)}
.st-icon-pick-btn i{font-size:15px;color:var(--pw-muted-color)}
/* Icon picker popup */
#st-icon-popup{display:none;position:fixed;inset:0;z-index:99999;background:var(--pw-modal-color,rgba(0,0,0,.45));align-items:center;justify-content:center}
#st-icon-popup.open{display:flex}
#st-icon-box{background:var(--pw-blocks-background);border:1px solid var(--pw-border-color);border-radius:6px;width:580px;max-width:calc(100vw - 24px);max-height:80vh;display:flex;flex-direction:column;overflow:hidden}
#st-icon-box-head{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid var(--pw-border-color);flex-shrink:0}
#st-icon-box-head strong{font-size:14px;color:var(--pw-text-color)}
#st-icon-close{background:none;border:none;font-size:20px;cursor:pointer;color:var(--pw-muted-color);line-height:1;padding:2px 6px}
#st-icon-close:hover{color:var(--pw-text-color)}
#st-icon-search-wrap{padding:10px 14px;border-bottom:1px solid var(--pw-border-color);flex-shrink:0}
#st-icon-q{width:100%;padding:6px 10px;border:1px solid var(--pw-border-color);background:var(--pw-inputs-background);color:var(--pw-text-color);font-size:13px;outline:none;box-sizing:border-box}
#st-icon-q:focus{border-color:var(--pw-main-color)}
#st-icon-grid{overflow-y:auto;flex:1;padding:10px;display:grid;grid-template-columns:repeat(auto-fill,minmax(52px,1fr));gap:4px;align-content:start}
.st-icon-cell{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;padding:8px 4px;cursor:pointer;border:1px solid transparent;border-radius:4px;transition:background .12s,border-color .12s;min-width:0}
.st-icon-cell:hover{background:var(--pw-main-background);border-color:var(--pw-border-color)}
.st-icon-cell.selected{background:var(--pw-main-color);border-color:var(--pw-main-color)}
.st-icon-cell.selected i,.st-icon-cell.selected span{color:#fff}
.st-icon-cell i{font-size:18px;color:var(--pw-text-color)}
.st-icon-cell span{font-size:9px;color:var(--pw-muted-color);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;width:100%;text-align:center}
#st-icon-count{padding:6px 14px;font-size:11px;color:var(--pw-muted-color);border-top:1px solid var(--pw-border-color);flex-shrink:0;text-align:right}
</style>

<div id="st-editor" data-json="{$jsonAttr}" data-picker-url="{$pickerUrl}" data-admin-url="{$adminUrlAttr}">

  <div class="st-editor-toolbar uk-margin-small-bottom">
    <div class="uk-flex uk-flex-middle" style="gap:10px">
      <label class="uk-form-label uk-text-small uk-margin-remove">{$tColumns}</label>
      <input class="uk-range" type="range" id="st-cols-range" min="2" max="6" value="{$colsInt}" step="1" style="flex:1;max-width:160px" oninput="stSetCols(this.value)">
      <span class="uk-badge" id="st-cols-val">{$colsInt}</span>
    </div>

  </div>

  <div id="st-groups" class="uk-margin-small-bottom"></div>

  <button type="button" class="st-add-btn" onclick="stAddGroup()">
    {$tAddGroup}
  </button>

  <div class="uk-card uk-card-default uk-card-small uk-card-body uk-margin-small-top">
    <div class="uk-text-uppercase uk-text-muted uk-text-xsmall uk-margin-small-bottom" style="letter-spacing:.06em;font-size:10px;font-weight:700">Preview</div>
    <div id="st-preview"></div>
  </div>

</div>

<!-- Icon picker popup — shared singleton, shown/hidden by JS -->
<div id="st-icon-popup">
  <div id="st-icon-box">
    <div id="st-icon-box-head">
      <strong>{$tSelectIcon}</strong>
      <button type="button" id="st-icon-close">&#215;</button>
    </div>
    <div id="st-icon-search-wrap">
      <input type="text" id="st-icon-q" placeholder="Search 1887 icons… (e.g. home, gear, envelope)" autocomplete="off">
    </div>
    <div id="st-icon-grid"></div>
    <div id="st-icon-count"></div>
  </div>
</div>

HTML;
  }

  protected static function buildEditorJS(string $json, int $cols, string $pickerUrl = '', string $adminUrl = ''): string {
    $colsInt   = (int) max(2, min(6, $cols));
    if (!$pickerUrl) {
      $pickerUrl = wire('config')->urls->admin . 'setup/start/edit/';
    }
    $pickerUrl = htmlspecialchars($pickerUrl, ENT_QUOTES, 'UTF-8');
    if (!$adminUrl) {
      $adminUrl = wire('config')->urls->admin;
    }
    $adminUrlAttr = htmlspecialchars($adminUrl, ENT_QUOTES, 'UTF-8');
    $jsonAttr  = htmlspecialchars($json ?: '[]', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $i18nJson  = json_encode([
      'add_link'    => self::t('add_link'),
      'add_group'   => self::t('add_group'),
      'remove'      => self::t('remove'),
      'remove_group'=> self::t('remove_group'),
      'browse_page' => self::t('browse_page'),
      'select_page' => self::t('select_page'),
      'select_icon' => self::t('select_icon'),
      'columns'     => self::t('columns'),
      'example'     => self::t('example'),
      'search_pages'=> self::t('search_pages'),
      'loading'     => self::t('loading'),
    ], JSON_UNESCAPED_UNICODE);
    return <<<HTML
<script>
window.stI18n = {$i18nJson};
// StartPagePicker — JS class, instantiated by the editor when needed
(function(global){
function StartPagePicker(baseUrl, onSelect){
  this.baseUrl  = baseUrl;
  this.onSelect = onSelect || function(){};
  this._built   = false;
}
StartPagePicker.prototype._build = function(){
  if(this._built) return;
  this._built = true;
  var self = this;

  // Inject modal into DOM on first use if not already present
  if(!document.getElementById('ppk-modal')){
    var wrap = document.createElement('div');
    wrap.innerHTML = [
      '<style>',
      '#ppk-modal{display:none;position:fixed;inset:0;z-index:99999;background:var(--pw-modal-color,rgba(0,0,0,.45));align-items:center;justify-content:center}',
      '#ppk-modal.ppk-open{display:flex}',
      '#ppk-box{background:var(--pw-blocks-background);border:1px solid var(--pw-border-color);border-radius:10px;box-shadow:0 8px 40px rgba(0,0,0,.25);width:560px;max-width:calc(100vw - 32px);max-height:78vh;display:flex;flex-direction:column;overflow:hidden}',
      '#ppk-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px 12px;border-bottom:1px solid var(--pw-border-color);font-weight:600;font-size:15px;flex-shrink:0;color:var(--pw-text-color)}',
      '#ppk-close{background:none;border:none;font-size:22px;cursor:pointer;color:var(--pw-muted-color);line-height:1;padding:2px 6px}',
      '#ppk-close:hover{color:var(--pw-text-color)}',
      '#ppk-search{padding:10px 16px;border-bottom:1px solid var(--pw-border-color);flex-shrink:0}',
      '#ppk-search input{width:100%;padding:7px 12px;border:1px solid var(--pw-border-color);border-radius:6px;font-size:13px;box-sizing:border-box;outline:none;background:var(--pw-inputs-background);color:var(--pw-text-color)}',
      '#ppk-search input:focus{border-color:var(--pw-main-color)}',
      '#ppk-tree{overflow-y:auto;flex:1}',
      '.ppk-row{border-bottom:1px solid var(--pw-inputs-background)}',
      '.ppk-item{display:flex;align-items:center;width:100%;border:none;background:none;padding:0;cursor:pointer;text-align:left;font-size:13px;color:var(--pw-text-color);min-height:36px;box-sizing:border-box}',
      '.ppk-item:hover{background:var(--pw-main-background)}',
      '.ppk-toggle{width:36px;min-width:36px;height:36px;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--pw-border-color);font-size:10px;line-height:1}',
      '.ppk-toggle.has-ch{color:var(--pw-muted-color)}',
      '.ppk-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding-right:8px}',
      '.ppk-url{font-size:11px;color:var(--pw-muted-color);white-space:nowrap;flex-shrink:0;max-width:170px;overflow:hidden;text-overflow:ellipsis;padding-right:6px}',
      '.ppk-pick{width:40px;min-width:40px;height:36px;padding:0;border:none;border-left:1px solid var(--pw-border-color);background:none;color:var(--pw-main-color);font-size:15px;cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center}',
      '.ppk-pick:hover{background:var(--pw-main-background)}',
      '.ppk-flat{display:flex;align-items:center;width:100%;border:none;border-bottom:1px solid var(--pw-inputs-background);background:none;padding:0 0 0 36px;cursor:pointer;text-align:left;font-size:13px;color:var(--pw-text-color);min-height:36px;box-sizing:border-box}',
      '.ppk-flat:hover{background:var(--pw-main-background)}',
      '.ppk-flat .ppk-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding-right:8px}',
      '.ppk-flat .ppk-url{font-size:11px;color:var(--pw-muted-color);white-space:nowrap;flex-shrink:0;padding-right:14px;max-width:200px;overflow:hidden;text-overflow:ellipsis}',
      '.ppk-children{padding-left:16px;border-left:2px solid var(--pw-border-color);margin-left:18px}',
      '.ppk-loading{text-align:center;padding:28px;color:var(--pw-muted-color);font-size:13px}',
      '</style>',
      '<div id="ppk-modal">',
      '  <div id="ppk-box">',
      '    <div id="ppk-head">'+(window.stI18n&&stI18n.select_page||'Select a page')+'<button id="ppk-close">&#215;</button></div>',
      '    <div id="ppk-search"><input type="text" id="ppk-q" placeholder="'+(window.stI18n&&stI18n.search_pages||'Search pages\u2026')+'" autocomplete="off"></div>',
      '    <div id="ppk-tree"><div class="ppk-loading">'+(window.stI18n&&stI18n.loading||'Loading\u2026')+'</div></div>',
      '  </div>',
      '</div>'
    ].join('');
    document.body.appendChild(wrap);
  }

  this.modal  = document.getElementById('ppk-modal');
  this.tree   = document.getElementById('ppk-tree');
  this.search = document.getElementById('ppk-q');
  this.close  = document.getElementById('ppk-close');
  if(!this.modal) return;

  var close = this.close;
  var modal = this.modal;
  close.addEventListener('click', function(){ self.close_(); });
  modal.addEventListener('click', function(e){ if(e.target===modal) self.close_(); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') self.close_(); });

  var searchTimer;
  var searchInput = this.search;
  searchInput.addEventListener('input', function(){
    clearTimeout(searchTimer);
    var q = this.value.trim();
    searchTimer = setTimeout(function(){
      if(q.length > 1) self._search(q);
      else if(q === '') self._loadTree(0);
    }, 300);
  });
};
StartPagePicker.prototype.open = function(){
  this._build();
  if(!this.modal) return;
  this.modal.classList.add('ppk-open');
  this.search.value = '';
  this._loadTree(0);
  setTimeout(function(){ }, 80);
  var s = this.search; setTimeout(function(){ s.focus(); }, 80);
};
StartPagePicker.prototype.close_ = function(){
  if(this.modal) this.modal.classList.remove('ppk-open');
};
StartPagePicker.prototype._loadTree = function(parentId, container){
  var self = this;
  var el = container || this.tree;
  el.innerHTML = '<div class="ppk-loading">'+(window.stI18n&&stI18n.loading||'Loading\u2026')+'</div>';
  fetch(this.baseUrl + '?action=pages&parent_id=' + parentId, {
    headers: {'X-Requested-With':'XMLHttpRequest'}
  }).then(function(r){return r.json();})
    .then(function(d){self._renderTree(d.items||[], el);})
    .catch(function(){ el.innerHTML='<div class="ppk-loading">Could not load pages.</div>'; });
};
StartPagePicker.prototype._search = function(q){
  var self = this;
  this.tree.innerHTML = '<div class="ppk-loading">Searching\u2026</div>';
  fetch(this.baseUrl + '?action=pages&q=' + encodeURIComponent(q), {
    headers: {'X-Requested-With':'XMLHttpRequest'}
  }).then(function(r){return r.json();})
    .then(function(d){
      var items = d.items||[];
      if(!items.length){ self.tree.innerHTML='<div class="ppk-loading">No pages found.</div>'; return; }
      var html='';
      items.forEach(function(p){
        html+='<button class="ppk-item ppk-flat" data-path="'+esc(p.path)+'">'
             +'<span class="ppk-name">'+escH(p.name)+'</span>'
             +'<span class="ppk-url">'+escH(p.path)+'</span>'
             +'</button>';
      });
      self.tree.innerHTML=html;
      self.tree.querySelectorAll('.ppk-flat').forEach(function(btn){
        btn.addEventListener('click', function(){ self._select(this.dataset.path); });
      });
    })
    .catch(function(){ self.tree.innerHTML='<div class="ppk-loading">Search failed.</div>'; });
};
StartPagePicker.prototype._renderTree = function(items, container){
  var self = this;
  if(!items.length){ container.innerHTML='<div class="ppk-loading">No pages found.</div>'; return; }
  var frag = document.createDocumentFragment();
  items.forEach(function(p){
    var row = document.createElement('div'); row.className='ppk-row';
    var btn = document.createElement('div'); btn.className='ppk-item';
    var tog = document.createElement('span');
    tog.className = p.hasChildren ? 'ppk-toggle has-ch' : 'ppk-toggle';
    tog.innerHTML = p.hasChildren ? '&#9658;' : '';
    btn.appendChild(tog);
    var nm = document.createElement('span'); nm.className='ppk-name'; nm.textContent=p.name; btn.appendChild(nm);
    var urlEl = document.createElement('span'); urlEl.className='ppk-url'; urlEl.textContent=p.path; btn.appendChild(urlEl);
    var pick = document.createElement('button'); pick.className='ppk-pick'; pick.title='Select'; pick.innerHTML='&#10003;';
    pick.addEventListener('click', function(e){ e.stopPropagation(); self._select(p.path); });
    btn.appendChild(pick);
    var ch = document.createElement('div'); ch.className='ppk-children'; ch.style.display='none';
    btn.addEventListener('click', function(e){
      if(e.target===pick) return;
      if(p.hasChildren){
        var open = ch.style.display!=='none';
        ch.style.display = open?'none':'block';
        tog.innerHTML = open?'&#9658;':'&#9660;';
        if(!open && !ch.dataset.loaded){ ch.dataset.loaded='1'; self._loadTree(p.id, ch); }
      } else { self._select(p.path); }
    });
    row.appendChild(btn); row.appendChild(ch); frag.appendChild(row);
  });
  container.innerHTML=''; container.appendChild(frag);
};
StartPagePicker.prototype._select = function(path){
  this.close_();
  this.onSelect(path);
};
function escH(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function esc(s){return String(s).replace(/"/g,'&quot;');}
global.StartPagePicker = StartPagePicker;
})(window);
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js"></script>
<script>
window.stI18n = {$i18nJson};
(function(){
var editorEl   = document.getElementById('st-editor');
var state      = {cols:{$colsInt},groups:[]};
var hiddenJson = document.getElementById('start_links_json');
var hiddenCols = document.getElementById('start_cols');
var pickerUrl  = editorEl.dataset.pickerUrl;
var adminUrl   = editorEl.dataset.adminUrl || '/';

var FA_ICONS = ["fa-0","fa-1","fa-2","fa-3","fa-4","fa-5","fa-6","fa-7","fa-8","fa-9","fa-42-group","fa-500px","fa-a","fa-accessible-icon","fa-accusoft","fa-address-book","fa-address-card","fa-adn","fa-adversal","fa-affiliatetheme","fa-airbnb","fa-algolia","fa-align-center","fa-align-justify","fa-align-left","fa-align-right","fa-alipay","fa-amazon","fa-amazon-pay","fa-amilia","fa-anchor","fa-anchor-circle-check","fa-anchor-circle-exclamation","fa-anchor-circle-xmark","fa-anchor-lock","fa-android","fa-angellist","fa-angle-down","fa-angle-left","fa-angle-right","fa-angle-up","fa-angles-down","fa-angles-left","fa-angles-right","fa-angles-up","fa-angrycreative","fa-angular","fa-ankh","fa-app-store","fa-app-store-ios","fa-apper","fa-apple","fa-apple-pay","fa-apple-whole","fa-archway","fa-arrow-down","fa-arrow-down-1-9","fa-arrow-down-9-1","fa-arrow-down-a-z","fa-arrow-down-long","fa-arrow-down-short-wide","fa-arrow-down-up-across-line","fa-arrow-down-up-lock","fa-arrow-down-wide-short","fa-arrow-down-z-a","fa-arrow-left","fa-arrow-left-long","fa-arrow-pointer","fa-arrow-right","fa-arrow-right-arrow-left","fa-arrow-right-from-bracket","fa-arrow-right-long","fa-arrow-right-to-bracket","fa-arrow-right-to-city","fa-arrow-rotate-left","fa-arrow-rotate-right","fa-arrow-trend-down","fa-arrow-trend-up","fa-arrow-turn-down","fa-arrow-turn-up","fa-arrow-up","fa-arrow-up-1-9","fa-arrow-up-9-1","fa-arrow-up-a-z","fa-arrow-up-from-bracket","fa-arrow-up-from-ground-water","fa-arrow-up-from-water-pump","fa-arrow-up-long","fa-arrow-up-right-dots","fa-arrow-up-right-from-square","fa-arrow-up-short-wide","fa-arrow-up-wide-short","fa-arrow-up-z-a","fa-arrows-down-to-line","fa-arrows-down-to-people","fa-arrows-left-right","fa-arrows-left-right-to-line","fa-arrows-rotate","fa-arrows-spin","fa-arrows-split-up-and-left","fa-arrows-to-circle","fa-arrows-to-dot","fa-arrows-to-eye","fa-arrows-turn-right","fa-arrows-turn-to-dots","fa-arrows-up-down","fa-arrows-up-down-left-right","fa-arrows-up-to-line","fa-artstation","fa-asterisk","fa-asymmetrik","fa-at","fa-atlassian","fa-atom","fa-audible","fa-audio-description","fa-austral-sign","fa-autoprefixer","fa-avianex","fa-aviato","fa-award","fa-aws","fa-b","fa-baby","fa-baby-carriage","fa-backward","fa-backward-fast","fa-backward-step","fa-bacon","fa-bacteria","fa-bacterium","fa-bag-shopping","fa-bahai","fa-baht-sign","fa-ban","fa-ban-smoking","fa-bandage","fa-bandcamp","fa-bangladeshi-taka-sign","fa-barcode","fa-bars","fa-bars-progress","fa-bars-staggered","fa-baseball","fa-baseball-bat-ball","fa-basket-shopping","fa-basketball","fa-bath","fa-battery-empty","fa-battery-full","fa-battery-half","fa-battery-quarter","fa-battery-three-quarters","fa-battle-net","fa-bed","fa-bed-pulse","fa-beer-mug-empty","fa-behance","fa-bell","fa-bell-concierge","fa-bell-slash","fa-bezier-curve","fa-bicycle","fa-bilibili","fa-bimobject","fa-binoculars","fa-biohazard","fa-bitbucket","fa-bitcoin","fa-bitcoin-sign","fa-bity","fa-black-tie","fa-blackberry","fa-blender","fa-blender-phone","fa-blog","fa-blogger","fa-blogger-b","fa-bluesky","fa-bluetooth","fa-bluetooth-b","fa-bold","fa-bolt","fa-bolt-lightning","fa-bomb","fa-bone","fa-bong","fa-book","fa-book-atlas","fa-book-bible","fa-book-bookmark","fa-book-journal-whills","fa-book-medical","fa-book-open","fa-book-open-reader","fa-book-quran","fa-book-skull","fa-book-tanakh","fa-bookmark","fa-bootstrap","fa-border-all","fa-border-none","fa-border-top-left","fa-bore-hole","fa-bots","fa-bottle-droplet","fa-bottle-water","fa-bowl-food","fa-bowl-rice","fa-bowling-ball","fa-box","fa-box-archive","fa-box-open","fa-box-tissue","fa-boxes-packing","fa-boxes-stacked","fa-braille","fa-brain","fa-brave","fa-brave-reverse","fa-brazilian-real-sign","fa-bread-slice","fa-bridge","fa-bridge-circle-check","fa-bridge-circle-exclamation","fa-bridge-circle-xmark","fa-bridge-lock","fa-bridge-water","fa-briefcase","fa-briefcase-medical","fa-broom","fa-broom-ball","fa-brush","fa-btc","fa-bucket","fa-buffer","fa-bug","fa-bug-slash","fa-bugs","fa-building","fa-building-circle-arrow-right","fa-building-circle-check","fa-building-circle-exclamation","fa-building-circle-xmark","fa-building-columns","fa-building-flag","fa-building-lock","fa-building-ngo","fa-building-shield","fa-building-un","fa-building-user","fa-building-wheat","fa-bullhorn","fa-bullseye","fa-burger","fa-buromobelexperte","fa-burst","fa-bus","fa-bus-simple","fa-business-time","fa-buy-n-large","fa-buysellads","fa-c","fa-cable-car","fa-cake-candles","fa-calculator","fa-calendar","fa-calendar-check","fa-calendar-day","fa-calendar-days","fa-calendar-minus","fa-calendar-plus","fa-calendar-week","fa-calendar-xmark","fa-camera","fa-camera-retro","fa-camera-rotate","fa-campground","fa-canadian-maple-leaf","fa-candy-cane","fa-cannabis","fa-capsules","fa-car","fa-car-battery","fa-car-burst","fa-car-on","fa-car-rear","fa-car-side","fa-car-tunnel","fa-caravan","fa-caret-down","fa-caret-left","fa-caret-right","fa-caret-up","fa-carrot","fa-cart-arrow-down","fa-cart-flatbed","fa-cart-flatbed-suitcase","fa-cart-plus","fa-cart-shopping","fa-cash-register","fa-cat","fa-cc-amazon-pay","fa-cc-amex","fa-cc-apple-pay","fa-cc-diners-club","fa-cc-discover","fa-cc-jcb","fa-cc-mastercard","fa-cc-paypal","fa-cc-stripe","fa-cc-visa","fa-cedi-sign","fa-cent-sign","fa-centercode","fa-centos","fa-certificate","fa-chair","fa-chalkboard","fa-chalkboard-user","fa-champagne-glasses","fa-charging-station","fa-chart-area","fa-chart-bar","fa-chart-column","fa-chart-gantt","fa-chart-line","fa-chart-pie","fa-chart-simple","fa-check","fa-check-double","fa-check-to-slot","fa-cheese","fa-chess","fa-chess-bishop","fa-chess-board","fa-chess-king","fa-chess-knight","fa-chess-pawn","fa-chess-queen","fa-chess-rook","fa-chevron-down","fa-chevron-left","fa-chevron-right","fa-chevron-up","fa-child","fa-child-combatant","fa-child-dress","fa-child-reaching","fa-children","fa-chrome","fa-chromecast","fa-church","fa-circle","fa-circle-arrow-down","fa-circle-arrow-left","fa-circle-arrow-right","fa-circle-arrow-up","fa-circle-check","fa-circle-chevron-down","fa-circle-chevron-left","fa-circle-chevron-right","fa-circle-chevron-up","fa-circle-dollar-to-slot","fa-circle-dot","fa-circle-down","fa-circle-exclamation","fa-circle-h","fa-circle-half-stroke","fa-circle-info","fa-circle-left","fa-circle-minus","fa-circle-nodes","fa-circle-notch","fa-circle-pause","fa-circle-play","fa-circle-plus","fa-circle-question","fa-circle-radiation","fa-circle-right","fa-circle-stop","fa-circle-up","fa-circle-user","fa-circle-xmark","fa-city","fa-clapperboard","fa-clipboard","fa-clipboard-check","fa-clipboard-list","fa-clipboard-question","fa-clipboard-user","fa-clock","fa-clock-rotate-left","fa-clone","fa-closed-captioning","fa-cloud","fa-cloud-arrow-down","fa-cloud-arrow-up","fa-cloud-bolt","fa-cloud-meatball","fa-cloud-moon","fa-cloud-moon-rain","fa-cloud-rain","fa-cloud-showers-heavy","fa-cloud-showers-water","fa-cloud-sun","fa-cloud-sun-rain","fa-cloudflare","fa-cloudscale","fa-cloudsmith","fa-cloudversify","fa-clover","fa-cmplid","fa-code","fa-code-branch","fa-code-commit","fa-code-compare","fa-code-fork","fa-code-merge","fa-code-pull-request","fa-codepen","fa-codiepie","fa-coins","fa-colon-sign","fa-comment","fa-comment-dollar","fa-comment-dots","fa-comment-medical","fa-comment-slash","fa-comment-sms","fa-comments","fa-comments-dollar","fa-compact-disc","fa-compass","fa-compass-drafting","fa-compress","fa-computer","fa-computer-mouse","fa-confluence","fa-connectdevelop","fa-contao","fa-cookie","fa-cookie-bite","fa-copy","fa-copyright","fa-cotton-bureau","fa-couch","fa-cow","fa-cpanel","fa-creative-commons","fa-creative-commons-by","fa-creative-commons-nc","fa-creative-commons-nc-eu","fa-creative-commons-nc-jp","fa-creative-commons-nd","fa-creative-commons-pd","fa-creative-commons-pd-alt","fa-creative-commons-remix","fa-creative-commons-sa","fa-creative-commons-sampling","fa-creative-commons-sampling-plus","fa-creative-commons-share","fa-creative-commons-zero","fa-credit-card","fa-critical-role","fa-crop","fa-crop-simple","fa-cross","fa-crosshairs","fa-crow","fa-crown","fa-crutch","fa-cruzeiro-sign","fa-css3","fa-css3-alt","fa-cube","fa-cubes","fa-cubes-stacked","fa-cuttlefish","fa-d","fa-d-and-d","fa-d-and-d-beyond","fa-dailymotion","fa-dart-lang","fa-dashcube","fa-database","fa-debian","fa-deezer","fa-delete-left","fa-delicious","fa-democrat","fa-deploydog","fa-deskpro","fa-desktop","fa-dev","fa-deviantart","fa-dharmachakra","fa-dhl","fa-diagram-next","fa-diagram-predecessor","fa-diagram-project","fa-diagram-successor","fa-diamond","fa-diamond-turn-right","fa-diaspora","fa-dice","fa-dice-d20","fa-dice-d6","fa-dice-five","fa-dice-four","fa-dice-one","fa-dice-six","fa-dice-three","fa-dice-two","fa-digg","fa-digital-ocean","fa-discord","fa-discourse","fa-disease","fa-display","fa-divide","fa-dna","fa-dochub","fa-docker","fa-dog","fa-dollar-sign","fa-dolly","fa-dong-sign","fa-door-closed","fa-door-open","fa-dove","fa-down-left-and-up-right-to-center","fa-down-long","fa-download","fa-draft2digital","fa-dragon","fa-draw-polygon","fa-dribbble","fa-dropbox","fa-droplet","fa-droplet-slash","fa-drum","fa-drum-steelpan","fa-drumstick-bite","fa-drupal","fa-dumbbell","fa-dumpster","fa-dumpster-fire","fa-dungeon","fa-dyalog","fa-e","fa-ear-deaf","fa-ear-listen","fa-earlybirds","fa-earth-africa","fa-earth-americas","fa-earth-asia","fa-earth-europe","fa-earth-oceania","fa-ebay","fa-edge","fa-edge-legacy","fa-egg","fa-eject","fa-elementor","fa-elevator","fa-ellipsis","fa-ellipsis-vertical","fa-ello","fa-ember","fa-empire","fa-envelope","fa-envelope-circle-check","fa-envelope-open","fa-envelope-open-text","fa-envelopes-bulk","fa-envira","fa-equals","fa-eraser","fa-erlang","fa-ethereum","fa-ethernet","fa-etsy","fa-euro-sign","fa-evernote","fa-exclamation","fa-expand","fa-expeditedssl","fa-explosion","fa-eye","fa-eye-dropper","fa-eye-low-vision","fa-eye-slash","fa-f","fa-face-angry","fa-face-dizzy","fa-face-flushed","fa-face-frown","fa-face-frown-open","fa-face-grimace","fa-face-grin","fa-face-grin-beam","fa-face-grin-beam-sweat","fa-face-grin-hearts","fa-face-grin-squint","fa-face-grin-squint-tears","fa-face-grin-stars","fa-face-grin-tears","fa-face-grin-tongue","fa-face-grin-tongue-squint","fa-face-grin-tongue-wink","fa-face-grin-wide","fa-face-grin-wink","fa-face-kiss","fa-face-kiss-beam","fa-face-kiss-wink-heart","fa-face-laugh","fa-face-laugh-beam","fa-face-laugh-squint","fa-face-laugh-wink","fa-face-meh","fa-face-meh-blank","fa-face-rolling-eyes","fa-face-sad-cry","fa-face-sad-tear","fa-face-smile","fa-face-smile-beam","fa-face-smile-wink","fa-face-surprise","fa-face-tired","fa-facebook","fa-facebook-f","fa-facebook-messenger","fa-fan","fa-fantasy-flight-games","fa-faucet","fa-faucet-drip","fa-fax","fa-feather","fa-feather-pointed","fa-fedex","fa-fedora","fa-ferry","fa-figma","fa-file","fa-file-arrow-down","fa-file-arrow-up","fa-file-audio","fa-file-circle-check","fa-file-circle-exclamation","fa-file-circle-minus","fa-file-circle-plus","fa-file-circle-question","fa-file-circle-xmark","fa-file-code","fa-file-contract","fa-file-csv","fa-file-excel","fa-file-export","fa-file-image","fa-file-import","fa-file-invoice","fa-file-invoice-dollar","fa-file-lines","fa-file-medical","fa-file-pdf","fa-file-pen","fa-file-powerpoint","fa-file-prescription","fa-file-shield","fa-file-signature","fa-file-video","fa-file-waveform","fa-file-word","fa-file-zipper","fa-fill","fa-fill-drip","fa-film","fa-filter","fa-filter-circle-dollar","fa-filter-circle-xmark","fa-fingerprint","fa-fire","fa-fire-burner","fa-fire-extinguisher","fa-fire-flame-curved","fa-fire-flame-simple","fa-firefox","fa-firefox-browser","fa-first-order","fa-first-order-alt","fa-firstdraft","fa-fish","fa-fish-fins","fa-flag","fa-flag-checkered","fa-flag-usa","fa-flask","fa-flask-vial","fa-flickr","fa-flipboard","fa-floppy-disk","fa-florin-sign","fa-flutter","fa-fly","fa-folder","fa-folder-closed","fa-folder-minus","fa-folder-open","fa-folder-plus","fa-folder-tree","fa-font","fa-font-awesome","fa-font-awesome","fa-fonticons","fa-fonticons-fi","fa-football","fa-fort-awesome","fa-fort-awesome-alt","fa-forumbee","fa-forward","fa-forward-fast","fa-forward-step","fa-foursquare","fa-franc-sign","fa-free-code-camp","fa-freebsd","fa-frog","fa-fulcrum","fa-futbol","fa-g","fa-galactic-republic","fa-galactic-senate","fa-gamepad","fa-gas-pump","fa-gauge","fa-gauge-high","fa-gauge-simple","fa-gauge-simple-high","fa-gavel","fa-gear","fa-gears","fa-gem","fa-genderless","fa-get-pocket","fa-gg","fa-gg-circle","fa-ghost","fa-gift","fa-gifts","fa-git","fa-git-alt","fa-github","fa-github-alt","fa-gitkraken","fa-gitlab","fa-gitter","fa-glass-water","fa-glass-water-droplet","fa-glasses","fa-glide","fa-glide-g","fa-globe","fa-gofore","fa-golang","fa-golf-ball-tee","fa-goodreads","fa-goodreads-g","fa-google","fa-google-drive","fa-google-pay","fa-google-play","fa-google-plus","fa-google-plus-g","fa-google-scholar","fa-google-wallet","fa-gopuram","fa-graduation-cap","fa-gratipay","fa-grav","fa-greater-than","fa-greater-than-equal","fa-grip","fa-grip-lines","fa-grip-lines-vertical","fa-grip-vertical","fa-gripfire","fa-group-arrows-rotate","fa-grunt","fa-guarani-sign","fa-guilded","fa-guitar","fa-gulp","fa-gun","fa-h","fa-hacker-news","fa-hackerrank","fa-hammer","fa-hamsa","fa-hand","fa-hand-back-fist","fa-hand-dots","fa-hand-fist","fa-hand-holding","fa-hand-holding-dollar","fa-hand-holding-droplet","fa-hand-holding-hand","fa-hand-holding-heart","fa-hand-holding-medical","fa-hand-lizard","fa-hand-middle-finger","fa-hand-peace","fa-hand-point-down","fa-hand-point-left","fa-hand-point-right","fa-hand-point-up","fa-hand-pointer","fa-hand-scissors","fa-hand-sparkles","fa-hand-spock","fa-handcuffs","fa-hands","fa-hands-asl-interpreting","fa-hands-bound","fa-hands-bubbles","fa-hands-clapping","fa-hands-holding","fa-hands-holding-child","fa-hands-holding-circle","fa-hands-praying","fa-handshake","fa-handshake-angle","fa-handshake-simple","fa-handshake-simple-slash","fa-handshake-slash","fa-hanukiah","fa-hard-drive","fa-hashnode","fa-hashtag","fa-hat-cowboy","fa-hat-cowboy-side","fa-hat-wizard","fa-head-side-cough","fa-head-side-cough-slash","fa-head-side-mask","fa-head-side-virus","fa-heading","fa-headphones","fa-headphones-simple","fa-headset","fa-heart","fa-heart-circle-bolt","fa-heart-circle-check","fa-heart-circle-exclamation","fa-heart-circle-minus","fa-heart-circle-plus","fa-heart-circle-xmark","fa-heart-crack","fa-heart-pulse","fa-helicopter","fa-helicopter-symbol","fa-helmet-safety","fa-helmet-un","fa-highlighter","fa-hill-avalanche","fa-hill-rockslide","fa-hippo","fa-hips","fa-hire-a-helper","fa-hive","fa-hockey-puck","fa-holly-berry","fa-hooli","fa-hornbill","fa-horse","fa-horse-head","fa-hospital","fa-hospital-user","fa-hot-tub-person","fa-hotdog","fa-hotel","fa-hotjar","fa-hourglass","fa-hourglass-end","fa-hourglass-half","fa-hourglass-start","fa-house","fa-house-chimney","fa-house-chimney-crack","fa-house-chimney-medical","fa-house-chimney-user","fa-house-chimney-window","fa-house-circle-check","fa-house-circle-exclamation","fa-house-circle-xmark","fa-house-crack","fa-house-fire","fa-house-flag","fa-house-flood-water","fa-house-flood-water-circle-arrow-right","fa-house-laptop","fa-house-lock","fa-house-medical","fa-house-medical-circle-check","fa-house-medical-circle-exclamation","fa-house-medical-circle-xmark","fa-house-medical-flag","fa-house-signal","fa-house-tsunami","fa-house-user","fa-houzz","fa-hryvnia-sign","fa-html5","fa-hubspot","fa-hurricane","fa-i","fa-i-cursor","fa-ice-cream","fa-icicles","fa-icons","fa-id-badge","fa-id-card","fa-id-card-clip","fa-ideal","fa-igloo","fa-image","fa-image-portrait","fa-images","fa-imdb","fa-inbox","fa-indent","fa-indian-rupee-sign","fa-industry","fa-infinity","fa-info","fa-instagram","fa-instalod","fa-intercom","fa-internet-explorer","fa-invision","fa-ioxhost","fa-italic","fa-itch-io","fa-itunes","fa-itunes-note","fa-j","fa-jar","fa-jar-wheat","fa-java","fa-jedi","fa-jedi-order","fa-jenkins","fa-jet-fighter","fa-jet-fighter-up","fa-jira","fa-joget","fa-joint","fa-joomla","fa-js","fa-jsfiddle","fa-jug-detergent","fa-jxl","fa-k","fa-kaaba","fa-kaggle","fa-key","fa-keybase","fa-keyboard","fa-keycdn","fa-khanda","fa-kickstarter","fa-kickstarter-k","fa-kip-sign","fa-kit-medical","fa-kitchen-set","fa-kiwi-bird","fa-korvue","fa-l","fa-land-mine-on","fa-landmark","fa-landmark-dome","fa-landmark-flag","fa-language","fa-laptop","fa-laptop-code","fa-laptop-file","fa-laptop-medical","fa-laravel","fa-lari-sign","fa-lastfm","fa-layer-group","fa-leaf","fa-leanpub","fa-left-long","fa-left-right","fa-lemon","fa-less","fa-less-than","fa-less-than-equal","fa-letterboxd","fa-life-ring","fa-lightbulb","fa-line","fa-lines-leaning","fa-link","fa-link-slash","fa-linkedin","fa-linkedin-in","fa-linode","fa-linux","fa-lira-sign","fa-list","fa-list-check","fa-list-ol","fa-list-ul","fa-litecoin-sign","fa-location-arrow","fa-location-crosshairs","fa-location-dot","fa-location-pin","fa-location-pin-lock","fa-lock","fa-lock-open","fa-locust","fa-lungs","fa-lungs-virus","fa-lyft","fa-m","fa-magento","fa-magnet","fa-magnifying-glass","fa-magnifying-glass-arrow-right","fa-magnifying-glass-chart","fa-magnifying-glass-dollar","fa-magnifying-glass-location","fa-magnifying-glass-minus","fa-magnifying-glass-plus","fa-mailchimp","fa-manat-sign","fa-mandalorian","fa-map","fa-map-location","fa-map-location-dot","fa-map-pin","fa-markdown","fa-marker","fa-mars","fa-mars-and-venus","fa-mars-and-venus-burst","fa-mars-double","fa-mars-stroke","fa-mars-stroke-right","fa-mars-stroke-up","fa-martini-glass","fa-martini-glass-citrus","fa-martini-glass-empty","fa-mask","fa-mask-face","fa-mask-ventilator","fa-masks-theater","fa-mastodon","fa-mattress-pillow","fa-maxcdn","fa-maximize","fa-mdb","fa-medal","fa-medapps","fa-medium","fa-medrt","fa-meetup","fa-megaport","fa-memory","fa-mendeley","fa-menorah","fa-mercury","fa-message","fa-meta","fa-meteor","fa-microblog","fa-microchip","fa-microphone","fa-microphone-lines","fa-microphone-lines-slash","fa-microphone-slash","fa-microscope","fa-microsoft","fa-mill-sign","fa-minimize","fa-mintbit","fa-minus","fa-mitten","fa-mix","fa-mixcloud","fa-mixer","fa-mizuni","fa-mobile","fa-mobile-button","fa-mobile-retro","fa-mobile-screen","fa-mobile-screen-button","fa-modx","fa-monero","fa-money-bill","fa-money-bill-1","fa-money-bill-1-wave","fa-money-bill-transfer","fa-money-bill-trend-up","fa-money-bill-wave","fa-money-bill-wheat","fa-money-bills","fa-money-check","fa-money-check-dollar","fa-monument","fa-moon","fa-mortar-pestle","fa-mosque","fa-mosquito","fa-mosquito-net","fa-motorcycle","fa-mound","fa-mountain","fa-mountain-city","fa-mountain-sun","fa-mug-hot","fa-mug-saucer","fa-music","fa-n","fa-naira-sign","fa-napster","fa-neos","fa-network-wired","fa-neuter","fa-newspaper","fa-nfc-directional","fa-nfc-symbol","fa-nimblr","fa-node","fa-node-js","fa-not-equal","fa-notdef","fa-note-sticky","fa-notes-medical","fa-npm","fa-ns8","fa-nutritionix","fa-o","fa-object-group","fa-object-ungroup","fa-octopus-deploy","fa-odnoklassniki","fa-odysee","fa-oil-can","fa-oil-well","fa-old-republic","fa-om","fa-opencart","fa-openid","fa-opensuse","fa-opera","fa-optin-monster","fa-orcid","fa-osi","fa-otter","fa-outdent","fa-p","fa-padlet","fa-page4","fa-pagelines","fa-pager","fa-paint-roller","fa-paintbrush","fa-palette","fa-palfed","fa-pallet","fa-panorama","fa-paper-plane","fa-paperclip","fa-parachute-box","fa-paragraph","fa-passport","fa-paste","fa-patreon","fa-pause","fa-paw","fa-paypal","fa-peace","fa-pen","fa-pen-clip","fa-pen-fancy","fa-pen-nib","fa-pen-ruler","fa-pen-to-square","fa-pencil","fa-people-arrows","fa-people-carry-box","fa-people-group","fa-people-line","fa-people-pulling","fa-people-robbery","fa-people-roof","fa-pepper-hot","fa-perbyte","fa-percent","fa-periscope","fa-person","fa-person-arrow-down-to-line","fa-person-arrow-up-from-line","fa-person-biking","fa-person-booth","fa-person-breastfeeding","fa-person-burst","fa-person-cane","fa-person-chalkboard","fa-person-circle-check","fa-person-circle-exclamation","fa-person-circle-minus","fa-person-circle-plus","fa-person-circle-question","fa-person-circle-xmark","fa-person-digging","fa-person-dots-from-line","fa-person-dress","fa-person-dress-burst","fa-person-drowning","fa-person-falling","fa-person-falling-burst","fa-person-half-dress","fa-person-harassing","fa-person-hiking","fa-person-military-pointing","fa-person-military-rifle","fa-person-military-to-person","fa-person-praying","fa-person-pregnant","fa-person-rays","fa-person-rifle","fa-person-running","fa-person-shelter","fa-person-skating","fa-person-skiing","fa-person-skiing-nordic","fa-person-snowboarding","fa-person-swimming","fa-person-through-window","fa-person-walking","fa-person-walking-arrow-loop-left","fa-person-walking-arrow-right","fa-person-walking-dashed-line-arrow-right","fa-person-walking-luggage","fa-person-walking-with-cane","fa-peseta-sign","fa-peso-sign","fa-phabricator","fa-phoenix-framework","fa-phoenix-squadron","fa-phone","fa-phone-flip","fa-phone-slash","fa-phone-volume","fa-photo-film","fa-php","fa-pied-piper","fa-pied-piper-alt","fa-pied-piper-hat","fa-pied-piper-pp","fa-piggy-bank","fa-pills","fa-pinterest","fa-pinterest-p","fa-pix","fa-pixiv","fa-pizza-slice","fa-place-of-worship","fa-plane","fa-plane-arrival","fa-plane-circle-check","fa-plane-circle-exclamation","fa-plane-circle-xmark","fa-plane-departure","fa-plane-lock","fa-plane-slash","fa-plane-up","fa-plant-wilt","fa-plate-wheat","fa-play","fa-playstation","fa-plug","fa-plug-circle-bolt","fa-plug-circle-check","fa-plug-circle-exclamation","fa-plug-circle-minus","fa-plug-circle-plus","fa-plug-circle-xmark","fa-plus","fa-plus-minus","fa-podcast","fa-poo","fa-poo-storm","fa-poop","fa-power-off","fa-prescription","fa-prescription-bottle","fa-prescription-bottle-medical","fa-print","fa-product-hunt","fa-pump-medical","fa-pump-soap","fa-pushed","fa-puzzle-piece","fa-python","fa-q","fa-qq","fa-qrcode","fa-question","fa-quinscape","fa-quora","fa-quote-left","fa-quote-right","fa-r","fa-r-project","fa-radiation","fa-radio","fa-rainbow","fa-ranking-star","fa-raspberry-pi","fa-ravelry","fa-react","fa-reacteurope","fa-readme","fa-rebel","fa-receipt","fa-record-vinyl","fa-rectangle-ad","fa-rectangle-list","fa-rectangle-xmark","fa-recycle","fa-red-river","fa-reddit","fa-reddit-alien","fa-redhat","fa-registered","fa-renren","fa-repeat","fa-reply","fa-reply-all","fa-replyd","fa-republican","fa-researchgate","fa-resolving","fa-restroom","fa-retweet","fa-rev","fa-ribbon","fa-right-from-bracket","fa-right-left","fa-right-long","fa-right-to-bracket","fa-ring","fa-road","fa-road-barrier","fa-road-bridge","fa-road-circle-check","fa-road-circle-exclamation","fa-road-circle-xmark","fa-road-lock","fa-road-spikes","fa-robot","fa-rocket","fa-rocketchat","fa-rockrms","fa-rotate","fa-rotate-left","fa-rotate-right","fa-route","fa-rss","fa-ruble-sign","fa-rug","fa-ruler","fa-ruler-combined","fa-ruler-horizontal","fa-ruler-vertical","fa-rupee-sign","fa-rupiah-sign","fa-rust","fa-s","fa-sack-dollar","fa-sack-xmark","fa-safari","fa-sailboat","fa-salesforce","fa-sass","fa-satellite","fa-satellite-dish","fa-scale-balanced","fa-scale-unbalanced","fa-scale-unbalanced-flip","fa-schlix","fa-school","fa-school-circle-check","fa-school-circle-exclamation","fa-school-circle-xmark","fa-school-flag","fa-school-lock","fa-scissors","fa-screenpal","fa-screwdriver","fa-screwdriver-wrench","fa-scribd","fa-scroll","fa-scroll-torah","fa-sd-card","fa-searchengin","fa-section","fa-seedling","fa-sellcast","fa-sellsy","fa-server","fa-servicestack","fa-shapes","fa-share","fa-share-from-square","fa-share-nodes","fa-sheet-plastic","fa-shekel-sign","fa-shield","fa-shield-cat","fa-shield-dog","fa-shield-halved","fa-shield-heart","fa-shield-virus","fa-ship","fa-shirt","fa-shirtsinbulk","fa-shoe-prints","fa-shoelace","fa-shop","fa-shop-lock","fa-shop-slash","fa-shopify","fa-shopware","fa-shower","fa-shrimp","fa-shuffle","fa-shuttle-space","fa-sign-hanging","fa-signal","fa-signal-messenger","fa-signature","fa-signs-post","fa-sim-card","fa-simplybuilt","fa-sink","fa-sistrix","fa-sitemap","fa-sith","fa-sitrox","fa-sketch","fa-skull","fa-skull-crossbones","fa-skyatlas","fa-skype","fa-slack","fa-slash","fa-sleigh","fa-sliders","fa-slideshare","fa-smog","fa-smoking","fa-snapchat","fa-snowflake","fa-snowman","fa-snowplow","fa-soap","fa-socks","fa-solar-panel","fa-sort","fa-sort-down","fa-sort-up","fa-soundcloud","fa-sourcetree","fa-spa","fa-space-awesome","fa-spaghetti-monster-flying","fa-speakap","fa-speaker-deck","fa-spell-check","fa-spider","fa-spinner","fa-splotch","fa-spoon","fa-spotify","fa-spray-can","fa-spray-can-sparkles","fa-square","fa-square-arrow-up-right","fa-square-behance","fa-square-caret-down","fa-square-caret-left","fa-square-caret-right","fa-square-caret-up","fa-square-check","fa-square-dribbble","fa-square-envelope","fa-square-facebook","fa-square-font-awesome","fa-square-font-awesome-stroke","fa-square-full","fa-square-git","fa-square-github","fa-square-gitlab","fa-square-google-plus","fa-square-h","fa-square-hacker-news","fa-square-instagram","fa-square-js","fa-square-lastfm","fa-square-letterboxd","fa-square-minus","fa-square-nfi","fa-square-odnoklassniki","fa-square-parking","fa-square-pen","fa-square-person-confined","fa-square-phone","fa-square-phone-flip","fa-square-pied-piper","fa-square-pinterest","fa-square-plus","fa-square-poll-horizontal","fa-square-poll-vertical","fa-square-reddit","fa-square-root-variable","fa-square-rss","fa-square-share-nodes","fa-square-snapchat","fa-square-steam","fa-square-threads","fa-square-tumblr","fa-square-twitter","fa-square-up-right","fa-square-upwork","fa-square-viadeo","fa-square-vimeo","fa-square-virus","fa-square-web-awesome","fa-square-web-awesome-stroke","fa-square-whatsapp","fa-square-x-twitter","fa-square-xing","fa-square-xmark","fa-square-youtube","fa-squarespace","fa-stack-exchange","fa-stack-overflow","fa-stackpath","fa-staff-snake","fa-stairs","fa-stamp","fa-stapler","fa-star","fa-star-and-crescent","fa-star-half","fa-star-half-stroke","fa-star-of-david","fa-star-of-life","fa-staylinked","fa-steam","fa-steam-symbol","fa-sterling-sign","fa-stethoscope","fa-sticker-mule","fa-stop","fa-stopwatch","fa-stopwatch-20","fa-store","fa-store-slash","fa-strava","fa-street-view","fa-strikethrough","fa-stripe","fa-stripe-s","fa-stroopwafel","fa-stubber","fa-studiovinari","fa-stumbleupon","fa-stumbleupon-circle","fa-subscript","fa-suitcase","fa-suitcase-medical","fa-suitcase-rolling","fa-sun","fa-sun-plant-wilt","fa-superpowers","fa-superscript","fa-supple","fa-suse","fa-swatchbook","fa-swift","fa-symfony","fa-synagogue","fa-syringe","fa-t","fa-table","fa-table-cells","fa-table-cells-column-lock","fa-table-cells-large","fa-table-cells-row-lock","fa-table-cells-row-unlock","fa-table-columns","fa-table-list","fa-table-tennis-paddle-ball","fa-tablet","fa-tablet-button","fa-tablet-screen-button","fa-tablets","fa-tachograph-digital","fa-tag","fa-tags","fa-tape","fa-tarp","fa-tarp-droplet","fa-taxi","fa-teamspeak","fa-teeth","fa-teeth-open","fa-telegram","fa-temperature-arrow-down","fa-temperature-arrow-up","fa-temperature-empty","fa-temperature-full","fa-temperature-half","fa-temperature-high","fa-temperature-low","fa-temperature-quarter","fa-temperature-three-quarters","fa-tencent-weibo","fa-tenge-sign","fa-tent","fa-tent-arrow-down-to-line","fa-tent-arrow-left-right","fa-tent-arrow-turn-left","fa-tent-arrows-down","fa-tents","fa-terminal","fa-text-height","fa-text-slash","fa-text-width","fa-the-red-yeti","fa-themeco","fa-themeisle","fa-thermometer","fa-think-peaks","fa-threads","fa-thumbs-down","fa-thumbs-up","fa-thumbtack","fa-thumbtack-slash","fa-ticket","fa-ticket-simple","fa-tiktok","fa-timeline","fa-toggle-off","fa-toggle-on","fa-toilet","fa-toilet-paper","fa-toilet-paper-slash","fa-toilet-portable","fa-toilets-portable","fa-toolbox","fa-tooth","fa-torii-gate","fa-tornado","fa-tower-broadcast","fa-tower-cell","fa-tower-observation","fa-tractor","fa-trade-federation","fa-trademark","fa-traffic-light","fa-trailer","fa-train","fa-train-subway","fa-train-tram","fa-transgender","fa-trash","fa-trash-arrow-up","fa-trash-can","fa-trash-can-arrow-up","fa-tree","fa-tree-city","fa-trello","fa-triangle-exclamation","fa-trophy","fa-trowel","fa-trowel-bricks","fa-truck","fa-truck-arrow-right","fa-truck-droplet","fa-truck-fast","fa-truck-field","fa-truck-field-un","fa-truck-front","fa-truck-medical","fa-truck-monster","fa-truck-moving","fa-truck-pickup","fa-truck-plane","fa-truck-ramp-box","fa-tty","fa-tumblr","fa-turkish-lira-sign","fa-turn-down","fa-turn-up","fa-tv","fa-twitch","fa-twitter","fa-typo3","fa-u","fa-uber","fa-ubuntu","fa-uikit","fa-umbraco","fa-umbrella","fa-umbrella-beach","fa-uncharted","fa-underline","fa-uniregistry","fa-unity","fa-universal-access","fa-unlock","fa-unlock-keyhole","fa-unsplash","fa-untappd","fa-up-down","fa-up-down-left-right","fa-up-long","fa-up-right-and-down-left-from-center","fa-up-right-from-square","fa-upload","fa-ups","fa-upwork","fa-usb","fa-user","fa-user-astronaut","fa-user-check","fa-user-clock","fa-user-doctor","fa-user-gear","fa-user-graduate","fa-user-group","fa-user-injured","fa-user-large","fa-user-large-slash","fa-user-lock","fa-user-minus","fa-user-ninja","fa-user-nurse","fa-user-pen","fa-user-plus","fa-user-secret","fa-user-shield","fa-user-slash","fa-user-tag","fa-user-tie","fa-user-xmark","fa-users","fa-users-between-lines","fa-users-gear","fa-users-line","fa-users-rays","fa-users-rectangle","fa-users-slash","fa-users-viewfinder","fa-usps","fa-ussunnah","fa-utensils","fa-v","fa-vaadin","fa-van-shuttle","fa-vault","fa-vector-square","fa-venus","fa-venus-double","fa-venus-mars","fa-vest","fa-vest-patches","fa-viacoin","fa-viadeo","fa-vial","fa-vial-circle-check","fa-vial-virus","fa-vials","fa-viber","fa-video","fa-video-slash","fa-vihara","fa-vimeo","fa-vimeo-v","fa-vine","fa-virus","fa-virus-covid","fa-virus-covid-slash","fa-virus-slash","fa-viruses","fa-vk","fa-vnv","fa-voicemail","fa-volcano","fa-volleyball","fa-volume-high","fa-volume-low","fa-volume-off","fa-volume-xmark","fa-vr-cardboard","fa-vuejs","fa-w","fa-walkie-talkie","fa-wallet","fa-wand-magic","fa-wand-magic-sparkles","fa-wand-sparkles","fa-warehouse","fa-watchman-monitoring","fa-water","fa-water-ladder","fa-wave-square","fa-waze","fa-web-awesome","fa-web-awesome","fa-webflow","fa-weebly","fa-weibo","fa-weight-hanging","fa-weight-scale","fa-weixin","fa-whatsapp","fa-wheat-awn","fa-wheat-awn-circle-exclamation","fa-wheelchair","fa-wheelchair-move","fa-whiskey-glass","fa-whmcs","fa-wifi","fa-wikipedia-w","fa-wind","fa-window-maximize","fa-window-minimize","fa-window-restore","fa-windows","fa-wine-bottle","fa-wine-glass","fa-wine-glass-empty","fa-wirsindhandwerk","fa-wix","fa-wizards-of-the-coast","fa-wodu","fa-wolf-pack-battalion","fa-won-sign","fa-wordpress","fa-wordpress-simple","fa-worm","fa-wpbeginner","fa-wpexplorer","fa-wpforms","fa-wpressr","fa-wrench","fa-x","fa-x-ray","fa-x-twitter","fa-xbox","fa-xing","fa-xmark","fa-xmarks-lines","fa-y","fa-y-combinator","fa-yahoo","fa-yammer","fa-yandex","fa-yandex-international","fa-yarn","fa-yelp","fa-yen-sign","fa-yin-yang","fa-yoast","fa-youtube","fa-z","fa-zhihu"];
var FA_BRANDS = /(^fa-42-group$|^fa-500px$|^fa-accessible-icon$|^fa-accusoft$|^fa-adn$|^fa-adversal$|^fa-affiliatetheme$|^fa-airbnb$|^fa-algolia$|^fa-alipay$|^fa-amazon$|^fa-amazon-pay$|^fa-amilia$|^fa-android$|^fa-angellist$|^fa-angrycreative$|^fa-angular$|^fa-app-store$|^fa-app-store-ios$|^fa-apper$|^fa-apple$|^fa-apple-pay$|^fa-artstation$|^fa-asymmetrik$|^fa-atlassian$|^fa-audible$|^fa-autoprefixer$|^fa-avianex$|^fa-aviato$|^fa-aws$|^fa-bandcamp$|^fa-battle-net$|^fa-behance$|^fa-bilibili$|^fa-bimobject$|^fa-bitbucket$|^fa-bitcoin$|^fa-bity$|^fa-black-tie$|^fa-blackberry$|^fa-blogger$|^fa-blogger-b$|^fa-bluesky$|^fa-bluetooth$|^fa-bluetooth-b$|^fa-bootstrap$|^fa-bots$|^fa-brave$|^fa-brave-reverse$|^fa-btc$|^fa-buffer$|^fa-buromobelexperte$|^fa-buy-n-large$|^fa-buysellads$|^fa-canadian-maple-leaf$|^fa-cc-amazon-pay$|^fa-cc-amex$|^fa-cc-apple-pay$|^fa-cc-diners-club$|^fa-cc-discover$|^fa-cc-jcb$|^fa-cc-mastercard$|^fa-cc-paypal$|^fa-cc-stripe$|^fa-cc-visa$|^fa-centercode$|^fa-centos$|^fa-chrome$|^fa-chromecast$|^fa-cloudflare$|^fa-cloudscale$|^fa-cloudsmith$|^fa-cloudversify$|^fa-cmplid$|^fa-codepen$|^fa-codiepie$|^fa-confluence$|^fa-connectdevelop$|^fa-contao$|^fa-cotton-bureau$|^fa-cpanel$|^fa-creative-commons$|^fa-creative-commons-by$|^fa-creative-commons-nc$|^fa-creative-commons-nc-eu$|^fa-creative-commons-nc-jp$|^fa-creative-commons-nd$|^fa-creative-commons-pd$|^fa-creative-commons-pd-alt$|^fa-creative-commons-remix$|^fa-creative-commons-sa$|^fa-creative-commons-sampling$|^fa-creative-commons-sampling-plus$|^fa-creative-commons-share$|^fa-creative-commons-zero$|^fa-critical-role$|^fa-css3$|^fa-css3-alt$|^fa-cuttlefish$|^fa-d-and-d$|^fa-d-and-d-beyond$|^fa-dailymotion$|^fa-dart-lang$|^fa-dashcube$|^fa-debian$|^fa-deezer$|^fa-delicious$|^fa-deploydog$|^fa-deskpro$|^fa-dev$|^fa-deviantart$|^fa-dhl$|^fa-diaspora$|^fa-digg$|^fa-digital-ocean$|^fa-discord$|^fa-discourse$|^fa-dochub$|^fa-docker$|^fa-draft2digital$|^fa-dribbble$|^fa-dropbox$|^fa-drupal$|^fa-dyalog$|^fa-earlybirds$|^fa-ebay$|^fa-edge$|^fa-edge-legacy$|^fa-elementor$|^fa-ello$|^fa-ember$|^fa-empire$|^fa-envira$|^fa-erlang$|^fa-ethereum$|^fa-etsy$|^fa-evernote$|^fa-expeditedssl$|^fa-facebook$|^fa-facebook-f$|^fa-facebook-messenger$|^fa-fantasy-flight-games$|^fa-fedex$|^fa-fedora$|^fa-figma$|^fa-firefox$|^fa-firefox-browser$|^fa-first-order$|^fa-first-order-alt$|^fa-firstdraft$|^fa-flickr$|^fa-flipboard$|^fa-flutter$|^fa-fly$|^fa-font-awesome$|^fa-fonticons$|^fa-fonticons-fi$|^fa-fort-awesome$|^fa-fort-awesome-alt$|^fa-forumbee$|^fa-foursquare$|^fa-free-code-camp$|^fa-freebsd$|^fa-fulcrum$|^fa-galactic-republic$|^fa-galactic-senate$|^fa-get-pocket$|^fa-gg$|^fa-gg-circle$|^fa-git$|^fa-git-alt$|^fa-github$|^fa-github-alt$|^fa-gitkraken$|^fa-gitlab$|^fa-gitter$|^fa-glide$|^fa-glide-g$|^fa-gofore$|^fa-golang$|^fa-goodreads$|^fa-goodreads-g$|^fa-google$|^fa-google-drive$|^fa-google-pay$|^fa-google-play$|^fa-google-plus$|^fa-google-plus-g$|^fa-google-scholar$|^fa-google-wallet$|^fa-gratipay$|^fa-grav$|^fa-gripfire$|^fa-grunt$|^fa-guilded$|^fa-gulp$|^fa-hacker-news$|^fa-hackerrank$|^fa-hashnode$|^fa-hips$|^fa-hire-a-helper$|^fa-hive$|^fa-hooli$|^fa-hornbill$|^fa-hotjar$|^fa-houzz$|^fa-html5$|^fa-hubspot$|^fa-ideal$|^fa-imdb$|^fa-instagram$|^fa-instalod$|^fa-intercom$|^fa-internet-explorer$|^fa-invision$|^fa-ioxhost$|^fa-itch-io$|^fa-itunes$|^fa-itunes-note$|^fa-java$|^fa-jedi-order$|^fa-jenkins$|^fa-jira$|^fa-joget$|^fa-joomla$|^fa-js$|^fa-jsfiddle$|^fa-jxl$|^fa-kaggle$|^fa-keybase$|^fa-keycdn$|^fa-kickstarter$|^fa-kickstarter-k$|^fa-korvue$|^fa-laravel$|^fa-lastfm$|^fa-leanpub$|^fa-less$|^fa-letterboxd$|^fa-line$|^fa-linkedin$|^fa-linkedin-in$|^fa-linode$|^fa-linux$|^fa-lyft$|^fa-magento$|^fa-mailchimp$|^fa-mandalorian$|^fa-markdown$|^fa-mastodon$|^fa-maxcdn$|^fa-mdb$|^fa-medapps$|^fa-medium$|^fa-medrt$|^fa-meetup$|^fa-megaport$|^fa-mendeley$|^fa-meta$|^fa-microblog$|^fa-microsoft$|^fa-mintbit$|^fa-mix$|^fa-mixcloud$|^fa-mixer$|^fa-mizuni$|^fa-modx$|^fa-monero$|^fa-napster$|^fa-neos$|^fa-nfc-directional$|^fa-nfc-symbol$|^fa-nimblr$|^fa-node$|^fa-node-js$|^fa-npm$|^fa-ns8$|^fa-nutritionix$|^fa-octopus-deploy$|^fa-odnoklassniki$|^fa-odysee$|^fa-old-republic$|^fa-opencart$|^fa-openid$|^fa-opensuse$|^fa-opera$|^fa-optin-monster$|^fa-orcid$|^fa-osi$|^fa-padlet$|^fa-page4$|^fa-pagelines$|^fa-palfed$|^fa-patreon$|^fa-paypal$|^fa-perbyte$|^fa-periscope$|^fa-phabricator$|^fa-phoenix-framework$|^fa-phoenix-squadron$|^fa-php$|^fa-pied-piper$|^fa-pied-piper-alt$|^fa-pied-piper-hat$|^fa-pied-piper-pp$|^fa-pinterest$|^fa-pinterest-p$|^fa-pix$|^fa-pixiv$|^fa-playstation$|^fa-product-hunt$|^fa-pushed$|^fa-python$|^fa-qq$|^fa-quinscape$|^fa-quora$|^fa-r-project$|^fa-raspberry-pi$|^fa-ravelry$|^fa-react$|^fa-reacteurope$|^fa-readme$|^fa-rebel$|^fa-red-river$|^fa-reddit$|^fa-reddit-alien$|^fa-redhat$|^fa-renren$|^fa-replyd$|^fa-researchgate$|^fa-resolving$|^fa-rev$|^fa-rocketchat$|^fa-rockrms$|^fa-rust$|^fa-safari$|^fa-salesforce$|^fa-sass$|^fa-schlix$|^fa-screenpal$|^fa-scribd$|^fa-searchengin$|^fa-sellcast$|^fa-sellsy$|^fa-servicestack$|^fa-shirtsinbulk$|^fa-shoelace$|^fa-shopify$|^fa-shopware$|^fa-signal-messenger$|^fa-simplybuilt$|^fa-sistrix$|^fa-sith$|^fa-sitrox$|^fa-sketch$|^fa-skyatlas$|^fa-skype$|^fa-slack$|^fa-slideshare$|^fa-snapchat$|^fa-soundcloud$|^fa-sourcetree$|^fa-space-awesome$|^fa-speakap$|^fa-speaker-deck$|^fa-spotify$|^fa-square-behance$|^fa-square-dribbble$|^fa-square-facebook$|^fa-square-font-awesome$|^fa-square-font-awesome-stroke$|^fa-square-git$|^fa-square-github$|^fa-square-gitlab$|^fa-square-google-plus$|^fa-square-hacker-news$|^fa-square-instagram$|^fa-square-js$|^fa-square-lastfm$|^fa-square-letterboxd$|^fa-square-odnoklassniki$|^fa-square-pied-piper$|^fa-square-pinterest$|^fa-square-reddit$|^fa-square-snapchat$|^fa-square-steam$|^fa-square-threads$|^fa-square-tumblr$|^fa-square-twitter$|^fa-square-upwork$|^fa-square-viadeo$|^fa-square-vimeo$|^fa-square-web-awesome$|^fa-square-web-awesome-stroke$|^fa-square-whatsapp$|^fa-square-x-twitter$|^fa-square-xing$|^fa-square-youtube$|^fa-squarespace$|^fa-stack-exchange$|^fa-stack-overflow$|^fa-stackpath$|^fa-staylinked$|^fa-steam$|^fa-steam-symbol$|^fa-sticker-mule$|^fa-strava$|^fa-stripe$|^fa-stripe-s$|^fa-stubber$|^fa-studiovinari$|^fa-stumbleupon$|^fa-stumbleupon-circle$|^fa-superpowers$|^fa-supple$|^fa-suse$|^fa-swift$|^fa-symfony$|^fa-teamspeak$|^fa-telegram$|^fa-tencent-weibo$|^fa-the-red-yeti$|^fa-themeco$|^fa-themeisle$|^fa-think-peaks$|^fa-threads$|^fa-tiktok$|^fa-trade-federation$|^fa-trello$|^fa-tumblr$|^fa-twitch$|^fa-twitter$|^fa-typo3$|^fa-uber$|^fa-ubuntu$|^fa-uikit$|^fa-umbraco$|^fa-uncharted$|^fa-uniregistry$|^fa-unity$|^fa-unsplash$|^fa-untappd$|^fa-ups$|^fa-upwork$|^fa-usb$|^fa-usps$|^fa-ussunnah$|^fa-vaadin$|^fa-viacoin$|^fa-viadeo$|^fa-viber$|^fa-vimeo$|^fa-vimeo-v$|^fa-vine$|^fa-vk$|^fa-vnv$|^fa-vuejs$|^fa-watchman-monitoring$|^fa-waze$|^fa-web-awesome$|^fa-webflow$|^fa-weebly$|^fa-weibo$|^fa-weixin$|^fa-whatsapp$|^fa-whmcs$|^fa-wikipedia-w$|^fa-windows$|^fa-wirsindhandwerk$|^fa-wix$|^fa-wizards-of-the-coast$|^fa-wodu$|^fa-wolf-pack-battalion$|^fa-wordpress$|^fa-wordpress-simple$|^fa-wpbeginner$|^fa-wpexplorer$|^fa-wpforms$|^fa-wpressr$|^fa-x-twitter$|^fa-xbox$|^fa-xing$|^fa-y-combinator$|^fa-yahoo$|^fa-yammer$|^fa-yandex$|^fa-yandex-international$|^fa-yarn$|^fa-yelp$|^fa-yoast$|^fa-youtube$|^fa-zhihu$)/;

// Currently active URL input waiting for a page pick
var activeUrlInput = null;

function uid(){return Math.random().toString(36).slice(2,8);}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function faIconCls(name){var n=name||'fa-link';if(n.indexOf('fa-')!==0)n='fa-'+n;var prefix=FA_BRANDS.test(n)?'fab':'fas';return prefix+' fa-fw '+n;}function faIcon(name){return '<i class="'+faIconCls(name)+'" aria-hidden="true"></i>';}

// ── PagePicker integration ──────────────────────────────────────────────────
// Lazily create one picker instance shared by all "Browse" buttons.
var _picker = null;
function getPicker(){
  if(!_picker) _picker = new StartPagePicker(pickerUrl, function(path){
    if(activeUrlInput){ activeUrlInput.value = path; activeUrlInput.dispatchEvent(new Event('input')); }
    activeUrlInput = null;
  });
  return _picker;
}
function openPicker(inputEl){ activeUrlInput = inputEl; getPicker().open(); }

function stSetCols(v){state.cols=parseInt(v);document.getElementById('st-cols-val').textContent=v;if(hiddenCols)hiddenCols.value=v;renderPreview();}
window.stSetCols=stSetCols;

function stAddGroup(){state.groups.push({id:uid(),label:'',items:[]});render();}
window.stAddGroup=stAddGroup;

function stAddItem(gid){var g=state.groups.find(function(x){return x.id===gid;});if(g)g.items.push({id:uid(),label:'',url:'',icon:'link',external:false});render();}

function stRemoveGroup(gid){state.groups=state.groups.filter(function(x){return x.id!==gid;});render();}
window.stRemoveGroup=stRemoveGroup;

function stRemoveItem(gid,iid){var g=state.groups.find(function(x){return x.id===gid;});if(g)g.items=g.items.filter(function(x){return x.id!==iid;});render();}
window.stRemoveItem=stRemoveItem;

function stUpdateGroup(gid,key,val){var g=state.groups.find(function(x){return x.id===gid;});if(g){g[key]=val;syncHidden();renderPreview();}}
window.stUpdateGroup=stUpdateGroup;

function stUpdateItem(gid,iid,key,val){var g=state.groups.find(function(x){return x.id===gid;});if(!g)return;var i=g.items.find(function(x){return x.id===iid;});if(i){i[key]=val;syncHidden();renderPreview();}}
window.stUpdateItem=stUpdateItem;
window.stOpenIconPicker=stOpenIconPicker;

// ── Icon Picker popup ────────────────────────────────────────────────────────
var _ipGid = null, _ipIid = null, _ipBtn = null;
var _ipBuilt = false;

function stOpenIconPicker(btn) {
  _ipGid = btn.dataset.gid;
  _ipIid = btn.dataset.iid;
  _ipBtn = btn;
  var g = state.groups.find(function(x){return x.id===_ipGid;});
  var item = g ? g.items.find(function(x){return x.id===_ipIid;}) : null;
  var current = item ? (item.icon||'') : '';

  var popup = document.getElementById('st-icon-popup');
  var grid  = document.getElementById('st-icon-grid');
  var q     = document.getElementById('st-icon-q');
  var count = document.getElementById('st-icon-count');

  // Build grid once
  if (!_ipBuilt) {
    _ipBuilt = true;
    var frag = document.createDocumentFragment();
    FA_ICONS.forEach(function(ic) {
      var cell = document.createElement('div');
      cell.className = 'st-icon-cell';
      cell.dataset.icon = ic;
      cell.title = ic.replace('fa-','');
      cell.innerHTML = '<i class="'+faIconCls(ic)+'" aria-hidden="true"></i>'
                     + '<span>'+ic.replace('fa-','')+'</span>';
      cell.addEventListener('click', function() { ipSelect(ic); });
      frag.appendChild(cell);
    });
    grid.appendChild(frag);
  }

  // Mark current selection
  grid.querySelectorAll('.st-icon-cell.selected').forEach(function(c){c.classList.remove('selected');});
  if (current) {
    var sel = grid.querySelector('[data-icon="'+current+'"]');
    if (sel) { sel.classList.add('selected'); sel.scrollIntoView({block:'center'}); }
  }

  // Reset search
  q.value = '';
  ipFilter('');
  count.textContent = FA_ICONS.length + ' icons';

  popup.classList.add('open');
  setTimeout(function(){ q.focus(); }, 60);
}

function ipFilter(query) {
  var grid  = document.getElementById('st-icon-grid');
  var count = document.getElementById('st-icon-count');
  var q = query.toLowerCase().replace(/^fa-/,'');
  var cells = grid.querySelectorAll('.st-icon-cell');
  var visible = 0;
  cells.forEach(function(c) {
    var name = c.dataset.icon.replace('fa-','');
    var show = !q || name.indexOf(q) !== -1;
    c.style.display = show ? '' : 'none';
    if (show) visible++;
  });
  count.textContent = visible + ' icons' + (q ? ' matching "'+q+'"' : '');
}

function ipSelect(icon) {
  if (!_ipGid || !_ipIid) return;
  stUpdateItem(_ipGid, _ipIid, 'icon', icon);
  // Update button preview
  if (_ipBtn) {
    var i = _ipBtn.querySelector('i');
    var sp = _ipBtn.querySelector('.st-icon-pick-name');
    if (i)  { i.className = faIconCls(icon); }
    if (sp) { sp.textContent = icon.replace('fa-',''); }
  }
  // Mark selected in grid
  var grid = document.getElementById('st-icon-grid');
  grid.querySelectorAll('.st-icon-cell.selected').forEach(function(c){c.classList.remove('selected');});
  var sel = grid.querySelector('[data-icon="'+icon+'"]');
  if (sel) sel.classList.add('selected');
  ipClose();
}

function ipClose() {
  var popup = document.getElementById('st-icon-popup');
  if (popup) popup.classList.remove('open');
  _ipGid = null; _ipIid = null; _ipBtn = null;
}

// Wire popup events once DOM is ready
(function() {
  function wirePopup() {
    var closeBtn = document.getElementById('st-icon-close');
    var popup    = document.getElementById('st-icon-popup');
    var q        = document.getElementById('st-icon-q');
    if (!closeBtn || !popup || !q) return;
    closeBtn.addEventListener('click', ipClose);
    popup.addEventListener('click', function(e){ if(e.target===popup) ipClose(); });
    document.addEventListener('keydown', function(e){ if(e.key==='Escape') ipClose(); });
    q.addEventListener('input', function(){ ipFilter(this.value.trim()); });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wirePopup);
  } else {
    wirePopup();
  }
})();

function stClearAll(){state.groups=[];render();}
window.stClearAll=stClearAll;

// Icon guessing by page name/url
function guessIcon(name, url){
  var n=(name+url).toLowerCase();
  if(/mail|smtp|email|mailer/.test(n)) return 'envelope';
  if(/backup|db|database|sql/.test(n)) return 'database';
  if(/user|access|role|member|login/.test(n)) return 'users';
  if(/image|photo|media|file|upload/.test(n)) return 'upload';
  if(/chart|stat|analytic|plausible|metric/.test(n)) return 'chart-bar';
  if(/map|geo|location/.test(n)) return 'world';
  if(/sitemap|seo|redirect/.test(n)) return 'world';
  if(/setting|config|option|cog|setup/.test(n)) return 'cog';
  if(/log|debug|tracy/.test(n)) return 'file-text';
  if(/search/.test(n)) return 'link';
  if(/shop|ecommerce|cart|stripe/.test(n)) return 'database';
  if(/collection|catalog|product/.test(n)) return 'grid';
  return 'bolt';
}

function stLoadExample(){
  var btn = document.querySelector('[onclick="stLoadExample()"]');
  if(btn){ btn.disabled=true; btn.textContent=stI18n.loading; }

  fetch(pickerUrl + '?action=modules', {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){return r.json();})
    .then(function(d){
      var items = (d.items||[]).map(function(p){
        return {id:uid(),label:p.label,url:p.url,icon:p.icon||'fa-bolt',external:false};
      });
      // Always add pages + external
      var fixed = [
        {id:uid(),label:'Pages',url:(d.adminUrl||adminUrl)+'page/',icon:'file-text',external:false},
      ];
      state.groups=[
        {id:uid(),label:'Content',items:fixed},
        {id:uid(),label:'Modules',items:items.length ? items : [{id:uid(),label:'No modules found',url:adminUrl,icon:'bolt',external:false}]},
        {id:uid(),label:'External',items:[
          {id:uid(),label:'GitHub',url:'https://github.com/mxmsmnv/Start',icon:'fa-github',external:true},
          {id:uid(),label:'More modules',url:'https://processwire.com/modules/author/maxim-semenov/',icon:'fa-puzzle-piece',external:true}
        ]}
      ];
      render();
    })
    .catch(function(){
      // Fallback with correct admin URL
      state.groups=[
        {id:uid(),label:'Content',items:[
          {id:uid(),label:'Pages',url:adminUrl+'page/',icon:'file-text',external:false}
        ]},
        {id:uid(),label:'External',items:[
          {id:uid(),label:'GitHub',url:'https://github.com/mxmsmnv/Start',icon:'fa-github',external:true},
          {id:uid(),label:'More modules',url:'https://processwire.com/modules/author/maxim-semenov/',icon:'fa-puzzle-piece',external:true}
        ]}
      ];
      render();
    })
    .finally(function(){
      if(btn){ btn.disabled=false; btn.textContent=stI18n.example; }
    });
}
window.stLoadExample=stLoadExample;

function syncHidden(){
  var out=state.groups
    .filter(function(g){return g.items.length>0;})
    .map(function(g){
      return {label:g.label,items:g.items.map(function(i){var o={label:i.label,url:i.url,icon:i.icon};if(i.external)o.external=true;return o;})};
    });
  if(hiddenJson)hiddenJson.value=JSON.stringify(out);
}

function render(){
  var el=document.getElementById('st-groups');
  el.innerHTML='';
  state.groups.forEach(function(g){
    var gDiv=document.createElement('div');
    gDiv.className='st-grp uk-card uk-card-default';
    gDiv.dataset.gid=g.id;

    var hdr=document.createElement('div');
    hdr.className='st-grp-hdr';
    hdr.innerHTML=''
      +'<span class="st-grp-handle uk-text-muted" title="Drag to reorder" uk-icon="icon:menu;ratio:0.7"></span>'
      +'<input class="uk-input st-grp-name" placeholder="Group name (e.g. Content)" value="'+esc(g.label)+'" oninput="stUpdateGroup(\''+g.id+'\',\'label\',this.value)">'
      +'<button type="button" class="st-icon-btn st-icon-btn-danger" onclick="stRemoveGroup(\''+g.id+'\')" title="'+stI18n.remove_group+'">'
      +'<span uk-icon="icon:close;ratio:0.7"></span>'
      +'</button>';
    gDiv.appendChild(hdr);

    var itemsDiv=document.createElement('div');
    itemsDiv.className='st-items st-items-grid';
    itemsDiv.dataset.gid=g.id;

    g.items.forEach(function(item){
      var row=document.createElement('div');
      row.className='st-row';
      row.dataset.iid=item.id;
      // opts removed — icon picker popup used instead

      // URL input + Browse button wired after DOM insert
      var urlInputId = 'st-url-'+item.id;
      row.innerHTML=''
        +'<span class="st-row-handle uk-text-muted" uk-icon="icon:menu;ratio:0.7"></span>'
        +'<input type="text" id="'+urlInputId+'" class="uk-input st-inp-label" placeholder="Label" value="'+esc(item.label)+'" oninput="stUpdateItem(\''+g.id+'\',\''+item.id+'\',\'label\',this.value)">'
        +'<input type="text" id="'+urlInputId+'-url" class="uk-input st-inp-url" placeholder="/admin/setup/..." value="'+esc(item.url)+'" oninput="stUpdateItem(\''+g.id+'\',\''+item.id+'\',\'url\',this.value)">'
        +'<button type="button" class="st-inp-icon st-icon-pick-btn" data-gid="'+g.id+'" data-iid="'+item.id+'" onclick="stOpenIconPicker(this)">'
        +'<i class="'+faIconCls(item.icon||'fa-link')+'" aria-hidden="true"></i>'
        +'<span class="st-icon-pick-name">'+((item.icon||'fa-link').replace('fa-',''))+'</span>'
        +'</button>'
        +'<div class="st-row-actions">'
        +'<label class="st-inp-ext uk-flex uk-flex-middle" style="gap:3px;cursor:pointer">'
        +'<input type="checkbox"'+(item.external?' checked':'')+' class="uk-checkbox" onchange="stUpdateItem(\''+g.id+'\',\''+item.id+'\',\'external\',this.checked)">'
        +'<span class="uk-text-small uk-text-muted">ext</span>'
        +'</label>'
        +'<button type="button" class="st-icon-btn" title="'+stI18n.browse_page+'" data-url-input="'+urlInputId+'-url">'
        +'<span uk-icon="icon:folder;ratio:0.9"></span>'
        +'</button>'
        +'<button type="button" class="st-icon-btn st-icon-btn-danger" onclick="stRemoveItem(\''+g.id+'\',\''+item.id+'\')" title="'+stI18n.remove+'">'
        +'<span uk-icon="icon:close;ratio:0.7"></span>'
        +'</button>'
        +'</div>';

      // Wire Browse button after inserting into DOM
      itemsDiv.appendChild(row);
      var browseBtn = row.querySelector('[data-url-input]');
      if(browseBtn){
        browseBtn.addEventListener('click', function(){
          var inp = document.getElementById(this.dataset.urlInput);
          if(inp) openPicker(inp);
        });
      }
    });

    var addBtn=document.createElement('button');
    addBtn.type='button';
    addBtn.className='st-add-btn';
    addBtn.textContent=stI18n.add_link;
    addBtn.onclick=function(){stAddItem(g.id);};
    itemsDiv.appendChild(addBtn);

    gDiv.appendChild(itemsDiv);
    el.appendChild(gDiv);

    if(typeof Sortable!=='undefined'){
      new Sortable(itemsDiv,{handle:'.st-row-handle',animation:120,draggable:'.st-row',onEnd:function(e){var m=g.items.splice(e.oldIndex,1)[0];g.items.splice(e.newIndex,0,m);syncHidden();renderPreview();}});
    }
  });

  if(typeof Sortable!=='undefined'){
    new Sortable(el,{handle:'.st-grp-handle',animation:120,draggable:'.st-grp',onEnd:function(e){var m=state.groups.splice(e.oldIndex,1)[0];state.groups.splice(e.newIndex,0,m);syncHidden();renderPreview();}});
  }

  syncHidden();
  renderPreview();
  if(window.UIkit) UIkit.update(el);
}

function renderPreview(){
  var cols=state.cols||3;
  var el=document.getElementById('st-preview');
  el.innerHTML='';
  if(!state.groups.length){
    el.innerHTML='<p class="uk-text-small uk-text-muted uk-margin-remove">No links configured yet.</p>';
    return;
  }
  state.groups.forEach(function(g){
    var wrap=document.createElement('div');
    wrap.className='uk-margin-small-bottom';
    if(g.label)wrap.innerHTML='<div class="uk-text-uppercase uk-text-muted uk-margin-small-bottom" style="font-size:10px;font-weight:700;letter-spacing:.06em">'+esc(g.label)+'</div>';
    var grid=document.createElement('div');
    grid.className='st-prev-grid';
    grid.style.gridTemplateColumns='repeat('+cols+',1fr)';
    g.items.forEach(function(i){
      var d=document.createElement('div');
      d.className='st-prev-item uk-card uk-card-default';
      d.innerHTML=faIcon(i.icon||'fa-link')+'<span class="uk-text-small">'+esc(i.label||'\u2026')+'</span>';

      grid.appendChild(d);
    });
    wrap.appendChild(grid);
    el.appendChild(wrap);
  });
}

var initJson=editorEl.dataset.json||'[]';
try{
  var parsed=JSON.parse(initJson);
  if(Array.isArray(parsed)&&parsed.length>0){
    state.groups=parsed.map(function(g){return{id:uid(),label:g.label||'',items:(g.items||[]).map(function(i){return{id:uid(),label:i.label||'',url:i.url||'',icon:i.icon||'link',external:!!i.external};})};});
  }
}catch(e){}

render();
})();
</script>
HTML;
  }
}

// =============================================================================
// StartPagePicker — bundled page-tree picker helper
// Prefixed "Start" to avoid class name collision if PagePicker.php is also
// present elsewhere in the project.
// =============================================================================

class StartPagePicker
{
  protected string $modalId  = 'ppk-modal';
  protected string $treeId   = 'ppk-tree';
  protected string $searchId = 'ppk-q';
  protected string $closeId  = 'ppk-close';

  protected string $baseUrl;

  protected array $excludePaths = [
    '/trash/',
    '/repeater_',
    '/repeaters/',
    'for-field-',
    'for-page-',
  ];

  public function __construct(string $baseUrl)
  {
    $this->baseUrl = rtrim($baseUrl, '/') . '/';
    // Admin pages are intentionally included — user may want to link to admin sections
  }

  // ── AJAX ────────────────────────────────────────────────────────

  public function ajax(): string
  {
    /** @var User $user */
    $user = wire('user');
    if (!$user->isLoggedin()) {
      wire('config')->ajax = true;
      header('Content-Type: application/json', true, 403);
      return json_encode(['error' => 'Forbidden']);
    }

    wire('config')->ajax = true;
    header('Content-Type: application/json');

    $q  = trim((string) wire('input')->get->q);
    $pw = wire('pages');

    if ($q !== '') {
      $qSafe    = wire('sanitizer')->selectorValue($q);
      $selector = "title|name%=$qSafe, limit=80, include=all, sort=title";
    } else {
      $parentId = (int) wire('input')->get('parent_id');
      $parentId = $parentId > 0 ? $parentId : 1;
      $selector = "parent_id=$parentId, include=all, limit=200, sort=sort";
    }

    $items = [];
    try {
      foreach ($pw->find($selector) as $p) {
        $path = $p->path;
        $skip = false;
        foreach ($this->excludePaths as $ex) {
          if (strpos($path, $ex) !== false) { $skip = true; break; }
        }
        $tplName = $p->template ? $p->template->name : '';
        if (strpos($tplName, 'repeater_') === 0) $skip = true;
        if ($skip) continue;

        $items[] = [
          'id'          => $p->id,
          'name'        => (string) $p->get('title|name'),
          'path'        => $path,
          'hasChildren' => $p->numChildren > 0,
        ];
      }
    } catch (\Exception $e) { /* silent */ }

    return json_encode(['items' => $items]);
  }

  // ── Modal HTML + CSS + JS ────────────────────────────────────────

  /**
   * Render the picker modal. Called automatically by the Start editor JS
   * via new StartPagePicker(url, callback).open() — the modal is injected
   * into the DOM on first use rather than on page load.
   *
   * This method is kept for standalone / external use:
   *   echo $picker->renderModal();
   */
  public function renderModal(): string
  {
    $baseUrlJ    = json_encode($this->baseUrl);
    $modalId     = $this->modalId;
    $treeId      = $this->treeId;
    $searchId    = $this->searchId;
    $closeId     = $this->closeId;
    $tSelectPage = self::t('select_page');
    $tSearchPages= self::t('search_pages');
    $tLoading    = self::t('loading');

    return <<<HTML
<style>
/* StartPagePicker modal — PW CSS custom properties for light/dark theme */
#{$modalId}{display:none;position:fixed;inset:0;z-index:99999;background:var(--pw-modal-color,rgba(0,0,0,.45));align-items:center;justify-content:center}
#{$modalId}.ppk-open{display:flex}
#ppk-box{background:var(--pw-blocks-background);border:1px solid var(--pw-border-color);border-radius:10px;box-shadow:0 8px 40px rgba(0,0,0,.25);width:560px;max-width:calc(100vw - 32px);max-height:78vh;display:flex;flex-direction:column;overflow:hidden}
#ppk-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px 12px;border-bottom:1px solid var(--pw-border-color);font-weight:600;font-size:15px;flex-shrink:0;color:var(--pw-text-color)}
#{$closeId}{background:none;border:none;font-size:22px;cursor:pointer;color:var(--pw-muted-color);line-height:1;padding:2px 6px}
#{$closeId}:hover{color:var(--pw-text-color)}
#ppk-search{padding:10px 16px;border-bottom:1px solid var(--pw-border-color);flex-shrink:0}
#ppk-search input{width:100%;padding:7px 12px;border:1px solid var(--pw-border-color);border-radius:6px;font-size:13px;box-sizing:border-box;outline:none;background:var(--pw-inputs-background);color:var(--pw-text-color)}
#ppk-search input:focus{border-color:var(--pw-main-color)}
#{$treeId}{overflow-y:auto;flex:1}
.ppk-row{border-bottom:1px solid var(--pw-inputs-background)}
.ppk-item{display:flex;align-items:center;width:100%;border:none;background:none;padding:0;cursor:pointer;text-align:left;font-size:13px;color:var(--pw-text-color);min-height:36px;box-sizing:border-box}
.ppk-item:hover{background:var(--pw-main-background)}
.ppk-toggle{width:36px;min-width:36px;height:36px;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--pw-border-color);font-size:10px;line-height:1}
.ppk-toggle.has-ch{color:var(--pw-muted-color)}
.ppk-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding-right:8px}
.ppk-url{font-size:11px;color:var(--pw-muted-color);white-space:nowrap;flex-shrink:0;max-width:170px;overflow:hidden;text-overflow:ellipsis;padding-right:6px}
.ppk-pick{width:40px;min-width:40px;height:36px;padding:0;border:none;border-left:1px solid var(--pw-border-color);background:none;color:var(--pw-main-color);font-size:15px;cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center}
.ppk-pick:hover{background:var(--pw-main-background)}
.ppk-flat{display:flex;align-items:center;width:100%;border:none;border-bottom:1px solid var(--pw-inputs-background);background:none;padding:0 0 0 36px;cursor:pointer;text-align:left;font-size:13px;color:var(--pw-text-color);min-height:36px;box-sizing:border-box}
.ppk-flat:hover{background:var(--pw-main-background)}
.ppk-flat .ppk-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding-right:8px}
.ppk-flat .ppk-url{font-size:11px;color:var(--pw-muted-color);white-space:nowrap;flex-shrink:0;padding-right:14px;max-width:200px;overflow:hidden;text-overflow:ellipsis}
.ppk-children{padding-left:16px;border-left:2px solid var(--pw-border-color);margin-left:18px}
.ppk-loading{text-align:center;padding:28px;color:var(--pw-muted-color);font-size:13px}
</style>

<div id="{$modalId}">
  <div id="ppk-box">
    <div id="ppk-head">
      {$tSelectPage}
      <button id="{$closeId}">&#215;</button>
    </div>
    <div id="ppk-search">
      <input type="text" id="{$searchId}" placeholder="{$tSearchPages}" autocomplete="off">
    </div>
    <div id="{$treeId}"><div class="ppk-loading">{$tLoading}</div></div>
  </div>
</div>

HTML;
  }
}