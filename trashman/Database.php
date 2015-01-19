<?php
/**
 * Trashman - a simple delayed files removal utility.
 * Copyright (C) 2014  Simon Robert
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

namespace trashman;

use Exception;
use SQLite3;

/**
 * Classe chargé de la gestion de la base de données.
 *
 * @author simon.robert
 */
class Database
{
    /**
     * Cache local pour les connexions aux bd sqlite.
     *
     * @var array
     */
    private static $dbs = array();

    /**
     * Ajoute le fichier à supprimer, ou le met à jour s'il existe déjà.
     *
     * @param string $path
     * @param integer $priority
     */
    public static function insert($path, $mountPath, $priority)
    {
        $db = self::getDb($path);

        $stmt = $db->prepare("UPDATE Paths SET priority = :priority WHERE path = :path");
        $stmt->bindValue(':path', $path, SQLITE3_TEXT);
        $stmt->bindValue(':priority', $priority, SQLITE3_INTEGER);
        $ok = $stmt->execute();

        if (!$ok) {
            $stmt = $db->prepare("INSERT INTO Paths (path, mount, priority) VALUES (:path, :mount, :priority)");
            $stmt->bindValue(':path', $path, SQLITE3_TEXT);
            $stmt->bindValue(':mount', $mountPath, SQLITE3_TEXT);
            $stmt->bindValue(':priority', $priority, SQLITE3_INTEGER);
            $ok = $stmt->execute();
        }

        return ($ok ? true : false);
    }

    /**
     * Retourne la base de données pour le point de montage du chemin donné.
     *
     * @param string $path
     *
     * @return SQLite3
     */
    public static function getDb($path)
    {
        if(array_key_exists($path, self::$dbs)) {
            $mountPath = $path;
        } else {
            $mountPath = Mount::getMountPath($path);
        }

        if (!array_key_exists($mountPath, self::$dbs)) {
            $dbPath = Mount::getTrashmanFolderPath($mountPath, true) . "/trashman.db";
            $shouldBeCreated = !file_exists($dbPath);
            $db = new SQLite3($dbPath);
            if ($shouldBeCreated) {
                $db->exec("CREATE TABLE Paths (path TEXT, mount TEXT, priority INTEGER)");
                $db->exec("CREATE INDEX PathsI ON Paths (mount, priority)");
                $db->exec("CREATE UNIQUE INDEX PathsI2 ON Paths (path)");
            }

            self::$dbs[$mountPath] = $db;
        }

        return self::$dbs[$mountPath];
    }

    /**
     * Retourne le prochain path à supprimer.
     * @staticvar SQLite3Result $result
     * @return string
     */
    public static function getNextPathToDelete($mountPath) {
        static $result = null;
        if($result === null) {
            $stmt = self::getDb($mountPath)->prepare("SELECT path FROM Paths WHERE mount = :mount ORDER BY priority ASC, rowid ASC");
            $stmt->bindValue(':mount', $mountPath, SQLITE3_TEXT);
            $result = $stmt->execute();
        }

        $array = $result->fetchArray(SQLITE3_ASSOC);
        if($array === false) {
            if(in_array(self::getDb($mountPath)->lastErrorCode(), array(0, 101))) {
                // Plus de ligne.
                return null;
            }

            // Erreur sql
            throw new Exception(self::getDb($mountPath)->lastErrorMsg());
        }

        return $array["path"];
    }
}
