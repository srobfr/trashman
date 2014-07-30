<?php

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
                        'keep', '-k', InputOption::VALUE_NONE,
                        "Marque les éléments pour suppression ultérieure, sans les modifier."
                )
                ->addOption(
                        'priority', '-p', InputOption::VALUE_OPTIONAL,
                        "Priorité de la suppression. 1: à supprimer en premier, 10 : a supprimer en dernier.", 5
                )
                ->addOption(
                        'list', '-l', InputOption::VALUE_NONE,
                        "Affiche les chemins qui peuvent être supprimés, avec la taille totale."
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

        if($input->getOption('free')) {
            return $this->free($input, $output);
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
        $mountFolders = $this->getMountFolders();

        $paths = $this->in->getArgument('paths');
        if(count($paths) === 0) {
            $paths = array_keys($this->getMountFolders());
        }

        foreach($paths as $path) {
            $mountPath = $this->getMountPath($path);
            // On détermine combien d'espace est demandé.
            $toFree = null;
            if(preg_match('~^(\d+)%$~', $this->in->getOption('free'), $m)) {
                $used = $mountFolders[$mountPath]['used'];
                $total = $mountFolders[$mountPath]['total'];
                $toFree = max(0, $used - $total * ($m[1] / 100));

            } elseif (preg_match('~^(\d+)([KMGT]?)$~', $this->in->getOption('free'), $m)) {
                $multipl = array('K' => 1024, 'M' => 1024 * 1024, 'G' => 1024 * 1024 * 1024,
                    'T' => 1024 * 1024 * 1024 * 1024);

                $toFree = intval($m[1]);
                if (!empty($m[2])) {
                    $toFree *= $multipl[$m[2]];
                }
            } else {
                throw new Exception("Impossible de déterminer l'espace à récupérer. : " . $this->in->getOption('free'));
            }

            if(OutputInterface::VERBOSITY_VERBOSE <= $this->out->getVerbosity()) {
                $this->out->writeln("Point de montage <comment>" . $mountPath . "</comment> : <info>" . $this->humanFilesize($toFree) . "</info> à libérer.");
            }

            if($toFree === 0) {
                // Rien à libérer sur ce montage.
                continue;
            }

            // On supprime autant de fichiers que nécessaire.
            if(!array_key_exists($mountPath, $toFree)) {
                $this->out->writeln("<fg=red>Impossible de déterminer l'espace libre du montage $mountPath</fg=red>");
                continue;
            }

            // On va trouver les fichiers à supprimer, jusqu'à la taille voulue.
            $trashmanFolder = preg_replace('~^//~', '/', $this->getTrashmanFolderPath($mountPath));
            $amount = $toFree;
            if(file_exists($trashmanFolder)) {
                $amount = $this->doDelete($trashmanFolder, $toFree[$mountPath]);
            }

            if($amount > 0) {
                echo "Impossible de libérer suffisament de place ! (reste " . humanFilesize($amount). " à supprimer)\n";
            }
        }
    }

    /**
    * Supprime le contenu du dossier, dans l'ordre alphabétique, jusqu'à ce que le nombre d'octets donné soit atteint.
    * @param string $path
    * @param integer $amount
    */
    private function doDelete($path, $amount) {
        $dryRun = $this->in->getOption('dry-run');

        if (preg_match('~.trashmanDelayedRemoval$~', $path)) {
            // On supprime la cible du lien symbolique
            $target = trim(file_get_contents($path));
            if ($target === false || !file_exists($target)) {
                // Lien cassé, on supprime le lien symbolique.
                if (!$dryRun && !unlink($path)) {
                    $this->out->writeln("<fg=red>Impossible de supprimer le fichier $path</fg=red>");
                }
            }

            if ($amount <= 0) {
                return $amount;
            }

            if ($target !== false && file_exists($target)) {
                $amount = $this->doDelete($target, $amount);
            }

            if (!$dryRun && !unlink($path)) {
                $this->out->writeln("<fg=red>Impossible de supprimer le fichier $path</fg=red>");
            }

        } elseif (is_dir($path)) {
            // On scanne le contenu du dossier
            $scan = scandir($path, SCANDIR_SORT_ASCENDING);
            if (count($scan) === 2) {
                if (!$dryRun) {
                    rmdir($path);
                }
                return $amount;
            }

            if ($amount <= 0) {
                return $amount;
            }

            foreach ($scan as $subPath) {
                if (preg_match('~^\.{1,2}$~', $subPath)) {
                    continue;
                }

                $amount = $this->doDelete($path . '/' . $subPath, $amount);
                if ($amount <= 0) {
                    return $amount;
                }
            }

            // Si le dossier est vide après suppression, on le supprime.
            $scan = scandir($path, SCANDIR_SORT_ASCENDING);
            if (count($scan) === 2 && !$dryRun && !rmdir($path)) {
                $this->out->writeln("<fg=red>Impossible de supprimer le dossier $path</fg=red>");
            }

        } elseif (is_file($path)) {
            if ($amount <= 0) {
                return $amount;
            }

            $this->out->writeln("Encore <fg=green>" . $this->humanFilesize($amount) . "</fg=green> à libérer. Suppression de <fg=yellow>" . $path . "</fg=yellow>");
            $amount -= filesize($path);

            if (!$dryRun && !unlink($path)) {
                $this->out->writeln("<fg=red>Impossible de supprimer le fichier $path</fg=red>");
            }
        } else {
            $this->out->writeln("<fg=red>Type de fichier non géré : $path</fg=red>");
        }

        return $amount;
    }

    /**
     * Retourne le chemin vers le dossier de stockage de Trashman, pour chaque montage.
     * @param string $mountPath
     * @return string
     */
    private function getTrashmanFolderPath($mountPath) {
        return $mountPath . '/.trashman';
    }

    /**
     * Déplace ou marque des fichiers pour suppression ultérieure.
     * 
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    public function trash(InputInterface $input, OutputInterface $output) {
        $dryRun = $this->in->getOption('dry-run');

        $trashManFoldersContent = array();
        
        foreach($input->getArgument('paths') as $path) {
            if (!file_exists($path)) {
                throw new Exception("$path n'existe pas.");
            }

            $path = realpath($path);
            $mountPath = $this->getMountPath($path);
            if ($mountPath === '/') {
                $mountPath = '';
            }

            $prio = str_pad($this->in->getOption('priority'), 4, '0', STR_PAD_LEFT);
            $date = date('Y-m-d_H-i-s.') . str_replace(time() . '.', '', microtime(true));

            $trashmanFolderPath = $this->getTrashmanFolderPath($mountPath);
            if(!array_key_exists($trashmanFolderPath, $trashManFoldersContent)) {
                $trashManFoldersContent[$trashmanFolderPath] = shell_exec('find -type f');
            }

            $trashPath = $this->getTrashmanFolderPath($mountPath) . "/" . $prio . '/' . $date . $path;
            $trashDirPath = dirname($trashPath);

            // On tente de créer le dossier.
            if (!$dryRun && !is_dir($trashDirPath) && !mkdir($trashDirPath, 0700, true)) {
                throw new Exception("Erreur à la création du dossier $trashDirPath");
            }

            if (!$dryRun && !is_writable($trashDirPath)) {
                throw new Exception("Permission écriture refusée dans $trashDirPath");
            }

            if ($this->in->getOption('keep')) {
                // On crée un lien vers ce fichier, pour suppression ultérieure.
                if(!$dryRun) {

                    $delayedRemovalFile = $trashPath . '.trashmanDelayedRemoval';
                    if(!preg_match("~".preg_quote($delayedRemovalFile)."$~m", $trashManFoldersContent[$trashmanFolderPath])) {
                        file_put_contents($delayedRemovalFile, $path);
                        $this->out->writeln("<fg=yellow>$path</fg=yellow> marqué pour suppression avec la priorité <fg=blue>$prio</fg=blue>");
                    } else {
                        $this->out->writeln("<fg=yellow>$path</fg=yellow> déjà marqué pour suppression.");
                    }
                }
            } else {
                // On déplace le fichier.
                if(!$dryRun && !rename($path, $trashPath)) {
                    throw new Exception("Impossible de déplacer $path vers $trashDirPath/");
                }

                $this->out->writeln("<fg=yellow>$path</fg=yellow> déplacé avec la priorité <fg=blue>$prio</fg=blue>");
            }
        }
    }

    /**
     * Retourne la liste des racines de montages
     * 
     * @return array
     */
    public function getMountFolders()
    {
        if($this->mountFolders === NULL) {
            $mountOut = shell_exec("df -B1");
            preg_match_all('~^(?P<fs>.*?) +(?P<total>\d+) +(?P<used>\d+) +(?P<free>\d+) +(?P<pc>\d+)% +(?P<mount>/.*?)$~m', $mountOut, $m);

            $this->mountFolders = array();
            foreach($m['mount'] as $i => $mount) {
                $this->mountFolders[$mount] = array(
                    'total' => $m['total'][$i],
                    'used' => $m['used'][$i],
                    'pc' => $m['pc'][$i],
                );
            }

            krsort($this->mountFolders);
        }
        
        return $this->mountFolders;
    }

    
    /**
     * Retourne le dossier de base du montage qui contient le fichier donné.
     * 
     * @param string $path
     * @return string
     */
    public function getMountPath($path)
    {
        $mountFolders = array_keys($this->getMountFolders());

        if (file_exists($path)) {
            $path = realpath($path);
        }

        foreach ($mountFolders as $mountFolder) {
            $m = $mountFolder;
            if($m === '/') {
                $m = '';
            }

            if (preg_match('~^' . preg_quote($m, '~') . '(/|$)~', $path)) {
                return $mountFolder;
            }
        }

        return null;
    }

    /**
     * Retourne un entier en valeur lisible.
     * @param integer $bytes
     * @param integer $decimals
     * @return string
     */
    public function humanFilesize($bytes, $decimals = 2)
    {
        $size = array('', 'K', 'M', 'G', 'T', 'P', 'E', 'Z', 'Y');
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$size[$factor];
    }

}
