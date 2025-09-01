<?php

/**
 * @author 26BZ (https://github.com/26BZ)
 * @license MIT License
 */

if (!defined("WHMCS")) {
  die("This file cannot be accessed directly");
}

use WHMCS\Authentication\CurrentUser;
use WHMCS\Database\Capsule;


function discord_verification_admin_output($vars)
{
  $currentUser = new CurrentUser();
  if (!$currentUser->isAuthenticatedAdmin()) {
    echo '<div class="alert alert-danger">Access Denied</div>';
    return;
  }

  $modulelink = $vars['modulelink'];
  $config = [
    'bot_token' => getenv('DISCORD_BOT_TOKEN') ?: $vars['bot_token'],
    'guild_id' => $vars['guild_id'],
    'active_role_id' => $vars['active_role_id'],
    'default_role_id' => $vars['default_role_id'],
    'client_id' => $vars['client_id'],
    'secret_id' => $vars['secret_id']
  ];

  if (isset($_POST['action'])) {
    switch ($_POST['action']) {
      case 'sync_all':
        handleSyncAllUsers($config);
        echo '<div class="alert alert-success"><i class="fa fa-check"></i> All users have been queued for synchronization.</div>';
        break;
      case 'sync_user':
        if (isset($_POST['client_id']) && isset($_POST['discord_id'])) {
          handleSyncSingleUser($_POST['client_id'], $_POST['discord_id'], $config);
          echo '<div class="alert alert-success"><i class="fa fa-check"></i> User synchronization completed.</div>';
        }
        break;
      case 'remove_user':
        if (isset($_POST['client_id'])) {
          handleRemoveUser($_POST['client_id']);
          echo '<div class="alert alert-success"><i class="fa fa-check"></i> Discord association removed.</div>';
        }
        break;
    }
  }

  $stats = getDiscordStatistics();
  $verifiedUsers = getVerifiedUsers();

  displayDiscordOverview($config, $stats);

  displaySyncActionsPanel($modulelink);

  displayVerifiedUsersTable($modulelink, $verifiedUsers);
}

function getDiscordStatistics()
{
  try {
    $totalVerified = Capsule::table('tblcustomfieldsvalues')
      ->join('tblcustomfields', 'tblcustomfieldsvalues.fieldid', '=', 'tblcustomfields.id')
      ->where('tblcustomfields.fieldname', 'discord')
      ->where('tblcustomfieldsvalues.value', '!=', '')
      ->count();

    $activeRoleUsers = Capsule::table('tblcustomfieldsvalues')
      ->join('tblcustomfields', 'tblcustomfieldsvalues.fieldid', '=', 'tblcustomfields.id')
      ->join('tblhosting', 'tblcustomfieldsvalues.relid', '=', 'tblhosting.userid')
      ->where('tblcustomfields.fieldname', 'discord')
      ->where('tblcustomfieldsvalues.value', '!=', '')
      ->where('tblhosting.domainstatus', 'Active')
      ->distinct()
      ->count('tblcustomfieldsvalues.relid');

    $defaultRoleUsers = $totalVerified - $activeRoleUsers;

    $thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
    $recentVerifications = Capsule::table('tblactivitylog')
      ->where('description', 'like', '%Discord successfully linked%')
      ->where('date', '>=', $thirtyDaysAgo)
      ->count();

    return [
      'total_verified' => $totalVerified,
      'active_role_users' => $activeRoleUsers,
      'default_role_users' => $defaultRoleUsers,
      'recent_verifications' => $recentVerifications
    ];
  } catch (Exception $e) {
    logActivity('Discord Verification: Failed to get statistics - ' . $e->getMessage());
    return [
      'total_verified' => 0,
      'active_role_users' => 0,
      'default_role_users' => 0,
      'recent_verifications' => 0
    ];
  }
}

