<?php

/**
 * Class to control the user permissioning
 *
 * @version $Id$
 * @author Matt McNaney <matt at tux dot appstate dot edu>
 * @package User Module
 */

class Users_Permission {
    var $permissions = NULL;
    var $groups      = NULL;
  
    function Users_Permission($groups=NULL)
    {
        $this->groups = $groups;
    }

    function allowedItem($module, $item_id, $itemname=NULL)
    {
        if (!isset($itemname)) {
            $itemname = $module;
        }

        // Get the permission level for the group
        $permissionLvl = $this->getPermissionLevel($module);

        switch ($permissionLvl) {
        case NO_PERMISSION:
            return FALSE;
            break;

        case UNRESTRICTED_PERMISSION:
            return TRUE;
            break;

        case RESTRICTED_PERMISSION:
            // If no items exist in the permission object, return FALSE
            if (!isset($this->permissions[$module]['items']) ||
                !isset($this->permissions[$module]['items'][$itemname])) {
                return FALSE;
            } elseif (in_array($item_id, $this->permissions[$module]['items'][$itemname])){
                return TRUE;
            } else {
                return FALSE;
            }
            break;
        }
    }

    // may change this to go the key edit route
    function getItemGroups(&$key)
    {
        $table = Users_Permission::getItemPermissionTableName($key->module);
        $db = & new PHPWS_DB($table);
        $db->addWhere('item_name', $key->item_name);
        $db->addWhere('item_id', $key->item_id);
        $db->addColumn('group_id');
        return $db->select('col');
    }

    function allow($module, $subpermission=NULL, $item_id=NULL, $itemname=NULL)
    {

        // If permissions object is not set, load it
        if (!isset($this->permissions[$module])) {
            $result = Users_Permission::loadPermission($module, $this->permissions);
            if (PEAR::isError($result)) {
                return $result;
            }
        }

        if ($this->getPermissionLevel($module) == NO_PERMISSION) {
            return FALSE;
        }
        if(!empty($this->permissions[$module]['permissions'])) {
            if (isset($subpermission)) {
                if (!isset($this->permissions[$module]['permissions'][$subpermission])) {
                    PHPWS_Error::log(USER_ERR_FAIL_ON_SUBPERM, 'users', 'allow', 'SubPerm: ' . $subpermission);
                    return FALSE;
                }

                $allow = $this->permissions[$module]['permissions'][$subpermission];
                if ((bool)$allow) {
                    if (isset($item_id)) {
                        // subpermission is set as is item id
                        return $this->allowedItem($module, $item_id, $itemname);
                    } else {
                        // subpermission is set and item id is not
                        return TRUE;
                    }
                } else {
                    // subpermission is not allowed
                    return FALSE;
                }
            } else {
                if (isset($item_id)) {
                    // subpermission is not set and item id is set
                    return $this->itemIsAllowed($module, $item_id, $itemname);
                } else {
                    // subpermission is not set and item id is not set
                    return TRUE;
                }
            }
        } elseif (empty($subpermission)) {
            return TRUE;
        } else {
            return FALSE;
        }
    }

    function getPermissionLevel($module)
    {
        if (!isset($this->permissions[$module])) {
            $result = Users_Permission::loadPermission($module, $this->permissions);
            if (PEAR::isError($result)) {
                return $result;
            }
        }

        return $this->permissions[$module]['permission_level'];
    }

    function loadPermission($module, &$permissions)
    {
        $groups = $this->groups;

        $permTable = Users_Permission::getPermissionTableName($module);
        $itemTable = Users_Permission::getItemPermissionTableName($module);

        PHPWS_DB::isTable($itemTable) ? $useItem = TRUE : $useItem = FALSE;

        if(!PHPWS_DB::isTable($permTable)) {
            $permissions[$module]['permission_level'] = UNRESTRICTED_PERMISSION;
            return TRUE;
        }

        $permDB = new PHPWS_DB($permTable);
        $itemDB = new PHPWS_DB($itemTable);

        if (isset($groups) && count($groups)) {
            foreach ($groups as $group_id){
                if ($useItem) {
                    $itemDB->addWhere('group_id', $group_id, NULL, 'or');
                }
        
                $permDB->addWhere('group_id', $group_id, NULL, 'or');
            }
        }

        $permResult = $permDB->select();

        if (!isset($permResult)) {
            $permissions[$module]['permission_level'] = NO_PERMISSION;
            return TRUE;
        }

        if ($useItem) {
            $itemResult = $itemDB->select();

            if (PEAR::isError($itemResult)) {
                return $itemResult;
            }

            if (!empty($itemResult)) {
                foreach ($itemResult as $item){
                    extract($item);
                    $itemList[$item_name][] = $item_id;
                }
            } else {
                $itemList = NULL;
            }
        } else {
            $itemList = NULL;
        }

        $permissionSet = array();
        foreach ($permResult as $permission){
            unset($permission['group_id']);
     
            if (!isset($permissionLevel) || $permissionLevel < $permission['permission_level']) {
                $permissionLevel = $permission['permission_level'];
            }

            unset($permission['permission_level']);
      
            foreach($permission as $name=>$value){
                if (!isset($permissionSet[$name]) || $permissionSet[$name] < $value) {
                    $permissionSet[$name] = $value;
                } elseif ($permissionSet[$name] < $value) {
                    $permissionSet[$name] = $value;
                }
            }
        }

        $permissions[$module]['items']            = $itemList;
        $permissions[$module]['permission_level'] = $permissionLevel;
        $permissions[$module]['permissions']      = $permissionSet;

        return TRUE;
    }

