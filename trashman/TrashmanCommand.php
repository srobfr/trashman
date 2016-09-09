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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Commande principale
 *
 * @author simon.robert
 */
class TrashmanCommand extends Command
{

    /**
     * Points de montages de la machine.
     * @var array
     */
    private $mountFolders;

    /**
     * Interface d'entrée
     * @var InputInterface
     */
    private $in;

    /**
     * Interface de sortie
     * @var OutputInterface
     */
    private $out;

    /**
     * Configuration de la commande
     */
    protected function configure()
    {
        $this
                ->setName('trashman')
                ->setDescription("Utilitaire pour la suppression de fichiers.")
                ->addArgument(
                        'paths', InputArgument::IS_ARRAY,
                        "Fichiers ou dossiers concernés."
                )
                ->addOption(
                        'dry-run', '-d', InputOption::VALUE_NONE,
                        "Affiche ce qui serait fait, sans appliquer les modifications."
                )
                ->addOption(
                        'all-mountpoints', '-a', InputOption::VALUE_NONE,
                        "Combiné à --free, tente de nettoyer tous les points de montage."
                )
                ->addOption(
                        'priority', '-p', InputOption::VALUE_OPTIONAL,
                        "Priorité de la suppression :
                            0: Suppression immédiate.
                            1: Suppression en priorité
                            ...
                            10: Suppression non prioritaire", 5
                )
                ->addOption(
                        'free', '-f', InputOption::VALUE_OPTIONAL,
                        "Libère de l'espace disque sur les systèmes de fichiers correspondants aux chemins donnés.
Les formats possibles sont :
    '90%' : Supprime assez de fichiers pour obtenir moins de 90% d'occupation disque.
    '1000', '5K', '5M', '5G' : Supprime assez de fichiers pour libérer l'espace donné."
                );
    }

    /**
     * Execute la commande
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->in = $input;
        $this->out = $output;
        Logger::$output = $output;

        if($input->getOption('free')) {
            return $this->free();
        }

        if(count($input->getArgument('paths')) === 0) {
            // La page d'aide.
            $command = $this->getApplication()->find('help');
            $arguments = array('command_name' => 'trashman');
            $input = new ArrayInput($arguments);
            return $command->run($input, $output);
        }

        return $this->trash($input, $output);
    }

    /**
     * Libère définitivement de l'espace disque
     */
    public function free() {
        $mountPaths = Mount::getMountPaths();

        $paths = $this->in->getArgument('paths');
        if($this->in->getOption("all-mountpoints")) {
            // Tous les points de montage.
            $paths = array_keys(Mount::getMountPaths());
        }

        if (count($paths) === 0) {
            throw new Exception("Aucun point de montage spécifié.");
        }

        foreach($paths as $path) {
            $mountPath = Mount::getMountPath($path);
            // On détermine combien d'espace est demandé.
            $toFree = null;
            $m = null;

            if(preg_match('~^(\d+)%$~', $this->in->getOption('free'), $m)) {
                $used = $mountPaths[$mountPath]['used'];
                $total = $mountPaths[$mountPath]['total'];
                $toFree = Utils::bcmax('0', bcsub($used, bcdiv(bcmul($total, $m[1]), '100')));

            } elseif (preg_match('~^(\d+)([KMGT]?)$~', $this->in->getOption('free'), $m)) {
                $multipl = array('K' => '1024', 'M' => bcpow('1024', '2'), 'G' => bcpow('1024', '3'),
                    'T' => bcpow('1024', '4'));

                $toFree = $m[1];
                if (!empty($m[2])) {
                    $toFree = bcmul($toFree, $multipl[$m[2]]);
                }
            } else {
                throw new Exception("Impossible de déterminer l'espace à récupérer. : " . $this->in->getOption('free'));
            }

            if (Utils::bcmax('0', $toFree) === '0') {
                // Rien à libérer sur ce montage.
                continue;
            }

            Logger::log(Utils::humanFilesize($toFree) . " à libérer sur " . $mountPath);

            // On commence par le dossier toDelete dans le dossier trashman du montage.
            $trashmanFolder = Mount::getTrashmanFolderPath($mountPath);
            $trashmanToDeleteFolder = $trashmanFolder . "/toDelete";
            $toFree = $this->doDelete($trashmanToDeleteFolder, $toFree);

            while(Utils::bcmax('0', $toFree) !== '0') {
                $path = Database::getNextPathToDelete($mountPath);
                if($path === null) {
                    // Terminé, mais on n'a pas pu récupérer assez de place.
                    Logger::error("Impossible de récupérer assez de place sur \"" . $mountPath
                                  . "\" (" . Utils::humanFilesize($toFree) . " manquants).");
                    break;
                }

                $toFree = $this->doDelete($path, $toFree);
            }
        }
    }

