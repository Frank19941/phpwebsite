<?php

/**
 * @version $Id$
 * @author Matthew McNaney <mcnaney at gmail dot com>
 */
class PS_Forms
{
    public $template = null;
    public $ps = null;
    public $tpl_list = null;

    public function editPage()
    {
        if (!$this->ps->page->id) {
            if (!empty($this->ps->page->_tpl)) {
                $this->pageLayout();
            } elseif (isset($_GET['fname'])) {
                $this->pickTemplate();
            } else {
                $this->pickFolder();
            }
            return;
        } else {
            $this->pageLayout();
        }
    }

    public function loadTemplates()
    {
        \phpws\PHPWS_Core::initModClass('pagesmith', 'PS_Template.php');
        if (!empty($this->tpl_list)) {
            return true;
        }

        $tpl_dir = $this->ps->pageTplDir();
        $templates = PHPWS_File::listDirectories($tpl_dir);

        if (empty($templates)) {
            PHPWS_Error::log(PS_TPL_DIR, 'pagesmith', 'PS_Forms::loadTemplates', $tpl_dir);
            return false;
        }

        foreach ($templates as $tpl) {
            $pg_tpl = new PS_Template($tpl);
            if ($pg_tpl->data) {
                $this->tpl_list[$tpl] = $pg_tpl;
            }
        }
        return true;
    }

    /**
     * Displays the page layout and lets user enter text fields, blocks, etc.
     */
    public function pageLayout()
    {
        javascript('jquery');
        javascript('jquery_ui');
        javascript('ckeditor');

        Layout::addStyle('pagesmith', 'admin.css');
        Layout::addJSHeader('<script type="text/javascript" src="' .
                PHPWS_SOURCE_HTTP . 'mod/pagesmith/javascript/pageedit/script.js"></script>', 'pageedit');

        Layout::addStyle('pagesmith');
        $page = $this->ps->page;

        $pg_tpl_name = & $page->_tpl->name;
        $this->ps->killSaved($page->id);
        if (!empty($page->_content)) {
            foreach ($page->_content as $key => $cnt) {
                if (!PageSmith::checkLorum($cnt)) {
                    $_SESSION['PS_Page'][$page->id][$key] = $cnt;
                }
            }
        }

        $form = new PHPWS_Form('pagesmith');
        $form->addHidden('module', 'pagesmith');
        $form->addHidden('aop', 'post_page');
        $form->addHidden('tpl', $page->template);
        $form->addHidden('pid', $page->parent_page);

        $template_list = $this->ps->getTemplateList();

        $form->addSelect('template_list', $template_list);
        $form->setMatch('template_list', $page->template);
        $form->addSubmit('change_tpl', 'Change template');

        if ($page->id) {
            $form->addHidden('id', $page->id);
        }

        if (empty($page->_tpl) || $page->_tpl->error) {
            $this->ps->content = 'Unable to load page template.';
            return;
        }
        $form->addSubmit('submit', 'Save page');
        $form->setClass('submit', 'btn btn-success');

        $page->loadKey();

        if ($page->_key->id && $page->_key->show_after) {
            $publish_date = $page->_key->show_after;
        } else {
            $publish_date = time();
        }

        $this->pageTemplateForm($form);

        $tpl = $form->getTemplate();
        $tpl['PUBLISH_DATE_LABEL'] = 'Show page after this date and time';
        $tpl['PUBLISH_VALUE'] = strftime('%Y-%m-%dT%H:%M:%S', $publish_date);

        $tpl['PAGE_TITLE'] = $page->title;
        $jsvars['page_title_input'] = 'pagesmith_title';
        $jsvars['page_title_id'] = sprintf('%s-page-title', $pg_tpl_name);
        javascriptMod('pagesmith', 'pagetitle', $jsvars);

        $tpl['HIDE_CHECK'] = $page->hide_title ? 'checked="checked"' : null;

        if (!empty($page->_orphans)) {
            $tpl['ORPHAN_LINK'] = sprintf('<a href="%s#orphans">%s</a>', \phpws\PHPWS_Core::getCurrentUrl(), 'Orphans');
            $tpl['ORPHANS'] = $this->listOrphans($page->_orphans);
        }

        // We wrap the textarea in a form just so ckeditor will allow use of the "Save" button.
        $modal = new \phpws2\Modal('edit-section', '<form><textarea id="block-edit-textarea"></textarea></form>', 'Edit text area');
        $modal->addButton('<button id="save-page" class="btn btn-success">' . 'Save' . '</button>');

        $modal->setWidthPercentage(90);
        $tpl['CONTENT_MODAL'] = $modal->__toString();

        $title_modal = new \phpws2\Modal('edit-title', '<input class="form-control" type="text" id="page-title-input" name="page_title" value="" />', 'Edit page title');
        $title_modal->addButton('<button id="save-title" class="btn btn-success">' . 'Save' . '</button>');
        $tpl['TITLE_MODAL'] = $title_modal->__toString();
        $this->ps->content = PHPWS_Template::process($tpl, 'pagesmith', 'page_form.tpl');
    }

