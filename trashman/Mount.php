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
use Symfony\Component\Yaml\Yaml;

/**
 * Classe chargé de la gestion des points de montage sur le système de fichiers local.
 *
 * @author simon.robert
 */
class Mount {

    /**
     * Cache local pour les points de montage.
     *
     * @var array
     */
    private static $mountPaths;

    /**
     * Retourne le chemin vers le dossier de stockage de Trashman, pour un montage.
     * @param string $mountPath
     * @return string
     */
    public static function getTrashmanFolderPath($mountPath, $create = false) {
        $path = $mountPath . '/.trashman';
        if(!file_exists($path) && !is_writeable($mountPath)
                || file_exists($path) && !is_writeable($path)) {
            $trashmanHomePath = getenv('HOME') . '/.trashman';
            $path = $trashmanHomePath . "/" . Utils::shortHash($mountPath);

            $configFile = getenv('HOME') . "/.trashman.yml";
            $forcedTrashmanFolders = array();
            if(file_exists($configFile)) {
                $config = Yaml::parse($configFile);
                if(array_key_exists('forcedTrashmanFolders', $config)) {
                    $forcedTrashmanFolders = $config['forcedTrashmanFolders'];
                    if(array_key_exists($mountPath, $forcedTrashmanFolders)) {
                        $path = $forcedTrashmanFolders[$mountPath];
                    }
                }
            }

            // On vérifie que le dossier soit bien sur le même point de montage.
            // Sinon, on ne peut rien faire.
            if (Mount::getMountPath($path) !== Mount::getMountPath($mountPath)) {
                throw new Exception('"' . $path . '" et "' . $mountPath . '" sont sur des systèmes de fichiers différents.');
            }
        }

        if($create && !file_exists($path)) {
            mkdir($path, 0777, true);
        }

        return $path;
    }

    /**
     * Retourne la liste des racines de montages
     *
     * @return array
     */
    public static function getMountPaths()
    {
        if(self::$mountPaths === NULL) {
            $mountOut = shell_exec("df -P -B1");
            preg_match_all('~^(?P<fs>.*?) +(?P<total>\d+) +(?P<used>\d+) +(?P<free>\d+) +(?P<pc>\d+)% +(?P<mount>/.*?)$~m', $mountOut, $m);

            self::$mountPaths = array();
            foreach($m['mount'] as $i => $mount) {
                self::$mountPaths[$mount] = array(
                    'total' => $m['total'][$i],
                    'used' => $m['used'][$i],
                    'pc' => $m['pc'][$i],
                );
            }

            krsort(self::$mountPaths);
        }

        return self::$mountPaths;
    }

    /**
     * Retourne le dossier de base du montage qui contient le fichier donné.
     *
     * @param string $path
     * @return string
     */
    public static function getMountPath($path)
    {
        $mountPaths = array_keys(self::getMountPaths());
        if (file_exists($path)) {
            $path = realpath($path);
        }

        foreach ($mountPaths as $mountPath) {
            $m = $mountPath;
            if($m === '/') {
                $m = '';
            }

            if (preg_match('~^' . preg_quote($m, '~') . '(/|$)~', $path)) {
                return $mountPath;
            }
        }

        return null;
    }
}
