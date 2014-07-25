<?php

namespace trashman;

use Exception;
use Symfony\Component\Console\Command\Command;
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
                        'dry-run', '-d', InputOption::VALUE_OPTIONAL,
                        "Affiche ce qui serait fait, sans appliquer les modifications."
                )
                ->addOption(
                        'keep', '-k', InputOption::VALUE_OPTIONAL,
                        "Marque les éléments pour suppression ultérieure, sans les modifier."
                )
                ->addOption(
                        'priority', '-p', InputOption::VALUE_OPTIONAL,
                        "Priorité de la suppression. 1: à supprimer en premier, 10 : a supprimer en dernier.", 5
                )
                ->addOption(
                        'list', '-l', InputOption::VALUE_OPTIONAL,
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
        if($input->getOption('free')) {
            return $this->free($input, $output);
        }

        return $this->trash($input, $output);
    }

    /**
     * Libère définitivement de l'espace disque
     * 
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    public function free(InputInterface $input, OutputInterface $output) {
        $mountFolders = $this->getMountFolders();

        $paths = $input->getArgument('paths');
        if(count($paths) === 0) {
            $paths = array_keys($this->getMountFolders());
        }
        
        foreach($input->getArgument('paths') as $path) {
            $mountPath = $this->getMountPath($path);
            // On détermine combien d'espace est demandé.
            $toFree = null;
            if(preg_match('~^(\d+)%$~', $input->getOption('free'), $m)) {
                $used = $mountFolders[$mountPath]['used'];
                $total = $mountFolders[$mountPath]['total'];
                $toFree = max(0, $used - $total * ($m[1] / 100));

            } elseif (preg_match('~^(\d+)([KMGT]?)$~', $input->getOption('free'), $m)) {
                $multipl = array('K' => 1024, 'M' => 1024 * 1024, 'G' => 1024 * 1024 * 1024,
                    'T' => 1024 * 1024 * 1024 * 1024);

                $toFree = intval($m[1]);
                if (!empty($m[2])) {
                    $toFree *= $multipl[$m[2]];
                }
            } else {
                throw new Exception("Impossible de déterminer l'espace à récupérer. : " . $input->getOption('free'));
            }

            if(OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity()) {
                $output->writeln("Point de montage <comment>" . $mountPath . "</comment> : <info>" . $this->humanFilesize($toFree) . "</info> à libérer.");
            }

            if($toFree === 0) {
                continue;
            }

            // On supprime autant de fichiers que nécessaire.

        }
    }

    /**
     * Déplace ou marque des fichiers pour suppression ultérieure.
     * 
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    public function trash(InputInterface $input, OutputInterface $output) {

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
