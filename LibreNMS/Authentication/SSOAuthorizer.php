<?php
/**
 * SSOAuthorizer.php
 *
 * -Description-
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package    LibreNMS\Authentication
 * @link       https://librenms.org
 * @copyright  2017 Adam Bishop
 * @author     Adam Bishop <adam@omega.org.uk>
 */

namespace LibreNMS\Authentication;

use LibreNMS\Config;
use LibreNMS\Util\IP;
use LibreNMS\Exceptions\AuthenticationException;
use LibreNMS\Exceptions\InvalidIpException;

/**
 * Some functionality in this mechanism is inspired by confluence_http_authenticator (@chauth) and graylog-plugin-auth-sso (@Graylog)
 *
 */

class SSOAuthorizer extends AuthorizerBase
{
    protected static $HAS_AUTH_USERMANAGEMENT = 1;
    protected static $CAN_UPDATE_USER = 1;
    protected static $AUTH_IS_EXTERNAL = 1;

    public function authenticate($username, $password)
    {
        if (empty($username)) {
            throw new AuthenticationException('$config[\'sso\'][\'user_attr\'] was not found or was empty');
        }

        // Build the user's details from attributes
        $email = $this->authSSOGetAttr(Config::get('sso.email_attr'));
        $realname = $this->authSSOGetAttr(Config::get('sso.realname_attr'));
        $description = $this->authSSOGetAttr(Config::get('sso.descr_attr'));
        $can_modify_passwd = 0;

        $level = $this->authSSOCalculateLevel();

        // User has already been approved by the authenicator so if automatic user create/update is enabled, do it
        if (Config::get('sso.create_users') && !$this->userExists($username)) {
            $this->addUser($username, $password, $level, $email, $realname, $can_modify_passwd, $description ? $description : 'SSO User');
        } elseif (Config::get('sso.update_users') && $this->userExists($username)) {
            $this->updateUser($this->getUserid($username), $realname, $level, $can_modify_passwd, $email);
        }

        return true;
    }

    public function addUser($username, $password, $level = 1, $email = '', $realname = '', $can_modify_passwd = 0, $description = 'SSO User')
    {
        // Check to see if user is already added in the database
        if (!$this->userExists($username)) {
            $userid = dbInsert(array('username' => $username, 'password' => null, 'realname' => $realname, 'email' => $email, 'descr' => $description, 'level' => $level, 'can_modify_passwd' => $can_modify_passwd), 'users');

            if ($userid == false) {
                return false;
            } else {
                foreach (dbFetchRows('select notifications.* from notifications where not exists( select 1 from notifications_attribs where notifications.notifications_id = notifications_attribs.notifications_id and notifications_attribs.user_id = ?) order by notifications.notifications_id desc', array($userid)) as $notif) {
                    dbInsert(array('notifications_id'=>$notif['notifications_id'],'user_id'=>$userid,'key'=>'read','value'=>1), 'notifications_attribs');
                }
            }
            return $userid;
        } else {
            return false;
        }
    }

    public function userExists($username, $throw_exception = false)
    {
        // A local user is always created, so we query this, as in the event of an admin choosing to manually administer user creation we should return false.
        $user = dbFetchCell('SELECT COUNT(*) FROM users WHERE username = ?', array($this->getExternalUsername()), true);

        if ($throw_exception) {
            // Hopefully the caller knows what they're doing
            throw new AuthenticationException('User not found and invocation requested an exception be thrown');
        }

        return $user;
    }


    public function getUserLevel($username)
    {
        // The user level should always be persisted to the database (and may be managed there) so we again query the database.
        return dbFetchCell('SELECT `level` FROM `users` WHERE `username` = ?', array($this->getExternalUsername()), true);
    }


    public function getUserid($username)
    {
        // User ID is obviously unique to LibreNMS, this must be resolved via the database
        return dbFetchCell('SELECT `user_id` FROM `users` WHERE `username` = ?', array($this->getExternalUsername()), true);
    }


    public function deleteUser($userid)
    {
        // Clean up the entries persisted to the database
        dbDelete('bill_perms', '`user_id` =  ?', array($userid));
        dbDelete('devices_perms', '`user_id` =  ?', array($userid));
        dbDelete('ports_perms', '`user_id` =  ?', array($userid));
        dbDelete('users_prefs', '`user_id` =  ?', array($userid));
        dbDelete('users', '`user_id` =  ?', array($userid));

        return dbDelete('users', '`user_id` =  ?', array($userid));
    }


    public function getUserlist()
    {
        return dbFetchRows('SELECT * FROM `users` ORDER BY `username`');
    }


    public function getUser($user_id)
    {
        return dbFetchRow('SELECT * FROM `users` WHERE `user_id` = ?', array($user_id), true);
    }

    public function updateUser($user_id, $realname, $level, $can_modify_passwd, $email)
    {
        // This could, in the worse case, occur on every single pageload, so try and do a bit of optimisation
        $user = $this->getUser($user_id);

        if ($realname !== $user['realname'] || $level !== $user['level'] || $can_modify_passwd !== $user['can_modify_passwd'] || $email !== $user['email']) {
            dbUpdate(array('realname' => $realname, 'level' => $level, 'can_modify_passwd' => $can_modify_passwd, 'email' => $email), 'users', '`user_id` = ?', array($user_id));
        }
    }