    function removePermissions($module)
    {
        $tableName = Users_Permission::getPermissionTableName($module);
        $itemTableName = Users_Permission::getItemPermissionTableName($module);

        $DB = & new PHPWS_DB($tableName);

        $result = $DB->dropTable();
        if (PEAR::isError($result)) {
            $errors[] = $result;
        }

        $result = $DB->setTable($itemTableName);
        if (PEAR::isError($result)) {
            $errors[] = $result;
        }

        $result = $DB->dropTable();
        if (PEAR::isError($result)) {
            $errors[] = $result;
        }

        if (isset($errors)) {
            foreach ($errors as $error)
                PHPWS_Error::log($error);
            return FALSE;
        }

        return TRUE;
    }

    function createPermissions($module)
    {
        $permissions = NULL;
        $file = PHPWS_Core::getConfigFile($module, 'permission.php');

        if ($file == FALSE) {
            return NULL;
        }

        include_once $file;

        $result = Users_Permission::createPermissionTable($module, $permissions);
        if (PEAR::isError($result)) {
            $errors[] = $result;
        }
      
        if (isset($item_permissions) && $item_permissions == TRUE) {
            $result = Users_Permission::createItemPermissionTable($module);
            if (PEAR::isError($result)) {
                $errors[] = $result;
            }
        }

        if (isset($errors)) {
            foreach ($errors as $error)
                PHPWS_Error::log($error);
            return FALSE;
        }

        return TRUE;
    }

    function createPermissionTable($module, $permissions=NULL)
    {
        $tableName = Users_Permission::getPermissionTableName($module);
        $columnSetting = 'smallint NOT NULL default \'0\'';

        if (PHPWS_DB::isTable($tableName)) {
            return PHPWS_Error::get(USER_ERR_PERM_TABLE, 'users', 'createPermissionTable', 'Table Name: ' . $tableName);
        }

        $DB = & new PHPWS_DB($tableName);
    
        $columns['group_id'] = 'int NOT NULL default \'0\'';
        $columns['permission_level'] = 'smallint NOT NULL default \'0\'';

        if (isset($permissions)) {
            foreach ($permissions as $permission=>$description)
                $columns[$permission] = &$columnSetting;
        }

        $DB->addValue($columns);
        return $DB->createTable();
    }

    function createItemPermissionTable($module, $itemNames=NULL)
    {
        $tableName = Users_Permission::getItemPermissionTableName($module);

        if (PHPWS_DB::isTable($tableName)) {
            return PHPWS_Error::get(USER_ERR_PERM_TABLE, 'users', 'createItemPermissionTable', 'Table: ' . $tableName);
        }

        $DB = new PHPWS_DB($tableName);
    
        $columns['item_name'] = 'varchar(30) NOT NULL';
        $columns['item_id'] = $columns['group_id'] = 'int NOT NULL default \'0\'';
        $DB->addValue($columns);
        return $DB->createTable();
    }

    function getPermissionTableName($module)
    {
        return implode('', array($module, '_permissions'));    
    }

    function getItemPermissionTableName($module)
    {
        return implode('', array($module, '_item_permissions'));
    }

