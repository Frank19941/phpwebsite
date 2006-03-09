<?php

/**
 * Image class that builds off the File_Command class
 * Assists with image files
 *
 * @author Matthew McNaney <matt at tux dot appstate dot edu>
 * @version $Id$
 */


PHPWS_Core::initModClass('filecabinet', 'File_Common.php');

class PHPWS_Image extends File_Common {

    var $width            = NULL;
    var $height           = NULL;
    var $alt              = NULL;
    var $border           = 0;
    var $thumbnail_source = 0;
    var $_max_size        = MAX_IMAGE_SIZE;
    var $_max_width       = MAX_IMAGE_WIDTH;
    var $_max_height      = MAX_IMAGE_HEIGHT;
    var $_classtype       = 'image';
    var $_thumbnail_dir   = NULL;

    function PHPWS_Image($id=NULL)
    {
        $this->loadAllowedTypes();
        $this->_file_type = 'image';

        if (empty($id)) {
            return;
        }
    
        $this->setId((int)$id);
        $result = $this->init();
        if (PEAR::isError($result)) {
            $this->_errors[] = $result;
        } elseif (empty($result)) {
            return PHPWS_Error::get(FC_IMG_NOT_FOUND, 'filecabinet', 'PHPWS_Image');
        }
    }

    function init()
    {
        if (!isset($this->id)) {
            return FALSE;
        }

        $db = & new PHPWS_DB('images');
        return $db->loadObject($this);
    }

    function getTag()
    {
        $tag[] = '<img';

        $tag[] = 'src="'    . $this->getPath() . '"';
        $tag[] = 'alt="'    . $this->getAlt(TRUE)   . '"';
        $tag[] = 'title="'  . $this->title . '"';
        $tag[] = 'width="'  . $this->width     . '"';
        $tag[] = 'height="' . $this->height    . '"';
        $tag[] = 'border="' . $this->border    . '"';
        $tag[] = '/>';
        return implode(' ', $tag);
    }

    function &getThumbnail()
    {
        if ($this->thumbnail_source == $this->id) {
            return $this;
        }

        $thumbnail = & new PHPWS_Image;
        $db = & new PHPWS_DB('images');
        $db->addWhere('thumbnail_source', $this->id);
        $db->loadObject($thumbnail);
        return $thumbnail;

    }

    function getLink($newTarget=FALSE)
    {
        $tag[] = '<a href="';
        $tag[] = $this->getPath();
        $tag[] = '"';
        if ($newTarget) {
            $tag[] = ' target="_blank"';
        }

        $tag[] = '>';
        $tag[] = $this->title;
        $tag[] = '</a>';

        return implode('', $tag);
    }

    function getJSView($thumbnail=FALSE)
    {
        if ($thumbnail) {
            $oThumbnail = $this->getThumbnail();
            $tag = $oThumbnail->getTag();
            $values['label'] = $tag;
        } else {
            $values['label'] = $this->title;
        }

        $values['address'] = $this->getPath();
        $values['width'] = $this->width + 20;
        $values['height'] = $this->height + 20;

        return Layout::getJavascript('open_window', $values);
    }

    function allowType($type=NULL)
    {
        $typeList = unserialize(ALLOWED_IMAGE_TYPES);
        if (!isset($type)) {
            $type = $this->file_type;
        }
        
        return in_array($type, $typeList);
    }


    function setType($type)
    {
        if (is_numeric($type)) {
            $new_type = image_type_to_mime_type($type);
            $this->file_type = $new_type;
        } else {
            $this->file_type = $type;
        }
    }

    function setWidth($width)
    {
        $this->width = $width;
    }

    function setHeight($height)
    {
        $this->height = $height;
    }

    function setBounds($path=NULL)
    {
        if (empty($path)) {
            $path = $this->getPath();
        }

        $bound = @getimagesize($path);

        if (!is_array($bound)) {
            return PHPWS_Error::get(FC_BOUND_FAILED, 'filecabinet',
                                    'PHPWS_image::setBounds', $this->getPath());
        }

        $size = @filesize($path);
        $this->setSize($size);
        $this->setWidth($bound[0]);
        $this->setHeight($bound[1]);
        $this->setType($bound[2]);
        return TRUE;
    }

    function setAlt($alt)
    {
        $this->alt = $alt;
    }

    function getAlt($check=FALSE)
    {
        if ((bool)$check && empty($this->alt) && isset($this->title)) {
            return $this->title;
        }

        return $this->alt;
    }

    function setMaxWidth($width)
    {
        $this->_max_width = (int)$width;
    }

    function setMaxHeight($height)
    {
        $this->_max_height = (int)$height;
    }

    function setBorder($border)
    {
        $this->border = $border;
    }

