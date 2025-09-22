<?php

/**
 * WHMCS Discord Client Verification Module
 * @author 26BZ (https://github.com/26BZ)
 * @license MIT License
 */

if (!defined("WHMCS")) {
  die("This file cannot be accessed directly");
}

use WHMCS\Authentication\CurrentUser;
use WHMCS\Database\Capsule;

require_once __DIR__ . '/admin.php';

if (!function_exists('discord_verification_config')) {
  function discord_verification_config()
  {
    return [
    'name' => 'Discord Client Verification',
    'description' => 'Automatically verify WHMCS clients with Discord roles based on their account status. Clients with active products receive one role, while verified clients without active products receive a different role.',
    'version' => '1.5.2',
    'author' => '<a href="https://26bz.online/" target="_blank">26BZ</a>',
    'language' => 'english',
    'fields' => [
      'client_id' => [
        'FriendlyName' => 'Discord Client ID',
        'Type' => 'text',
        'Size' => '30',
        'Description' => 'Your Discord Application Client ID',
        'Default' => '',
      ],
      'secret_id' => [
        'FriendlyName' => 'Discord Client Secret',
        'Type' => 'password',
        'Size' => '30',
        'Description' => 'Your Discord Application Client Secret',
        'Default' => '',
      ],
      'bot_token' => [
        'FriendlyName' => 'Discord Bot Token',
        'Type' => 'password',
        'Size' => '30',
        'Description' => 'Your Discord Bot Token (for role management)',
        'Default' => '',
      ],
      'guild_id' => [
        'FriendlyName' => 'Discord Server ID',
        'Type' => 'text',
        'Size' => '25',
        'Description' => 'Your Discord Server (Guild) ID',
        'Default' => '',
      ],
      'active_role_id' => [
        'FriendlyName' => 'Active Client Role ID',
        'Type' => 'text',
        'Size' => '25',
        'Description' => 'Discord Role ID for clients with active products',
        'Default' => '',
      ],
      'default_role_id' => [
        'FriendlyName' => 'Default Role ID',
        'Type' => 'text',
        'Size' => '25',
        'Description' => 'Discord role ID for verified clients without active products',
        'Default' => '',
      ],
      'enable_client_widget' => [
        'FriendlyName' => 'Enable Client Widget',
        'Type' => 'yesno',
        'Default' => 'yes',
        'Description' => 'Show Discord verification status widget in client area sidebar',
      ],
      'force_verification' => [
        'FriendlyName' => 'Force Discord Verification',
        'Type' => 'yesno',
        'Default' => 'no',
        'Description' => 'Force clients to link their Discord account to access the client area (similar to email verification)',
      ],
      'auto_join_guild' => [
        'FriendlyName' => 'Auto Join Server',
        'Type' => 'yesno',
        'Default' => 'yes',
        'Description' => 'Automatically add users to your Discord server when they verify (requires bot with MANAGE_GUILD permission)',
      ],
    ]
  ];
}
}
if (!function_exists('discord_verification_activate')) {
function discord_verification_activate()
{
  try {
    $existingField = Capsule::table('tblcustomfields')
      ->where('fieldname', 'discord')
      ->where('type', 'client')
      ->first();

    if (!$existingField) {
      Capsule::table('tblcustomfields')->insert([
        'type' => 'client',
        'fieldname' => 'discord',
        'fieldtype' => 'text',
        'description' => 'Discord User ID',
        'fieldoptions' => '',
        'regexpr' => '',
        'adminonly' => 'on',
        'required' => '',
        'showorder' => 'on',
        'showinvoice' => '',
        'sortorder' => 0,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
      ]);
    }

    if (Capsule::schema()->hasTable('mod_discord_verification_backup')) {
      $backedUpSettings = Capsule::table('mod_discord_verification_backup')->get();

      if ($backedUpSettings->count() > 0) {
        sleep(1);

        foreach ($backedUpSettings as $setting) {
          Capsule::table('tbladdonmodules')
            ->where('module', 'discord_verification')
            ->where('setting', $setting->setting)
            ->update(['value' => $setting->value]);
        }
        Capsule::table('mod_discord_verification_backup')->truncate();

        logActivity('Discord Verification: Settings restored from backup during activation');

        return [
          'status' => 'success',
          'description' => 'Discord Verification module activated successfully! Your previous settings have been restored.',
        ];
      }
    }

    return [
      'status' => 'success',
      'description' => 'Discord Verification module activated successfully! The required custom field "discord" has been created. Configure your Discord application settings in the module configuration.',
    ];
  } catch (\Exception $e) {
    logActivity('Discord Verification: Error during activation - ' . $e->getMessage());
    return [
      'status' => 'error',
      'description' => 'Unable to activate module: ' . $e->getMessage(),
    ];
  }
}
}