    function setPermissions($group_id, $module, $level, $subpermissions=NULL)
    {
        $tableName = Users_Permission::getPermissionTableName($module);
        if (!PHPWS_DB::isTable($tableName)) {
            return;
        }

        $db = & new PHPWS_DB($tableName);
        $db->addWhere('group_id', (int)$group_id);

        $db->delete();

        $db->resetWhere();

        $db->addValue('group_id', (int)$group_id);
        $columns = $db->getTableColumns();

        $db->addValue('permission_level', (int)$level);

        if ($level == NO_PERMISSION) {
            unset($subpermissions);
            Users_Permission::clearItemPermissions($module, $group_id);
        }

        if (isset($subpermissions)) {
            foreach ($columns as $colName){
                if ($colName == 'permission_level' || $colName == 'group_id') {
                    continue;
                }
        
                if (isset($subpermissions[$colName]) && (int)$subpermissions[$colName] == 1) {
                    $db->addValue($colName, 1);
                } else {
                    $db->addValue($colName, 0);
                }
            }
        }

        return $db->insert();
    }


    /**
     * Returns all non-user groups that have restricted item permissions
     * for a specific module
     */
    function getRestrictedGroups($key, $edit_rights=FALSE)
    {
        if (empty($key) ||
            !PHPWS_Core::isClass($key, 'key') || 
            $key->isHomeKey() ||
            empty($key->module) ||
            empty($key->edit_permission)) {
            return NULL;
        }

        $itemTable = Users_Permission::getItemPermissionTableName($key->module);
        $permTable = Users_Permission::getPermissionTableName($key->module);

        if (!PHPWS_DB::isTable($permTable)) {
            return PHPWS_Error::get(USER_ERR_PERM_FILE, 'users', __CLASS__ . '::' . __FUNCTION__);
        }

        if (!PHPWS_DB::isTable($itemTable)) {
            return PHPWS_Error::get(USER_ERR_ITEM_PERM_FILE, 'users', __CLASS__ . '::' . __FUNCTION__);
        }
        $db = & new PHPWS_DB('users_groups');
        $db->addWhere('user_id', 0);
        $db->addWhere('id', "$permTable.group_id");
        $db->addWhere("$permTable.permission_level", RESTRICTED_PERMISSION);

        if ($edit_rights) {
            $db->addWhere($permTable . '.' . $key->edit_permission, 1);
        }

        $db->addColumn('name');
        $db->addColumn('id');
        $db->setIndexBy('id');

        $result = $db->select('col');
        return $result;
    }

    function getGroupList($groups)
    {
        PHPWS_Core::initModClass('users', 'Group.php');

        $db = & new PHPWS_DB('users_groups');

        $db->addWhere('id', $groups);
        $result = $db->getObjects('PHPWS_Group');

        if (PEAR::isError($result)) {
            return $result;
        }

        foreach ($result as $group) {
            $inputs[$group->getId()] = $group->getName();
        }
        return $inputs;
    }

    function saveEditPermissions(&$key)
    {
        $table = Users_Permission::getItemPermissionTableName($key->module);
        $db = & new PHPWS_DB($table);
        $db->addWhere('item_id', $key->item_id);
        $db->addWhere('item_name', $key->item_name);
        $db->delete();
        $db->reset();

        if (!isset($_POST['edit_groups']) || !is_array($_POST['edit_groups'])) {
            return;
        }

        $groups = & $_POST['edit_groups'];
        $db->addValue('item_name', $key->item_name);
        $db->addValue('item_id', $key->item_id);
        foreach ($groups as $group_id){
            $db->addValue('group_id', $group_id);
            $result = $db->insert();

            if (PEAR::isError($result)) {
                return $result;
            }
        }
        return TRUE;
    }

    function clearItemPermissions($module, $group_id)
    {
        $itemTable = Users_Permission::getItemPermissionTableName($module);
        if (PHPWS_DB::isTable($itemTable)) {
            $db = & new PHPWS_DB($itemTable);
            $db->addWhere('group_id', $group_id);
            return $db->delete();
        }
    }

    function giveItemPermission($user_id, $module, $item_id, $itemname=NULL)
    {
        if (!isset($itemname)) {
            $itemname = $module;
        }

        $table = Users_Permission::getItemPermissionTableName($module);

        $user = & new PHPWS_User($user_id);
        $group_id = $user->getUserGroup();
    
        $db = & new PHPWS_DB($table);
        $db->addWhere('item_id', $item_id);
        $db->addWhere('item_name', $itemname);
        $db->addWhere('group_id', $group_id);
        $db->delete();
        $db->reset();

        $db->addValue('item_id', $item_id);
        $db->addValue('item_name', $itemname);
        $db->addValue('group_id', $group_id);
        $result = $db->insert();
        if (PEAR::isError($result)) {
            return $result;
        } else {
            return TRUE;
        }
      
    }
}

?>