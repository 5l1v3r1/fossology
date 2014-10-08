<?php
/*
Copyright (C) 2014, Siemens AG
Authors: Daniele Fognini, Steffen Weber, Andreas Würl

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
version 2 as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License along
with this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

namespace Fossology\Lib\Dao;

use Fossology\Lib\Data\FileTreeBounds;
use Fossology\Lib\Data\Highlight;
use Fossology\Lib\Db\DbManager;
use Fossology\Lib\Util\Object;
use Monolog\Logger;

class HighlightDao extends Object
{
  /**
   * @var DbManager
   */
  private $dbManager;

  /**
   * @var Logger
   */
  private $logger;

  private $typeMap;

  function __construct(DbManager $dbManager)
  {
    $this->dbManager = $dbManager;
    $this->logger = new Logger(self::className());

    $this->typeMap = array(
        'M' => Highlight::MATCH,
        'M ' => Highlight::MATCH,
        'M0' => Highlight::MATCH,
        'M+' => Highlight::ADDED,
        'M-' => Highlight::DELETED,
        'MR' => Highlight::CHANGED,
        'L' => Highlight::SIGNATURE,
        'L ' => Highlight::SIGNATURE,
        'K' => Highlight::KEYWORD,
        'K ' => Highlight::KEYWORD,
    );
  }

  /**
   * @param FileTreeBounds $fileTreeBounds
   * @param int $licenseId
   * @param int $agentId
   * @param null $highlightId
   * @return array
   */
  private function getHighlightDiffs(FileTreeBounds $fileTreeBounds, $licenseId = null, $agentId = null, $highlightId = null)
  {
    $params =array($fileTreeBounds->getUploadTreeId());
    $uploadTreeTableName = $fileTreeBounds->getUploadTreeTableName();

    $sql = "SELECT start,len,type,rf_fk,rf_start,rf_len
            FROM license_file
              INNER JOIN highlight ON license_file.fl_pk = highlight.fl_fk
              INNER JOIN $uploadTreeTableName ut ON ut.pfile_fk = license_file.pfile_fk
              WHERE uploadtree_pk = $1 AND (type LIKE 'M_' OR type = 'L')";

    $stmt = __METHOD__.$uploadTreeTableName;
    if (!empty($licenseId))
    {
      $params[] = $licenseId;
      $stmt .= '.License';
      $sql .= " AND license_file.rf_fk=$" . count($params);
    }
    if (!empty($agentId))
    {
      $params[] = $agentId;
      $stmt .= '.Agent';
      $sql .= " AND license_file.agent_fk=$" . count($params);
    }
    if (!empty($highlightId))
    {
      $params[] = $highlightId;
      $stmt .= '.Highlight';
      $sql .= " AND fl_pk=$" . count($params);
    }
    $this->dbManager->prepare($stmt, $sql);
    $result = $this->dbManager->execute($stmt, $params);
    $highlightEntries = array();
    while ($row = $this->dbManager->fetchArray($result))
    {
      $newHiglight = new Highlight(
          intval($row['start']), intval($row['start'] + $row['len']),
          $this->typeMap[$row['type']],
          intval($row['rf_start']), intval($row['rf_start'] + $row['rf_len']));

      $licenseFileId = $row['rf_fk'];
      if ($licenseFileId)
      {
        $newHiglight->setLicenseId($licenseFileId);
      }
      $highlightEntries[] = $newHiglight;
    }
    $this->dbManager->freeResult($result);
    return $highlightEntries;
  }

  /**
   * @param FileTreeBounds $fileTreeBounds
   * @return array
   */
  private function getHighlightKeywords(FileTreeBounds $fileTreeBounds)
  {
    $uploadTreeTableName = $fileTreeBounds->getUploadTreeTableName();
    $stmt = __METHOD__.$uploadTreeTableName;
    $sql = "SELECT start,len
             FROM highlight_keyword
             WHERE pfile_fk = (SELECT pfile_fk FROM $uploadTreeTableName WHERE uploadtree_pk = $1)";
    $this->dbManager->prepare($stmt, $sql);
    $result = $this->dbManager->execute($stmt, array($fileTreeBounds->getUploadTreeId()));
    $highlightEntries = array();
    while ($row = $this->dbManager->fetchArray($result))
    {
      $highlightEntries[] = new Highlight(
          intval($row['start']), intval($row['start'] + $row['len']),
          'K', 0, 0);
    }
    $this->dbManager->freeResult($result);
    return $highlightEntries;
  }
  
  
  /**
   * @param FileTreeBounds $fileTreeBounds
   * @param int $licenseId
   * @param int $agentId
   * @param null $highlightId
   * @return array
   */ 
  public function getHighlightEntries(FileTreeBounds $fileTreeBounds, $licenseId = null, $agentId = null, $highlightId = null){
    $highlightDiffs = $this->getHighlightDiffs($fileTreeBounds, $licenseId, $agentId, $highlightId);
    $highlightKeywords = $this->getHighlightKeywords($fileTreeBounds);
    $highlightEntries = array_merge($highlightDiffs,$highlightKeywords);
    return $highlightEntries;
  }
}