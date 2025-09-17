<?php

/**
 * @author 26BZ (https://github.com/26BZ)
 */

if (!defined("WHMCS")) {
  die("This file cannot be accessed directly");
}

if (!function_exists('discord_verification_config')) {
  require_once __DIR__ . '/discord_verification.php';
}

use WHMCS\Database\Capsule;
use WHMCS\View\Menu\Item as MenuItem;

function getDiscordModuleConfig()
{
  static $config = null;

  if ($config === null) {
    $config = [];
    $moduleSettings = Capsule::table('tbladdonmodules')
      ->where('module', 'discord_verification')
      ->get();

    foreach ($moduleSettings as $setting) {
      $config[$setting->setting] = $setting->value;
    }

    $config['client_id'] = getenv('DISCORD_CLIENT_ID') ?: ($config['client_id'] ?? '');
    $config['secret_id'] = getenv('DISCORD_SECRET_ID') ?: ($config['secret_id'] ?? '');
    $config['bot_token'] = getenv('DISCORD_BOT_TOKEN') ?: ($config['bot_token'] ?? '');
  }

  return $config;
}

function checkDiscordMembership($userId, $guildId, $botToken)
{
  $url = "https://discord.com/api/guilds/$guildId/members/$userId";
  $ch = curl_init($url);

  curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => [
      'Authorization: Bot ' . $botToken,
      'Content-Type: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT => 10
  ]);

  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return $httpCode === 200;
}

function removeRole($userId, $guildId, $roleId, $botToken)
{
  $url = "https://discord.com/api/guilds/$guildId/members/$userId/roles/$roleId";
  $ch = curl_init($url);

  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => "DELETE",
    CURLOPT_HTTPHEADER => [
      'Authorization: Bot ' . $botToken,
      'Content-Type: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT => 10
  ]);

  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return in_array($httpCode, [204, 404]);
}

function assignDiscordRole($userId, $clientId, $guildId, $activeRoleId, $defaultRoleId, $botToken)
{
  $activeProducts = Capsule::table('tblhosting')
    ->where('userid', $clientId)
    ->where('domainstatus', 'Active')
    ->count();

  $roleId = $activeProducts > 0 ? $activeRoleId : $defaultRoleId;

  logActivity("Assigning role to Discord user $userId (Client ID: $clientId) - Active products: $activeProducts - Role ID: $roleId");

  $url = "https://discord.com/api/guilds/{$guildId}/members/$userId/roles/$roleId";
  $ch = curl_init($url);

  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => "PUT",
    CURLOPT_HTTPHEADER => [
      'Authorization: Bot ' . $botToken,
      'Content-Type: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT => 10
  ]);

  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($response === false) {
    throw new Exception('Failed to assign role: cURL error');
  }

  if (!in_array($httpCode, [204, 200])) {
    throw new Exception('Failed to assign role: HTTP ' . $httpCode . ' - ' . $response);
  }

  return true;
}

add_hook('CustomFieldSave', 1, function ($vars) {
  $fieldName = Capsule::table('tblcustomfields')
    ->where('id', $vars['fieldid'])
    ->value('fieldname');

  if (strtolower($fieldName) === 'discord') {
    $value = $vars['value'];
    $cleanValue = preg_replace('/[^0-9]/', '', $value);

    if (!empty($cleanValue)) {
      // Discord IDs are typically 17-20 digits long
      if (strlen($cleanValue) < 17 || strlen($cleanValue) > 20) {
        throw new Exception('Invalid Discord ID format');
      }

      return [
        'value' => $cleanValue
      ];
    }
  }
});

