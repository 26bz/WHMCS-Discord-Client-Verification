<?php
//FILE LOCATION: includes/hooks/discord.php

use WHMCS\Database\Capsule;
use WHMCS\View\Menu\Item as MenuItem;

global $discord_config;
$discord_config = require '/config.php';
$discord_config['bot_token'] = getenv('DISCORD_BOT_TOKEN') ?: $discord_config['bot_token'];

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

function updateClientDiscordId($discordId, $clientId)
{
    try {
        $discordFieldId = Capsule::table('tblcustomfields')
            ->where('fieldname', 'discord')
            ->value('id');

        if (!$discordFieldId) {
            throw new Exception('Discord custom field not found');
        }
        Capsule::table('tblcustomfieldsvalues')
            ->updateOrInsert(
                [
                    'fieldid' => $discordFieldId,
                    'relid' => $clientId,
                ],
                [
                    'value' => $discordId
                ]
            );

        return true;
    } catch (Exception $e) {
        throw new Exception('Failed to update Discord ID: ' . $e->getMessage());
    }
}

add_hook('CustomFieldSave', 1, function ($vars) {
    $fieldName = Capsule::table('tblcustomfields')
        ->where('id', $vars['fieldid'])
        ->value('fieldname');

    // Only process if it's our discord field
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

function assignRoleToUser($userId, $clientId)
{
    global $discord_config;

    $activeProducts = Capsule::table('tblhosting')
        ->where('userid', $clientId)
        ->where('domainstatus', 'Active')
        ->count();

    $roleId = $activeProducts > 0 ? $discord_config['active_role_id'] : $discord_config['default_role_id'];

    logActivity("Assigning role to Discord user $userId (Client ID: $clientId) - Active products: $activeProducts - Role ID: $roleId");

    $url = "https://discord.com/api/guilds/{$discord_config['guild_id']}/members/$userId/roles/$roleId";
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => "PUT",
        CURLOPT_HTTPHEADER => [
            'Authorization: Bot ' . $discord_config['bot_token'],
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
        throw new Exception('Failed to assign role: ' . curl_error($ch));
    }

    if (!in_array($httpCode, [204, 404])) {
        throw new Exception("Discord API error: HTTP $httpCode - $response");
    }

    $roleToRemove = $roleId === $discord_config['active_role_id'] ?
        $discord_config['default_role_id'] :
        $discord_config['active_role_id'];
    removeRole($userId, $discord_config['guild_id'], $roleToRemove, $discord_config['bot_token']);
}
add_hook('ClientAreaSecondaryNavbar', 1, function ($secondaryNavbar) {
    try {
        if ($accountMenu = $secondaryNavbar->getChild('Account')) {
            $accountMenu->addChild(
                'customSubButton',
                [
                    'name' => 'Verify Discord',
                    'label' => 'Verify Discord',
                    'uri' => '/discord.php',
                    'order' => 84,
                ]
            );
        }
    } catch (\Exception $e) {
        logActivity("Secondary navbar hook error: " . $e->getMessage());
    }
});
function syncExpiredServicesRoles()
{
    global $discord_config;

    try {
        $clients = Capsule::table('tblcustomfields')
            ->where('fieldname', 'LIKE', '%discord%')
            ->join('tblcustomfieldsvalues', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
            ->join('tblclients', 'tblcustomfieldsvalues.relid', '=', 'tblclients.id')
            ->join('tblhosting', 'tblclients.id', '=', 'tblhosting.userid')
            ->select(
                'tblclients.id',
                'tblcustomfieldsvalues.value as discord_id'
            )
            ->distinct()
            ->get();

        foreach ($clients as $client) {
            if (empty($client->discord_id) || !is_numeric($client->discord_id)) {
                continue;
            }

            $activeServices = Capsule::table('tblhosting')
                ->where('userid', $client->id)
                ->where('domainstatus', 'Active')
                ->count();

            try {
                if ($activeServices == 0) {

                    removeRole($client->discord_id, $discord_config['guild_id'], $discord_config['active_role_id'], $discord_config['bot_token']);


                    $url = "https://discord.com/api/guilds/{$discord_config['guild_id']}/members/{$client->discord_id}/roles/{$discord_config['default_role_id']}";
                    $ch = curl_init($url);

                    curl_setopt_array($ch, [
                        CURLOPT_CUSTOMREQUEST => "PUT",
                        CURLOPT_HTTPHEADER => [
                            'Authorization: Bot ' . $discord_config['bot_token'],
                            'Content-Type: application/json',
                        ],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_TIMEOUT => 10
                    ]);

                    curl_exec($ch);
                    curl_close($ch);

                    logActivity("Updated Discord role for client with expired services - Client ID: {$client->id}");
                }
            } catch (Exception $e) {
                logActivity("Failed to update Discord role for expired services - Client ID: {$client->id} - " . $e->getMessage());
            }
        }
    } catch (Exception $e) {
        logActivity("Expired services role sync failed: " . $e->getMessage());
    }
}

add_hook('DailyCronJob', 1, function () {
    global $discord_config;

    try {
        $clients = Capsule::table('tblcustomfields')
            ->where('fieldname', 'LIKE', '%discord%')
            ->join('tblcustomfieldsvalues', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
            ->join('tblclients', 'tblcustomfieldsvalues.relid', '=', 'tblclients.id')
            ->select(
                'tblclients.id',
                'tblclients.status',
                'tblcustomfieldsvalues.value as discord_id'
            )
            ->get();

        foreach ($clients as $client) {
            if (empty($client->discord_id) || !is_numeric($client->discord_id)) {
                continue;
            }

            try {
                if ($client->status == 'Inactive') {
                    // Remove both roles if client is inactive
                    $activeRemoved = removeRole(
                        $client->discord_id,
                        $discord_config['guild_id'],
                        $discord_config['active_role_id'],
                        $discord_config['bot_token']
                    );

                    $defaultRemoved = removeRole(
                        $client->discord_id,
                        $discord_config['guild_id'],
                        $discord_config['default_role_id'],
                        $discord_config['bot_token']
                    );

                    if ($activeRemoved || $defaultRemoved) {
                        logActivity("Removed Discord roles for inactive client ID: {$client->id}");
                    }
                } else {
                    $activeServices = Capsule::table('tblhosting')
                        ->where('userid', $client->id)
                        ->where('domainstatus', 'Active')
                        ->count();

                    try {
                        // This will assign the appropriate role based on active services
                        assignRoleToUser($client->discord_id, $client->id);
                        logActivity("Updated Discord role for client ID: {$client->id} - Active services: {$activeServices}");
                    } catch (Exception $e) {
                        logActivity("Failed to update Discord role for client ID: {$client->id} - " . $e->getMessage());
                    }
                }
            } catch (Exception $e) {
                logActivity("Error processing Discord roles for client ID: {$client->id} - " . $e->getMessage());
                continue;
            }
        }

        syncExpiredServicesRoles();
    } catch (Exception $e) {
        logActivity("Discord role sync failed: " . $e->getMessage());
    }
});

add_hook('AfterCronJob', 1, function ($vars) {
    global $discord_config;

    // This hook runs after every cron job We'll use it to check for expired services and update roles
    logActivity("Running Discord role sync for services");
    syncExpiredServicesRoles();
});

add_hook('AfterModuleSuspend', 1, function ($vars) {
    global $discord_config;

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

            assignRoleToUser($discordId, $userId);
        }
    } catch (Exception $e) {
        logActivity("Failed to update Discord role on service suspension - " . $e->getMessage());
    }
});

add_hook('AfterModuleTerminate', 1, function ($vars) {
    global $discord_config;

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

            assignRoleToUser($discordId, $userId);
        }
    } catch (Exception $e) {
        logActivity("Failed to update Discord role on service termination - " . $e->getMessage());
    }
});

