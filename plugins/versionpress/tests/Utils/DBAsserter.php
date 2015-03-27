<?php

namespace VersionPress\Tests\Utils;

use VersionPress\Database\DbSchemaInfo;
use VersionPress\Filters\AbsoluteUrlFilter;
use VersionPress\Storages\StorageFactory;
use VersionPress\Utils\ArrayUtils;
use VersionPress\Utils\ReferenceUtils;

class DBAsserter {
    /** @var DbSchemaInfo */
    private static $schemaInfo;
    /** @var StorageFactory */
    private static $storageFactory;
    /** @var TestConfig */
    private static $testConfig;
    /** @var \mysqli */
    private static $database;


    public static function assertFilesEqualDatabase() {
        self::staticInitialization();
        $entityNames = self::$schemaInfo->getAllEntityNames();
        foreach ($entityNames as $entityName) {
            self::assertEntitiesEqualDatabase($entityName);
        }
    }

    private static function staticInitialization() {
        self::$testConfig = TestConfig::createDefaultConfig();

        $vpdbPath = self::$testConfig->testSite->path . '/wp-content/vpdb';
        $schemaReflection = new \ReflectionClass('VersionPress\Database\DbSchemaInfo');
        $schemaFile = dirname($schemaReflection->getFileName()) . '/wordpress-schema.neon';
        self::$schemaInfo = new DbSchemaInfo($schemaFile, self::$testConfig->testSite->dbTablePrefix);

        $dbHost = self::$testConfig->testSite->dbHost;
        $dbUser = self::$testConfig->testSite->dbUser;
        $dbPassword = self::$testConfig->testSite->dbPassword;
        $dbName = self::$testConfig->testSite->dbName;
        self::$database = new \mysqli($dbHost, $dbUser, $dbPassword, $dbName);

        self::$storageFactory = new StorageFactory($vpdbPath, self::$schemaInfo, self::$database);

    }

    private static function assertEntitiesEqualDatabase($entityName) {
        $storage = self::$storageFactory->getStorage($entityName);
        $entityInfo = self::$schemaInfo->getEntityInfo($entityName);

        $allDbEntities = self::selectAll(self::$schemaInfo->getPrefixedTableName($entityName));
        $dbEntities = array_filter($allDbEntities, array($storage, 'shouldBeSaved'));

        $idMap = self::getVpIdMap();
        $dbEntities = self::replaceForeignKeys($entityName, $dbEntities, $idMap);

        $urlFilter = new AbsoluteUrlFilter(self::$testConfig->testSite->url);
        $storageEntities = array_map(function ($entity) use ($urlFilter) { return $urlFilter->restore($entity); }, $storage->loadAll());
        $countOfentitiesInDb = count($dbEntities);
        $countOfentitiesInStorage = count($storageEntities);

        if ($countOfentitiesInDb !== $countOfentitiesInStorage) {
            if ($countOfentitiesInStorage > $countOfentitiesInDb) {
                $problematicEntities = self::findMissingEntities($entityName, $storageEntities, $dbEntities);
            } else {
                $problematicEntities = self::findExceedingEntities($entityName, $storageEntities, $dbEntities);
            }

            throw new \PHPUnit_Framework_AssertionFailedError("Different count of synchronized entities ($entityName): DB = $countOfentitiesInDb, storage = $countOfentitiesInStorage\nProblematic entities: " . join(", ", $problematicEntities));
        }

        foreach ($dbEntities as $dbEntity) {
            $id = $dbEntity[$entityInfo->vpidColumnName];
            $storageEntity = $storageEntities[$id];

            foreach ($dbEntity as $column => $value) {
                if (!isset($storageEntity[$column])) {
                    continue;
                }

                if (is_string($storageEntity[$column])) {
                    $storageEntity[$column] = str_replace("\r\n", "\n", $storageEntity[$column]);
                }

                if (is_string($value)) {
                    $value = str_replace("\r\n", "\n", $value);
                }

                if ($storageEntity[$column] != $value) {
                    throw new \PHPUnit_Framework_AssertionFailedError("Different values ({$entityName}[$column]: $id): DB = $value, storage = $storageEntity[$column]");
                }
            }
        }

        $missingReferences = array();
        $exceedingReferences = array();

        foreach ($entityInfo->mnReferences as $reference => $targetEntity) {
            if ($entityInfo->isVirtualReference($reference)) {
                continue;
            }

            $referenceDetails = ReferenceUtils::getMnReferenceDetails(self::$schemaInfo, $entityName, $reference);
            $sourceColumn = $referenceDetails['source-column'];
            $targetColumn = $referenceDetails['target-column'];
            $junctionTable = $referenceDetails['junction-table'];
            $prefixedJunctionTable = self::$schemaInfo->getPrefixedTableName($junctionTable);
            $prefixedVpIdTable = self::$schemaInfo->getPrefixedTableName('vp_id');
            $sourceTable = self::$schemaInfo->getTableName($referenceDetails['source-entity']);
            $targetTable = self::$schemaInfo->getTableName($referenceDetails['target-entity']);

            $junctionTableContent = self::fetchAll("SELECT HEX(s_vp_id.vp_id), HEX(t_vp_id.vp_id) FROM $prefixedJunctionTable j JOIN $prefixedVpIdTable s_vp_id ON j.$sourceColumn = s_vp_id.id AND s_vp_id.`table`='$sourceTable' JOIN $prefixedVpIdTable t_vp_id ON j.$targetColumn = t_vp_id.id AND t_vp_id.`table` = '$targetTable'", MYSQLI_NUM);

            $checkedReferences = array();
            $missingReferences[$junctionTable] = array();
            foreach ($storageEntities as $storageEntity) {
                if (!isset($storageEntity["vp_$targetEntity"])) {
                    continue;
                }

                foreach ($storageEntity["vp_$targetEntity"] as $referenceVpId) {
                    if (!ArrayUtils::any($junctionTableContent, function ($junctionRow) use ($storageEntity, $referenceVpId) {
                        return $junctionRow[0] === $storageEntity['vp_id'] && $junctionRow[1] === $referenceVpId;
                    })) {
                        $missingReferences[$junctionTable][] = array($sourceColumn => $storageEntity['vp_id'], $targetColumn => $referenceVpId);
                    }
                    $checkedReferences[] = array($storageEntity['vp_id'], $referenceVpId);
                }
            }

            $exceedingReferences[$junctionTable] = array_map(
                function ($pair) use ($sourceColumn, $targetColumn) {
                    return array($sourceColumn => $pair[0], $targetColumn => $pair[1]);
                }, array_filter($junctionTableContent,
                    function ($pair) use ($checkedReferences) {
                        foreach ($checkedReferences as $reference) {
                            if ($reference[0] === $pair[0] && $reference[1] === $pair[1]) {
                                return false;
                            }
                        }
                        return true;
                    })
            );
        }

        self::reportResultOfMnReferenceCheck($missingReferences, "Missing");
        self::reportResultOfMnReferenceCheck($exceedingReferences, "Exceeding");
    }