if (!function_exists('discord_verification_deactivate')) {
function discord_verification_deactivate()
{
  try {
    $currentSettings = Capsule::table('tbladdonmodules')
      ->where('module', 'discord_verification')
      ->get();

    if ($currentSettings->count() > 0) {
      if (!Capsule::schema()->hasTable('mod_discord_verification_backup')) {
        Capsule::schema()->create('mod_discord_verification_backup', function ($table) {
          $table->increments('id');
          $table->string('setting');
          $table->text('value');
          $table->timestamp('created_at')->useCurrent();
        });
      }

      Capsule::table('mod_discord_verification_backup')->truncate();

      foreach ($currentSettings as $setting) {
        Capsule::table('mod_discord_verification_backup')->insert([
          'setting' => $setting->setting,
          'value' => $setting->value,
          'created_at' => date('Y-m-d H:i:s')
        ]);
      }

      logActivity('Discord Verification: Settings backed up before deactivation');
    }

    return [
      'status' => 'success',
      'description' => 'Discord Verification module deactivated successfully. Settings have been backed up and will be restored on reactivation.',
    ];
  } catch (\Exception $e) {
    logActivity('Discord Verification: Error during deactivation - ' . $e->getMessage());
    return [
      'status' => 'error',
      'description' => 'Error during deactivation: ' . $e->getMessage(),
    ];
  }
}
}

if (!function_exists('discord_verification_upgrade')) {
function discord_verification_upgrade($vars)
{
  $currentlyInstalledVersion = $vars['version'];

  try {
    logActivity("Discord Verification module upgraded from version {$currentlyInstalledVersion} to 1.5");

    return [
      'status' => 'success',
      'description' => 'Discord Verification module upgraded successfully to version 1.5',
    ];
  } catch (\Exception $e) {
    return [
      'status' => 'error',
      'description' => 'Error during upgrade: ' . $e->getMessage(),
    ];
  }
}
}

if (!function_exists('discord_verification_output')) {
function discord_verification_output($vars)
{
  return discord_verification_admin_output($vars);
}
}