function getVerifiedUsers()
{
  try {
    $users = Capsule::table('tblcustomfieldsvalues')
      ->join('tblcustomfields', 'tblcustomfieldsvalues.fieldid', '=', 'tblcustomfields.id')
      ->join('tblclients', 'tblcustomfieldsvalues.relid', '=', 'tblclients.id')
      ->where('tblcustomfields.fieldname', 'discord')
      ->where('tblcustomfieldsvalues.value', '!=', '')
      ->select(
        'tblclients.id as client_id',
        'tblclients.firstname',
        'tblclients.lastname',
        'tblclients.companyname',
        'tblclients.email',
        'tblcustomfieldsvalues.value as discord_data'
      )
      ->get();

    foreach ($users as $user) {
      $discordData = $user->discord_data;
      if (strpos($discordData, '{') === 0) {
        // JSON format: {"id":"123","username":"user#1234"}
        $decoded = json_decode($discordData, true);
        $user->discord_id = $decoded['id'] ?? $discordData;
        $user->discord_username = $decoded['username'] ?? null;
      } else {
        // Plain ID format
        $user->discord_id = $discordData;
        $user->discord_username = null;
      }

      $activeProducts = Capsule::table('tblhosting')
        ->where('userid', $user->client_id)
        ->where('domainstatus', 'Active')
        ->count();

      $user->has_active_products = $activeProducts > 0;

      $lastSync = Capsule::table('tblactivitylog')
        ->where('description', 'like', '%Discord role sync%')
        ->where('description', 'like', '%Client ID: ' . $user->client_id . '%')
        ->orderBy('date', 'desc')
        ->first();

      $user->last_sync = $lastSync ? $lastSync->date : null;

      if (!$user->discord_username && $user->discord_id) {
        $user->discord_username = fetchDiscordUsername($user->discord_id);
      }
    }

    return $users;
  } catch (Exception $e) {
    logActivity('Discord Verification: Failed to get verified users - ' . $e->getMessage());
    return [];
  }
}

function handleSyncAllUsers($config)
{
  try {
    $users = Capsule::table('tblcustomfieldsvalues')
      ->join('tblcustomfields', 'tblcustomfieldsvalues.fieldid', '=', 'tblcustomfields.id')
      ->where('tblcustomfields.fieldname', 'discord')
      ->where('tblcustomfieldsvalues.value', '!=', '')
      ->select('tblcustomfieldsvalues.relid as client_id', 'tblcustomfieldsvalues.value as discord_data')
      ->get();

    $syncCount = 0;
    foreach ($users as $user) {
      try {
        $discordId = $user->discord_data;
        if (strpos($user->discord_data, '{') === 0) {
          $decoded = json_decode($user->discord_data, true);
          $discordId = $decoded['id'] ?? $user->discord_data;
        }

        assignRoleToUser(
          $discordId,
          $user->client_id,
          $config['guild_id'],
          $config['active_role_id'],
          $config['default_role_id'],
          $config['bot_token']
        );
        $syncCount++;
      } catch (Exception $e) {
        logActivity("Discord Verification: Failed to sync user {$user->client_id} - " . $e->getMessage());
      }
    }

    logActivity("Discord Verification: Bulk sync completed - {$syncCount} users synchronized");
  } catch (Exception $e) {
    logActivity('Discord Verification: Bulk sync failed - ' . $e->getMessage());
  }
}

function handleSyncSingleUser($clientId, $discordData, $config)
{
  try {
    $discordId = $discordData;
    if (strpos($discordData, '{') === 0) {
      $decoded = json_decode($discordData, true);
      $discordId = $decoded['id'] ?? $discordData;
    }

    assignRoleToUser(
      $discordId,
      $clientId,
      $config['guild_id'],
      $config['active_role_id'],
      $config['default_role_id'],
      $config['bot_token']
    );

    logActivity("Discord Verification: Manual sync completed for client {$clientId}");
  } catch (Exception $e) {
    logActivity("Discord Verification: Manual sync failed for client {$clientId} - " . $e->getMessage());
  }
}

function handleRemoveUser($clientId)
{
  try {
    $config = getDiscordConfig();

    $fieldId = Capsule::table('tblcustomfields')
      ->where('fieldname', 'discord')
      ->value('id');

    if (!$fieldId) {
      logActivity("Discord Verification: Discord field not found for client {$clientId}");
      return;
    }

    $discordData = Capsule::table('tblcustomfieldsvalues')
      ->where('fieldid', $fieldId)
      ->where('relid', $clientId)
      ->value('value');

    if (!$discordData) {
      logActivity("Discord Verification: No Discord data found for client {$clientId}");
      return;
    }

    $discordId = $discordData;
    if (strpos($discordData, '{') === 0) {
      $decoded = json_decode($discordData, true);
      $discordId = $decoded['id'] ?? $discordData;
    }

    if ($config['bot_token'] && $config['guild_id'] && $discordId) {
      removeAllDiscordRoles($discordId, $config['guild_id'], $config['active_role_id'], $config['default_role_id'], $config['bot_token']);
    }

    Capsule::table('tblcustomfieldsvalues')
      ->where('fieldid', $fieldId)
      ->where('relid', $clientId)
      ->delete();

    logActivity("Discord Verification: Discord association and roles removed for client {$clientId}");
  } catch (Exception $e) {
    logActivity("Discord Verification: Failed to remove Discord association for client {$clientId} - " . $e->getMessage());
  }
}