function syncExpiredServicesRoles()
{
  $config = getDiscordModuleConfig();

  if (empty($config['bot_token']) || empty($config['guild_id'])) {
    return;
  }

  try {
    $expiredServices = Capsule::table('tblhosting')
      ->join('tblcustomfieldsvalues', 'tblhosting.userid', '=', 'tblcustomfieldsvalues.relid')
      ->join('tblcustomfields', 'tblcustomfieldsvalues.fieldid', '=', 'tblcustomfields.id')
      ->where('tblcustomfields.fieldname', 'LIKE', '%discord%')
      ->where('tblhosting.domainstatus', '!=', 'Active')
      ->whereNotNull('tblcustomfieldsvalues.value')
      ->where('tblcustomfieldsvalues.value', '!=', '')
      ->select('tblhosting.userid', 'tblcustomfieldsvalues.value as discord_id')
      ->distinct()
      ->get();

    foreach ($expiredServices as $service) {
      if (is_numeric($service->discord_id)) {
        try {
          assignDiscordRole(
            $service->discord_id,
            $service->userid,
            $config['guild_id'],
            $config['active_role_id'],
            $config['default_role_id'],
            $config['bot_token']
          );
        } catch (Exception $e) {
          logActivity("Failed to sync role for expired service - User ID: {$service->userid} - " . $e->getMessage());
        }
      }
    }
  } catch (Exception $e) {
    logActivity("Failed to sync expired services roles: " . $e->getMessage());
  }
}

add_hook('ClientAreaSecondaryNavbar', 1, function ($secondaryNavbar) {
  try {
    if ($accountMenu = $secondaryNavbar->getChild('Account')) {
      $accountMenu->addChild(
        'discordVerification',
        [
          'name' => 'Verify Discord',
          'label' => 'Verify Discord',
          'uri' => '/index.php?m=discord_verification',
          'order' => 84,
        ]
      );
    }
  } catch (Exception $e) {
    logActivity("Discord Verification: Secondary navbar hook error - " . $e->getMessage());
  }
});

add_hook('DailyCronJob', 1, function () {
  $config = getDiscordModuleConfig();

  if (empty($config['bot_token']) || empty($config['guild_id'])) {
    return;
  }

  logActivity('Discord Verification: Starting daily role synchronization');

  try {
    $clients = Capsule::table('tblcustomfieldsvalues')
      ->join('tblcustomfields', 'tblcustomfieldsvalues.fieldid', '=', 'tblcustomfields.id')
      ->where('tblcustomfields.fieldname', 'discord')
      ->where('tblcustomfieldsvalues.value', '!=', '')
      ->select('tblcustomfieldsvalues.relid as client_id', 'tblcustomfieldsvalues.value as discord_id')
      ->get();

    foreach ($clients as $client) {
      try {
        assignRoleToUser(
          $client->discord_id,
          $client->client_id,
          $config['guild_id'],
          $config['active_role_id'],
          $config['default_role_id'],
          $config['bot_token']
        );

        logActivity("Discord role synchronized for client {$client->client_id}");
      } catch (Exception $e) {
        logActivity("Failed to sync Discord role for client {$client->client_id}: " . $e->getMessage());
      }
    }

    logActivity('Discord Verification: Daily role synchronization completed');
  } catch (Exception $e) {
    logActivity('Discord Verification: Daily cron job failed - ' . $e->getMessage());
  }
});