if (!function_exists('discord_verification_clientarea')) {
function discord_verification_clientarea($vars)
{
  $clientId = getenv('DISCORD_CLIENT_ID') ?: $vars['client_id'];
  $secretId = getenv('DISCORD_SECRET_ID') ?: $vars['secret_id'];
  $botToken = getenv('DISCORD_BOT_TOKEN') ?: $vars['bot_token'];
  $guildId = $vars['guild_id'];
  $activeRoleId = $vars['active_role_id'];
  $defaultRoleId = $vars['default_role_id'];

  if (empty($clientId) || empty($secretId) || empty($botToken) || empty($guildId)) {
    return [
      'pagetitle' => 'Discord Verification',
      'breadcrumb' => ['index.php?m=discord_verification' => 'Discord Verification'],
      'templatefile' => 'verification',
      'requirelogin' => true,
      'forcessl' => true,
      'vars' => [
        'error' => true,
        'message' => 'Service temporarily unavailable. Please contact support.',
      ],
    ];
  }

  if (session_status() === PHP_SESSION_NONE) {
    session_start();
  }

  $currentUser = new CurrentUser();
  if (!$currentUser->isAuthenticatedUser()) {
    return [
      'pagetitle' => 'Discord Verification',
      'breadcrumb' => ['index.php?m=discord_verification' => 'Discord Verification'],
      'templatefile' => 'verification',
      'requirelogin' => true,
      'forcessl' => true,
      'vars' => [
        'error' => true,
        'message' => 'You must be logged in to verify your Discord account.',
      ],
    ];
  }

  $client = $currentUser->client();
  if (!$client) {
    return [
      'pagetitle' => 'Discord Verification',
      'breadcrumb' => ['index.php?m=discord_verification' => 'Discord Verification'],
      'templatefile' => 'verification',
      'requirelogin' => true,
      'forcessl' => true,
      'vars' => [
        'error' => true,
        'message' => 'Unable to retrieve client information.',
      ],
    ];
  }

  $redirectUri = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') .
    '://' . $_SERVER['HTTP_HOST'] . '/index.php?m=discord_verification';

  if (isset($_GET['action']) && $_GET['action'] === 'verify') {
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;
    
    $config = getDiscordConfig();
    $autoJoinGuild = isset($config['auto_join_guild']) && $config['auto_join_guild'] === 'on';
    $scope = $autoJoinGuild ? 'identify email guilds.join' : 'identify email';

    $authorizationUrl = 'https://discord.com/oauth2/authorize?' . http_build_query([
      'response_type' => 'code',
      'client_id' => $clientId,
      'redirect_uri' => $redirectUri,
      'scope' => $scope,
      'state' => $csrfToken,
    ]);

    header('Location: ' . $authorizationUrl);
    exit();
  }

  if (isset($_GET['code'])) {
    // Verify CSRF 
    if (!isset($_GET['state']) || !isset($_SESSION['csrf_token']) || $_GET['state'] !== $_SESSION['csrf_token']) {
      return [
        'pagetitle' => 'Discord Verification',
        'breadcrumb' => ['index.php?m=discord_verification' => 'Discord Verification'],
        'templatefile' => 'verification',
        'requirelogin' => true,
        'forcessl' => true,
        'vars' => [
          'error' => true,
          'message' => 'Invalid security token. Please try again.',
        ],
      ];
    }

    try {
      $tokenData = exchangeAuthorizationCodeForAccessToken($_GET['code'], $clientId, $secretId, $redirectUri);
      $userInfo = getUserInfo($tokenData->access_token);

      if (!isset($userInfo->id)) {
        throw new Exception("VERIFICATION_FAILED:Unable to complete verification. Please try again.");
      }

      $existingClient = checkDuplicateDiscordAccount($userInfo->id, $client->id);
      if ($existingClient) {
        throw new Exception("DUPLICATE_ACCOUNT:This Discord account is already linked to another WHMCS account. Please contact support if you believe this is an error.");
      }

      $discordUsername = $userInfo->username;
      if (isset($userInfo->discriminator) && $userInfo->discriminator !== '0') {
        $discordUsername .= '#' . $userInfo->discriminator;
      }
      updateClientDiscordId($userInfo->id, $client->id, $discordUsername);

      try {
        $config = getDiscordConfig();
        $autoJoinGuild = isset($config['auto_join_guild']) && $config['auto_join_guild'] === 'on';
        
        if ($autoJoinGuild) {
          try {
            addUserToGuild($userInfo->id, $tokenData->access_token, $guildId, $botToken);
          } catch (Exception $e) {
            logActivity("Failed to add Discord user {$userInfo->id} to guild - " . $e->getMessage());
          }
        }
        
        assignRoleToUser($userInfo->id, $client->id, $guildId, $activeRoleId, $defaultRoleId, $botToken);
        logActivity("Discord successfully linked for Client ID: " . $client->id);

        $avatarUrl = isset($userInfo->avatar) ?
          "https://cdn.discordapp.com/avatars/{$userInfo->id}/{$userInfo->avatar}.png" :
          "https://cdn.discordapp.com/embed/avatars/0.png";

        $username = htmlspecialchars($userInfo->username);
        $discriminator = isset($userInfo->discriminator) && $userInfo->discriminator !== '0' ?
          "#{$userInfo->discriminator}" : '';

        return [
          'pagetitle' => 'Discord Verification',
          'breadcrumb' => ['index.php?m=discord_verification' => 'Discord Verification'],
          'templatefile' => 'verification',
          'requirelogin' => true,
          'forcessl' => true,
          'vars' => [
            'verified' => true,
            'avatar' => $avatarUrl,
            'username' => $username,
            'discriminator' => $discriminator,
            'message' => 'Successfully linked your Discord account!',
            'modulelink' => $vars['modulelink'],
          ],
        ];
      } catch (Exception $e) {
        logActivity("Failed to assign Discord role for Client ID: " . $client->id . " - " . $e->getMessage());

        $errorMessage = parseDiscordRoleError($e->getMessage());

        return [
          'pagetitle' => 'Discord Verification',
          'breadcrumb' => ['index.php?m=discord_verification' => 'Discord Verification'],
          'templatefile' => 'verification',
          'requirelogin' => true,
          'forcessl' => true,
          'vars' => [
            'error' => true,
            'message' => $errorMessage,
          ],
        ];
      }
    } catch (Exception $e) {
      logActivity("Discord linking error for Client ID: " . $client->id . " - " . $e->getMessage());

      $errorMessage = parseDiscordError($e->getMessage());

      return [
        'pagetitle' => 'Discord Verification',
        'breadcrumb' => ['index.php?m=discord_verification' => 'Discord Verification'],
        'templatefile' => 'verification',
        'requirelogin' => true,
        'forcessl' => true,
        'vars' => [
          'error' => true,
          'message' => $errorMessage,
        ],
      ];
    }
  }

  $existingDiscord = getExistingDiscordInfo($client->id, $botToken);

  if ($existingDiscord) {
    return [
      'pagetitle' => 'Discord Verification',
      'breadcrumb' => ['index.php?m=discord_verification' => 'Discord Verification'],
      'templatefile' => 'verification',
      'requirelogin' => true,
      'forcessl' => true,
      'vars' => [
        'verified' => true,
        'avatar' => $existingDiscord['avatar'],
        'username' => $existingDiscord['username'],
        'discriminator' => $existingDiscord['discriminator'],
        'message' => 'Your Discord account is linked',
        'modulelink' => $vars['modulelink'],
      ],
    ];
  } else {
    $config = getDiscordConfig();
    $forceVerification = isset($config['force_verification']) && $config['force_verification'] === 'on';
    
    return [
      'pagetitle' => 'Discord Verification',
      'breadcrumb' => ['index.php?m=discord_verification' => 'Discord Verification'],
      'templatefile' => 'verification',
      'requirelogin' => true,
      'forcessl' => true,
      'vars' => [
        'verified' => false,
        'message' => 'Link your Discord account to your client area',
        'modulelink' => $vars['modulelink'],
        'force_verification' => $forceVerification,
      ],
    ];
  }
}

function exchangeAuthorizationCodeForAccessToken($code, $client_id, $secret_id, $redirect_uri)
{
  $ch = curl_init('https://discord.com/api/oauth2/token');
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
      'client_id'     => $client_id,
      'client_secret' => $secret_id,
      'grant_type'    => 'authorization_code',
      'code'          => $code,
      'redirect_uri'  => $redirect_uri
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true
  ]);

  $response = curl_exec($ch);
  if ($response === false) {
    throw new Exception('OAUTH_ERROR:Authentication failed. Please try again.');
  }

  $data = json_decode($response);
  if (!isset($data->access_token)) {
    throw new Exception('OAUTH_ERROR:Authentication failed. Please try again.');
  }

  curl_close($ch);
  return $data;
}