    /**
    * Supprime le contenu du dossier, dans l'ordre alphabétique, jusqu'à ce que le nombre d'octets donné soit atteint.
    * @param string $path
    * @param string $amount
    */
    private function doDelete($path, $amount) {
        if(@lstat($path) === false) {
            // Chemin inexistant.
            Logger::debug("Chemin inexistant : $path");
            Database::deletePath($path);
            return $amount;
        }

        $dryRun = $this->in->getOption('dry-run');
        if (Utils::bcmax('0', $amount) === '0') {
            // Plus rien à supprimer, on arrête.
            return $amount;
        }

        Logger::log(Utils::humanFilesize($amount) . " " . $path);

        if (is_dir($path)) {
            // On scanne tous les sous-dossiers & fichiers.
            Logger::debug("$path est un dossier.");
            $scan = scandir($path, SCANDIR_SORT_ASCENDING);
            foreach ($scan as $subPath) {
                if (preg_match('~^\.{1,2}$~', $subPath)) {
                    // Raccourcis "." et ".." => ignorés.
                    continue;
                }

                $amount = $this->doDelete($path . '/' . $subPath, $amount);
            }

            // Si le dossier est vide après suppression, on le supprime.
            $scan = scandir($path, SCANDIR_SORT_ASCENDING);
            if (count($scan) === 2) {
                // Dossier vide, on le supprime.
                if (!$dryRun) {
                    Logger::debug("Suppression du dossier vide : $path");
                    rmdir($path);
                    Database::deletePath($path);
                }

                return $amount;
            }

        } elseif (is_file($path) || is_link($path)) {
            $amount = bcsub($amount, trim(shell_exec("stat -c%s " . escapeshellarg($path))));

            if (!$dryRun) {
                Logger::debug("Suppression du fichier : $path");
                unlink($path);
                Database::deletePath($path);
            }
        } else {
            Logger::error("Type de fichier non géré : " . $path);
            if (!$dryRun) {
                Database::deletePath($path);
            }
        }

        return $amount;
    }

    /**
     * Déplace ou marque des fichiers pour suppression ultérieure.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    public function trash(InputInterface $input, OutputInterface $output) {
        $dryRun = $this->in->getOption('dry-run');
        $priority = $this->in->getOption('priority');
        if (!is_numeric($priority)) {
            throw new Exception("Priorité invalide : " . $priority);
        }

        $priority = intval($priority);

        foreach($input->getArgument('paths') as $path) {
            if(preg_match("~^\.{1,2}$~", $path)) {
                continue;
            }

            if (!file_exists($path)) {
                Logger::error('Le chemin "' . $path . '" n\'existe pas.');
                continue;
            }

            $path = realpath($path);
            Logger::log($path);

            $mountPath = Mount::getMountPath($path);
            if ($mountPath === '/') {
                $mountPath = '';
            }

            Logger::debug("Mountpoint pour " . $path . " : " . $mountPath);

            if ($priority === 0) {
                // Suppression immédiate. On déplace le fichier dans le dossier dédié.
                try {
                    $toDeleteFolderPath = Mount::getTrashmanFolderPath($mountPath, true) . "/toDelete";
                } catch(Exception $e) {
                    // Impossible de traiter ce chemin.
                    Logger::error($e->getMessage());
                    continue;
                }

                if(!file_exists($toDeleteFolderPath)) {
                    mkdir($toDeleteFolderPath, 0777, true);
                }

                $destName = Utils::shortHash($path . microtime(true));
                $destPath = $toDeleteFolderPath . '/' . $destName;
                if(!$dryRun) {
                    rename($path, $destPath);
                }

                Logger::log($path . ' -> ' . $destPath);
                continue;
            }

            // Suppression délayée.
            try {
                if(!$dryRun) {
                    Database::insert($path, $mountPath, $priority);
                }
            } catch(Exception $e) {
                // Impossible de traiter ce chemin.
                Logger::error($e->getMessage());
                continue;
            }
        }
    }
}
