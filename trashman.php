<?php

use trashman\TrashmanApplication;
define('ROOT_DIR', __DIR__);

$autoloader = require __DIR__ . '/vendor/autoload.php';
$autoloader->set('trashman\\', array(ROOT_DIR));

$application = new TrashmanApplication();
$application->run();

die;
function getTrashmanFolderPath($mountPath) {
    if(is_writable($mountPath)) {
        return $mountPath . '/.trashman';
    } else {
        return $_SERVER['HOME'] . '/.trashman/' . md5($mountPath);
    }
}

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

if($dryRun) {
    echo "Mode DRY-RUN - Aucune modification ne sera effectuée.\n";
}

$mountFolders = getMountFolders();

// Suppression effective des fichiers
if (array_key_exists('free', $args) && preg_match('~^([\d]+)%$~', $args['free'], $m)) {
    $amountToFree = getAmountToFree(intval($m[1]));
    $paths = $arguments->getInvalidArguments();
    if(count($paths) === 0) $paths = array_keys ($amountToFree);
    foreach($paths as $path) {
        if (!file_exists($path)) {
            echo $path . " n'existe pas !\n";
            continue;
        }

        $path = realpath($path);
        
        $mountPath = getMountPath($path, $mountFolders);
        
        // On va libérer de l'espace sur le montage $mountPath
        if(!array_key_exists($mountPath, $amountToFree)) {
            echo "Impossible de déterminer d'espace libre du montage $mountPath\n";
            exit(5);
        }
        
        if($amountToFree[$mountPath] === 0) {
            continue;
        }

        echo "Nettoyage du montage '" . $mountPath . "' ...\n";
        
        // On va trouver les fichiers à supprimer, jusqu'à la taille voulue.
        $trashmanFolder = preg_replace('~^//~', '/', getTrashmanFolderPath($mountPath));
        $amount = $amountToFree[$mountPath];
        if(file_exists($trashmanFolder)) {
            $amount = doDelete($trashmanFolder, $amountToFree[$mountPath]);
        }

        if($amount > 0) {
            echo "Impossible de libérer suffisament de place ! (reste " . humanFilesize($amount). " à supprimer)\n";
        }
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
    $trashPath = getTrashmanFolderPath($mountPath) . "/" . $prio . '/' . $date . $path;
    $trashDirPath = dirname($trashPath);
    
    // On tente de créer le dossier.
    if (!$dryRun && !is_dir($trashDirPath) && !mkdir($trashDirPath, 0700, true)) {
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
 * Supprime le contenu du dossier, dans l'ordre alphabétique, jusqu'à ce que le nombre d'octets donné soit atteint.
 * @param string $path
 * @param integer $amount
 */
function doDelete($path, $amount) {
    global $dryRun;

    if(is_link($path)) {
        // On supprime la cible du lien symbolique
        $target = readlink($path);
        if($target === false || !file_exists($target)) {
            // Lien cassé, on supprime le lien symbolique.
            if(!$dryRun && !unlink($path)) {
                echo "Unable to remove broken link " . $path . "\n";
                exit(9);
            }
        }

        if($amount <= 0) return $amount;

        if($target !== false && file_exists($target)) {
            $amount = doDelete($target, $amount);
        }

        if(!$dryRun && !unlink($path)) {
            echo "Unable to remove link " . $path . "\n";
            exit(6);
        }

    } elseif(is_dir($path)) {
        // On scanne le contenu du dossier
        $scan = scandir($path, SCANDIR_SORT_ASCENDING);
        if(count($scan) === 2) {
            if(!$dryRun) rmdir($path);
            return $amount;
        }
        
        if($amount <= 0) return $amount;
        
        foreach($scan as $subPath) {
            if(preg_match('~^\.{1,2}$~', $subPath)) continue;
            $amount = doDelete($path . '/' . $subPath, $amount);
            if($amount <= 0) return $amount;
        }
        
        // Si le dossier est vide après suppression, on le supprime.
        $scan = scandir($path, SCANDIR_SORT_ASCENDING);
        if(count($scan) === 2) {

            if(!$dryRun && !rmdir($path)) {
                echo "Unable to remove folder " . $path . "\n";
                exit(8);
            }
        }

    } elseif (is_file($path)) {
        if($amount <= 0) return $amount;
        
        echo "Encore " . humanFilesize($amount)." à libérer. Suppression de : " . $path . "\n";
        $amount -= filesize($path);

        if(!$dryRun && !unlink($path)) {
            echo "Unable to remove file " . $path . "\n";
            exit(7);
        }
    } else {
        echo "Type non reconnu : $path\n";
    }
    
    
    
    return $amount;
}

function humanFilesize($bytes, $decimals = 2) {
    $size = array('o','ko','Mo','Go','To','Po','Eo','Zo','Yo');
    $factor = floor((strlen($bytes) - 1) / 3);
    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$size[$factor];
}

/**
 * Retourne la quantité d'octets à libérer pour attendre le pourcentage donné.
 * @param integer $maxUsedPc
 * @param array $mountFolders
 */
function getAmountToFree($maxUsedPc) {
    preg_match_all('~([\d]+)\s+([\d]+)\s+[\d]+%\s+(.*?)$~m', shell_exec('df'), $m);
    $r = array();
    foreach($m[3] as $k => $mount) {
        $used = $m[1][$k] * 1000;
        $free = $m[2][$k] * 1000;
        $total = $used + $free;
        
        $r[$mount] = max(0, $used - $total * ($maxUsedPc / 100));
    }
    
    return $r;
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