add_hook('ServiceStatusChange', 1, function ($vars) {
    global $discord_config;

    // Process role changes for any service status change
    // This ensures roles are updated when services become Active or Terminated/Suspended
    try {
        $discordId = Capsule::table('tblcustomfields')
            ->join('tblcustomfieldsvalues', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
            ->where('tblcustomfields.fieldname', 'LIKE', '%discord%')
            ->where('tblcustomfieldsvalues.relid', $vars['userid'])
            ->value('tblcustomfieldsvalues.value');

        if ($discordId && is_numeric($discordId)) {
            logActivity("Service status changed from {$vars['oldstatus']} to {$vars['status']} - User ID: {$vars['userid']}");

            assignRoleToUser($discordId, $vars['userid']);
        }
    } catch (Exception $e) {
        logActivity("Failed to update Discord role on service status change - User ID: {$vars['userid']} - " . $e->getMessage());
    }
});

add_hook('ClientStatusChange', 1, function ($vars) {
    global $discord_config;

    try {
        $discordId = Capsule::table('tblcustomfields')
            ->join('tblcustomfieldsvalues', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
            ->where('tblcustomfields.fieldname', 'LIKE', '%discord%')
            ->where('tblcustomfieldsvalues.relid', $vars['userid'])
            ->value('tblcustomfieldsvalues.value');

        if ($discordId && is_numeric($discordId)) {
            if ($vars['status'] == 'Inactive') {
                removeRole($discordId, $discord_config['guild_id'], $discord_config['active_role_id'], $discord_config['bot_token']);
                removeRole($discordId, $discord_config['guild_id'], $discord_config['default_role_id'], $discord_config['bot_token']);
            } else {
                assignRoleToUser($discordId, $vars['userid']);
            }
        }
    } catch (Exception $e) {
        logActivity("Failed to update Discord role on client status change - User ID: {$vars['userid']} - " . $e->getMessage());
    }
});
