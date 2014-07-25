<?php

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
