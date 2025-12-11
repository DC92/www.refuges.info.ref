<?php
// Ce fichier centralise les fonctions PHP liées à tracer l'environnement des posts et enregistrements
// Attention: Le code suivant s'exécute dans un "namespace" bien défini

/* Tests à faire
Création user
Création topic
Création post
Réponse post
Quote post
Création point
Création commentaire

Déconnecté refusé
Déconnecté
Connecté refusé
Connecté

Nouveau
Ancien (avant les traces)

Traces avec tri
[i] post,
[i] point,
[i] commentaire,
user
*/

//TODO Relecture code
//TODO fichiers de la base geo

namespace RefugesInfo\trace\event;

include __DIR__.'/../geoip2/geoip2.phar';
use GeoIp2\Database\Reader;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
class listener implements EventSubscriberInterface
{
  protected $forum_root, $u_action, $tables, $limit, $argument_names;

  public function __construct()
  {
    global $request, $db;

    $request->enable_super_globals();

    // Calcul de la racine du forum
    preg_match('|'.$_SERVER['DOCUMENT_ROOT'].'(.*/)ext/|', __DIR__, $forum_dirs);
    $this->forum_root = $forum_dirs[1];
    $this->u_action = $this->forum_root.'mcp.php?i=-'.str_replace(['\\event','\\'], ['','-'], __NAMESPACE__).'-mcp-main_module';

    // Liste les tables et les colonnes pour ne prendre que les arguments qui correspondent
    $this->limit = $_GET['limit'] ?? 20;
    $this->argument_names = [
      'ext_error' => 'text',
      'browser_operator' => 'text',
      'trace_id' => 'number',
      'user_id' => 'number',
      'asn_id' => 'text',
      'uri' => 'text', // Pour profile user
      'checked' => 'number',
      'topic_id' => 'number',
      'post_id' => 'number',
      'id_point' => 'number',
      'id_commentaire' => 'number',
      'group_id' => 'number',
      'limit' => 'number',
      'offset' => 'number',
    ];

    // Liste les tables et les colonnes pour ne prendre que les arguments qui correspondent
    $this->tables = [
      'trace_requettes LEFT JOIN '.USERS_TABLE.' USING(user_id)',
      'points USING(id_point)',
      'commentaires USING(id_commentaire)',
    ];
  }

  static public function getSubscribedEvents()
  {
    return [
      // Log request
      'core.submit_post_end' => 'log_request_context', // functions_posting.php 2634
      'core.posting_modify_template_vars' => 'log_request_context', // posting.php 2089 (post rejeté)
      'core.ucp_register_register_after' => 'log_request_context', // ucp_register.php 562 (user acceptée)
      'core.ucp_register_modify_template_data' => 'log_request_context', // ucp_register.php 682
      'refugesinfo.ajout_point' => 'log_request_context',
      'refugesinfo.ajout_commentaire' => 'log_request_context',

      // Display traces
      'core.mcp_post_additional_options' => 'display_traces', // mcp_post.php 125
      'core.memberlist_view_profile' => 'display_traces', // memberlist.php 757
      'refugesinfo.trace_status' => 'status', // Pour les lignes du menu du bandeau bandeau
      'refugesinfo.display_traces' => 'display_traces', // Affichage du MCP traces
    ];
  }

