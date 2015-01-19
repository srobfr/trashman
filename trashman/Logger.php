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

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Logger
 *
 * @author simon.robert
 */
class Logger
{

    /**
     * Sortie standard.
     *
     * @var OutputInterface
     */
    public static $output;

    /**
     * Affiche un message sur la sortie standard.
     * @param type $msg
     */
    public static function log($msg)
    {
        if (self::$output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            self::$output->writeln($msg);
        }
    }

    /**
     * Affiche un message sur la sortie standard.
     * @param type $msg
     */
    public static function debug($msg)
    {
        if (self::$output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            self::$output->writeln($msg);
        }
    }

    /**
     * Affiche un message sur la sortie standard.
     * @param type $msg
     */
    public static function error($msg)
    {
        if (self::$output->getVerbosity() >= OutputInterface::VERBOSITY_NORMAL) {
            $out = self::$output;
            if ($out instanceof ConsoleOutputInterface) {
                $out = $out->getErrorOutput();
            }

            $out->writeln("<fg=red>" . $msg . "</fg=red>");
        }
    }

}