add_hook('ClientAreaPrimarySidebar', 1, function ($primarySidebar) {
  $config = getDiscordModuleConfig();

  if (empty($config['enable_client_widget']) || $config['enable_client_widget'] !== 'on') {
    return;
  }

  $filename = basename($_SERVER['REQUEST_URI'], '.php');
  $parseFile = explode('.', $filename);

  if ($parseFile[0] !== 'clientarea') {
    return;
  }

  try {
    if (!isset($_SESSION['uid']) || empty($_SESSION['uid'])) {
      return;
    }

    $clientId = intval($_SESSION['uid']);
    if ($clientId === 0) {
      return;
    }

    $discordData = Capsule::table('tblcustomfieldsvalues')
      ->join('tblcustomfields', 'tblcustomfieldsvalues.fieldid', '=', 'tblcustomfields.id')
      ->where('tblcustomfields.fieldname', 'discord')
      ->where('tblcustomfieldsvalues.relid', $clientId)
      ->value('tblcustomfieldsvalues.value');

    $discordId = null;
    $discordUsername = null;
    $discordAvatar = null;

    if (!empty($discordData)) {
      if (substr($discordData, 0, 1) === '{') {
        $decoded = json_decode($discordData, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['id'])) {
          $discordId = $decoded['id'];
          $discordUsername = $decoded['username'] ?? 'Discord User';
        }
      } else {
        $discordId = $discordData;
      }

      if ($discordId && !empty($config['bot_token'])) {
        try {
          $userInfo = getDiscordUserInfo($discordId, $config['bot_token']);
          if ($userInfo) {
            $discordUsername = $userInfo['username'];
            if (isset($userInfo['discriminator']) && $userInfo['discriminator'] !== '0') {
              $discordUsername .= '#' . $userInfo['discriminator'];
            }

            if (isset($userInfo['avatar'])) {
              $discordAvatar = "https://cdn.discordapp.com/avatars/{$discordId}/{$userInfo['avatar']}.png";
            }
          }
        } catch (Exception $e) {
        }
      }

      if (empty($discordAvatar)) {
        $discordAvatar = "https://cdn.discordapp.com/embed/avatars/0.png";
      }
    }

    $activeProducts = Capsule::table('tblhosting')
      ->where('userid', $clientId)
      ->where('domainstatus', 'Active')
      ->count();

    $hasActiveProducts = $activeProducts > 0;

    $primarySidebar->addChild('Discord-Verification', [
      'label' => 'Discord Status',
      'uri' => '#',
      'order' => '10',
      'icon' => 'fab fa-discord'
    ]);

    $discordPanel = $primarySidebar->getChild('Discord-Verification');
    $discordPanel->moveToBack();
    $discordPanel->setOrder(10);

    if ($discordId) {
      $statusBadge = $hasActiveProducts
        ? '<span class="label label-success">Active Member</span>'
        : '<span class="label label-info">Verified</span>';

      $flexLayout = '<div style="display: flex; align-items: center; margin: 10px 0;">';

      if ($discordAvatar) {
        $flexLayout .= '<div style="flex: 0 0 auto; margin-right: 12px;">';
        $flexLayout .= '<img src="' . $discordAvatar . '" alt="Discord Avatar" style="width: 48px; height: 48px; border-radius: 50%;">';
        $flexLayout .= '</div>';
      }

      $flexLayout .= '<div style="flex: 1;">';

      if ($discordUsername) {
        $smallBadge = str_replace('class="label ', 'class="label label-sm ', $statusBadge);
        $smallBadge = str_replace('span class', 'span style="font-size: 10px; padding: 2px 5px; margin-left: 5px;" class', $smallBadge);

        $flexLayout .= '<div style="display: flex; align-items: center; margin-bottom: 3px;">';
        $flexLayout .= '<strong>' . htmlspecialchars($discordUsername) . '</strong>';
        $flexLayout .= $smallBadge;
        $flexLayout .= '</div>';
      } else {
        $flexLayout .= $statusBadge . '<br>';
      }

      $flexLayout .= '<small class="text-muted">ID: ' . htmlspecialchars($discordId) . '</small>';
      $flexLayout .= '</div>';
      $flexLayout .= '</div>';

      $discordPanel->addChild('discord-info', [
        'uri' => '/index.php?m=discord_verification',
        'label' => $flexLayout,
        'order' => 0
      ]);

      $discordPanel->setFooterHtml(
        '<div class="panel-footer text-center">' .
          '<a href="/index.php?m=discord_verification" class="btn btn-default btn-sm">' .
          '<i class="fab fa-discord"></i> Manage Discord' .
          '</a>' .
          '</div>'
      );
    } else {
      $discordPanel->addChild('discord-status', [
        'uri' => '/index.php?m=discord_verification',
        'label' => '<span class="label label-warning">Not Verified</span>',
        'order' => 1
      ]);

      $discordPanel->addChild('discord-info', [
        'uri' => '#',
        'label' => '<small class="text-muted">Connect your Discord account</small>',
        'order' => 2
      ]);

      $discordPanel->setFooterHtml(
        '<div class="panel-footer text-center">' .
          '<a href="/index.php?m=discord_verification" class="btn btn-success btn-sm">' .
          '<i class="fab fa-discord"></i> Verify Discord' .
          '</a>' .
          '</div>'
      );
    }
  } catch (Exception $e) {
    logActivity('Discord Verification: Widget error - ' . $e->getMessage());
  }
});