  // Log le contexte d'une soumission
  //TODO log d'une création de point
  //TODO affichage point
  public function log_request_context($event, $eventName)
  {
    global $db, $config_wri, $user, $auth;

    if(!count($_POST) || // Not the first page display
      isset($_POST['preview'])) return; // Post preview is not traced

    $ip = $_SERVER['REMOTE_ADDR'];
    $reader_asn = new Reader(__DIR__.'/../geoip2/GeoLite2-ASN.mmdb');
    $geodata_asn = $reader_asn->asn($ip);
    $reader_city = new Reader(__DIR__.'/../geoip2/GeoLite2-City.mmdb');
    $geodata_city = $reader_city->city($ip);

    // Exclusion de certains ASN
    if(in_array($geodata_asn->autonomousSystemNumber ?? 0, $config_wri['trace_block_asn'] ?? [])) {
      $error = $event['error'] ?? []; // Pour ajout venant de l'extension
      $error[] = 'Forbiden origin';
      $event['error'] = $error;
      return;
    }

    // Cherche les infos à logguer
    $data = array_merge(
      array_filter((array) $event),
      array_filter($event['point'] ?? []),
      array_filter($event['commentaire'] ?? []),
      array_filter($event['user_row'] ?? []),
      array_filter($event['data'] ?? []),
      array_filter($event['post_data'] ?? []),
      array_filter($user->data ?? []), // mode, subject, username, topic_type, url
      array_filter($_POST ?? []),
    );

    // Log le contexte d'une création de user rejetée (Except when load the registration page)
    $error = $event['error'] ?? []; // Pour ajout venant de l'extension
    if(isset($_POST['new_password']))

    // Soumission de post mis en approbation par CleanTalk, qui l'enregistre quand même
    if($data['post_visibility']??null === ITEM_UNAPPROVED)
      $error[] = 'Post mis en approbation par CleanTalk';
    $event['error'] = $error;

    $trace_data = [
      // 'trace_id' => autoincrement,
      'ext_error' => count($error) ? json_encode($error) : null,
      'date' => date('r'),
      'checked' => $auth->acl_get('m_'), // Quand le post est édité par un modo
      'appel' => str_replace(['core.', 'refugesinfo.'], '', $eventName),

      // Post & Point
      'topic_id' => intval($data['topic_id'] ?? 0),
      'post_id' => intval($data['post_id'] ?? $event['topic_cur_post_id'] ?? 0),
      'id_point' => intval($data['id_point'] ?? 0),
      'id_commentaire' => intval($data['id_commentaire'] ?? 0),
      'title' => $data['subject'] ?? $data['topic_title'] ?? $data['nom']->nom ?? '',
      'text' => mb_substr(
        $data['message'] ?? $data['texte'] ?? '',
        0,
        256
      ),

      // Serveur
      'uri' => isset($_SERVER['HTTP_HOST']) ?
        (
          ($_SERVER['REQUEST_SCHEME']??'').'://'.
          ($_SERVER['HTTP_HOST'] ?? '').
          ($_SERVER['REQUEST_URI'] ?? '')
        ) : '',
      'referer' => $_SERVER['HTTP_REFERER'] ?? '',

      // Navigateur
      'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
      'language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
      'browser_locale' => $data['mrk_browser_locale'] ?? '',
      'browser_timezone' => $data['mrk_browser_timezone'] ?? '',
      'browser_operator' => $data['mrk_browser_operator'] ?? '',
      'browser_referer' => $data['mrk_browser_referer'] ?? '',

      // Infos enregistrées à la création du user
      // Sont gardées dans la table au cas où on supprimerait le user
      'user_id' => intval($data['user_id'] ?? 0),
      'user_name' => $data['username'] ?? $data['nom_createur'] ?? '',
      'user_email' => $data['user_email'] ?? $data['email'] ?? '',
      'user_lang' => $data['user_lang'] ?? $data['lang'] ?? '',
      'user_timezone' => $data['user_timezone'] ?? $data['tz'] ?? '',
      'ip_enregistrement' => $data['user_ip'] ?? '',
      'host_enregistrement' => gethostbyaddr($data['user_ip'] ?? $data['session_ip'] ?? $_SERVER['REMOTE_ADDR'] ?? ''),
      'creator_id' => 0, //['UINT', NULL], //TODO
      'creator_name' => ($data['poster_id'] ?? 0) > 1 ? $data['username'] : 'Anonymous', //TODO on nom saisi
      'creator_id' => intval($data['poster_id'] ?? 0),

      // ASN / FAI
      'ip' => $ip,
      'host' => gethostbyaddr($ip),
      'asn_id' => 'AS'.$geodata_asn->autonomousSystemNumber,
      'asn_name' => $geodata_asn->autonomousSystemOrganization,
      'country_name' => $geodata_city->country->name,
      'city' => $geodata_city->city->name,
    ];

    // Enregistrement de la trace
    $sql = 'INSERT INTO trace_requettes'.$db->sql_build_array('INSERT', $trace_data);
//*DCMM*/echo var_export($sql,true).'<br>';
//*DCMM*/var_dump($trace_data);
//*DCMM*/var_dump($data);
//exit
    $db->sql_query($sql);
  }

  /*
   * Affichage des traces
   */
  // Hook pour renseigner le bandeau
  public function status($event)
  {
    global $pdo, $config_wri;

    $sql = 'SELECT COUNT(trace_id)'.
      ' FROM '.$this->tables[0].
      ' WHERE uri LIKE \'%edit%\''.
        ' AND ext_error IS NULL'.
        ' AND checked = 0';

    if(isset($config_wri['trace_no_edit_check_groups']))
      $sql .= ' AND group_id NOT IN ('.implode(',', $config_wri['trace_no_edit_check_groups']).')';

    if($res = $pdo->query($sql))
      $event['posts_edit'] = $res->fetch()->count;
  }