function displayDiscordOverview($config, $stats)
{
  echo '<div class="row">';
  echo '<div class="col-md-6">';
  echo '<div class="panel panel-default">';
  echo '<div class="panel-heading">Discord Configuration</div>';
  echo '<div class="panel-body">';
  echo '<div class="row">';
  echo '<div class="col-sm-6">';
  echo '<strong>Guild ID:</strong> ' . ($config['guild_id'] ? '<code>' . htmlspecialchars($config['guild_id']) . '</code>' : '<span class="text-muted">Not configured</span>') . '<br>';
  echo '<strong>Active Role ID:</strong> ' . ($config['active_role_id'] ? '<code>' . htmlspecialchars($config['active_role_id']) . '</code>' : '<span class="text-muted">Not configured</span>') . '<br>';
  echo '</div>';
  echo '<div class="col-sm-6">';
  echo '<strong>Default Role ID:</strong> ' . ($config['default_role_id'] ? '<code>' . htmlspecialchars($config['default_role_id']) . '</code>' : '<span class="text-muted">Not configured</span>') . '<br>';
  echo '<strong>Bot Token:</strong> ' . ($config['bot_token'] ? '<span class="text-success">Configured</span>' : '<span class="text-danger">Missing</span>') . '<br>';
  echo '</div>';
  echo '</div>';
  echo '</div>';
  echo '</div>';
  echo '</div>';

  echo '<div class="col-md-6">';
  echo '<div class="panel panel-default">';
  echo '<div class="panel-heading">Statistics</div>';
  echo '<div class="panel-body">';
  echo '<div class="row">';
  echo '<div class="col-sm-6">';
  echo '<strong>Total Verified:</strong> ' . number_format($stats['total_verified']) . '<br>';
  echo '<strong>Active Role Users:</strong> ' . number_format($stats['active_role_users']) . '<br>';
  echo '</div>';
  echo '<div class="col-sm-6">';
  echo '<strong>Default Role Users:</strong> ' . number_format($stats['default_role_users']) . '<br>';
  echo '<strong>Last 30 Days:</strong> ' . number_format($stats['recent_verifications'] ?? 0) . '<br>';
  echo '</div>';
  echo '</div>';
  echo '</div>';
  echo '</div>';
  echo '</div>';
  echo '</div>';
}

function displaySyncActionsPanel($modulelink)
{
  echo '<div class="panel panel-default">';
  echo '<div class="panel-heading">Bulk Actions</div>';
  echo '<div class="panel-body">';
  echo '<form method="post" action="' . $modulelink . '" class="form-inline">';
  echo '<input type="hidden" name="action" value="sync_all">';
  echo '<div class="form-group" style="margin-right: 10px;">';
  echo '<button type="submit" class="btn btn-primary" onclick="return confirm(\'Sync all Discord users? This may take a while and will update all user roles based on their current product status.\');">';
  echo '<i class="fa fa-refresh"></i> Sync All Users';
  echo '</button>';
  echo '</div>';
  echo '<div class="form-group">';
  echo '<small class="text-muted">This will synchronize all verified Discord users and update their roles based on active products.</small>';
  echo '</div>';
  echo '</form>';
  echo '</div>';
  echo '</div>';
}