    private function listOrphans($orphans)
    {
        javascript('jquery');
        javascriptMod('pagesmith', 'delete_orphan');
        $tpl = array();
        $tpl['TITLE'] = 'Orphaned content';
        foreach ($orphans as $orf) {
            $row = array();
            switch ($orf['sectype']) {
                case 'text':
                case 'header':
                    $row['ID'] = 'text-' . $orf['id'];
                    $sec = new PS_Text;
                    $empty_content = empty($orf['content']);
                    break;

                case 'image':
                case 'document':
                case 'media':
                case 'block':
                    $row['ID'] = 'block-' . $orf['id'];
                    $sec = new PS_Block;
                    $empty_content = empty($orf['type_id']);
                    break;
            }
            \phpws\PHPWS_Core::plugObject($sec, $orf);

            if ($empty_content) {
                $row['CONTENT'] = sprintf('<em>%s</em>', 'Empty content. Consider deletion.');
            } else {
                $row['CONTENT'] = $sec->getContent();
            }

            $row['OPTIONS'] = sprintf('<a href="#" onclick="delete_orphan(\'%s\'); return false">%s</a>', $row['ID'], 'Delete orphan');
            $tpl['orphan-list'][] = $row;
        }

        return PHPWS_Template::process($tpl, 'pagesmith', 'orphans.tpl');
    }

    public function editPageHeader()
    {
        $section_name = $_GET['section'];
        $pid = $_GET['id'];

        $content = @$_SESSION['PS_Page'][$pid][$section_name];
        $form = new PHPWS_Form('edit');
        $form->addHidden('pid', $pid);
        $form->addHidden('tpl', $_GET['tpl']);
        $form->addHidden('module', 'pagesmith');
        $form->addHidden('aop', 'post_header');
        $form->addHidden('section_name', $section_name);
        $form->addText('header', $content);
        $form->setLabel('header', 'Header');
        $form->setSize('header', 40);
        $form->addSubmit('Update');
        $tpl = $form->getTemplate();

        $tpl['CANCEL'] = javascript('close_window');
        $this->ps->title = 'Edit header';
        $this->ps->content = PHPWS_Template::process($tpl, 'pagesmith', 'edit_header.tpl');
    }