function getUserInfo($accessToken)
{
  $ch = curl_init('https://discord.com/api/users/@me');
  curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true
  ]);

  $response = curl_exec($ch);
  if ($response === false) {
    throw new Exception('USER_INFO_ERROR:Unable to retrieve account information. Please try again.');
  }

  $data = json_decode($response);
  if (!isset($data->id)) {
    throw new Exception('USER_INFO_ERROR:Unable to retrieve account information. Please try again.');
  }

  curl_close($ch);
  return $data;
}

function updateClientDiscordId($discordId, $clientId, $discordUsername = null)
{
  try {
    $discordFieldId = Capsule::table('tblcustomfields')
      ->where('fieldname', 'discord')
      ->value('id');

    if (!$discordFieldId) {
      throw new Exception('SYSTEM_ERROR:Configuration error. Please contact support.');
    }

    // Store data as JSON if username is available otherwise just ID
    $discordData = $discordUsername ?
      json_encode(['id' => $discordId, 'username' => $discordUsername]) :
      $discordId;

    Capsule::table('tblcustomfieldsvalues')
      ->updateOrInsert(
        [
          'fieldid' => $discordFieldId,
          'relid' => $clientId,
        ],
        [
          'value' => $discordData
        ]
      );

    return true;
  } catch (Exception $e) {
    throw new Exception('SYSTEM_ERROR:Unable to save verification data. Please contact support.');
  }
}

