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

/**
 * Méthodes utiles.
 *
 * @author simon.robert
 */
class Utils
{

    /**
     * Retourne la valeur max.
     * @return string
     */
    public static function bcmax()
    {
        $args = func_get_args();
        if (count($args) == 0) {
            return false;
        }

        $max = $args[0];
        foreach ($args as $value) {
            if (bccomp($value, $max) == 1) {
                $max = $value;
            }
        }
        return $max;
    }

    /**
     * Retourne un hash court de la chaîne donnée.
     * @param string $str
     * @return string
     */
    public static function shortHash($str)
    {
        return substr(md5($str), 0, 5);
    }

    /**
     * Retourne un entier en valeur lisible.
     * @param string $bytes
     * @param integer $decimals
     * @return string
     */
    public static function humanFilesize($bytes, $decimals = 2)
    {
        $size = array('', 'K', 'M', 'G', 'T', 'P', 'E', 'Z', 'Y');
        $factor = floor((strlen($bytes) - 1) / 3);
        return bcdiv($bytes, bcpow('1024', strval($factor)), $decimals) . @$size[$factor];
    }

}