  //BEST statistique sur les posts/comptes supprimés
  public function display_traces($event)
  {
    global $db, $template, $auth;

    if(!$auth->acl_get('m_')) // Uniquement pour les modérateurs
      return;

    // Marquer la trace checked
    if(!empty($_GET['trace_id']) && !empty($_GET['to_check'])) {
      $sql = 'UPDATE trace_requettes SET checked = 1 WHERE trace_id = '.$_GET['trace_id'];
      $db->sql_query($sql);
    }

    // Champs d'édition de la requête
    foreach($this->argument_names as $name => $type)
      $template->assign_block_vars('inputs_requete', [
        'NAME' => $name,
        'VALUE' => $_GET[$name] ?? '',
      ]);

    // Affichage d'entête de la table
    $this->affiche_une_ligne(['Trace', 'Statut', 'Utilisateur', 'Machine', 'ASN (FAI)', 'IP', 'Contenu']);

    $where = $this->where($_GET);

    // Nombre de traces répondant aux critères
    $sql_count = 'SELECT COUNT(trace_id)'.
      ' FROM '.$this->tables[0].
      $this->where($_GET);
    $result = $db->sql_query($sql_count);
    $row_count = $db->sql_fetchrow($result);
    $db->sql_freeresult($result);

    // Liste des traces affichables
    $sql = 'SELECT *,trace_requettes.date AS date_trace'.
      ' FROM '.implode(' LEFT JOIN ', $this->tables).
      $this->where($_GET).
      ' ORDER BY trace_id DESC'.
      ' LIMIT '.$this->limit.
      (!empty($_GET['offset']) ? ' OFFSET '.$_GET['offset'] : '');
    $result = $db->sql_query($sql);

    $compteur_traces = 0;
    while($row = $db->sql_fetchrow($result))
      $compteur_traces = $this->affiche_une_trace(array_map('trim', $row), $compteur_traces);
    $db->sql_freeresult($result);

    // S'il n'y a pas de trace dans la table, simplement décode l'IP utilisée.
    /*//TODO $event_ip =
      $ip ??
      $event['member']['user_ip'] ??
      '';

    if(!$compteur_traces && $event_ip) {
      $compteur_traces = $this->affiche_une_trace([
        'ip' => $event_ip,
      ]);
    }*/

    $template->assign_vars([
      'WHERE_SQL' => $where,
      'LIMIT' => $this->limit,
      'REQUETE_SQL' => $sql,
      'NOMBRE_LIGNES' => $compteur_traces,
      'NOMBRE_TRACES' => $row_count['count'] ?? 0,
    ]);
  }