function assignRoleToUser($userId, $clientId, $guildId, $activeRoleId, $defaultRoleId, $botToken)
{
  $activeProducts = Capsule::table('tblhosting')
    ->where('userid', $clientId)
    ->where('domainstatus', 'Active')
    ->count();

  $roleId = $activeProducts > 0 ? $activeRoleId : $defaultRoleId;
  $requestData = "PUT /guilds/{$guildId}/members/$userId/roles/$roleId";

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

  logModuleCall(
    'discord_verification',
    'assign_role',
    $requestData,
    $response,
    $httpCode,
    [$botToken] // Scrub 
  );

  if ($response === false) {
    throw new Exception('CURL_ERROR:Failed to connect to Discord API. Please check your internet connection and try again.');
  }

  if (!in_array($httpCode, [204, 200])) {
    $errorData = json_decode($response, true);

    switch ($httpCode) {
      case 403:
        if (isset($errorData['code']) && $errorData['code'] === 50013) {
          throw new Exception('BOT_PERMISSIONS:Bot permissions issue - please contact support. The bot may not have permission to manage roles.');
        }
        throw new Exception('NOT_IN_GUILD:Please join our Discord server first before verifying your account.');

      case 404:
        throw new Exception('NOT_IN_GUILD:Please join our Discord server first before verifying your account.');

      case 400:
        throw new Exception('INVALID_REQUEST:Invalid request to Discord API. Please contact support.');

      case 429:
        throw new Exception('RATE_LIMITED:Too many requests. Please wait a moment and try again.');

      case 500:
      case 502:
      case 503:
        throw new Exception('DISCORD_DOWN:Discord API is currently unavailable. Please try again later.');

      default:
        throw new Exception('ROLE_ASSIGNMENT_FAILED:Role assignment failed - please try again or contact admin if the problem persists.');
    }
  }

  return true;
}

function getDiscordConfig()
{
  try {
    $config = Capsule::table('tbladdonmodules')
      ->where('module', 'discord_verification')
      ->pluck('value', 'setting')
      ->toArray();

    return [
      'client_id' => getenv('DISCORD_CLIENT_ID') ?: ($config['client_id'] ?? ''),
      'secret_id' => getenv('DISCORD_SECRET_ID') ?: ($config['secret_id'] ?? ''),
      'bot_token' => getenv('DISCORD_BOT_TOKEN') ?: ($config['bot_token'] ?? ''),
      'guild_id' => $config['guild_id'] ?? '',
      'active_role_id' => $config['active_role_id'] ?? '',
      'default_role_id' => $config['default_role_id'] ?? '',
      'enable_client_widget' => $config['enable_client_widget'] ?? 'yes',
      'force_verification' => $config['force_verification'] ?? 'no',
      'auto_join_guild' => $config['auto_join_guild'] ?? 'yes',
    ];
  } catch (Exception $e) {
    return [
      'client_id' => '',
      'secret_id' => '',
      'bot_token' => '',
      'guild_id' => '',
      'active_role_id' => '',
      'default_role_id' => '',
      'enable_client_widget' => 'yes',
      'force_verification' => 'no',
      'auto_join_guild' => 'yes',
    ];
  }
}