    private static function selectAll($table) {
        return self::fetchAll("SELECT * FROM $table");
    }

    private static function fetchAll($query, $resultType = MYSQLI_ASSOC) {
        $res = self::$database->query($query);
        return $res->fetch_all($resultType);
    }

    private static function getVpIdMap() {
        $vpIdTable = self::selectAll(self::$schemaInfo->getPrefixedTableName('vp_id'));
        $idMap = array();
        foreach ($vpIdTable as $row) {
            $idMap[$row['table']][$row['id']] = strtoupper(bin2hex($row['vp_id']));
        }
        return $idMap;
    }

    private static function replaceForeignKeys($entityName, $dbEntities, $idMap) {
        $entities = array();
        foreach ($dbEntities as $entity) {
            foreach (self::$schemaInfo->getEntityInfo($entityName)->references as $column => $targetEntity) {
                if ($entity[$column] != "0") {
                    $entity["vp_$column"] = $idMap[self::$schemaInfo->getTableName($targetEntity)][$entity[$column]];
                }
                unset($entity[$column]);
            }

            if (!self::$schemaInfo->getEntityInfo($entityName)->hasNaturalVpid) {
                $idColumnName = self::$schemaInfo->getEntityInfo($entityName)->idColumnName;
                $entity['vp_id'] = $idMap[self::$schemaInfo->getTableName($entityName)][$entity[$idColumnName]];
                if (!empty($entity['vp_id'])) {
                    unset($entity[$idColumnName]);
                }
            } else {
                unset($entity['option_id']);
            }

            $entities[] = $entity;
        }
        return $entities;
    }

    private static function findMissingEntities($entityName, $storageEntities, $dbEntities) {
        $storageVpIds = array_keys($storageEntities);
        $idColumnName = self::$schemaInfo->getEntityInfo($entityName)->vpidColumnName;
        foreach ($dbEntities as $dbEntity) {
            unset($storageVpIds[$dbEntity[$idColumnName]]);
        }
        return $storageVpIds;
    }

    private static function findExceedingEntities($entityName, $storageEntities, $dbEntities) {
        $exceedingEntities = array();
        $vpidColumnName = self::$schemaInfo->getEntityInfo($entityName)->vpidColumnName;
        $idColumnName = self::$schemaInfo->getEntityInfo($entityName)->idColumnName;

        foreach ($dbEntities as $dbEntity) {
            if (empty($dbEntity[$vpidColumnName])) {
                $exceedingEntities[] = $dbEntity[$idColumnName];
            } elseif (!isset($storageEntities[$dbEntity[$vpidColumnName]])) {
                $exceedingEntities[] = $dbEntity[$vpidColumnName];
            }
        }

        return $exceedingEntities;
    }

    private static function reportResultOfMnReferenceCheck($referenceResult, $verb) {
        foreach ($referenceResult as $junctionTable => $references) {
            if (count($references) == 0) {
                continue;
            }

            $list = "";
            foreach ($references as $reference) {
                $list .= "[";
                foreach ($reference as $column => $vpId) {
                    $list .= "$column = $vpId ";
                }
                $list .= "] ";
            }

            throw new \PHPUnit_Framework_AssertionFailedError(sprintf($verb . " M:N reference in table %s %s", $junctionTable, $list));
        }
    }
}