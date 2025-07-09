# WHMCS Discord Client Verification

This integration allows WHMCS clients to automatically receive Discord roles based on their account status. Clients with active products receive one role, while verified clients without active products receive a different role. The system automatically updates roles when client status changes.

![Cover Image](/.images/cover_img.png)

## Features

- OAuth2 integration for secure Discord verification
- Automatic role assignment based on client status
- Different roles for active and inactive product states
- Automatic role updates via WHMCS hooks:
  - When products become active/inactive
  - When client status changes
  - Daily synchronization via cron
- CSRF protection and secure error handling

## Requirements

- WHMCS 8.11 or higher
- PHP 7.4 or higher with cURL extension enabled
- A Discord server where you have administrative permissions

## Installation

1. **File Setup**:

   - Place `discord.php` in your WHMCS root directory
   - Add `discord.tpl` to your active template directory (e.g., `/templates/six/`)
   - Add `hooks/discord.php` to your WHMCS hooks directory (`/includes/hooks/`)

2. **Configuration**:
   Create `config.php` outside your web root (recommended) or in a secure location with proper file permissions (644). Example structure:

   ```php
   <?php
   return [
       'client_id' => '',        // Discord Application Client ID
       'secret_id' => '',        // Discord Application Secret
       'scopes' => 'identify email',
       'redirect_uri' => 'https://billing.yourdomain.com/discord.php',
       'guild_id' => '',         // Your Discord Server ID
       'active_role_id' => '',   // Role ID for clients with active products
       'default_role_id' => '',  // Role ID for verified clients without active products
       'bot_token' => ''         // Your Discord Bot Token
   ];
   ```

3. **Update File Paths**:
   In both `discord.php` and `hooks/discord.php`, update the config path:

   ```php
   $config = require '/path/to/your/config.php';
   ```

4. **Discord Setup**:

   **Step 1: Create Discord Application**

   - Go to [Discord Developer Portal](https://discord.com/developers/applications)
   - Click "New Application" and give it a name
   - Navigate to "OAuth2" section
   - Copy the "Client ID" and "Client Secret" for your config

   **Step 2: Configure OAuth2**

   - In the OAuth2 section, add your redirect URL: `https://yourdomain.com/discord.php`
   - Under "Scopes", ensure "identify" and "email" are available

   **Step 3: Create and Configure Bot**

   - Navigate to "Bot" section
   - Click "Add Bot"
   - Copy the bot token for your config
   - Under "Privileged Gateway Intents", enable "Server Members Intent"

   **Step 4: Add Bot to Server**

   - Go to OAuth2 > URL Generator
   - Select scopes: "bot"
   - Select bot permissions: "Manage Roles"
   - Copy the generated URL and visit it to add the bot to your server

   **Step 5: Get Server and Role IDs**

   - Enable Developer Mode in Discord (User Settings > App Settings > Advanced > Developer Mode)
   - Right-click your server name and "Copy ID" (this is your guild_id)
   - Create two roles in your server for active and inactive clients
   - Right-click each role and "Copy ID" for active_role_id and default_role_id

   **Step 6: Role Hierarchy**

   - Ensure your bot's role is positioned above the roles it needs to manage
   - The bot cannot assign roles that are positioned higher than its own role

5. **WHMCS Setup**:
   Create a custom client field:

   - Go to Setup > Custom Client Fields
   - Add field named "discord" (exactly as shown, case-sensitive)
   - Field Type: Text Box
   - Set Admin Only = Yes
   - Save Changes

6. **Testing the Setup**:
   - Visit `https://yourdomain.com/discord.php` while logged into WHMCS
   - Complete the Discord OAuth flow
   - Check that your Discord ID appears in the client's custom field
   - Verify that the appropriate role is assigned in Discord

## Troubleshooting

**Common Issues:**

- **"Discord custom field not found"**: Ensure the custom field is named exactly "discord" (lowercase)
- **Bot can't assign roles**: Check that the bot's role is positioned above the target roles
- **OAuth errors**: Verify your redirect URI matches exactly (including https://)
- **Permission errors**: Ensure the bot has "Manage Roles" permission in your server

## Environment Variables (Optional)

For additional security, you can use environment variables instead of config values:

- `DISCORD_CLIENT_ID`
- `DISCORD_SECRET_ID`
- `DISCORD_BOT_TOKEN`

## How It Works

1. Clients visit `/discord.php` to link their Discord account
2. Upon verification:
   - Clients with active products receive the active role
   - Clients without active products receive the default role
3. Roles automatically update:
   - When products are activated/cancelled
   - When client status changes
   - During daily WHMCS cron job

## Security Notes

- **Store `config.php` outside web root** or protect it with proper file permissions (644)
- **Use environment variables** for sensitive data when possible (see Environment Variables section)
- The integration includes **CSRF protection** for secure form submissions
- **OAuth2 flow** follows Discord's security best practices
- **Discord IDs are validated** to ensure proper format (17-20 digits)
- **Bot token should never be exposed** in client-side code or logs

## Support

For questions or support, please [join our Discord server](https://discord.gg/kSbCa6Q25p) or open an issue on GitHub.
