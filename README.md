# WHMCS Discord Client Verification Addon

A professional WHMCS addon module that integrates with Discord to automatically verify clients and assign roles based on their account status.

## Features

- **OAuth2 Discord Authentication**: Secure Discord verification using OAuth2 flow
- **Automatic Role Assignment**: Assigns different roles based on active WHMCS products
- **Real-time Updates**: Hooks into WHMCS events for instant role synchronization
- **Daily Synchronization**: Cron job ensures roles stay in sync
- **Environment Variable Support**: Secure credential management
- **Comprehensive Logging**: Full activity and API call logging for debugging

## Installation

1. **Upload Files**: Copy the entire addon directory to your WHMCS installation:

   ```
   /path/to/whmcs/modules/addons/discord_verification/
   ```

2. **Activate Module**:

   - Go to **Setup → Addon Modules** in WHMCS Admin
   - Find "Discord Client Verification" and click **Activate**

3. **Configure Module**:
   - Click **Configure** next to the activated module
   - Fill in your Discord application credentials:
     - **Discord Client ID**: Your Discord app's client ID
     - **Discord Secret ID**: Your Discord app's client secret
     - **Discord Bot Token**: Your Discord bot token
     - **Discord Guild ID**: Your Discord server ID
     - **Active Role ID**: Role ID for clients with active products
     - **Default Role ID**: Role ID for verified clients without active products

## Discord Application Setup

1. **Create Discord Application**:

   - Go to [Discord Developer Portal](https://discord.com/developers/applications)
   - Create a new application
   - Note the **Client ID** and **Client Secret**

2. **Create Bot**:

   - In your application, go to the **Bot** section
   - Create a bot and copy the **Bot Token**
   - Enable required bot permissions: `Manage Roles`

3. **Set OAuth2 Redirect URI**:

   - In **OAuth2 → General**, add redirect URI:

   ```
   https://yourdomain.com/index.php?m=discord_verification
   ```

4. **Invite Bot to Server**:
   - Use OAuth2 URL Generator with `bot` scope and `Manage Roles` permission
   - Ensure bot role is higher than roles it needs to assign

## Environment Variables (Optional)

For enhanced security, you can use environment variables:

```bash
export DISCORD_CLIENT_ID="your_client_id"
export DISCORD_SECRET_ID="your_client_secret"
export DISCORD_BOT_TOKEN="your_bot_token"
```

These will override module configuration settings.

## Usage

1. **Client Verification**:

   - Clients visit: `https://yourdomain.com/index.php?m=discord_verification`
   - They authenticate with Discord and get verified
   - Roles are automatically assigned based on account status

2. **Admin Management**:

   - Go to **Setup → Addon Modules → Discord Client Verification**
   - View statistics: total verified users, active vs default role counts
   - See all verified users with their Discord info and role status
   - **Sync All Users**: Manually trigger role synchronization for all users
   - **Individual Actions**: Sync or remove Discord associations per user

3. **Client Area Widget**:

   - Optional sidebar widget showing Discord verification status
   - Shows "Active Member", "Verified", or "Not Verified" badges
   - Quick access buttons to verify or manage Discord connection
   - Can be enabled/disabled in addon configuration

4. **Automatic Role Management**:
   - Roles update when services are activated/suspended/terminated
   - Daily cron job ensures synchronization
   - Manual role updates via admin interface or activity log

## Troubleshooting

1. **Check WHMCS Activity Log**: All operations are logged for debugging
2. **Verify Discord Permissions**: Ensure bot has `Manage Roles` permission
3. **Check Role Hierarchy**: Bot role must be higher than assigned roles
4. **Validate OAuth2 Settings**: Ensure redirect URI matches exactly

## Requirements

- WHMCS 7.0+
- PHP 7+
- cURL extension
- Valid Discord application with bot

## Support

Create a ticket via Github or join the discord

- https://26bz.online/discord
