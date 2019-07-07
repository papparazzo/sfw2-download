<?php

/**
 *  SFW2 - SimpleFrameWork
 *
 *  Copyright (C) 2017  Stefan Paproth
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program. If not, see <http://www.gnu.org/licenses/agpl.txt>.
 *
 */

namespace SFW2\Download\Controller;

use SFW2\Routing\AbstractController;
use SFW2\Routing\Result\Content;
use SFW2\Routing\Result\File;
use SFW2\Routing\Resolver\ResolverException;
use SFW2\Controllers\Widget\Obfuscator\EMail;
use SFW2\Authority\User;
use SFW2\Controllers\Controller\Helper\GetDivisionTrait;

use SFW2\Core\Config;
use SFW2\Core\Database;

use DateTime;
use DateTimeZone;

class Download extends AbstractController {

    use GetDivisionTrait;

    /**
     * @var Database
     */
    protected $database;

    /**
     * @var User
     */
    protected $user;

    /**
     * @var Config
     */
    protected $config;

    protected $title;

    public function __construct(int $pathId, Database $database, Config $config, User $user, string $title = null) {
        parent::__construct($pathId);
        $this->database = $database;
        $this->user = $user;
        $this->title = $title;
        $this->config = $config;
        $this->clearTmpFolder();
    }

    public function index($all = false) : Content {
        unset($all);
        $content = new Content('SFW2\\Download\\Download\\Download');
        $content->appendJSFile('crud.js');
# FIXME
#            $this->ctrl->addJSFile('jquery.fileupload');
#            $this->ctrl->addJSFile('download');

        $content->assign('entries',  $this->loadEntries());
        $content->assign('title',    $this->title);
        $content->assign('divisions', $this->getDivisions());
        $content->assign('modificationDate', $this->getLastModificationDate());
        $content->assign('mailaddr', (string)(new EMail($this->config->getVal('project', 'eMailWebMaster'), 'Bescheid.')));
        return $content;
    }

    protected function loadEntries() {
        $stmt =
            "SELECT `media`.`Id`, `media`.`Name`, `media`.`CreationDate`, " .
            "`media`.`Description`, `media`.`FileType`, `media`.`Autogen`, " .
            "`media`.`Token`, `division`.`Name` AS `Category`, " .
            "IF(`media`.`UserId` = '%s' OR '%s', '1', '0') AS `DelAllowed`, " .
            "`user`.`FirstName`, `user`.`LastName` " .
            "FROM `{TABLE_PREFIX}_media` AS `media` " .
            "LEFT JOIN `{TABLE_PREFIX}_division` AS `division` " .
            "ON `division`.`Id` = `media`.`DivisionId` " .
            "LEFT JOIN `{TABLE_PREFIX}_user` AS `user` " .
            "ON `user`.`Id` = `media`.`UserId` ";

        $rows = $this->database->select($stmt, [$this->user->getUserId(), $this->user->isAdmin() ? '1' : '0']);

        $entries = [];

        foreach($rows as $row) {
            $entry = [];
            $entry['id'         ] = $row['Id'];
            $entry['description'] = $row['Description'];
            $entry['filename'   ] = $row['Name'       ];
            $entry['autoGen'    ] = (bool)$row['Autogen'];
            $entry['delAllowed' ] = (bool)$row['DelAllowed'];
            $entry['addFileInfo'] = $this->getAdditionalFileInfo($row);
            $entry['icon'       ] = '/img/layout/icon_' . $row['FileType'] . '.png'; # TODO Remove
            $entry['href'       ] = '?do=getFile&token=' . $row['Token'];
            $entries[$row['Category']][] = $entry;
        }
        return $entries;
    }

    public function getFile() : File {
        $token = filter_input(INPUT_GET, 'token');

        $stmt =
            "SELECT `media`.`Token`, `media`.`Name`, `media`.`FileType`, `media`.`Autogen` " .
            "FROM `{TABLE_PREFIX}_media` AS `media` " .
            "WHERE `media`.`Token` = '%s' ";

        $result = $this->database->selectRow($stmt, [$token]);

        if(empty($result)) {
            throw new ResolverException("no entry found for id <$token>", ResolverException::NO_PERMISSION);
        }
        return new File($this->config->getVal('path', 'data'), $result['Token'], $result['Name'] /*,bool $isTemp = false*/);
    }

    protected function getLastModificationDate() : string {
        $stmt = "SELECT `media`.`CreationDate` FROM `{TABLE_PREFIX}_media` AS `media` ORDER BY `media`.`CreationDate` DESC";
        return $this->database->selectSingle($stmt);
    }

