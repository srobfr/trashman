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

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Classe de l'application
 *
 * @author simon.robert
 */
class TrashmanApplication extends Application
{

    /**
     * Récupère le nom de la commande saisie.
     *
     * @param InputInterface $input L'interface de saisie
     *
     * @return string Le nom de la commande
     */
    protected function getCommandName(InputInterface $input)
    {
        // Retourne le nom de votre commande.
        return 'trashman';
    }

    /**
     * Récupère les commandes par défaut qui sont toujours disponibles.
     *
     * @return array Un tableau d'instances de commandes par défaut
     */
    protected function getDefaultCommands()
    {
        // Conserve les commandes par défaut du noyau pour avoir la
        // commande HelpCommand en utilisant l'option --help
        $defaultCommands = parent::getDefaultCommands();
        $defaultCommands[] = new TrashmanCommand();
        return $defaultCommands;
    }

    /**
     * Surchargé afin que l'application accepte que le premier argument ne
     * soit pas le nom.
     */
    public function getDefinition()
    {
        $inputDefinition = parent::getDefinition();
        // efface le premier argument, qui est le nom de la commande
        $inputDefinition->setArguments();

        return $inputDefinition;
    }

}