function fetchDiscordUsername($discordId)
{
  try {
    $config = getDiscordConfig();
    if (!$config['bot_token']) {
      return null;
    }

    $url = "https://discord.com/api/v10/users/{$discordId}";
    $headers = [
      'Authorization: Bot ' . $config['bot_token'],
      'Content-Type: application/json'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
      $userData = json_decode($response, true);
      if (isset($userData['username'])) {
        $username = $userData['username'];
        if (isset($userData['discriminator']) && $userData['discriminator'] !== '0') {
          $username .= '#' . $userData['discriminator'];
        }
        return $username;
      }
    }

    return null;
  } catch (Exception $e) {
    // Silently fail
    return null;
  }
}

function removeAllDiscordRoles($discordId, $guildId, $activeRoleId, $defaultRoleId, $botToken)
{
  $rolesToRemove = array_filter([$activeRoleId, $defaultRoleId]);

  foreach ($rolesToRemove as $roleId) {
    try {
      $url = "https://discord.com/api/v10/guilds/{$guildId}/members/{$discordId}/roles/{$roleId}";
      $headers = [
        'Authorization: Bot ' . $botToken,
        'Content-Type: application/json'
      ];

      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($ch, CURLOPT_TIMEOUT, 10);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

      $response = curl_exec($ch);
      $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);

      if ($httpCode === 204 || $httpCode === 404) {
        logActivity("Discord Verification: Role {$roleId} removed from user {$discordId}");
      } else {
        logActivity("Discord Verification: Failed to remove role {$roleId} from user {$discordId} - HTTP {$httpCode}");
      }
    } catch (Exception $e) {
      logActivity("Discord Verification: Error removing role {$roleId} from user {$discordId} - " . $e->getMessage());
    }
  }
}

function getDiscordUserInfo($userId, $botToken)
{
  $url = "https://discord.com/api/users/$userId";
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

  if ($response === false) {
    throw new Exception('API_ERROR:Unable to retrieve user information. Please contact support.');
  }

  if ($httpCode !== 200) {
    throw new Exception('API_ERROR:Unable to retrieve user information. Please contact support.');
  }

  return json_decode($response, true);
}

