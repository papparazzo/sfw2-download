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
use SFW2\Authority\User;
use SFW2\Controllers\Controller\Helper\GetDivisionTrait;

use SFW2\Core\Config;
use SFW2\Core\Database;

use SFW2\Validator\Ruleset;
use SFW2\Validator\Validator;
use SFW2\Validator\Validators\IsNotEmpty;

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
     * @var string
     */
    protected $title;

    /**
     * @var string
     */
    protected $path;

    public function __construct(int $pathId, Database $database, Config $config, User $user, string $title = '') {
        parent::__construct($pathId);
        $this->database = $database;
        $this->user = $user;
        $this->title = $title;
        $this->path = $config->getVal('path', 'data') . DIRECTORY_SEPARATOR . $this->pathId . DIRECTORY_SEPARATOR;
        if(!is_dir($this->path) && !mkdir($this->path)) {
            throw new ResolverException("unable to create dir <{$this->path}>", ResolverException::UNKNOWN_ERROR);
        }
    }

    public function index($all = false) : Content {
        unset($all);
        $content = new Content('SFW2\\Download\\Download');
        $content->assign('tableTitle', $this->title);
        return $content;
    }

    public function read($all = false) {
        unset($all);
        $content = new Content('Download');

        $count = (int)filter_input(INPUT_GET, 'count', FILTER_VALIDATE_INT);
        $start = (int)filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT);

        $count = $count ? $count : 500;

        $stmt =
            "SELECT `media`.`Id`, `media`.`Name`, `media`.`CreationDate`, " .
            "`media`.`Description`, `media`.`FileType`, `media`.`Autogen`, " .
            "`media`.`Token`, `user`.`FirstName`, `user`.`LastName` " .
            "FROM `{TABLE_PREFIX}_media` AS `media` " .
            "LEFT JOIN `{TABLE_PREFIX}_user` AS `user` " .
            "ON `user`.`Id` = `media`.`UserId` " .
            "WHERE `PathId` = '%s' " .
            "ORDER BY `media`.`Name` DESC " .
            "LIMIT %s, %s ";

        $rows = $this->database->select($stmt, [$this->pathId, $start, $count]);
        $cnt = $this->database->selectCount('{TABLE_PREFIX}_media', "WHERE `PathId` = '%s'", [$this->pathId]);

        $entries = [];
        foreach($rows as $row) {
            $entry = [];
            $entry['id'           ] = $row['Id'];
            $entry['description'  ] = $row['Description'];
            $entry['filename'     ] = $row['Name'       ];
            $entry['deleteAllowed'] = !(bool)$row['Autogen'];
            $entry['addFileInfo'  ] = $this->getAdditionalFileInfo($row);
            $entry['icon'         ] = '/img/layout/icon_' . $row['FileType'] . '.png'; # TODO Remove
            $entry['href'         ] = '?do=getFile&token=' . $row['Token'];
            $entries[] = $entry;
        }
        $content->assign('offset', $start + $count);
        $content->assign('hasNext', $start + $count < $cnt);
        $content->assign('entries', $entries);
        return $content;
    }

    public function getFile() : File {
        $token = filter_input(INPUT_GET, 'token');

        $stmt =
            "SELECT `Token`, `Name`, `FileType`, `Autogen`, `ActionHandler` " .
            "FROM `{TABLE_PREFIX}_media` AS `media` " .
            "WHERE `media`.`Token` = '%s' ";

        $result = $this->database->selectRow($stmt, [$token]);

        if(empty($result)) {
            throw new ResolverException("no entry found for id <$token>", ResolverException::NO_PERMISSION);
        }

        $isTempFile = false;

        if($result['ActionHandler'] != '') {
            $isTempFile = true;
            $class = '\\' . $result['ActionHandler'];
            $handler = new $class($this->database); // TODO: generate object per di-container...
            $handler->createFile($this->path, $result['Token'], $result['Name']);
        }

        return new File($this->path, $result['Token'], $result['Name'], $isTempFile);
    }

    public function delete($all = false) : Content {
        $entryId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if($entryId === false) {
            throw new ResolverException("invalid data given", ResolverException::INVALID_DATA_GIVEN);
        }

        $stmt =
            "SELECT `Token` " .
            "FROM `{TABLE_PREFIX}_media` AS `media` " .
            "WHERE `Id` = '%s' AND `PathId` = '%s' AND `Autogen` = '0'";

        if(!$all) {
            $stmt .= "AND `UserId` = '" . $this->database->escape($this->user->getUserId()) . "'";
        }

        $result = $this->database->selectRow($stmt, [$entryId, $this->pathId]);

        if(empty($result)) {
            throw new ResolverException("no entry found for id <$entryId>", ResolverException::NO_PERMISSION);
        }

        if(!unlink($this->path . $result['Token'])) {
            throw new ResolverException("unlinking for id <$entryId> failed", ResolverException::INVALID_DATA_GIVEN);
        }

        $stmt = "DELETE FROM `{TABLE_PREFIX}_media` WHERE `Id` = '%s' AND `PathId` = '%s' AND `Autogen` = '0'";

        if(!$all) {
            $stmt .= "AND `UserId` = '" . $this->database->escape($this->user->getUserId()) . "'";
        }

        if(!$this->database->delete($stmt, [$entryId, $this->pathId])) {
            throw new ResolverException("no entry found for id <$entryId>", ResolverException::NO_PERMISSION);
        }

        return new Content();
    }

    public function create() {
        $content = new Content('Download');

        $validateOnly = filter_input(INPUT_POST, 'validateOnly', FILTER_VALIDATE_BOOLEAN);

        $rulset = new Ruleset();
        $rulset->addNewRules('title', new IsNotEmpty());

        $validator = new Validator($rulset);
        $values = [];

        $error = $validator->validate($_POST, $values);
        $content->assignArray($values);

        if(!$error) {
            $content->setError(true);
        }

        if($validateOnly || !$error) {
            return $content;
        }

        $title = $values['title']['value'];

        $token = $this->addFile();

        $stmt =
            "INSERT INTO `{TABLE_PREFIX}_media` " .
            "SET `Token` = '%s', `UserId` = '%s', `Name` = '%s', `Description` = '%s', `PathId` = '%s', " .
            "`CreationDate` = NOW(), `ActionHandler` = '', `FileType` = '%s', `Autogen` = '0'";

        $id = $this->database->insert(
            $stmt, [$token, $this->user->getUserId(), $_POST['name'], $title, $this->pathId, $this->getFileType($this->path . $token)]
        );

        $content->assign('id',    ['value' => $id]);
        $content->assign('title', ['value' => $title]);

        return $content;
    }

    protected function addFile() {
        if(!isset($_POST['file'])) {
            throw new ResolverException("file not set", ResolverException::UNKNOWN_ERROR);
        }

        $chunk = explode(';', $_POST['file']);
        $type = explode(':', $chunk[0]);
        $type = $type[1];
        $data = explode(',', $chunk[1]);

        $token = md5(getmypid() . uniqid('', true) . microtime());

        if(is_file($this->path . $token)) {
            throw new SFW_Exception("file <$this->path$token> allready exists.");
        }


        if(!is_dir($this->path) && !mkdir($this->path)) {
            throw new ResolverException("could not create path <$this->path$token>", ResolverException::UNKNOWN_ERROR);
        }

        if(!file_put_contents($this->path . $token, base64_decode($data[1]))) {
            throw new ResolverException("could not store file <$this->path$token>", ResolverException::UNKNOWN_ERROR);
        }

        return $token;
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

        if($row['CreationDate'] === null || $row['CreationDate'] == '0000-00-00') {
            $date = '[unbekannt]';
        } else {
            $date = new DateTime($row['CreationDate'], new DateTimeZone('Europe/Berlin'));
            $date = strftime('%d. %b. %G', $date->getTimestamp());
        }
        return '(' .  $name . '; Stand: ' . $date . ')';
    }
}