  /*
   * Affichage d'une trace
   */
  private function affiche_une_trace($row, $compteur_traces = 0)
  {
    global $db, $template;

    foreach($row as $name => $value)
      if(intval($value) > 1000000000)
        $row[$name] = date('r', intval($value));

    // Supression des infos non souhaitées dans le dump
    $row = array_filter(array_merge($row, [
      'user_permissions' => null,
      'user_password' => null,
      'user_form_salt' => null,
      'user_sig' => null,
      'user_last_confirm_key ' => null,
    ]));

    // Calcul du statut
    $colonne_statut = [];

    $traduction_appel = [
      ' SPE' => 'SPE ???', //TODO ???
      'submit_post_end' => '',
      'point_ajout_commentaire' => '', //TODO
      'log_request_context ' => '', //TODO
      'ucp_register_register_after' => '',
      'posting_modify_submit_post_before' => '',
      'ucp_register_modify_template_data' => '',
      'posting_modify_template_vars' => '',
      'register' => 'Création d\'un user', //TODO
      'post' => 'Création d\'un sujet',
      'reply' => 'Réponse à un post',
      'quote' => 'Quote d\'un post',
      'edit' => 'Edition d\'un post',
    ];
    $appel = str_replace(array_keys($traduction_appel), $traduction_appel, $row['appel'] ?? '');

    if(!empty($row['ext_error']))
      $colonne_statut[] = 'REJET '.$appel;
    elseif(!empty($row['uri'])) {
  //TODO affichage point
      //BEST lien vers un post mis en approbation
      if(strpos($row['uri'] ?? '', 'point_modification') !== false) {
        if(!empty($row['id_point']))
          $colonne_statut[] = 'Création d\'un <a '.
            'href="'.$this->forum_root.'../point/'.$row['id_point'].'"'.
          '>point</a>';
        elseif(!empty($row['post_id']))
          $colonne_statut[] = 'Création d\'un point et de son <a '.
            'href="'.$this->forum_root.'viewtopic.php?p='.$row['post_id'].'"'.
          '>forum</a>';
        else
          $colonne_statut[] = 'Erreur de modification point sans id_point ni post_id';
      }
      elseif(strpos($row['uri'] ?? '', 'ajout_commentaire') !== false) {
        if(!empty($row['id_point']))
          $colonne_statut[] = 'Création d\'un <a '.
            'href="'.$this->forum_root.'../point/'.$row['id_point'].'#C'.($row['id_commentaire'] ?? 0).'"'.
          '>commentaire</a>';
        else
          $colonne_statut[] = 'Erreur de ajout commentaire sans id_point';
      }
      elseif(strpos($row['uri'] ?? '', 'mode=register') !== false) {
        if(!empty($row['user_id']) && ($row['user_id'] ?? 1) > 1)
          $colonne_statut[] = 'Création du compte <a '.
            'href="'.$this->forum_root.'memberlist.php?mode=viewprofile&u='.$row['user_id'].'"'.
          '>'.($row['user_name'] ?? 'NONAME').'</a>';
        else
          $colonne_statut[] = 'Autre erreur de création du compte';
      }
      elseif(strpos($row['uri'] ?? '', 'mode=post') !== false ||
        strpos($row['uri'] ?? '', 'contactadmin') !== false) {
        if(!empty($row['post_id']))
          $colonne_statut[] = 'Création d\'un <a '.
            'href="'.$this->forum_root.'viewtopic.php?p='.$row['post_id'].'"'.
          '>sujet</a>';
        elseif(!empty($row['topic_id']))
          $colonne_statut[] = 'Création d\'un <a '.
            'href="'.$this->forum_root.'viewtopic.php?t='.$row['topic_id'].'"'.
          '>sujet</a>';
        else
          $colonne_statut[] = 'Erreur de création d\'un post sans topic_id ni post_id';
      }
      elseif(strpos($row['uri'] ?? '', 'posting.php') !== false) { // reply, quote, edit
        if(!empty($row['post_id']))
          $colonne_statut[] = str_replace(
            'post',
            '<a href="'.$this->forum_root.'viewtopic.php?'.
              'p='.$row['post_id'].'#p'.$row['post_id'].'">post'.
            '</a>',
            $appel
          );
        else
          $colonne_statut[] = 'Erreur de posting sans post_id';
      }
      else
        $colonne_statut[] = 'Erreur url inconnue';
    }

    $colonne_statut[] =
      str_replace( // Split encoded lines
        ['","', '["', '"]', 'posting_modify_template_vars : ', 'ucp_register_modify_template_data : '],
        ['<br/>- ', '- ', '', '', ''],
        preg_replace( // Décode unicode if such returned by extensions
          '/\\\\u([a-e0-9]{4})/',
          '&#x$1;',
          $row['ext_error'] ?? '',
        ),
      );

    $colonne_statut[] = !strncmp($row['appel']??'', 'edit', 4) && !empty($row['trace_id']) && empty($row['checked']) ?
      '<br/><a class="check-trace" href="'.$this->u_action.'&trace_id='.$row['trace_id'].'&to_check=1">Marquer vérifié</a>' :
      null;

    // Affiche une ligne du tableau
    $this->affiche_une_ligne([
      isset($row['trace_id']) ? [ // Trace
        'Trace n° <a href="'.$this->u_action.'&trace_id='.$row['trace_id'].'">'.$row['trace_id'].'</a>',
        preg_replace('/\+[0-9]+/i', '', $row['date_trace']),
      ] : [],
      $colonne_statut,
      array_merge(
        // Auteur
        !strncmp($row['appel']??'', 'edit', 4) && !empty($row['creator_id']) ? [
          'Créé par: <a title="Voir son profil"'.
            'href="'.$this->forum_root.'memberlist.php?mode=viewprofile&u='.($row['creator_id'] ?? 0).'">'.
            ($row['creator_name'] ?? '').'</a>',
          'Modifié par:',
        ] : [],
        [ // Utilisateur
          '<a title="Voir son profil"'.
            'href="'.$this->forum_root.'memberlist.php?mode=viewprofile&u='.($row['user_id'] ?? 0).'">'.
            ($row['user_name'] ?? '').'</a>',
          ($row['user_id'] ?? 0) > 1 ?
            '<a title="Voir ses traces"'.
              'href="'.$this->u_action.'&user_id='.$row['user_id'].'">Contributions</a>' : null,
          $row['user_email'] ?? '' ?
            '<a title="Avis Cleantalk"'.
              'href="https://cleantalk.org/email-checker/'.$row['user_email'].'">'.($row['user_email'] ?? '').'</a>' : null,
          !empty($row['user_posts']) && ($row['user_id'] ?? 1) > 1 ? 'Posts: '.$row['user_posts'] : null,
          !empty($row['user_lang']) ? 'Langue: '.$row['user_lang'] : null,
          !empty($row['user_timezone']) ? $row['user_timezone'] : null,
          !empty($row['user_login_attempts']) ? 'Tentatives login: '.$row['user_login_attempts'] : null,
          !empty($row['user_inactive_time']) ? 'Temps inactif: '.$row['user_inactive_time'] : null,
          !empty($row['user_inactive_reason']) ? 'Raison inactif: '.$row['user_inactive_reason'] : null,
        ]
      ),
      [ // Machine
        $row['browser_operator'] ?? '',
        str_replace(['<t>','</t>'], '', $row['user_sig'] ?? '') ?
          'Signature: '.$row['user_sig'] :
          null,
        !empty($row['browser_locale']) ?
          'Langue: '.$row['browser_locale'] :
          null,
        !empty($row['browser_timezone']) ?
          'Timezone: '.$row['browser_timezone'] :
          null,
      ],
      [ // FAI
        $row['host'] ??'',
        '<a title="Fiche de l\'ASN"'.
          'href="https://ipinfo.io/'.($row['asn_id'] ?? $row['ip'] ?? '').'">'.
          ($row['asn_name'] ?? $row['host'] ?? $row['ip'] ?? '').'</a>',
        ($row['country_name'] ?? '').' / '.($row['city'] ?? ''),
        '<a title="Les contributions passant par '.($row['asn_name'] ?? '').'"'.
          'href="'.$this->u_action.'&asn_id='.($row['asn_id'] ?? '').'">Contributions</a>',
      ],
      isset($row['ip']) ? [ // IP
        $row['ip'],
        '<a href="https://ipinfo.io/'.$row['ip'].'">IpInfo</a>',
        '<a href="https://whatismyipaddress.com/ip/'.$row['ip'].'">WhatIsMyIP</a>',
        '<a href="https://www.iplocation.net/ip-lookup?query='.$row['ip'].'">IpLocation</a>',
        '<a href="https://stopforumspam.com/ipcheck/'.$row['ip'].'">StopForumSpam</a>',
        '<a href="https://www.spamcop.net/w3m?action=checkblock&ip='.
          $row['ip'].'">SpamCop</a>',
        '<a href="https://www.abuseipdb.com/check/'.$row['ip'].'">AbuseIPdb</a>',
        '<a href="https://cleantalk.org/blacklists/'.$row['ip'].'">CleanTalk</a>',
      ] : [],
      [ // Contenu
        '<b>'.($row['title'] ?? '').'</b>',
        mb_substr($row['text'] ?? '', 0, 240).(strlen($row['text'] ?? '') > 239 ? '...' : ''),
      ],
    ]);

    // Affiche le résultat complet sur la fiche d'une trace
    if(isset($_GET['trace_id']))
      foreach($row as $name => $value)
        $template->assign_block_vars('full_trace', [
          'NAME' => $name,
          'VALUE' => $value,
        ]);

    return $compteur_traces + 1;
  }