    public function pageList()
    {
        Layout::addStyle('pagesmith');
        \phpws\PHPWS_Core::initCoreClass('DBPager.php');
        \phpws\PHPWS_Core::initModClass('pagesmith', 'PS_Page.php');

        $pgtags['ACTION_LABEL'] = 'Action';
        $createText = 'New Page';
        $pgtags['NEW'] = "<a href='index.php?module=pagesmith&amp;aop=menu&amp;tab=new' class='button'>$createText/a>";
        if (PHPWS_Settings::get('pagesmith', 'text_only_default')) {
            $pgtags['NEW_PAGE_LINK_URI'] = 'index.php?module=pagesmith&aop=pick_template&tpl=text_only&pid=0&authkey=' . \Current_User::getAuthKey();
        } else {
            $pgtags['NEW_PAGE_LINK_URI'] = "index.php?module=pagesmith&amp;aop=menu&amp;tab=new";
        }
        $pgtags['NEW_PAGE_LINK_TEXT'] = $createText;

        $pager = new DBPager('ps_page', 'PS_Page');
        $pager->addWhere('deleted', 0);
        $pager->cacheQueries();
        $pager->addPageTags($pgtags);
        $pager->setModule('pagesmith');
        $pager->setTemplate('page_list.tpl');
        $pager->addRowTags('row_tags');
        $pager->setEmptyMessage('No pages have been created.');
        $pager->setSearch('title', 'id');
        $pager->addSortHeader('id', 'Id');
        $pager->addSortHeader('title', 'Title');
        $pager->addSortHeader('create_date', 'Created');
        $pager->addSortHeader('last_updated', 'Updated');
        $pager->addWhere('parent_page', 0);
        $pager->setDefaultLimit(10);
        $pager->setDefaultOrder('last_updated', 'desc');
        $this->ps->title = 'Pages';
        $pager->initialize();
        $this->pullUpdated($pager);
        $this->ps->content = $pager->get();
    }

    private function pullUpdated($pager)
    {
        if (empty($pager->display_rows)) {
            return null;
        }
        foreach ($pager->display_rows as $row_id => $page) {
            $keys[$page->key_id] = $row_id;
        }
        $db = \phpws2\Database::newDB();
        $t = $db->addTable('phpws_key');
        $t->addFieldConditional('id', array_keys($keys), 'in');
        $result = $db->select();
        foreach ($result as $row) {
            $id = $keys[$row['id']];
            $pager->display_rows[$id]->_updater = $row['updater'];
        }
    }

    public function pageTemplateForm(PHPWS_Form $form)
    {
        $edit_button = false;
        $page = $this->ps->page;

        $page->_tpl->loadStyle();
        $vars['id'] = $page->id;
        $vars['tpl'] = $page->template;

        foreach ($page->_sections as $name => $section) {
            $form->addHidden('sections', $name);
            $content = $section->getContent();
            if (empty($content) && ($section->sectype == 'text' || $section->sectype == 'header')) {
                $section->loadFiller();
                $tpl[$name] = $section->getContent();
            } else {
                $tpl[$name] = $content;
            }

            switch ($section->sectype) {
                case 'header':
                    $js['label'] = 'Edit header';
                    $js['link_title'] = 'Change header';
                    $vars['aop'] = 'edit_page_header';
                    $js['width'] = 400;
                    $js['height'] = 200;
                    $js['class'] = 'change-link';
                    $edit_button = true;
                    break;

                case 'text':
                    $edit_message = 'Click here to edit this content';
                    $tpl[$name . '_admin'] = " data-page-id=\"$page->id\" data-block-id=\"$section->id\"";
                    break;
            }
        }

        $template_file = $page->_tpl->page_path . 'page.tpl';

        if (empty($page->title)) {
            $tpl['page_title'] = '<span id="page-title-edit" data-new="true" style="cursor:pointer;color : #969696">' . dgettext('pagesmith', 'Page Title (click to edit)') . '</span>';
        } else {
            $tpl['page_title'] = '<span id="page-title-edit" style="cursor:pointer;">' . $page->title . '</span>';
        }
        $pg_tpl = PHPWS_Template::process($tpl, 'pagesmith', $template_file);

        $form->addTplTag('PAGE_TEMPLATE', $pg_tpl);
    }

    public function pickTemplate()
    {
        Layout::addStyle('pagesmith');
        $this->ps->title = 'Pick a template';
        $this->loadTemplates();

        if (empty($this->tpl_list)) {
            $this->ps->content = 'Could not find any page templates. Please check your error log.';
        }

        @$fname = $_GET['fname'];

        foreach ($this->tpl_list as $pgtpl) {
            if ($fname && !empty($pgtpl->folders) && !in_array($fname, $pgtpl->folders)) {
                continue;
            }
            $tpl['page-templates'][] = $pgtpl->pickTpl($this->ps->page->parent_page);
        }

        $tpl['BACK'] = PHPWS_Text::secureLink('Back to style selection', 'pagesmith', array('aop' => 'menu', 'tab' => 'new'));
        $this->ps->content = PHPWS_Template::process($tpl, 'pagesmith', 'pick_template.tpl');
    }