    public function delete($all = false) {
        $entryId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if($entryId === false) {
            throw new ResolverException("invalid data given", ResolverException::INVALID_DATA_GIVEN);
        }

        $stmt = "DELETE FROM `{TABLE_PREFIX}_media` WHERE `Id` = '%s' AND `Autogen` = '0'";

        if(!$all) {
            $stmt .= "AND `UserId` = '" . $this->database->escape($this->user->getUserId()) . "'";
        }

        if(!$this->database->delete($stmt, [$entryId, $this->pathId])) {
            throw new ResolverException("no entry found for id [" . $entryId . "]", ResolverException::NO_PERMISSION);
        }

        return new Content();
    }



    


    public function create() {

        $method = filter_input(INPUT_SERVER, 'REQUEST_METHOD', FILTER_VALIDATE_INT);

        if(strtolower($method) != 'post') {
            $this->dto->getErrorProvider()->addError(SFW_Error_Provider::INT_ERR, array(), 'dropzone_' . $this->getPageId());
        }

        $tmp['title'] = $this->dto->getTitle('title', true);
        $tmp['section'] = $this->dto->getArrayValue('section', true, $this->sections);

        if(!array_key_exists('userfile', $_FILES) || $_FILES['userfile']['error'] != 0 || $_FILES['userfile']['tmp_name'] == '') {
            $this->dto->getErrorProvider()->addError(SFW_Error_Provider::NO_FILE, array(), 'dropzone_' . $this->getPageId());
        }

        if($this->dto->getErrorProvider()->hasErrors() || $this->dto->getErrorProvider()->hasWarning()) {
            return false;
        }

        $file = $_FILES['userfile'];
        $path = SFW_DATA_PATH . $this->pathId . '/';

        if(!is_dir($path) && !mkdir($path)) {
            throw new SFW_Exception("could not create path <$path>");
        }

        $token = md5($file['tmp_name'] . getmypid() . SFW_AuxFunc::getRandomInt());

        if(is_file($path . $token)) {
            throw new SFW_Exception('file <' . $path . $token .'> allready exists.');
        }

        if(!move_uploaded_file($file['tmp_name'], $path . $token)) {
            throw new SFW_Exception('could not move file <' . $file['tmp_name'] . '> to <' . $path . $token .'>');
        }

        $stmt =
            "INSERT INTO `{TABLE_PREFIX}_media` " .
            "SET `Token` = '%s', `UserId` = '%s', `Name` = '%s', `Description` = '%s', `DivisionId` = '%s', `CreationDate` = NOW(), " .
            "`ActionHandler` = '', `Path` = '%s', `FileType` = '%s', `Deleted` = '0', `Autogen` = '0'";

        $this->database->insert(
            $stmt, [$token, $this->user->getUserId(), $file['name'], $tmp['title'], $tmp['section'], $path, $this->getFileType($path . $token)]
        );

        $tmp['title'  ] = '';
        $tmp['section'] = '';
        $this->dto->setSaveSuccess();
        #$this->ctrl->updateModificationDate();
        return true;
    }

    protected function clearTmpFolder() {
return; # FIXME
        $dir = dir(SFW_TMP_PATH);
        while(false !== ($file = $dir->read())) {
            if($file  == '.' || $file == '..' || $file == '.htaccess') {
                continue;
            }

            if(time() - filemtime(SFW_TMP_PATH . $file) > 60 * 60) {
                unlink(SFW_TMP_PATH . $file);
            }
        }
        $dir->close();
    }

    protected function getFileType($file) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        $mi = finfo_file($finfo, $file);
        finfo_close($finfo);

        switch($mi) {
            case 'application/pdf':
                return 'pdf';

            case 'application/vnd.ms-excel':
            case 'application/vnd.oasis.opendocument.spreadsheet':
                return 'xls';

            case 'application/msword':
            case 'application/rtf':
            case 'application/vnd.oasis.opendocument.text':
                return 'doc';

            case 'application/vnd.ms-powerpoint':
                return 'ppt';

            case 'application/zip':
            case 'application/x-rar-compressed':
                return 'zip';
        }

        if(strstr($mi, 'text/')) {
            return 'txt';
        }
        return 'ukn';
    }

    protected function getAdditionalFileInfo($row) {
        $name = substr($row['FirstName'], 0, 1)  . '. ' . $row['LastName'];

        $date = new DateTime($row['CreationDate'], new DateTimeZone('Europe/Berlin'));
        $date = $this->vars['modificationDate'] = strftime('%d. %b. %G', $date->getTimestamp());


        if($date != '') {
            return '(' .  $name . '; Stand: ' . $date . ')';
        }
        return '(' . $name . ')';
    }
}
