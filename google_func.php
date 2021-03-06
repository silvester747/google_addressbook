<?php

/**
 * Functions which can be used from plugin or cli.
 *
 * @version 1.0
 * @author Stefan L. Wagner
 */

require_once(dirname(__FILE__) . '/../../vendor/autoload.php');
require_once(dirname(__FILE__) . '/google_addressbook_backend.php');
require_once(dirname(__FILE__) . '/xml_utils.php');

class google_func
{
  public static $settings_key_token = 'google_current_token';
  public static $settings_key_use_plugin = 'google_use_addressbook';
  public static $settings_key_auto_sync = 'google_autosync';
  public static $settings_key_auth_code = 'google_auth_code';

  static function get_client()
  {
    $client = new Google_Client();
    $client->setApplicationName('rc-google-addressbook');
    $client->setScopes("http://www.google.com/m8/feeds/");
    $client->setClientId('775403024003-e5m4h02j1hsgjj0dipef3ugbg8ee9emb.apps.googleusercontent.com');
    $client->setClientSecret('HcRLtRTEGIqScjMpkREddO6L');
    $client->setRedirectUri('urn:ietf:wg:oauth:2.0:oob');
    $client->setAccessType('offline');
    return $client;
  }

  static function get_auth_code($user) {
    $prefs = $user->get_prefs();
    return $prefs[google_func::$settings_key_auth_code];
  }

  static function get_current_token($user, $from_db = false)
  {
    $prefs = $user->get_prefs();
    return $prefs[google_func::$settings_key_token];
  }

  static function save_current_token($user, $token)
  {
    $prefs = array(google_func::$settings_key_token => $token);
    if(!$user->save_prefs($prefs)) {
      // TODO: error handling
    }
  }

  static function is_enabled($user)
  {
    $prefs = $user->get_prefs();
    return (bool)$prefs[google_func::$settings_key_use_plugin];
  }

  static function is_autosync($user)
  {
    $prefs = $user->get_prefs();
    return (bool)$prefs[google_func::$settings_key_auto_sync];
  }

  static function google_authenticate($client, $user)
  {
    $rcmail = rcmail::get_instance();
    $token = google_func::get_current_token($user);
    if($token != null) {
      $client->setAccessToken($token);
    }

    $success = false;
    $msg = '';

    try {
      if($client->getAccessToken() == null || $client->getAccessToken() == '[]') {
        $code = google_func::get_auth_code($user);
        if(empty($code)) {
          throw new Exception($rcmail->gettext('noauthcode', 'google_addressbook'));
        }
        $client->authenticate($code);
        $success = true;
      } else if($client->isAccessTokenExpired()) {
        $token = json_decode($client->getAccessToken());
        if(empty($token->refresh_token)) {
          throw new Exception("Error fetching refresh token.");
          // this only happens if google client id is wrong and access type != offline
        } else {
          $client->refreshToken($token->refresh_token);
          $success = true;
        }
      } else {
        // token valid, nothing to do.
        $success = true;
      }
    } catch(Exception $e) {
      $msg = $e->getMessage();
    }

    if($success) {
      $token = $client->getAccessToken();
      google_func::save_current_token($user, $token);
    }

    return array('success' => $success, 'message' => $msg);
  }

  static function google_sync_contacts($user)
  {
    $rcmail = rcmail::get_instance();
    $client = google_func::get_client();

    $auth_res = google_func::google_authenticate($client, $user);
    if(!$auth_res['success']) {
      return $auth_res;
    }

    $feed = 'https://www.google.com/m8/feeds/groups/default/full'.'?v=3.0';
    $val = $client->getAuth()->authenticatedRequest(new Google_Http_Request($feed));
    if($val->getResponseHttpCode() == 401) {
      return array('success' => false, 'message' => "Authentication failed.");
    } else if($val->getResponseHttpCode() == 403) {
      return array('success' => false, 'message' => $rcmail->gettext('googleforbidden', 'google_addressbook'));
    } else if($val->getResponseHttpCode() != 200) {
      return array('success' => false, 'message' => $rcmail->gettext('googleunreachable', 'google_addressbook'));
    }

    $xml = xml_utils::xmlstr_to_array($val->getResponseBody());
    $gid = $xml['entry'][0]['id'][0]['@text'];

    $feed = 'https://www.google.com/m8/feeds/contacts/default/full'.'?max-results=9999'.'&v=3.0'.'&group='.urlencode($gid);
    $val = $client->getAuth()->authenticatedRequest(new Google_Http_Request($feed));
    if($val->getResponseHttpCode() == 401) {
      return array('success' => false, 'message' => "Authentication failed.");
    } else if($val->getResponseHttpCode() == 403) {
      return array('success' => false, 'message' => $rcmail->gettext('googleforbidden', 'google_addressbook'));
    } else if($val->getResponseHttpCode() != 200) {
      return array('success' => false, 'message' => $rcmail->gettext('googleunreachable', 'google_addressbook'));
    }

    $xml = xml_utils::xmlstr_to_array($val->getResponseBody());

    $backend = new google_addressbook_backend($rcmail->get_dbh(), $user->ID);
    $backend->delete_all();

    $num_entries = 0;
    foreach($xml['entry'] as $entry) {
      //write_log('google_addressbook', 'getting contact: '.$entry['title'][0]['@text']);
      $record = array();
      $name = $entry['gd:name'][0];
      $record['name']= trim($name['gd:fullName'][0]['@text']);
      $record['firstname'] = trim($name['gd:givenName'][0]['@text']);
      $record['surname'] = trim($name['gd:familyName'][0]['@text']);
      $record['middlename'] = trim($name['gd:additionalName'][0]['@text']);
      $record['prefix'] = $name['gd:namePrefix'][0]['@text'];
      $record['suffix'] = $name['gd:nameSuffix'][0]['@text'];
      if(empty($record['name'])) {
        $record['name'] = $entry['title'][0]['@text'];
      }

      if(empty($record['name']) &&
         empty($record['firstname']) &&
         empty($record['surname']) &&
         empty($record['middlename']) &&
         !array_key_exists('gd:email', $entry)) {
          continue;
      }

      if(array_key_exists('gd:email', $entry)) {
        foreach($entry['gd:email'] as $email) {
          list($rel, $type) = explode('#', $email['@attributes']['rel'], 2);
          $type = empty($type) ? '' : ':'.$type;
          $record['email'.$type][] = $email['@attributes']['address'];
        }
      }

      if(array_key_exists('gd:phoneNumber', $entry)) {
        foreach($entry['gd:phoneNumber'] as $phone) {
          list($rel, $type) = explode('#', $phone['@attributes']['rel'], 2);
          $type = empty($type) ? '' : ':'.$type;
          $record['phone'.$type][] = $phone['@text'];
        }
      }

      if(array_key_exists('link', $entry)) {
        foreach($entry['link'] as $link) {
          $rel = $link['@attributes']['rel'];
          $href = $link['@attributes']['href'];
          if($rel == 'http://schemas.google.com/contacts/2008/rel#photo') {
            // etag is only set if image is available
            if(isset($link['@attributes']['etag'])) {
              $resp = $client->getAuth()->authenticatedRequest(new Google_Http_Request($href));
              if($resp->getResponseHttpCode() == 200) {
                $record['photo'] = $resp->getResponseBody();
              }
            }
            break;
          }
        }
      }

      $num_entries++;

      $backend->insert($record, false);
    }

    return array('success' => true, 'message' => $num_entries.$rcmail->gettext('contactsfound', 'google_addressbook'));
  }
}

?>