function displayVerifiedUsersTable($modulelink, $verifiedUsers)
{
  echo '<div class="panel panel-default">';
  echo '<div class="panel-heading">Verified Discord Users</div>';
  echo '<div class="panel-body">';

  if (empty($verifiedUsers)) {
    echo '<div class="alert alert-info"><i class="fa fa-info-circle"></i> No verified Discord users found. Users will appear here after they complete Discord verification.</div>';
  } else {
    echo '<style>';
    echo '.user-details { display: none; background-color: #f9f9f9; }';
    echo '.expand-btn { cursor: pointer; color: #337ab7; }';
    echo '.expand-btn:hover { color: #23527c; }';
    echo '</style>';

    echo '<div class="table-responsive">';
    echo '<table class="table table-striped" id="discordUsersTable">';
    echo '<thead><tr><th width="30"></th><th>Client</th><th>Discord User</th><th>Role Status</th><th>Last Sync</th><th>Actions</th></tr></thead>';
    echo '<tbody>';

    foreach ($verifiedUsers as $user) {
      $name = trim($user->firstname . ' ' . $user->lastname);
      if ($user->companyname) {
        $name .= " ({$user->companyname})";
      }

      echo '<tr>';
      echo '<td><i class="fa fa-plus expand-btn" onclick="toggleUserDetails(' . $user->client_id . ')"></i></td>';
      echo '<td><a href="clientssummary.php?userid=' . $user->client_id . '" target="_blank">' . htmlspecialchars($name) . '</a></td>';
      echo '<td>' . htmlspecialchars($user->discord_username ?: 'Unknown') . '<br><small class="text-muted"><code>' . htmlspecialchars($user->discord_id) . '</code></small></td>';
      echo '<td><span class="label label-' . ($user->has_active_products ? 'success' : 'default') . '">' . ($user->has_active_products ? 'Active Member' : 'Default Role') . '</span></td>';
      echo '<td>' . ($user->last_sync ? date('M j, Y H:i', strtotime($user->last_sync)) : '<span class="text-muted">Never</span>') . '</td>';
      echo '<td>';
      echo '<form method="post" action="' . $modulelink . '" style="display: inline-block; margin-right: 5px;">';
      echo '<input type="hidden" name="action" value="sync_user">';
      echo '<input type="hidden" name="client_id" value="' . $user->client_id . '">';
      echo '<input type="hidden" name="discord_id" value="' . $user->discord_id . '">';
      echo '<button type="submit" class="btn btn-success btn-sm"><i class="fa fa-refresh"></i> Sync</button>';
      echo '</form>';
      echo '<form method="post" action="' . $modulelink . '" style="display: inline-block;">';
      echo '<input type="hidden" name="action" value="remove_user">';
      echo '<input type="hidden" name="client_id" value="' . $user->client_id . '">';
      echo '<button type="submit" class="btn btn-danger btn-sm" onclick="return confirm(\'Remove Discord association for this user? This will unlink their Discord account.\');">';
      echo '<i class="fa fa-times"></i> Remove';
      echo '</button>';
      echo '</form>';
      echo '</td>';
      echo '</tr>';

      // Expandable row
      echo '<tr id="user-details-' . $user->client_id . '" class="user-details">';
      echo '<td colspan="6">';
      echo '<div style="padding: 15px;">';
      echo '<div class="row">';
      echo '<div class="col-md-6">';
      echo '<strong>Client Information:</strong><br>';
      echo 'Email: ' . htmlspecialchars($user->email) . '<br>';
      echo 'Client ID: ' . $user->client_id . '<br>';
      echo 'Active Products: ' . ($user->has_active_products ? 'Yes' : 'No') . '<br>';
      echo '</div>';
      echo '<div class="col-md-6">';
      echo '<strong>Discord Information:</strong><br>';
      echo 'Discord ID: <code>' . htmlspecialchars($user->discord_id) . '</code><br>';
      echo 'Username: ' . htmlspecialchars($user->discord_username ?: 'Unknown') . '<br>';
      echo 'Current Role: ' . ($user->has_active_products ? 'Active Member' : 'Default Role') . '<br>';
      echo '</div>';
      echo '</div>';
      echo '</div>';
      echo '</td>';
      echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';

    echo '<script>';
    echo 'function toggleUserDetails(clientId) {';
    echo '  var row = document.getElementById("user-details-" + clientId);';
    echo '  var icon = event.target;';
    echo '  if (row.style.display === "none" || row.style.display === "") {';
    echo '    row.style.display = "table-row";';
    echo '    icon.className = "fa fa-minus expand-btn";';
    echo '  } else {';
    echo '    row.style.display = "none";';
    echo '    icon.className = "fa fa-plus expand-btn";';
    echo '  }';
    echo '}';
    echo '</script>';
  }

  echo '</div>';
  echo '</div>';
}