    public function pickFolder()
    {
        @include PHPWS_SOURCE_DIR . 'mod/pagesmith/conf/folder_icons.php';
        $folder_list = array();

        Layout::addStyle('pagesmith');
        $this->loadTemplates();
        foreach ($this->tpl_list as $template) {
            if ($template->folders) {
                foreach ($template->folders as $folder_name) {
                    if (isset($folder_list[$folder_name])) {
                        $folder_list[$folder_name] ++;
                    } else {
                        $folder_list[$folder_name] = 1;
                    }
                }
            }
        }

        $vars['aop'] = 'menu';
        $vars['pid'] = $this->ps->page->parent_page;
        foreach ($folder_list as $name => $count) {
            $vars['fname'] = $name;
            $image = @$folder_icon[$name];
            if (!$image) {
                $image = 'folder_contents.png';
            }
            $vars['tab'] = 'new';
            $link = PHPWS_Text::linkAddress('pagesmith', $vars, true);
            $tpl['folders'][] = array('TITLE' => ucwords(str_replace('-', '&nbsp;', $name)),
                'IMAGE' => sprintf('<a href="%s"><img src="%smod/pagesmith/img/folder_icons/%s" /></a>', $link, PHPWS_SOURCE_HTTP, $image),
                'COUNT' => sprintf(dngettext('pagesmith', '%s template', '%s templates', $count), $count));
        }

        $this->ps->title = 'Choose a style';
        $this->ps->content = PHPWS_Template::process($tpl, 'pagesmith', 'pick_folder.tpl');
    }

    public function settings()
    {
        $form = new PHPWS_Form('ps-settings');
        $form->addHidden('module', 'pagesmith');
        $form->addHidden('aop', 'post_settings');
        $form->addSubmit('Save');

        $form->addCheck('auto_link', 1);
        $form->setMatch('auto_link', PHPWS_Settings::get('pagesmith', 'auto_link'));
        $form->setLabel('auto_link', 'Add menu link for new pages.');

        $form->addCheck('back_to_top', 1);
        $form->setMatch('back_to_top', PHPWS_Settings::get('pagesmith', 'back_to_top'));
        $form->setLabel('back_to_top', dgettext('pagesmith', 'Add "Back to top" links at page bottom.'));

        $form->addCheck('create_shortcuts', 1);
        $form->setMatch('create_shortcuts', PHPWS_Settings::get('pagesmith', 'create_shortcuts'));
        $form->setLabel('create_shortcuts', 'Create Access shortcuts automatically');


        $form->addTplTag('LENGTH_EXAMPLE', 'pagesmith/2 => index.php?module=pagesmith&uop=view_page&id=2');


        $this->ps->title = 'PageSmith Settings';

        $tpl['SHORTEN_MENU_LINKS'] = PHPWS_Text::secureLink('Shorten all menu links', 'pagesmith', array('aop' => 'shorten_links'));
        $tpl['SHORTEN_MENU_LINKS_URI'] = PHPWS_Text::linkAddress('pagesmith', array('aop' => 'shorten_links'), true);

        $tpl['LENGTHEN_MENU_LINKS'] = PHPWS_Text::secureLink('Lengthen all menu links', 'pagesmith', array('aop' => 'lengthen_links'));
        $tpl['LENGTHEN_MENU_LINKS_URI'] = PHPWS_Text::linkAddress('pagesmith', array('aop' => 'lengthen_links'), true);

        $form->mergeTemplate($tpl);

        $this->ps->content = PHPWS_Template::process($form->getTemplate(), 'pagesmith', 'settings.tpl');
    }

    public function purge()
    {
        javascript('jquery');
        $script = PHPWS_SOURCE_HTTP . 'mod/pagesmith/javascript/purge/script.js';
        \phpws2\Pager::prepare();
        \Layout::addJSHeader("<script type='text/javascript' src='$script'><script>");
        $template = new \phpws2\Template;
        $template->setModuleTemplate('pagesmith', 'purge.html');
        $this->ps->title = 'Purge deleted pages';
        $this->ps->content = $template->get();
    }

}