function getExistingDiscordInfo($clientId, $botToken)
{
  try {
    $discordId = Capsule::table('tblcustomfields')
      ->join('tblcustomfieldsvalues', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
      ->where('tblcustomfields.fieldname', 'LIKE', '%discord%')
      ->where('tblcustomfieldsvalues.relid', $clientId)
      ->value('tblcustomfieldsvalues.value');

    if ($discordId && is_numeric($discordId)) {
      $userInfo = getDiscordUserInfo($discordId, $botToken);
      if ($userInfo) {
        return [
          'id' => $discordId,
          'username' => $userInfo['username'],
          'discriminator' => isset($userInfo['discriminator']) && $userInfo['discriminator'] !== '0' ?
            "#{$userInfo['discriminator']}" : '',
          'avatar' => isset($userInfo['avatar']) ?
            "https://cdn.discordapp.com/avatars/{$discordId}/{$userInfo['avatar']}.png" :
            "https://cdn.discordapp.com/embed/avatars/0.png"
        ];
      }
    }
  } catch (Exception $e) {
    logActivity("Failed to fetch existing Discord info - Client ID: {$clientId} - " . $e->getMessage());
  }
  return null;
}

function checkDuplicateDiscordAccount($discordId, $currentClientId)
{
  try {
    $existingClient = Capsule::table('tblcustomfields')
      ->join('tblcustomfieldsvalues', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
      ->where('tblcustomfields.fieldname', 'discord')
      ->where('tblcustomfieldsvalues.value', $discordId)
      ->where('tblcustomfieldsvalues.relid', '!=', $currentClientId)
      ->value('tblcustomfieldsvalues.relid');

    return $existingClient;
  } catch (Exception $e) {
    logActivity("Failed to check duplicate Discord account - Discord ID: {$discordId} - " . $e->getMessage());
    return null;
  }
}

function parseDiscordError($errorMessage)
{
  if (strpos($errorMessage, 'DUPLICATE_ACCOUNT:') === 0) {
    return substr($errorMessage, 17);
  }

  if (strpos($errorMessage, 'CURL_ERROR:') === 0) {
    return substr($errorMessage, 11);
  }

  if (strpos($errorMessage, 'NOT_IN_GUILD:') === 0) {
    return substr($errorMessage, 13);
  }

  if (strpos($errorMessage, 'BOT_PERMISSIONS:') === 0) {
    return substr($errorMessage, 16);
  }

  if (strpos($errorMessage, 'INVALID_REQUEST:') === 0) {
    return substr($errorMessage, 16);
  }

  if (strpos($errorMessage, 'RATE_LIMITED:') === 0) {
    return substr($errorMessage, 13);
  }

  if (strpos($errorMessage, 'DISCORD_DOWN:') === 0) {
    return substr($errorMessage, 13);
  }
  
  if (strpos($errorMessage, 'GUILD_JOIN_FAILED:') === 0) {
    return substr($errorMessage, 17);
  }

  return 'An unexpected error occurred during Discord verification. Please try again or contact support if the problem persists.';
}

function parseDiscordRoleError($errorMessage)
{
  if (strpos($errorMessage, 'NOT_IN_GUILD:') === 0) {
    return substr($errorMessage, 13);
  }

  if (strpos($errorMessage, 'BOT_PERMISSIONS:') === 0) {
    return substr($errorMessage, 16);
  }

  if (strpos($errorMessage, 'ROLE_ASSIGNMENT_FAILED:') === 0) {
    return substr($errorMessage, 23);
  }

  if (strpos($errorMessage, 'RATE_LIMITED:') === 0) {
    return substr($errorMessage, 13);
  }

  if (strpos($errorMessage, 'DISCORD_DOWN:') === 0) {
    return substr($errorMessage, 13);
  }

  if (strpos($errorMessage, 'CURL_ERROR:') === 0) {
    return substr($errorMessage, 11);
  }

  if (strpos($errorMessage, 'INVALID_REQUEST:') === 0) {
    return substr($errorMessage, 16);
  }

  return 'Discord linked successfully, but role assignment failed. Please try again or contact admin if the problem persists.';
}

function addUserToGuild($userId, $accessToken, $guildId, $botToken)
{
  $url = "https://discord.com/api/v10/guilds/{$guildId}/members/{$userId}";
  $data = json_encode(['access_token' => $accessToken]);
  
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => "PUT",
    CURLOPT_POSTFIELDS => $data,
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
  
  logModuleCall(
    'discord_verification',
    'add_to_guild',
    "PUT /guilds/{$guildId}/members/{$userId}",
    $response,
    $httpCode,
    [$botToken, $accessToken]
  );
  
  if ($response === false) {
    throw new Exception('CURL_ERROR:Failed to connect to Discord API.');
  }

  if (!in_array($httpCode, [201, 204])) {
    if ($httpCode === 403) {
      throw new Exception('NOT_IN_GUILD:Failed to add you to the Discord server.');
    } else {
      throw new Exception('GUILD_JOIN_FAILED:Failed to add you to the Discord server.');
    }
  }
  
  return true;
}
}