    function getPath()
    {
        $directory = str_replace(PHPWS_HOME_DIR, './', $this->file_directory);
        return sprintf('%s%s', $directory, $this->file_name);
    }

    function getFullDirectory()
    {
        if (empty($this->file_directory)) {
            return NULL;
        }
        return sprintf('%s%s', $this->file_directory, $this->file_name);
    }

    function getDefaultDirectory()
    {
        return PHPWS_Settings::get('filecabinet', 'base_img_directory');
    }

    function allowWidth($imagewidth=NULL)
    {
        if (!isset($imagewidth)) {
            $imagewidth = &$this->width;
        }

        return ($imagewidth <= $this->_max_width) ? TRUE : FALSE;
    }

    function allowHeight($imageheight=NULL)
    {
        if (!isset($imageheight)) {
            $imageheight = &$this->height;
        }

        return ($imageheight <= $this->_max_height) ? TRUE : FALSE;
    }


    function allowDimensions()
    {
        if (!$this->allowWidth() || !$this->allowHeight()) {
            return FALSE;
        } else {
            return TRUE;
        }
    }


    function save($no_dupes=TRUE, $write=TRUE)
    {
        if (empty($this->file_directory)) {
            $this->file_directory = $this->getDefaultDirectory();
        }

        if (empty($this->alt)) {
            if (empty($this->title)) {
                $this->title = $this->file_name;
            }
            $this->alt = $this->title;
        }

        if ($write) {
            $result = $this->write();
            if (PEAR::isError($result)) {
                return $result;
            }
        }

        $db = & new PHPWS_DB('images');

        if ((bool)$no_dupes && empty($this->id)) {
            $db->addWhere('file_name',  $this->file_name);
            $db->addWhere('file_directory', $this->file_directory);
            $db->addColumn('id');
            $result = $db->select('one');
            if (PEAR::isError($result)) {
                return $result;
            } elseif (isset($result) && is_numeric($result)) {
                $this->id = $result;
                return TRUE;
            }

            $db->reset();
        }
        return $db->saveObject($this);
    }
 

    function isImage($type)
    {
        $imageTypes = array('image/jpeg',
                            'image/jpg',
                            'image/pjpeg',
                            'image/png',
                            'image/x-png',
                            'image/gif',
                            'image/wbmp');

        return in_array(trim($type), $imageTypes);
    }

    function delete($delete_thumbnail=TRUE)
    {
        $db = & new PHPWS_DB('images');
        $db->addWhere('id', $this->id);
        $result = $db->delete();
        if (PEAR::isError($result)) {
            return $result;
        }
        
        $path = $this->getFullDirectory();

        if (PEAR::isError($path) || empty($path)) {
            return $path;
        }

        unlink($path);

        if ($this->thumbnail_source != $this->id) {
            $tn = & new PHPWS_Image;
            $db->reset();
            $db->addWhere('thumbnail_source', $this->id);
            $result = $db->loadObject($tn);
            if (empty($result)) {
                return TRUE;
            } elseif (PEAR::isError($result)) {
                return $result;
            }
            return $tn->delete(FALSE);
        }

        return TRUE;
    }

    function getXML()
    {
        $content[] = '<image>';
        $content[] = '<src>' . $this->getPath() . '</src>';
        $content[] = '<width>' . $this->width . '</width>';
        $content[] = '<height>' . $this->height . '</height>';
        $content[] = '<title>' . $this->title . '</title>';
        $content[] = '<alt>' . $this->getAlt() . '</alt>';
        $content[] = '<desc>' . $this->getDescription(TRUE) . '</desc>';
        $content[] = '</image>';
        return implode("\n", $content);
    }


    function loadAllowedTypes()
    {
        $this->_allowed_types = unserialize(ALLOWED_IMAGE_TYPES);
    }

    function getRowTags()
    {
        $vars['action'] = 'admin_edit_image';
        $vars['image_id'] = $this->id;
        $links[] = PHPWS_Text::secureLink(_('Edit'), 'filecabinet', $vars);

        $vars['action'] = 'admin_delete_image';
        $vars['image_id'] = $this->id;
        $js['QUESTION'] = _('Are you sure you want to delete this image?');
        $js['ADDRESS']  = PHPWS_Text::linkAddress('filecabinet', $vars, true);
        $js['LINK']     = _('Delete');
        $links[] = javascript('confirm', $js);

        $tpl['ACTION'] = implode(' | ', $links);
        $tpl['SIZE'] = $this->getSize(TRUE);
        $tpl['FILE_NAME'] = $this->file_name;
        $tpl['THUMBNAIL'] = $this->getJSView(TRUE);
        $tpl['TITLE']     = $this->title;

        return $tpl;
    }

}

?>