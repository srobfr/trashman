<?php
require __DIR__ . '/vendor/autoload.php';

$arguments = new \cli\Arguments();

$arguments->addFlag(array('help', 'h'), "Affiche cet écran d'aide");
$arguments->addFlag(array('keep', 'k'), "Marque le fichier pour une suppression future, sans le supprimer immédiatement.");
$arguments->addFlag(array('dry-run', 'd'), "N'effectue pas les modifications.");

$arguments->addOption(array('priority', 'p'), array(
    'default' => '5',
    'description' => "Priorité de la suppression de ce fichier (10=en premier, 0=en dernier)"));

$arguments->addOption(array('free', 'f'), array(
    'default' => '80%',
    'description' => "Pourcentage maximum d'espace disque occupé à atteindre"));

$arguments->parse();

$args = $arguments->getArguments();

if ($arguments['help']) {
    echo "TrashMan - Utilitaire simple de suppression délayée de fichiers\n\n";
    echo $arguments->getHelpScreen();
    echo "\n\n";
    exit(0);
}

$prio = isset($args['priority']) && is_numeric($args['priority']) ? intval($args['priority']) : 5;
$prio = str_pad($prio, 4, '0', STR_PAD_LEFT);
$date = date('Y-m-d_H-i-s.') . str_replace(time() . '.', '', microtime(true));
$dryRun = array_key_exists('dry-run', $args);

$mountFolders = getMountFolders();

// TODO suppression effective des fichiers
if (array_key_exists('free', $args) && preg_match('~^([\d]+)%$~', $args['free'], $m)) {
    foreach($arguments->getInvalidArguments() as $path) {
        if (!file_exists($path)) {
            echo $path . " n'existe pas !\n";
            continue;
        }

        $path = realpath($path);
        
        $mountPath = getMountPath($path, $mountFolders);
        if($mountPath === '/') $mountPath = '';
        
        // On va libérer de l'espace sur le montage $mountPath
        
    }
    
    exit(0);
}

// Déplacement ou marquage des fichiers à supprimer
foreach($arguments->getInvalidArguments() as $path) {
    if (!file_exists($path)) {
        echo $path . " n'existe pas !\n";
        continue;
    }
    
    $path = realpath($path);

    $mountPath = getMountPath($path, $mountFolders);
    if($mountPath === '/') $mountPath = '';
    $trashPath = $mountPath . "/tmp/.trashman/" . $prio . '/' . $date . $path;
    $trashDirPath = dirname($trashPath);
    
    // On tente de créer le dossier.
    if (!$dryRun && !file_exists($trashDirPath) && !mkdir($trashDirPath, 0700, true)) {
        echo "Erreur à la création du dossier : " . $trashDirPath . "\n";
        exit(1);
    }
    
    if (!$dryRun && !is_writable($trashDirPath)) {
        echo "Permission denied on : " . $trashDirPath . "\n";
        exit(2);
    }
    
    if (array_key_exists('keep', $args)) {
        // On crée un symlink vers ce fichier.
        if(!$dryRun && !symlink($path, $trashPath)) {
            echo "Unable to create symlink : " . $trashPath . "\n";
            exit(3);
        }
        
        echo $path . " marqué pour suppression (prio=$prio)\n";
    } else {
        // On déplace le fichier.
        if(!$dryRun && !rename($path, $trashPath)) {
            echo "Unable to move " . $path . " to " . $trashPath . "\n";
            exit(4);
        }
        
        echo $path . " déplacé. (prio=$prio)\n";
    }
}

/**
 * Retourne la liste des racines de montages
 * @return array
 */
function getMountFolders() {
    $mountOut = shell_exec("mount");
    preg_match_all('~ on (.*?) type ~', $mountOut, $m);
    $mounts = $m[1];
    rsort($mounts);
    return $mounts;
}

/**
 * Retourne le dossier de base du montage qui contient le fichier donné.
 * @param string $path
 * @return string
 */
function getMountPath($path, $mountFolders) {
    if(file_exists($path)) $path = realpath($path);
    foreach($mountFolders as $mountFolder) {
        if(strpos($path, $mountFolder) === 0) {
            return $mountFolder;
        }
    }
    
    return null;
}