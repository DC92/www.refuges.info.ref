<?php
require_once ("meta_donnee.php");

$vue->titre="Ajouter un point dans refuges.info";
$vue->types_point_affichables=types_point_affichables(); // Menu des types de points

// Hook ext/RefugesInfo/trace pour enregistrer la trace
$mode = 'Ajout point';
//TODO metre les paramètres du point
$user_row = [
  'username' => $commentaire->auteur_commentaire,//TODO
  'user_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
];
$data = [
  'id_point' => $commentaire->id_point,
  'title' => $point->nom,
  'uri' => $_SERVER['REQUEST_URI'],
];
$vars = [
  'mode',
  'user_row',
  'data',
];
extract($phpbb_dispatcher->trigger_event('refugesinfo.ajout_point', compact($vars)));