add_hook('AfterModuleSuspend', 1, function ($vars) {
  $config = getDiscordModuleConfig();

  if (empty($config['bot_token']) || empty($config['guild_id'])) {
    return;
  }

  try {
    $userId = Capsule::table('tblhosting')
      ->where('id', $vars['params']['serviceid'])
      ->value('userid');

    if (!$userId) {
      return;
    }

    $discordId = Capsule::table('tblcustomfields')
      ->join('tblcustomfieldsvalues', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
      ->where('tblcustomfields.fieldname', 'LIKE', '%discord%')
      ->where('tblcustomfieldsvalues.relid', $userId)
      ->value('tblcustomfieldsvalues.value');

    if ($discordId && is_numeric($discordId)) {
      logActivity("Service suspended - User ID: {$userId}");
      assignDiscordRole(
        $discordId,
        $userId,
        $config['guild_id'],
        $config['active_role_id'],
        $config['default_role_id'],
        $config['bot_token']
      );
    }
  } catch (Exception $e) {
    logActivity("Failed to update Discord role on service suspension - " . $e->getMessage());
  }
});

add_hook('AfterModuleTerminate', 1, function ($vars) {
  $config = getDiscordModuleConfig();

  if (empty($config['bot_token']) || empty($config['guild_id'])) {
    return;
  }

  try {
    $userId = Capsule::table('tblhosting')
      ->where('id', $vars['params']['serviceid'])
      ->value('userid');

    if (!$userId) {
      return;
    }

    $discordId = Capsule::table('tblcustomfields')
      ->join('tblcustomfieldsvalues', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
      ->where('tblcustomfields.fieldname', 'LIKE', '%discord%')
      ->where('tblcustomfieldsvalues.relid', $userId)
      ->value('tblcustomfieldsvalues.value');

    if ($discordId && is_numeric($discordId)) {
      logActivity("Service terminated - User ID: {$userId}");
      assignDiscordRole(
        $discordId,
        $userId,
        $config['guild_id'],
        $config['active_role_id'],
        $config['default_role_id'],
        $config['bot_token']
      );
    }
  } catch (Exception $e) {
    logActivity("Failed to update Discord role on service termination - " . $e->getMessage());
  }
});

add_hook('ServiceStatusChange', 1, function ($vars) {
  $config = getDiscordModuleConfig();

  if (empty($config['bot_token']) || empty($config['guild_id'])) {
    return;
  }

  try {
    $discordId = Capsule::table('tblcustomfields')
      ->join('tblcustomfieldsvalues', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
      ->where('tblcustomfields.fieldname', 'LIKE', '%discord%')
      ->where('tblcustomfieldsvalues.relid', $vars['userid'])
      ->value('tblcustomfieldsvalues.value');

    if ($discordId && is_numeric($discordId)) {
      logActivity("Service status changed from {$vars['oldstatus']} to {$vars['status']} - User ID: {$vars['userid']}");
      assignDiscordRole(
        $discordId,
        $vars['userid'],
        $config['guild_id'],
        $config['active_role_id'],
        $config['default_role_id'],
        $config['bot_token']
      );
    }
  } catch (Exception $e) {
    logActivity("Failed to update Discord role on service status change - User ID: {$vars['userid']} - " . $e->getMessage());
  }
});

add_hook('ClientStatusChange', 1, function ($vars) {
  $config = getDiscordModuleConfig();

  if (empty($config['bot_token']) || empty($config['guild_id'])) {
    return;
  }

  try {
    $discordId = Capsule::table('tblcustomfields')
      ->join('tblcustomfieldsvalues', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
      ->where('tblcustomfields.fieldname', 'LIKE', '%discord%')
      ->where('tblcustomfieldsvalues.relid', $vars['userid'])
      ->value('tblcustomfieldsvalues.value');

    if ($discordId && is_numeric($discordId)) {
      if ($vars['status'] == 'Inactive') {
        removeRole($discordId, $config['guild_id'], $config['active_role_id'], $config['bot_token']);
        assignDiscordRole(
          $discordId,
          $vars['userid'],
          $config['guild_id'],
          $config['active_role_id'],
          $config['default_role_id'],
          $config['bot_token']
        );
      } else {
        assignDiscordRole(
          $discordId,
          $vars['userid'],
          $config['guild_id'],
          $config['active_role_id'],
          $config['default_role_id'],
          $config['bot_token']
        );
      }
    }
  } catch (Exception $e) {
    logActivity("Failed to update Discord role on client status change - User ID: {$vars['userid']} - " . $e->getMessage());
  }
});

add_hook('IntelligentSearch', 1, function ($vars) {
  $searchResults = [];
  $searchTerm = trim($vars['searchTerm']);
  
  if (!empty($searchTerm) && is_numeric($searchTerm) && strlen($searchTerm) >= 17 && strlen($searchTerm) <= 20) {
    try {
      $results = Capsule::table('tblcustomfields')
        ->join('tblcustomfieldsvalues', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
        ->join('tblclients', 'tblcustomfieldsvalues.relid', '=', 'tblclients.id')
        ->where('tblcustomfields.fieldname', 'discord')
        ->where(function ($query) use ($searchTerm) {
          $query->where('tblcustomfieldsvalues.value', $searchTerm)
                ->orWhere('tblcustomfieldsvalues.value', 'LIKE', '%"id":"' . $searchTerm . '"%');
        })
        ->select(
          'tblclients.id',
          'tblclients.firstname',
          'tblclients.lastname',
          'tblclients.email',
          'tblclients.status as client_status',
          'tblcustomfieldsvalues.value as discord_data'
        )
        ->limit($vars['numResults'])
        ->get();

      foreach ($results as $client) {
        $discordUsername = '';
        
        if (substr($client->discord_data, 0, 1) === '{') {
          $decoded = json_decode($client->discord_data, true);
          if (json_last_error() === JSON_ERROR_NONE && isset($decoded['username'])) {
            $discordUsername = $decoded['username'];
          }
        }
        
        $clientName = trim($client->firstname . ' ' . $client->lastname);
        if (empty($clientName)) {
          $clientName = 'Client #' . $client->id;
        }
        
        $subtitle = $client->email;
        if (!empty($discordUsername)) {
          $subtitle .= ' • Discord: ' . htmlspecialchars($discordUsername);
        }
        $subtitle .= ' • ID: ' . $searchTerm;
        
        $statusIcon = 'fal fa-user';
        switch (strtolower($client->client_status)) {
          case 'active':
            $statusIcon = 'fal fa-user-check';
            break;
          case 'inactive':
            $statusIcon = 'fal fa-user-times';
            break;
          case 'closed':
            $statusIcon = 'fal fa-user-slash';
            break;
        }
        
        $searchResults[] = [
          'title' => $clientName,
          'href' => 'clientssummary.php?userid=' . $client->id,
          'subTitle' => $subtitle,
          'icon' => $statusIcon,
        ];
      }
      
      if (!empty($searchResults)) {
        logActivity("Discord Verification: Admin searched for Discord ID '{$searchTerm}' - " . count($searchResults) . " result(s) found");
      }
      
    } catch (Exception $e) {
      logActivity("Discord Verification: IntelligentSearch error - " . $e->getMessage());
    }
  }
  
  return $searchResults;
});