    public function getExternalUsername()
    {
        return $this->authSSOGetAttr(Config::get('sso.user_attr'), '');
    }

    /**
     * Return an attribute from the configured attribute store.
     * Returns null if the attribute cannot be found
     *
     * @param string $attr The name of the attribute to find
     * @return string|null
     */
    public function authSSOGetAttr($attr, $prefix = 'HTTP_')
    {
        // Check attribute originates from a trusted proxy - we check it on every attribute just in case this gets called after initial login
        if ($this->authSSOProxyTrusted()) {
            // Short circuit everything if the attribute is non-existant or null
            if (empty($attr)) {
                return null;
            }

            $header_key = $prefix . str_replace('-', '_', strtoupper($attr));

            if (Config::get('sso.mode') === 'header' && array_key_exists($header_key, $_SERVER)) {
                return $_SERVER[$header_key];
            } elseif (Config::get('sso.mode') === 'env' && array_key_exists($attr, $_SERVER)) {
                return $_SERVER[$attr];
            } else {
                return null;
            }
        } else {
            throw new AuthenticationException('$config[\'sso\'][\'trusted_proxies\'] is set, but this connection did not originate from trusted source: ' . $_SERVER['REMOTE_ADDR']);
        }
    }

    /**
     * Checks to see if the connection originated from a trusted source address stored in the configuration.
     * Returns false if the connection is untrusted, true if the connection is trusted, and true if the trusted sources are not defined.
     *
     * @return bool
     */
    public function authSSOProxyTrusted()
    {
        // We assume IP is used - if anyone is using a non-ip transport, support will need to be added
        if (Config::get('sso.trusted_proxies')) {
            try {
                // Where did the HTTP connection originate from?
                if (isset($_SERVER['REMOTE_ADDR'])) {
                    // Do not replace this with a call to authSSOGetAttr
                    $source = IP::parse($_SERVER['REMOTE_ADDR']);
                } else {
                    return false;
                }

                foreach (Config::get('sso.trusted_proxies') as $value) {
                    $proxy = IP::parse($value);

                    if ($source->innetwork((string) $proxy)) {
                        // Proxy matches trusted subnet
                        return true;
                    }
                }
                // No match, proxy is untrusted
                return false;
            } catch (InvalidIpException $e) {
                // Webserver is talking nonsense (or, IPv10 has been deployed, or maybe something weird like a domain socket is in use?)
                return false;
            }
        }
        // Not enabled, trust everything
        return true;
    }

    /**
     * Calculate the privilege level to assign to a user based on the configuration and attributes supplied by the external authenticator.
     * Returns an integer if the permission is found, or raises an AuthenticationException if the configuration is not valid.
     *
     * @return integer
     */
    public function authSSOCalculateLevel()
    {
        if (Config::get('sso.group_strategy') === 'attribute') {
            if (Config::get('sso.level_attr')) {
                if (is_numeric($this->authSSOGetAttr(Config::get('sso.level_attr')))) {
                    return (int) $this->authSSOGetAttr(Config::get('sso.level_attr'));
                } else {
                    throw new AuthenticationException('group assignment by attribute requested, but httpd is not setting the attribute to a number');
                }
            } else {
                throw new AuthenticationException('group assignment by attribute requested, but $config[\'sso\'][\'level_attr\'] not set');
            }
        } elseif (Config::get('sso.group_strategy') === 'map') {
            if (Config::get('sso.group_level_map') && is_array(Config::get('sso.group_level_map')) && Config::get('sso.group_delimiter') && Config::get('sso.group_attr')) {
                return (int) $this->authSSOParseGroups();
            } else {
                throw new AuthenticationException('group assignment by level map requested, but $config[\'sso\'][\'group_level_map\'], $config[\'sso\'][\'group_attr\'], or $config[\'sso\'][\'group_delimiter\'] are not set');
            }
        } elseif (Config::get('sso.group_strategy') === 'static') {
            if (Config::get('sso.static_level')) {
                return (int) Config::get('sso.static_level');
            } else {
                throw new AuthenticationException('group assignment by static level was requested, but $config[\'sso\'][\'group_level_map\'] was not set');
            }
        }

        throw new AuthenticationException('$config[\'sso\'][\'group_strategy\'] is not set to one of attribute, map or static - configuration is unsafe');
    }

    /**
     * Map a user to a permission level based on a table mapping, 0 if no matching group is found.
     *
     * @return integer
     */
    public function authSSOParseGroups()
    {
        // Parse a delimited group list
        $groups = explode(Config::get('sso.group_delimiter'), $this->authSSOGetAttr(Config::get('sso.group_attr')));

        $valid_groups = array();

        // Only consider groups that match the filter expression - this is an optimisation for sites with thousands of groups
        if (Config::get('sso.group_filter')) {
            foreach ($groups as $group) {
                if (preg_match(Config::get('sso.group_filter'), $group)) {
                    array_push($valid_groups, $group);
                }
            }

            $groups = $valid_groups;
        }

        $level = 0;

        $config_map = Config::get('sso.group_level_map');

        // Find the highest level the user is entitled to
        foreach ($groups as $value) {
            if (isset($config_map[$value])) {
                $map = $config_map[$value];

                if (is_integer($map) && $level < $map) {
                    $level = $map;
                }
            }
        }

        return $level;
    }
}