  private function affiche_une_ligne($values)
  {
    global $template;

    $template->assign_block_vars('output_requetes_raw', []);
    foreach(array_filter($values) as $v)
      $template->assign_block_vars('output_requetes_raw.output_requetes_col', [
        'VALUE' => getType($v) === 'array' ? implode('<br/>', array_filter($v)) : $v,
      ]);
  }

  private function where($cond)
  {
    // Arguments pour mcp_post_additional_options & core.memberlist_view_profile
    if(!empty($cond['p']))
      $cond['post_id'] = $cond['p'];
    if(!empty($cond['u'])) {
      $cond['user_id'] = $cond['u'];
      $cond['uri'] = 'register';
    }
    //TODO anonymous SQL jointures point / commentaire /Auteur (nom si non conecté) ??? + pour forum ? / nom point / texte commentaire

    $conditions = [];

    foreach($this->argument_names as $name => $type)
      if(isset($cond[$name]))
        foreach(explode(',', $cond[$name]) as $k => $v) {
          $vs = array_reverse(explode('!', $v ?? '')); // Separate the ! at the beginning
          $requ = isset($vs[1]) ? ' != ' : ' = ';
          $rnot = isset($vs[1]) ? ' NOT' : '';

          if($type === 'number')
            $conditions[] = $name.$requ.intval($vs[0]);
          elseif($vs[0] === 'null')
            $conditions[] = "$name IS$rnot NULL";
          else
            $conditions[] = "$name$rnot LIKE '%{$vs[0]}%'";
        }

    return $conditions ? ' WHERE '.implode(' AND ', $conditions) : '';
  }
}
